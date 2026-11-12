<?php
/**
 * Fake addInvoicePayment. Behaviour by invoice id:
 *   9001 refuses (returns false, writes nothing)    9002 throws before the insert (DB error)
 *   9003 inserts, then a hook throws               9004 holds for 4 s before inserting (slow invoice load)
 *   9005 a hook echoes output and raises a notice after the insert
 *   9006 throws before the insert the FIRST time, succeeds on the next call (to test the retry of a 503)
 *   anything else: inserts and returns true
 */
function addInvoicePayment($invoiceid, $transid, $amount, $fee, $gateway)
{
    $invoiceid = (int) $invoiceid;
    if ($invoiceid === 9001) {
        return false;
    }
    if ($invoiceid === 9002) {
        throw new RuntimeException('simulated DB error before insert');
    }
    if ($invoiceid === 9004) {
        sleep(4);
    }
    if ($invoiceid === 9006) {
        $seen = (int) fake_pdo()->query("SELECT COUNT(*) FROM testlog WHERE type = 'flag9006'")->fetchColumn();
        if (!$seen) {
            fake_event('flag9006', 'first call fails');
            throw new RuntimeException('simulated DB error, first attempt');
        }
    }
    fake_pdo()->prepare('INSERT INTO tblaccounts (invoiceid, transid, amountin, gateway) VALUES (?,?,?,?)')
        ->execute(array($invoiceid, $transid, $amount, $gateway));
    if ($invoiceid === 9005) {
        echo "hook output <br>";
        trigger_error('simulated hook notice', E_USER_NOTICE);
    }
    if ($invoiceid === 9003) {
        throw new RuntimeException('simulated hook failure after insert');
    }
    return true;
}
