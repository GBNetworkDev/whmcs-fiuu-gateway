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
 *    The lock is tied to the DB connection, so it is released even if PHP dies. The lock name is scoped to the
 *    database and uses the same case-insensitive notion of "same tranID" as tblaccounts.transid.
 *  - The in-lock check is a locking read, so it sees the latest committed row even if the caller's connection has
 *    an open transaction (REPEATABLE READ snapshot). WHMCS normally runs in autocommit, where nothing changes.
 *  - Write an "Accepted" gateway log BEFORE addInvoicePayment(), so every accepted payment is visible even if a hook
 *    fails, and write "Successful" only after the tblaccounts row is confirmed.
 *  - ignore_user_abort(true), and raise (never lower) the time limit to 600 s, so the request is not cut off if Fiuu
 *    hangs up. (This does NOT stop LiteSpeed's extappkill; see docs/gb-3-deploy-plan.md, the .htaccess rule is
 *    mandatory.)
 *  - A hook exception inside addInvoicePayment() is caught. If the tblaccounts row exists the payment is recorded
 *    ('error': WHMCS already committed the row and marked the invoice Paid, checked on #146795, 10 Jul 2026); if it
 *    does not, the result is 'not_recorded' so the endpoint asks Fiuu to retry instead of acknowledging.
 *  - gbfiuu_should_ack() tells the endpoints when to acknowledge Fiuu. 'busy' and 'not_recorded' must NOT be
 *    acknowledged (HTTP 503, no CBTOKEN), otherwise Fiuu stops retrying and the payment can be lost.
 * The slow InvoicePaid hook itself belongs to the e_invoice addon and is fixed there, not here
 * (GBNetworkDev/whmcs-einvoice#1: deferred, batched Bukku sync). This module contains no e_invoice/Bukku code.
 *
 * WHMCS calls are injected through $deps so the logic can be tested against a plain MariaDB (see tests/).
 */

if (!function_exists('gbfiuu_record_payment')) {

    /** Normalise a tranID the way tblaccounts.transid compares it (case-insensitive, PAD SPACE). */
    function gbfiuu_norm_transid($transid)
    {
        return strtolower(rtrim(trim((string) $transid), ' '));
    }

    /**
     * Named-lock key for a transaction. MariaDB named locks are server-wide, so the database name is part of the key
     * (another WHMCS on the same MariaDB, e.g. staging, gets its own namespace). MariaDB limits lock names to 64 chars.
     */
    function gbfiuu_lock_name($transid, $dbname = '')
    {
        return 'gbfiuu_tx_' . substr(hash('sha256', (string) $dbname . '|' . gbfiuu_norm_transid($transid)), 0, 40);
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
     * Whether an endpoint may acknowledge Fiuu for this result (302 / CBTOKEN). Retrying cannot fix 'invalid', and
     * 'recorded' / 'duplicate' / 'error' (row exists, a hook failed after it) are done. 'busy' and 'not_recorded'
     * mean nothing is recorded yet: answer HTTP 503 so Fiuu retries (the fast path makes retries cheap).
     */
    function gbfiuu_should_ack($result)
    {
        return !in_array($result, array('busy', 'not_recorded'), true);
    }

    /** Count tblaccounts rows for a tranID. $locking = locking read (latest committed, ignores the caller's snapshot). */
    function gbfiuu_count_rows(PDO $pdo, $transid, $locking = false)
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM tblaccounts WHERE transid = ?' . ($locking ? ' LOCK IN SHARE MODE' : ''));
        $st->execute(array($transid));
        return (int) $st->fetchColumn();
    }

    /**
     * Record a verified, successful Fiuu payment at most once per tranID.
     *
     * @param array $p     invoiceid, transid, amount, gateway (module name, "rms"), gatewayname (for the log), log (array)
     * @param array $deps  optional: pdo, addPayment(invoiceid, transid, amount, fee, gateway), log(name, data, result),
     *                     alert(message) (admin activity log), lockWait (seconds, default 60), dbname
     * @return string 'recorded' | 'duplicate' | 'busy' | 'invalid' | 'error' | 'not_recorded'
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
        $alert = isset($deps['alert']) ? $deps['alert'] : function ($message) {
            if (function_exists('logActivity')) {
                logActivity($message);
            }
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
        if (gbfiuu_count_rows($pdo, $transid) > 0) {
            $log($p['gatewayname'], $logData, 'Already recorded (duplicate ignored)');
            return 'duplicate';
        }

        $dbname = isset($deps['dbname']) ? (string) $deps['dbname'] : (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $lock = gbfiuu_lock_name($transid, $dbname);
        $st = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute(array($lock, $lockWait));
        if ((int) $st->fetchColumn() !== 1) {
            // 0 = another request for the same tranID still holds the lock; NULL = error. Either way nothing was recorded.
            $log($p['gatewayname'], $logData, 'Not recorded: same tranID is being processed (lock busy), Fiuu will retry');
            $alert("Fiuu: tranID $transid for invoice #$invoiceid not recorded (lock busy), waiting for Fiuu retry");
            return 'busy';
        }

        try {
            if (gbfiuu_count_rows($pdo, $transid, true) > 0) {
                $log($p['gatewayname'], $logData, 'Already recorded (duplicate ignored)');
                return 'duplicate';
            }

            $log($p['gatewayname'], $logData, 'Accepted, recording payment');
            $error = null;
            $returned = null;
            try {
                $returned = $addPayment($invoiceid, $transid, $p['amount'], 0, $p['gateway']);
            } catch (\Throwable $e) {
                $error = $e;
            }

            // Logging after the payment must never turn a recorded payment into a fatal error (the DB link may be gone).
            $safe = function (callable $fn) {
                try {
                    $fn();
                } catch (\Throwable $e) {
                }
            };

            // The row is the truth: addInvoicePayment() returns false for some refusals and a hook can throw after the
            // insert. Re-check (locking read) instead of trusting the return value or the exception alone.
            try {
                $recorded = gbfiuu_count_rows($pdo, $transid, true) > 0;
            } catch (\Throwable $e) {
                $recorded = null; // cannot verify, e.g. the connection died inside a hook
            }
            if ($recorded === null) {
                if ($error === null) {
                    // addInvoicePayment() returned normally, so WHMCS inserted and committed the row before its hooks.
                    $safe(function () use ($log, $p, $logData) {
                        $log($p['gatewayname'], $logData, 'Successful (not re-verified: DB connection lost)');
                    });
                    return 'recorded';
                }
                // Unknown: let Fiuu retry; a retry is idempotent (fast path answers 'duplicate' if the row exists).
                $safe(function () use ($alert, $transid, $invoiceid, $error) {
                    $alert("Fiuu: tranID $transid for invoice #$invoiceid unverified after error: " . $error->getMessage());
                });
                return 'not_recorded';
            }
            if ($recorded && $error === null) {
                $safe(function () use ($log, $p, $logData) {
                    $log($p['gatewayname'], $logData, 'Successful');
                });
                return 'recorded';
            }
            if ($recorded) {
                $safe(function () use ($log, $alert, $p, $logData, $error, $transid, $invoiceid) {
                    $log($p['gatewayname'], $logData + array('gb_error' => $error->getMessage()), 'Successful (hook error after recording)');
                    $alert("Fiuu: tranID $transid recorded on invoice #$invoiceid, but a hook failed: " . $error->getMessage());
                });
                return 'error';
            }
            $why = $error !== null ? $error->getMessage() : ('addInvoicePayment returned ' . var_export($returned, true));
            $safe(function () use ($log, $alert, $p, $logData, $why, $transid, $invoiceid) {
                $log($p['gatewayname'], $logData + array('gb_error' => $why), 'Not recorded: ' . $why);
                $alert("Fiuu: tranID $transid for invoice #$invoiceid NOT recorded ($why), waiting for Fiuu retry");
            });
            return 'not_recorded';
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
