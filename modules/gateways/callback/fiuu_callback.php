<?php

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

// Check if the current $skey is the same as the one stored in the session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['prev_skey']) && $_SESSION['prev_skey'] === $skey) {
    exit("Duplicate response. Request terminated.");
}
# GB patch 3: prev_skey is saved only after Fiuu is acknowledged (below), so a 503 retry is not dropped here.
 
 if($nbcb == 1)
{
	# GB patch 3: acknowledge Fiuu only after the payment is recorded (see the end of this file)
	$gbfiuu_cb_ack = true;
}
else
{
	foreach ($_POST as $k => $v) 
	{
	    $postData[] = $k . "=" . $v;
	}
	$postdata =implode("&",$postData);
	$url	="https://pay.fiuu.com/RMS/API/chkstat/returnipn.php";
	$ch 	=curl_init();
	curl_setopt($ch, CURLOPT_POST , 1 );
	curl_setopt($ch, CURLOPT_POSTFIELDS , $postdata );
	curl_setopt($ch, CURLOPT_URL , $url );
	curl_setopt($ch, CURLOPT_HEADER , 1 );
	curl_setopt($ch, CURLINFO_HEADER_OUT , TRUE );
	curl_setopt($ch, CURLOPT_RETURNTRANSFER , 1 );
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER , FALSE);
	$result = curl_exec( $ch );
	curl_close( $ch );
}
 
  $key0 = md5($tranID.$orderid.$status.$domain.$amount.$currency);
  $key1 = md5($paydate.$domain.$key0.$appcode.$passwd);

  if (!is_string($skey) || !hash_equals($key1, $skey)) $status = -1; # GB patch 3: constant-time compare

 $viewinvoice = $CONFIG['SystemURL']."/viewinvoice.php?id=".$orderid;
 $clientarea = $CONFIG['SystemURL']."/clientarea.php?action=invoices";

$invoiceid = checkCbInvoiceID($orderid,$GATEWAY["name"]); # Checks invoice ID is a valid invoice number or ends processing

//checkCbTransID($transid); # Checks transaction number isn't already in the database and ends processing if it does


if ($status=="00") {
    # Successful
    
    # --- GB Network patch 3: record each tranID once (lock + check inside the lock); see fiuu/gb_payment.php ---
    require_once __DIR__ . '/../fiuu/gb_payment.php';
    $gbfiuu_ob = ob_get_level();
    ob_start(); # GB patch 3: hook/WHMCS output must not precede the ack or the 503
    $gbfiuu_result = gbfiuu_record_payment(array(
        'invoiceid' => $invoiceid,
        'transid' => $transid,
        'amount' => $amount,
        'gateway' => $gatewaymodule,
        'gatewayname' => $GATEWAY["name"],
        'log' => $_POST,
    ));
    gbfiuu_discard_output($gbfiuu_ob);
    if (!gbfiuu_should_ack($gbfiuu_result)) {
        # Not recorded yet: no CBTOKEN, so Fiuu retries the callback (up to 3 times, 15 min apart).
        gbfiuu_respond_retry();
    }
    $_SESSION['prev_skey'] = $skey;
    if (!empty($gbfiuu_cb_ack)) {
        echo "CBTOKEN:MPSTATOK";
        exit;
    }
    # --- end GB Network patch 3 ---
    header('Location: '.$viewinvoice);
	
		
} else {
	# Unsuccessful
    logTransaction($GATEWAY["name"],$_POST,"Unsuccessful");
    $_SESSION['prev_skey'] = $skey; # GB patch 3: saved after processing, not before
    if (!empty($gbfiuu_cb_ack)) { echo "CBTOKEN:MPSTATOK"; exit; } # GB patch 3: ack after processing
    header('Location: '.$clientarea);
}

?>
