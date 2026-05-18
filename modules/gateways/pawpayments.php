<?php

if (!defined("WHMCS")) die("This file cannot be accessed directly");

require_once __DIR__ . '/pawpayments/vendor/pawpayments/sdk/src/Exception/PawPaymentsApiException.php';
require_once __DIR__ . '/pawpayments/vendor/pawpayments/sdk/src/PawPaymentsClient.php';
require_once __DIR__ . '/pawpayments/vendor/pawpayments/sdk/src/Webhook.php';

function pawpayments_MetaData()
{
    return [
        'DisplayName' => 'PawPayments (Crypto)',
        'APIVersion' => '2.0',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function pawpayments_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'PawPayments (Crypto)',
        ],
        'api_key' => [
            'FriendlyName' => 'API Key',
            'Type' => 'text',
            'Size' => '64',
            'Description' => 'Your PawPayments API key from the merchant dashboard',
        ],
        'api_base_url' => [
            'FriendlyName' => 'API Base URL',
            'Type' => 'text',
            'Size' => '64',
            'Default' => 'https://api.pawpayments.com',
            'Description' => 'PawPayments API base URL (default: https://api.pawpayments.com)',
        ],
        'default_ttl' => [
            'FriendlyName' => 'Invoice TTL (seconds)',
            'Type' => 'text',
            'Size' => '10',
            'Default' => '3600',
            'Description' => 'Time-to-live for payment invoices in seconds',
        ],
        'webhook_urls' => [
            'FriendlyName' => 'Webhook URLs (info)',
            'Type' => 'System',
            'Value' => 'Checkout: /modules/gateways/callback/pawpayments.php | Topup: /modules/gateways/callback/pawpayments_topup.php',
        ],
    ];
}

function pawpayments_link($params)
{
    $apiKey = $params['api_key'];
    $baseUrl = $params['api_base_url'] ?: 'https://api.pawpayments.com';
    $ttl = (int) ($params['default_ttl'] ?: 3600);

    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $currency = $params['currency'];

    $cachedUrl = _pawpayments_get_cached_url($invoiceId, $ttl);
    if ($cachedUrl) {
        return '<a href="' . htmlspecialchars($cachedUrl) . '" class="btn btn-primary">Pay with Crypto</a>';
    }

    $callbackUrl = $params['systemurl'] . 'modules/gateways/callback/pawpayments.php';

    $client = new \PawPayments\Sdk\PawPaymentsClient($apiKey, $baseUrl);

    try {
        $data = $client->createInvoice([
            'extra' => (string) $invoiceId,
            'amount' => (float) $amount,
            'fiat_currency' => $currency,
            'billing_type' => 'VARY',
            'ttl' => $ttl,
            'on_paid_url' => $params['returnurl'],
            'on_cancel_url' => $params['returnurl'],
            'notify_url' => $callbackUrl,
            'metadata' => [
                'source' => 'whmcs',
                'flow' => 'checkout',
                'client_id' => (string) $params['clientdetails']['userid'],
            ],
        ]);
    } catch (\PawPayments\Sdk\Exception\PawPaymentsApiException $e) {
        logTransaction('pawpayments', ['error' => $e->getMessage()], 'Error');
        return '<div class="alert alert-danger">Failed to create payment: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }

    $paymentUrl = $data['payment_url'] ?? '';
    if (!$paymentUrl) {
        return '<div class="alert alert-danger">No payment URL returned</div>';
    }

    _pawpayments_cache_url($invoiceId, $paymentUrl);

    return '<a href="' . htmlspecialchars($paymentUrl) . '" class="btn btn-primary">Pay with Crypto</a>';
}

function _pawpayments_get_cached_url(int $invoiceId, int $ttl): ?string
{
    try {
        $invoice = \WHMCS\Billing\Invoice::find($invoiceId);
        if ($invoice && $invoice->notes) {
            $cached = json_decode($invoice->notes, true);
            if (is_array($cached)
                && !empty($cached['pawpayments_url'])
                && !empty($cached['pawpayments_ts'])
                && (time() - $cached['pawpayments_ts']) < $ttl
            ) {
                return $cached['pawpayments_url'];
            }
        }
    } catch (\Exception $e) {
    }
    return null;
}

function _pawpayments_cache_url(int $invoiceId, string $url): void
{
    try {
        $invoice = \WHMCS\Billing\Invoice::find($invoiceId);
        if ($invoice) {
            $existing = json_decode($invoice->notes, true) ?: [];
            $existing['pawpayments_url'] = $url;
            $existing['pawpayments_ts'] = time();
            $invoice->notes = json_encode($existing);
            $invoice->save();
        }
    } catch (\Exception $e) {
    }
}
