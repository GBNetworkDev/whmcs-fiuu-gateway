<?php
/**
 * Fake addInvoicePayment. Behaviour by invoice id:
 *   9001 refuses (returns false, writes nothing)    9002 throws before the insert (DB error)
 *   9003 inserts, then a hook throws               9004 holds for 4 s before inserting (slow invoice load)
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
    fake_pdo()->prepare('INSERT INTO tblaccounts (invoiceid, transid, amountin, gateway) VALUES (?,?,?,?)')
        ->execute(array($invoiceid, $transid, $amount, $gateway));
    if ($invoiceid === 9003) {
        throw new RuntimeException('simulated hook failure after insert');
    }
    return true;
}
