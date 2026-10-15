<?php
// Unit tests for GB patch 2 (modules/gateways/fiuu/lib.php). Run: php tests/gb2_redirect_test.php
require __DIR__ . '/../modules/gateways/fiuu/lib.php';

$fail = 0;
$n = 0;
function ok($cond, $name)
{
    global $fail, $n;
    $n++;
    if (!$cond) {
        $fail++;
        echo "FAIL: $name\n";
    }
}

$gw = array('merchantid' => 'gbnet_Dev', 'verifykey' => 'vk123', 'secretkey' => 'sk456', 'type' => 'Invoices');
$sys = 'https://secure.gbnetwork.com/';
$invoices = array(
    151000 => array('id' => 151000, 'userid' => 20925, 'status' => 'Unpaid', 'paymentmethod' => 'coinpayments'),
    151001 => array('id' => 151001, 'userid' => 20925, 'status' => 'Unpaid', 'paymentmethod' => 'rms'),
    151002 => array('id' => 151002, 'userid' => 20925, 'status' => 'Paid', 'paymentmethod' => 'rms'),
    151003 => array('id' => 151003, 'userid' => 20925, 'status' => 'Unpaid', 'paymentmethod' => 'rms'),
);
$params = array(
    151001 => array('invoiceid' => 151001, 'amount' => 103.0, 'currency' => 'MYR', 'description' => 'GB Network - Invoice #151001',
        'clientdetails' => array('userid' => 20925, 'firstname' => 'GB', 'lastname' => 'Umie', 'email' => 'u@x.my',
            'country' => 'MY', 'phonenumber' => '+60.123', 'address1' => 'a')),
    151003 => array('invoiceid' => 151003, 'amount' => 0, 'currency' => 'MYR', 'description' => 'x', 'clientdetails' => array()),
);
$logs = array();
$deps = array(
    'gw' => $gw, 'systemUrl' => $sys,
    'invoice' => function ($id) use ($invoices) { return isset($invoices[$id]) ? $invoices[$id] : null; },
    'params' => function ($id) use ($params) { return $params[$id]; },
    'itemDesc' => function ($id) { return 'Hosting <b>"Silver"</b>'; },
    'log' => function ($m, $u) use (&$logs) { $logs[] = array($m, $u); },
);
$t = function ($id) use ($gw) { return gbfiuu_token($id, $gw); };

// 1. Umie's bug: method switched away from Fiuu -> back to invoice, nothing sent to Fiuu, activity logged.
$r = gbfiuu_handle(array('invoiceid' => '151000', 't' => $t(151000)), $deps);
ok($r['type'] === 'redirect' && $r['url'] === 'https://secure.gbnetwork.com/viewinvoice.php?id=151000', 'method changed -> back');
ok($r['reason'] === 'method_changed_to_coinpayments', 'reason names new method');
ok(count($logs) === 1 && $logs[0][1] === 20925 && strpos($logs[0][0], '#151000') !== false, 'activity logged once');

// 2. Still Fiuu -> auto-POST with CURRENT amount and matching vcode.
$r = gbfiuu_handle(array('invoiceid' => '151001', 't' => $t(151001)), $deps);
ok($r['type'] === 'html', 'still rms -> pay');
ok($r['fields']['amount'] === '103.00', 'fresh amount formatted');
ok($r['fields']['vcode'] === md5('103.00gbnet_Dev151001vk123'), 'vcode = md5(amount.merchant.orderid.verifykey)');
ok($r['fields']['orderid'] === '151001' && $r['fields']['instID'] === 'gbnet_Dev', 'order + merchant');
ok($r['fields']['callbackurl'] === 'https://secure.gbnetwork.com/modules/gateways/notify/fiuu_notify.php', 'notify url no double slash');
ok($r['fields']['returnurl'] === 'https://secure.gbnetwork.com/modules/gateways/return/fiuu_return.php', 'return url');
ok(strpos($r['body'], 'action="https://pay.fiuu.com/RMS/pay/gbnet_Dev/"') !== false, 'posts to Fiuu');
ok(strpos($r['body'], '&lt;b&gt;&quot;Silver&quot;&lt;/b&gt;') !== false && strpos($r['body'], '<b>"Silver"</b>') === false, 'fields HTML-escaped');
ok(strpos($r['body'], 'sk456') === false && strpos($r['body'], 'vk123') === false, 'no keys leaked');

// 3. Paid / zero balance / bad token / unknown invoice / missing id.
$r = gbfiuu_handle(array('invoiceid' => '151002', 't' => $t(151002)), $deps);
ok($r['type'] === 'redirect' && $r['reason'] === 'status_paid', 'paid -> back');
$r = gbfiuu_handle(array('invoiceid' => '151003', 't' => $t(151003)), $deps);
ok($r['type'] === 'redirect' && $r['reason'] === 'nothing_to_pay', 'zero balance -> back');
$r = gbfiuu_handle(array('invoiceid' => '151001', 't' => 'x'), $deps);
ok($r['type'] === 'redirect' && $r['url'] === 'https://secure.gbnetwork.com/' && $r['reason'] === 'bad_token', 'bad token denied');
$r = gbfiuu_handle(array('invoiceid' => '151001', 't' => $t(151000)), $deps);
ok($r['reason'] === 'bad_token', 'token for another invoice denied');
$r = gbfiuu_handle(array('invoiceid' => '151001'), $deps);
ok($r['reason'] === 'bad_token', 'missing token denied');
$r = gbfiuu_handle(array('invoiceid' => '999'), $deps);
ok($r['reason'] === 'invoice_not_found', 'unknown invoice denied');
$r = gbfiuu_handle(array(), $deps);
ok($r['reason'] === 'no_invoice', 'no id');
$empty = $gw; $empty['secretkey'] = ''; $empty['verifykey'] = '';
ok(!gbfiuu_token_ok(151001, gbfiuu_token(151001, $empty), $empty), 'empty keys never validate');

// 4. Canary switch.
ok(!gbfiuu_enabled_for(20925, array('mode' => 'off', 'clients' => array(20925))), 'off');
ok(gbfiuu_enabled_for(20925, array('mode' => 'clients', 'clients' => array(20925))), 'canary client on');
ok(gbfiuu_enabled_for('20925', array('mode' => 'clients', 'clients' => array('20925'))), 'string ids');
ok(!gbfiuu_enabled_for(1, array('mode' => 'clients', 'clients' => array(20925))), 'other client off');
ok(gbfiuu_enabled_for(1, array('mode' => 'all')), 'all');
ok(!gbfiuu_enabled_for(1, array()), 'missing config = off');

// 5. Button: same-origin GET form with valid token, no amount in it.
$b = gbfiuu_button_html(151001, $gw, $sys);
ok(strpos($b, 'action="https://secure.gbnetwork.com/modules/gateways/fiuu/redirect.php"') !== false, 'button -> redirect.php');
ok(strpos($b, 'method="get"') !== false && strpos($b, 'type="image"') !== false, 'image submit kept for theme JS');
ok(strpos($b, gbfiuu_token(151001, $gw)) !== false && strpos($b, 'amount') === false, 'token, no amount');

// 6. Amount format edge cases.
ok(gbfiuu_amount('1') === '1.00' && gbfiuu_amount(1234.5) === '1234.50' && gbfiuu_amount('0.035') === '0.04', 'amount format');

echo ($fail ? "FAILED $fail/$n\n" : "OK $n tests\n");
exit($fail ? 1 : 0);
