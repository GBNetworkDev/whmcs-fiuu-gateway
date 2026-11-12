<?php
/**
 * GB patch 3: record each Fiuu transaction exactly once, and do not leave a payment half processed.
 *
 * Production incidents (Nov 2026):
 *  1. Double payment, INV-1017315 (tranID 4166432931). Fiuu sent the notify three times in the same second, from two
 *     IPs. The vendor code runs "SELECT COUNT(*) FROM tblaccounts WHERE transid = ?" and then addInvoicePayment().
 *     That is check-then-insert with no lock, so two requests both saw 0 and both recorded the payment. The
 *     $_SESSION['prev_skey'] guard cannot help: server-to-server requests carry no session cookie.
 *  2. Mass payment half applied, INV-1017375 (tranID 4174268594). On a mass-pay invoice, addInvoicePayment() pays
 *     every child invoice in the same request, and each child fires the InvoicePaid hooks (Bukku e-invoice sync,
 *     about 80 s each). The notify request ended with HTTP 500 after 2 of 5 children. logTransaction() runs after
 *     addInvoicePayment(), so it never ran. Any retry sees the transid and exits, so nothing finished the rest.
 *
 * Fix (this file):
 *  - Serialise per tranID with a MariaDB named lock (GET_LOCK) and do the "already recorded?" check inside the lock.
 *    The lock is tied to the DB connection, so it is released even if PHP dies.
 *  - Write the gateway log BEFORE addInvoicePayment(), so every accepted payment is visible even if a hook fails.
 *  - ignore_user_abort(true) and a longer time limit, so the request is not cut off if Fiuu hangs up.
 * The slow InvoicePaid hook itself belongs to the e_invoice addon and is fixed there, not here
 * (GBNetworkDev/whmcs-einvoice#1: deferred, batched Bukku sync). This module contains no e_invoice/Bukku code.
 *
 * WHMCS calls are injected through $deps so the logic can be tested against a plain MariaDB (see tests/).
 */

if (!function_exists('gbfiuu_record_payment')) {

    /** Named-lock key for a transaction. MariaDB limits lock names to 64 characters. */
    function gbfiuu_lock_name($transid)
    {
        return 'gbfiuu_tx_' . substr(hash('sha256', (string) $transid), 0, 40);
    }

    /**
     * Record a verified, successful Fiuu payment at most once per tranID.
     *
     * @param array $p     invoiceid, transid, amount, gateway (module name, "rms"), gatewayname (for the log), log (array)
     * @param array $deps  optional: pdo, addPayment(invoiceid, transid, amount, fee, gateway), log(name, data, result),
     *                     lockWait (seconds, default 60)
     * @return string 'recorded' | 'duplicate' | 'busy' | 'invalid' | 'error'
     */
    function gbfiuu_record_payment(array $p, array $deps = array())
    {
        $pdo = isset($deps['pdo']) ? $deps['pdo'] : \WHMCS\Database\Capsule::connection()->getPdo();
        $addPayment = isset($deps['addPayment']) ? $deps['addPayment'] : function ($inv, $tid, $amt, $fee, $gw) {
            return addInvoicePayment($inv, $tid, $amt, $fee, $gw);
        };
        $log = isset($deps['log']) ? $deps['log'] : function ($name, $data, $result) {
            logTransaction($name, $data, $result);
        };
        $lockWait = isset($deps['lockWait']) ? (int) $deps['lockWait'] : 60;

        $invoiceid = (int) $p['invoiceid'];
        $transid = trim((string) $p['transid']);
        $logData = isset($p['log']) && is_array($p['log']) ? $p['log'] : array();
        if ($invoiceid <= 0 || $transid === '') {
            $log($p['gatewayname'], $logData, 'Not recorded: missing invoice or tranID');
            return 'invalid';
        }

        // Finish the job even if Fiuu (or the customer's browser) disconnects while hooks run.
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $lock = gbfiuu_lock_name($transid);
        $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute(array($lock, $lockWait));
        if ((int) $st->fetchColumn() !== 1) {
            // Another request for the same tranID still holds the lock and is recording it.
            $log($p['gatewayname'], $logData, 'Not recorded: same tranID is being processed (lock busy)');
            return 'busy';
        }

        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM tblaccounts WHERE transid = ?');
            $st->execute(array($transid));
            if ((int) $st->fetchColumn() > 0) {
                $log($p['gatewayname'], $logData, 'Already recorded (duplicate ignored)');
                return 'duplicate';
            }

            $log($p['gatewayname'], $logData, 'Successful');
            try {
                $addPayment($invoiceid, $transid, $p['amount'], 0, $p['gateway']);
            } catch (\Throwable $e) {
                $log($p['gatewayname'], $logData + array('gb_error' => $e->getMessage()), 'Error after recording started');
                return 'error';
            }
            return 'recorded';
        } finally {
            $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $st->execute(array($lock));
        }
    }
}
