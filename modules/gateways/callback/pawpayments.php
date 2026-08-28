<?php

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

$gatewayModule = 'pawpayments';
$gateway = getGatewayVariables($gatewayModule);
if (!$gateway['type']) {
    die('Module Not Activated');
}

require_once __DIR__ . '/../pawpayments/vendor/pawpayments/sdk/src/Exception/PawPaymentsApiException.php';
require_once __DIR__ . '/../pawpayments/vendor/pawpayments/sdk/src/Webhook.php';

$rawBody = file_get_contents('php://input');
if (!$rawBody) {
    http_response_code(400);
    die('Empty body');
}

$headerSig = $_SERVER['HTTP_X_PAW_SIGNATURE'] ?? '';
$apiKey = $gateway['api_key'];

if (!$headerSig || !\PawPayments\Sdk\Webhook::verifyRawBody($rawBody, $headerSig, $apiKey)) {
    http_response_code(401);
    die('Invalid signature');
}

$payload = \PawPayments\Sdk\Webhook::parsePayload($rawBody);

if (!empty($payload['permanent_address_id'])) {
    http_response_code(200);
    exit;
}

$invoiceId = $payload['extra'] ?? '';
$orderId = $payload['order_id'] ?? '';
$status = $payload['status'] ?? '';
// `fiat_amount` is what the deposit was worth when credited (live rate, net of
// commission); on a settled invoice it can land a fraction under the amount the
// invoice was issued for, and booking that leaves the invoice Unpaid over cents.
// Only used on success/paid_over below, so the larger of the two is correct.
$fiatAmount = max(
    (float) ($payload['fiat_amount'] ?? 0),
    (float) ($payload['initial_fiat_amount'] ?? 0)
);

if (!$invoiceId || !$orderId) {
    http_response_code(400);
    die('Missing extra or order_id');
}

checkCbInvoiceID($invoiceId, $gatewayModule);

switch ($status) {
    case 'success':
    case 'paid_over':
        addInvoicePayment(
            $invoiceId,
            $orderId,
            (float) $fiatAmount,
            0,
            $gatewayModule
        );
        logTransaction($gatewayModule, $payload, 'Successful');
        break;

    case 'partially_paid':
        logTransaction($gatewayModule, $payload, 'Pending');
        break;

    case 'cancelled':
    case 'failed':
        logTransaction($gatewayModule, $payload, 'Unsuccessful');
        break;

    default:
        logTransaction($gatewayModule, $payload, 'Unknown status: ' . $status);
        break;
}

http_response_code(200);
echo 'OK';
