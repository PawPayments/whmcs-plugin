<?php

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__ . '/../pawpayments/pawpayments_common.php';

$gatewayModule = 'pawpayments';
$gateway = getGatewayVariables($gatewayModule);
if (!$gateway['type']) {
    die('Module Not Activated');
}

$payload = pawpayments_verified_payload((string) ($gateway['api_key'] ?? ''));

$invoiceId = (int) ($payload['extra'] ?? 0);
$orderId = (string) ($payload['order_id'] ?? '');
if (!$invoiceId || $orderId === '') {
    http_response_code(400);
    die('Missing extra or order_id');
}

checkCbInvoiceID($invoiceId, $gatewayModule);

// The facts pawpayments_checkout_action() decides on, looked up only for a
// webhook that may book money on the invoice.
$invoiceTotal = null;
$clientCurrency = '';
$alreadyRecorded = false;
if (in_array((string) ($payload['status'] ?? ''), PAWPAYMENTS_SETTLING_STATUSES, true)) {
    $alreadyRecorded = pawpayments_transaction_recorded($orderId);
    $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    $invoiceTotal = $invoice['total'] ?? null;
    $clientCurrency = pawpayments_client_currency((int) ($invoice['userid'] ?? 0));
}

$action = pawpayments_checkout_action($payload, $invoiceTotal, $clientCurrency, $alreadyRecorded);
if ($action['book'] !== null) {
    addInvoicePayment($invoiceId, $orderId, $action['book'], 0, $gatewayModule);
}
logTransaction($gatewayModule, $payload, $action['log']);

http_response_code($action['http']);
echo $action['body'];
