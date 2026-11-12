<?php
function getGatewayVariables($module)
{
    return array('type' => 'Invoices', 'name' => 'Fiuu', 'secretkey' => 'TESTSECRET', 'merchantid' => 'testmerchant');
}

function checkCbInvoiceID($invoiceid, $gateway)
{
    return (int) $invoiceid;
}
