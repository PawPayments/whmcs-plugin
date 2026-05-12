<?php

if (!defined("WHMCS")) die("This file cannot be accessed directly");

const PAWPAYMENTS_SUPPORTED_FIATS = [
    'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'NZD', 'SGD', 'HKD',
    'NGN', 'KRW', 'ILS', 'RON', 'ARS', 'INR', 'IDR', 'MXN', 'MYR', 'TRY',
    'PLN', 'BRL', 'THB',
];

function pawpayments_topup_config()
{
    return [
        'name' => 'PawPayments Crypto Deposit',
        'description' => 'Allows clients to add funds via cryptocurrency through PawPayments',
        'version' => '2.0.0',
        'author' => 'PawPayments',
        'fields' => [
            'api_key' => [
                'FriendlyName' => 'API Key',
                'Type' => 'text',
                'Size' => '64',
                'Description' => 'PawPayments API key (leave empty to use gateway settings)',
            ],
            'api_base_url' => [
                'FriendlyName' => 'API Base URL',
                'Type' => 'text',
                'Size' => '64',
                'Default' => 'https://api.pawpayments.com',
            ],
        ],
    ];
}

function pawpayments_topup_activate()
{
    try {
        \Illuminate\Database\Capsule\Manager::schema()->create('mod_pawpayments_credits', function ($table) {
            $table->string('order_id', 128)->primary();
            $table->integer('client_id')->unsigned();
            $table->decimal('amount', 16, 2);
            $table->string('currency', 8)->default('USD');
            $table->timestamp('created_at')->useCurrent();
            $table->index('client_id');
        });
    } catch (\Exception $e) {
    }
    return ['status' => 'success', 'description' => 'PawPayments Topup module activated'];
}

function pawpayments_topup_deactivate()
{
    return ['status' => 'success', 'description' => 'PawPayments Topup module deactivated'];
}

function pawpayments_topup_clientarea($vars)
{
    require_once __DIR__ . '/../../gateways/pawpayments/vendor/pawpayments/sdk/src/Exception/PawPaymentsApiException.php';
    require_once __DIR__ . '/../../gateways/pawpayments/vendor/pawpayments/sdk/src/PawPaymentsClient.php';

    $moduleParams = $vars;
    $apiKey = $moduleParams['api_key'] ?? '';
    $baseUrl = $moduleParams['api_base_url'] ?? 'https://api.pawpayments.com';

    if (!$apiKey) {
        $gateway = getGatewayVariables('pawpayments');
        $apiKey = $gateway['api_key'] ?? '';
        $baseUrl = $gateway['api_base_url'] ?: $baseUrl;
    }

    $clientId = (int) $_SESSION['uid'];
    if (!$clientId) {
        return ['pagetitle' => 'Crypto Deposit', 'breadcrumb' => ['index.php?m=pawpayments_topup' => 'Crypto Deposit'], 'vars' => ['error' => 'Not authenticated']];
    }

    if (isset($_GET['deposited'])) {
        return [
            'pagetitle' => 'Crypto Deposit',
            'breadcrumb' => ['index.php?m=pawpayments_topup' => 'Crypto Deposit'],
            'templatefile' => 'templates/topup',
            'vars' => ['success' => true],
        ];
    }

    if (isset($_GET['cancelled'])) {
        return [
            'pagetitle' => 'Crypto Deposit',
            'breadcrumb' => ['index.php?m=pawpayments_topup' => 'Crypto Deposit'],
            'templatefile' => 'templates/topup',
            'vars' => ['cancelled' => true],
        ];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['amount'])) {
        $amount = (float) $_POST['amount'];
        $postedCurrency = strtoupper($_POST['currency'] ?? '');
        $currency = in_array($postedCurrency, PAWPAYMENTS_SUPPORTED_FIATS, true) ? $postedCurrency : 'USD';

        if ($amount < 1 || $amount > 100000) {
            return [
                'pagetitle' => 'Crypto Deposit',
                'breadcrumb' => ['index.php?m=pawpayments_topup' => 'Crypto Deposit'],
                'templatefile' => 'templates/topup',
                'vars' => ['error' => 'Amount must be between 1 and 100,000', 'amount' => $amount, 'currency' => $currency, 'supported_fiats' => PAWPAYMENTS_SUPPORTED_FIATS],
            ];
        }

        $systemUrl = \App::getSystemURL();
        $callbackUrl = $systemUrl . 'modules/gateways/callback/pawpayments_topup.php';

        $client = new \PawPayments\Sdk\PawPaymentsClient($apiKey, $baseUrl);

        try {
            $data = $client->createInvoice([
                'extra' => (string) $clientId,
                'amount' => $amount,
                'fiat_currency' => $currency,
                'billing_type' => 'VARY',
                'metadata' => [
                    'source' => 'whmcs',
                    'flow' => 'topup',
                    'client_id' => (string) $clientId,
                ],
                'on_paid_url' => $systemUrl . 'index.php?m=pawpayments_topup&deposited=1',
                'on_cancel_url' => $systemUrl . 'index.php?m=pawpayments_topup&cancelled=1',
                'notify_url' => $callbackUrl,
            ]);

            $paymentUrl = $data['payment_url'] ?? '';
            if ($paymentUrl) {
                header('Location: ' . $paymentUrl);
                exit;
            }
        } catch (\PawPayments\Sdk\Exception\PawPaymentsApiException $e) {
            return [
                'pagetitle' => 'Crypto Deposit',
                'breadcrumb' => ['index.php?m=pawpayments_topup' => 'Crypto Deposit'],
                'templatefile' => 'templates/topup',
                'vars' => ['error' => 'Payment error: ' . $e->getMessage(), 'amount' => $amount, 'currency' => $currency, 'supported_fiats' => PAWPAYMENTS_SUPPORTED_FIATS],
            ];
        }
    }

    return [
        'pagetitle' => 'Crypto Deposit',
        'breadcrumb' => ['index.php?m=pawpayments_topup' => 'Crypto Deposit'],
        'templatefile' => 'templates/topup',
        'vars' => ['amount' => '', 'currency' => 'USD', 'supported_fiats' => PAWPAYMENTS_SUPPORTED_FIATS],
    ];
}
