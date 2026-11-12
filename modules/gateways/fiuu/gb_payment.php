<?php
/**
 * GB patch 3: record each Fiuu transaction exactly once, and always leave a gateway log for an accepted payment.
 * (Finishing a half-applied mass pay is NOT done here; that needs the e_invoice fix, see below.)
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
 *  - ignore_user_abort(true), and raise (never lower) the time limit to 600 s, so the request is not cut off if Fiuu
 *    hangs up.
 *  - A hook exception inside addInvoicePayment() is caught and logged. This is safe: WHMCS has already committed the
 *    tblaccounts row and marked the invoice Paid by then (checked on #146795, 10 Jul 2026, SolusVM hook TypeError).
 *  - The COUNT check runs in autocommit (WHMCS commits each statement, which is why rows survive a dead request), so it
 *    sees rows committed by the previous lock holder.
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
     * Raise max_execution_time to $seconds, but never lower it. Production WHMCS runs with 700 s (php.ini/.user.ini),
     * so a plain set_time_limit(600) would shorten it. 0 means unlimited and is left alone.
     */
    function gbfiuu_raise_time_limit($seconds)
    {
        $current = (int) ini_get('max_execution_time');
        if ($current === 0 || $current >= $seconds || !function_exists('set_time_limit')) {
            return false;
        }
        return @set_time_limit($seconds);
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
        gbfiuu_raise_time_limit(600);

        // Fast path: WHMCS inserts the tblaccounts row at the start of addInvoicePayment(), long before the hooks finish.
        // A retry or the customer's browser return that comes in while the first request is still in hooks can stop
        // here instead of waiting up to $lockWait seconds for the lock. The check is repeated inside the lock.
        $st = $pdo->prepare('SELECT COUNT(*) FROM tblaccounts WHERE transid = ?');
        $st->execute(array($transid));
        if ((int) $st->fetchColumn() > 0) {
            $log($p['gatewayname'], $logData, 'Already recorded (duplicate ignored)');
            return 'duplicate';
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
            // Never let the unlock replace the result: if the connection is gone, MariaDB has already freed the lock.
            try {
                $st = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $st->execute(array($lock));
            } catch (\Throwable $e) {
            }
        }
    }
}
