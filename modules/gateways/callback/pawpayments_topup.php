<?php

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');

require_once __DIR__ . '/../pawpayments/pawpayments_common.php';
require_once __DIR__ . '/../../addons/pawpayments_topup/pawpayments_topup.php';

$gatewayModule = 'pawpayments';

// The key the deposit invoice was created with: the addon's own, else the
// gateway's (pawpayments_topup_credentials, shared with the addon page).
$payload = pawpayments_verified_payload(pawpayments_topup_credentials()['api_key']);

$action = pawpayments_topup_action($payload, 'pawpayments_client_currency');

if ($action['credit'] !== null) {
    $orderId = (string) $payload['order_id'];

    // order_id is the primary key: a repeated delivery stops here, before
    // AddCredit, so a deposit is credited once.
    try {
        \Illuminate\Database\Capsule\Manager::table('mod_pawpayments_credits')->insert([
            'order_id' => $orderId,
            'client_id' => $action['client_id'],
            'amount' => $action['credit'],
            'currency' => $action['currency'],
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
        'clientid' => $action['client_id'],
        'amount' => $action['credit'],
        'description' => 'Crypto deposit ' . $orderId,
    ]);

    logTransaction(
        $gatewayModule,
        array_merge($payload, ['addcredit_result' => $result]),
        'Topup ' . (($result['result'] ?? '') === 'success' ? 'Successful' : 'Failed')
    );
} elseif ($action['log'] !== null) {
    logTransaction($gatewayModule, $payload, $action['log']);
}

http_response_code($action['http']);
echo $action['body'];
