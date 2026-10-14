<?php

# --- GB Network patch 1 (2026-10-14): same-site bounce so the customer stays logged in after paying. ---
# Fiuu returns the customer with a cross-site POST from pay.fiuu.com. Browsers do not send the WHMCS session cookie
# (no SameSite attribute => treated as Lax) on a cross-site POST, so loading init.php here would start a NEW session
# and overwrite the customer's login cookie (they land on the login page). On the first POST we therefore do not load
# WHMCS: we re-submit the same fields to this URL from our own origin, which carries the cookie, and the unchanged
# vendor code below then runs with the customer's session. Payment validation and recording are not modified.
# See https://github.com/GBNetworkDev/whmcs-fiuu-gateway (README, "GB patch 1").
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST['gb_bounced'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Robots-Tag: noindex');
    $h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $fields = '';
    foreach ($_POST as $k => $v) {
        if (is_array($v)) { continue; }
        $fields .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
    }
    $action = $h(strtok($_SERVER['REQUEST_URI'] ?? 'fiuu_return.php', '?'));
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
        . '<title>Returning to GB Network</title></head>'
        . '<body style="font-family:sans-serif;text-align:center;padding-top:60px">'
        . '<form id="gbret" method="post" action="' . $action . '">' . $fields
        . '<input type="hidden" name="gb_bounced" value="1">'
        . '<p>Payment received. Returning you to your account&hellip;</p>'
        . '<noscript><button type="submit">Continue</button></noscript>'
        . '</form><script>document.getElementById("gbret").submit();</script></body></html>';
    exit;
}
unset($_POST['gb_bounced']);
# --- end GB Network patch 1 ---

# Required File Includes
include("../../../init.php");
include("../../../includes/functions.php");
include("../../../includes/gatewayfunctions.php");
include("../../../includes/invoicefunctions.php");


global $CONFIG;

$gatewaymodule = "rms"; # Enter your gateway module name here replacing template

$GATEWAY = getGatewayVariables($gatewaymodule);
if (!$GATEWAY["type"]) die("Module Not Activated"); # Checks gateway module is active before accepting callback

# Get Returned Variables

$_POST['treq'] = 1;
$nbcb = $_POST['nbcb'];

 $transid = $_POST['tranID'];
 $orderid = $_POST['orderid'];	
 $status = $_POST['status'];
 $domain = $_POST['domain'];
 $amount = $_POST['amount'];
 $currency = $_POST['currency'];
 $appcode = $_POST['appcode'];
 $paydate = $_POST['paydate'];
 $skey = $_POST['skey'];
 $cust_name = $_POST['cust_name'];
 $cust_email = $_POST['email'];
 $passwd = $GATEWAY['secretkey'];
 
$key0 = md5($tranID.$orderid.$status.$domain.$amount.$currency);
$key1 = md5($paydate.$domain.$key0.$appcode.$passwd);

if ( $skey != $key1 ) $status = -1;

$viewinvoice = $CONFIG['SystemURL']."/viewinvoice.php?id=".$orderid;
$clientarea = $CONFIG['SystemURL']."/clientarea.php?action=invoices";

$invoiceid = checkCbInvoiceID($orderid,$GATEWAY["name"]); # Checks invoice ID is a valid invoice number or ends processing

//checkCbTransID($transid); # Checks transaction number isn't already in the database and ends processing if it does

if ($status=="00") {
    # Successful
    
    $checkResult = select_query("tblaccounts", "COUNT(*)", array("transid" => $transid));
    $checkData = mysql_fetch_array($checkResult);
	
    if ($checkData[0]) {
	header("Location: ".$viewinvoice);
	exit();
    }
    
    addInvoicePayment($invoiceid,$transid,$amount,$fee,$gatewaymodule);
    logTransaction($GATEWAY["name"],$_POST,"Successful");
    header('Location: '.$viewinvoice);
	
		
} else {
	# Unsuccessful
    logTransaction($GATEWAY["name"],$_POST,"Unsuccessful");
    header('Location: '.$clientarea);
}

?>
