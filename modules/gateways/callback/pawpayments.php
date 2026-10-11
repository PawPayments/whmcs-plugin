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

// addInvoicePayment() does not deduplicate: two deliveries of one webhook
// running at once would both see nothing booked yet and both book it.
if (!pawpayments_lock($orderId)) {
    http_response_code(503);
    die('Busy, retry later');
}

// The facts pawpayments_checkout_action() decides on; the invoice is looked up
// only for a webhook that may book money on it.
$issued = pawpayments_issued_invoice($orderId);
$balance = null;
$clientCurrency = '';
$booked = ['amount' => 0.0, 'count' => 0];
if (in_array((string) ($payload['status'] ?? ''), PAWPAYMENTS_SETTLING_STATUSES, true)) {
    $booked = pawpayments_booked($invoiceId, $orderId);
    $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    $balance = $invoice['balance'] ?? null;
    $clientCurrency = pawpayments_client_currency((int) ($invoice['userid'] ?? 0));
}

$action = pawpayments_checkout_action($payload, $invoiceId, $issued, $balance, $clientCurrency, $booked['amount']);
if ($action['book'] !== null) {
    addInvoicePayment(
        $invoiceId,
        pawpayments_transaction_id($orderId, $booked['count']),
        $action['book'],
        0,
        $gatewayModule
    );
}
pawpayments_unlock($orderId);
logTransaction($gatewayModule, $payload, $action['log']);

http_response_code($action['http']);
echo $action['body'];
