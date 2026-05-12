<?php

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');

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

$status = $payload['status'] ?? '';
if ($status !== 'success' && $status !== 'paid_over') {
    http_response_code(200);
    echo 'OK';
    exit;
}

$clientId = (int) ($payload['extra'] ?? 0);
$orderId = $payload['order_id'] ?? '';
$fiatAmount = (float) ($payload['fiat_amount'] ?? $payload['amount'] ?? 0);
$fiatCurrency = $payload['fiat_currency'] ?? 'USD';

if (!$clientId || !$orderId) {
    http_response_code(400);
    die('Missing client ID or order_id');
}

try {
    \Illuminate\Database\Capsule\Manager::table('mod_pawpayments_credits')->insert([
        'order_id' => $orderId,
        'client_id' => $clientId,
        'amount' => $fiatAmount,
        'currency' => $fiatCurrency,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
} catch (\Illuminate\Database\QueryException $e) {
    if (strpos($e->getMessage(), '1062') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
        http_response_code(200);
        echo 'Already processed';
        exit;
    }
    throw $e;
}

$result = localAPI('AddCredit', [
    'clientid' => $clientId,
    'amount' => $fiatAmount,
    'description' => 'Crypto deposit ' . $orderId,
]);

logTransaction($gatewayModule, array_merge($payload, ['addcredit_result' => $result]), 'Topup ' . ($result['result'] === 'success' ? 'Successful' : 'Failed'));

http_response_code(200);
echo 'OK';
