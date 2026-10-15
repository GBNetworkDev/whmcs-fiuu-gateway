<?php
/**
 * GB patch 2: build the Fiuu payment form from the invoice as it is NOW, at click time.
 *
 * Problem: rms_link() used to print a form that POSTs straight to pay.fiuu.com with the amount and vcode computed
 * when the invoice page was rendered. If the customer changes the payment method and clicks "Make Payment" before
 * the page reloads, the payment-gateway-charges module has already added the new method's fee to the invoice, but
 * the stale form still sends the old amount. Fiuu charges the old amount and the invoice stays Unpaid.
 *
 * Fix (same pattern as the CHIP module's redirect.php): the button now points to our own redirect.php. That page
 * re-reads the invoice and only forwards to Fiuu if the invoice is still Unpaid AND still set to this gateway, using
 * the current balance. Otherwise it sends the customer back to the invoice.
 *
 * Pure functions only (no WHMCS calls) so they can be unit tested; redirect.php wires in WHMCS.
 */

if (!function_exists('gbfiuu_config')) {

    function gbfiuu_config()
    {
        $file = __DIR__ . '/config.php';
        $cfg = is_file($file) ? include $file : array();
        if (!is_array($cfg)) {
            $cfg = array();
        }
        return $cfg + array('mode' => 'off', 'clients' => array());
    }

    /** Whether the redirect flow is on for this client. mode: off | clients (allowlist) | all */
    function gbfiuu_enabled_for($clientId, $cfg)
    {
        $mode = isset($cfg['mode']) ? $cfg['mode'] : 'off';
        if ($mode === 'all') {
            return true;
        }
        if ($mode === 'clients') {
            $ids = array_map('intval', isset($cfg['clients']) ? (array) $cfg['clients'] : array());
            return in_array((int) $clientId, $ids, true);
        }
        return false;
    }

    function gbfiuu_key($gw)
    {
        $secret = isset($gw['secretkey']) ? (string) $gw['secretkey'] : '';
        $verify = isset($gw['verifykey']) ? (string) $gw['verifykey'] : '';
        return 'gb-fiuu-redirect|' . $secret . '|' . $verify;
    }

    /** Link token: lets whoever could see the invoice page start a payment, without exposing other invoices. */
    function gbfiuu_token($invoiceId, $gw)
    {
        return hash_hmac('sha256', 'invoice:' . (int) $invoiceId, gbfiuu_key($gw));
    }

    function gbfiuu_token_ok($invoiceId, $token, $gw)
    {
        if (!is_string($token) || $token === '' || gbfiuu_key($gw) === 'gb-fiuu-redirect||') {
            return false;
        }
        return hash_equals(gbfiuu_token($invoiceId, $gw), $token);
    }

    function gbfiuu_amount($amount)
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    /**
     * Decide what redirect.php should do.
     * $invoice: array(id, userid, status, paymentmethod) from tblinvoices, or null.
     * Returns array('action' => 'deny'|'back'|'pay', 'reason' => string).
     */
    function gbfiuu_decide($invoice, $token, $gw, $gatewayModule = 'rms')
    {
        if (!$invoice) {
            return array('action' => 'deny', 'reason' => 'invoice_not_found');
        }
        if (!gbfiuu_token_ok($invoice['id'], $token, $gw)) {
            return array('action' => 'deny', 'reason' => 'bad_token');
        }
        if ($invoice['status'] !== 'Unpaid') {
            return array('action' => 'back', 'reason' => 'status_' . strtolower((string) $invoice['status']));
        }
        if ($invoice['paymentmethod'] !== $gatewayModule) {
            return array('action' => 'back', 'reason' => 'method_changed_to_' . $invoice['paymentmethod']);
        }
        return array('action' => 'pay', 'reason' => 'ok');
    }

    /** The same fields the vendor rms_link() posted, built from current invoice params. */
    function gbfiuu_fields($params, $gw, $itemDesc, $systemUrl)
    {
        $cd = isset($params['clientdetails']) ? $params['clientdetails'] : array();
        $g = function ($k) use ($cd) {
            return isset($cd[$k]) ? (string) $cd[$k] : '';
        };
        $amount = gbfiuu_amount($params['amount']);
        $invoiceId = (string) $params['invoiceid'];
        $merchantId = (string) $gw['merchantid'];
        $base = rtrim((string) $systemUrl, '/');
        $description = isset($params['description']) ? (string) $params['description'] : '';
        return array(
            'instID' => $merchantId,
            'orderid' => $invoiceId,
            'amount' => $amount,
            'cur' => (string) $params['currency'],
            'bill_desc' => $description . ' ' . $itemDesc,
            'bill_email' => $g('email'),
            'bill_name' => $g('firstname') . ' ' . $g('lastname'),
            'country' => $g('country'),
            'bill_mobile' => $g('phonenumber'),
            'returnurl' => $base . '/modules/gateways/return/fiuu_return.php',
            'callbackurl' => $base . '/modules/gateways/notify/fiuu_notify.php',
            'vcode' => md5($amount . $merchantId . $invoiceId . (string) $gw['verifykey']),
        );
    }

    /** Auto-submitting POST to Fiuu (with a manual button when JavaScript is off). */
    function gbfiuu_autopost_html($merchantId, $fields)
    {
        $h = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        $inputs = '';
        foreach ($fields as $k => $v) {
            $inputs .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">' . "\n";
        }
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<title>Redirecting to Fiuu...</title></head><body>'
            . '<form id="gbfiuu" action="https://pay.fiuu.com/RMS/pay/' . $h(rawurlencode($merchantId)) . '/" method="post">'
            . "\n" . $inputs
            . '<noscript><button type="submit">Continue to Fiuu</button></noscript></form>'
            . '<p style="font-family:sans-serif">Redirecting to Fiuu...</p>'
            . '<script>document.getElementById("gbfiuu").submit();</script></body></html>';
    }

    /** Button on the invoice page: a same-origin GET form to redirect.php, styled like the vendor image button. */
    function gbfiuu_button_html($invoiceId, $gw, $systemUrl)
    {
        $h = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };
        $action = rtrim((string) $systemUrl, '/') . '/modules/gateways/fiuu/redirect.php';
        return '<form action="' . $h($action) . '" method="get">'
            . '<input type="hidden" name="invoiceid" value="' . (int) $invoiceId . '">'
            . '<input type="hidden" name="t" value="' . $h(gbfiuu_token($invoiceId, $gw)) . '">'
            . '<br><input src="./images/logo_rms.gif" name="submit" type="image">'
            . '</form>';
    }

    /**
     * Whole request handling with injected dependencies (testable without WHMCS).
     * $deps: invoice(id) => row|null, params(id) => gateway invoice params, gw => gateway vars,
     *        itemDesc(id) => string, systemUrl => string, log(msg, userid) => void.
     * Returns array('type' => 'redirect', 'url' => ...) or array('type' => 'html', 'body' => ...).
     */
    function gbfiuu_handle($get, $deps)
    {
        $systemUrl = rtrim((string) $deps['systemUrl'], '/');
        $invoiceId = isset($get['invoiceid']) ? (int) $get['invoiceid'] : 0;
        if ($invoiceId <= 0) {
            return array('type' => 'redirect', 'url' => $systemUrl . '/', 'reason' => 'no_invoice');
        }
        $token = isset($get['t']) ? (string) $get['t'] : '';
        $invoice = call_user_func($deps['invoice'], $invoiceId);
        $decision = gbfiuu_decide($invoice, $token, $deps['gw']);
        $back = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;
        if ($decision['action'] === 'deny') {
            return array('type' => 'redirect', 'url' => $systemUrl . '/', 'reason' => $decision['reason']);
        }
        if ($decision['action'] === 'back') {
            if (strpos($decision['reason'], 'method_changed_to_') === 0) {
                call_user_func($deps['log'], 'Fiuu redirect stopped: invoice #' . $invoiceId
                    . ' payment method is now ' . $invoice['paymentmethod'] . ' (customer clicked a stale Fiuu button)',
                    (int) $invoice['userid']);
            }
            return array('type' => 'redirect', 'url' => $back, 'reason' => $decision['reason']);
        }
        $params = call_user_func($deps['params'], $invoiceId);
        if (!is_array($params) || !isset($params['amount']) || (float) $params['amount'] <= 0) {
            return array('type' => 'redirect', 'url' => $back, 'reason' => 'nothing_to_pay');
        }
        $fields = gbfiuu_fields($params, $deps['gw'], (string) call_user_func($deps['itemDesc'], $invoiceId), $systemUrl);
        return array('type' => 'html', 'body' => gbfiuu_autopost_html($deps['gw']['merchantid'], $fields),
            'reason' => 'ok', 'fields' => $fields);
    }
}
