<?php
/**
 * GB patch 2: click-time redirect to Fiuu (see lib.php for the why).
 * GET ?invoiceid=<id>&t=<hmac>  ->  re-read the invoice, then auto-POST to Fiuu with the CURRENT balance,
 * or send the customer back to the invoice if it is no longer Unpaid or no longer set to Fiuu.
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/lib.php';
App::load_function('gateway');
App::load_function('invoice');

global $CONFIG;

$gw = getGatewayVariables('rms');
if (empty($gw['type'])) {
    die('Module Not Activated');
}

$deps = array(
    'gw' => $gw,
    'systemUrl' => $CONFIG['SystemURL'],
    'invoice' => function ($id) {
        $row = Capsule::table('tblinvoices')->where('id', $id)->first(array('id', 'userid', 'status', 'paymentmethod'));
        return $row ? (array) $row : null;
    },
    'params' => function ($id) {
        $invoice = new \WHMCS\Invoice($id);
        return $invoice->getGatewayInvoiceParams();
    },
    'itemDesc' => function ($id) {
        $d = Capsule::table('tblinvoiceitems')->where('invoiceid', $id)->orderBy('id')->value('description');
        return $d ? (string) $d : '';
    },
    'log' => function ($msg, $userid) {
        logActivity($msg, $userid);
    },
);

$result = gbfiuu_handle($_GET, $deps);

header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
if ($result['type'] === 'redirect') {
    header('Location: ' . $result['url']);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
echo $result['body'];
