<?php

if (!defined("WHMCS")) die("This file cannot be accessed directly");

require_once __DIR__ . '/../../gateways/pawpayments/pawpayments_common.php';

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
        'version' => '2.1.1',
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

/**
 * API key and base URL deposit invoices are created with, and their webhooks
 * verified with: the addon's own settings, else the gateway's. Shared with the
 * top-up callback, so both always use the same key.
 *
 * @param array|null $settings The addon settings (null: read them from WHMCS)
 */
function pawpayments_topup_credentials(?array $settings = null): array
{
    if ($settings === null) {
        $settings = \Illuminate\Database\Capsule\Manager::table('tbladdonmodules')
            ->where('module', 'pawpayments_topup')
            ->pluck('value', 'setting')
            ->all();
    }

    $apiKey = (string) ($settings['api_key'] ?? '');
    $baseUrl = (string) ($settings['api_base_url'] ?? '') ?: 'https://api.pawpayments.com';

    if ($apiKey === '') {
        // WHMCS loads the gateway functions for callbacks, not for addon pages.
        if (!function_exists('getGatewayVariables')) {
            \App::load_function('gateway');
        }
        $gateway = getGatewayVariables('pawpayments');
        $apiKey = (string) ($gateway['api_key'] ?? '');
        $baseUrl = ($gateway['api_base_url'] ?? '') ?: $baseUrl;
    }

    return ['api_key' => $apiKey, 'api_base_url' => $baseUrl];
}

/** The deposit page with $vars. */
function pawpayments_topup_page(array $vars): array
{
    return [
        'pagetitle' => 'Crypto Deposit',
        'breadcrumb' => ['index.php?m=pawpayments_topup' => 'Crypto Deposit'],
        'templatefile' => 'templates/topup',
        'vars' => $vars,
    ];
}

function pawpayments_topup_clientarea($vars)
{
    require_once __DIR__ . '/../../gateways/pawpayments/vendor/pawpayments/sdk/src/Exception/PawPaymentsApiException.php';
    require_once __DIR__ . '/../../gateways/pawpayments/vendor/pawpayments/sdk/src/Version.php';
    require_once __DIR__ . '/../../gateways/pawpayments/vendor/pawpayments/sdk/src/PawPaymentsClient.php';

    $credentials = pawpayments_topup_credentials($vars);

    $clientId = (int) $_SESSION['uid'];
    if (!$clientId) {
        return pawpayments_topup_page(['error' => 'Not authenticated']);
    }

    if (isset($_GET['deposited'])) {
        return pawpayments_topup_page(['success' => true]);
    }

    if (isset($_GET['cancelled'])) {
        return pawpayments_topup_page(['cancelled' => true]);
    }

    $currency = pawpayments_client_currency($clientId);
    if (!in_array($currency, PAWPAYMENTS_SUPPORTED_FIATS, true)) {
        return pawpayments_topup_page([
            'unsupported' => true,
            'error' => 'Crypto deposits are not available for your account currency'
                . ($currency !== '' ? ' (' . $currency . ')' : '') . '.',
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['amount'])) {
        $amount = (float) $_POST['amount'];

        if ($amount < 1 || $amount > 100000) {
            return pawpayments_topup_page([
                'error' => 'Amount must be between 1 and 100,000', 'amount' => $amount, 'currency' => $currency,
            ]);
        }

        $systemUrl = \App::getSystemURL();
        $callbackUrl = $systemUrl . 'modules/gateways/callback/pawpayments_topup.php';

        $client = new \PawPayments\Sdk\PawPaymentsClient($credentials['api_key'], $credentials['api_base_url']);

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
            return pawpayments_topup_page([
                'error' => 'Payment error: ' . $e->getMessage(), 'amount' => $amount, 'currency' => $currency,
            ]);
        }
    }

    return pawpayments_topup_page(['amount' => '', 'currency' => $currency]);
}
