<?php

if (!defined("WHMCS")) die("This file cannot be accessed directly");

require_once __DIR__ . '/pawpayments/vendor/pawpayments/sdk/src/Exception/PawPaymentsApiException.php';
require_once __DIR__ . '/pawpayments/vendor/pawpayments/sdk/src/Version.php';
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

if (!defined('PAWPAYMENTS_CACHE_TABLE')) {
    define('PAWPAYMENTS_CACHE_TABLE', 'mod_pawpayments_invoice_cache');
}

function pawpayments_link($params)
{
    $apiKey = $params['api_key'];
    $baseUrl = $params['api_base_url'] ?: 'https://api.pawpayments.com';
    $ttl = (int) ($params['default_ttl'] ?: 3600);

    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $currency = $params['currency'];

    // Older releases cached the payment URL in tblinvoices.notes, which WHMCS
    // renders on the client-facing invoice. Clean it up as invoices are viewed.
    _pawpayments_strip_legacy_notes($invoiceId);

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

/**
 * Lazily create the payment-URL cache table. Gateway modules have no activation
 * hook in WHMCS, so the table is provisioned on first use.
 */
function _pawpayments_cache_table_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $schema = \Illuminate\Database\Capsule\Manager::schema();
        if (!$schema->hasTable(PAWPAYMENTS_CACHE_TABLE)) {
            $schema->create(PAWPAYMENTS_CACHE_TABLE, function ($table) {
                $table->integer('invoice_id')->unsigned()->primary();
                $table->text('payment_url');
                $table->integer('created_at')->unsigned();
            });
        }
        $ready = true;
    } catch (\Exception $e) {
        $ready = false;
    }

    return $ready;
}

function _pawpayments_get_cached_url(int $invoiceId, int $ttl): ?string
{
    if (!_pawpayments_cache_table_ready()) {
        return null;
    }

    try {
        $row = \Illuminate\Database\Capsule\Manager::table(PAWPAYMENTS_CACHE_TABLE)
            ->where('invoice_id', $invoiceId)
            ->first();

        if ($row && $row->payment_url && (time() - (int) $row->created_at) < $ttl) {
            return $row->payment_url;
        }
    } catch (\Exception $e) {
    }

    return null;
}

function _pawpayments_cache_url(int $invoiceId, string $url): void
{
    if (!_pawpayments_cache_table_ready()) {
        return;
    }

    try {
        $row = ['payment_url' => $url, 'created_at' => time()];
        $query = \Illuminate\Database\Capsule\Manager::table(PAWPAYMENTS_CACHE_TABLE)
            ->where('invoice_id', $invoiceId);

        if ($query->exists()) {
            $query->update($row);
        } else {
            \Illuminate\Database\Capsule\Manager::table(PAWPAYMENTS_CACHE_TABLE)
                ->insert($row + ['invoice_id' => $invoiceId]);
        }
    } catch (\Exception $e) {
    }
}

/**
 * Remove the payment-URL blob older releases wrote into the client-visible
 * invoice notes. Only touches notes that are a JSON object carrying our keys,
 * so hand-written notes are left alone.
 */
function _pawpayments_strip_legacy_notes(int $invoiceId): void
{
    try {
        $notes = \Illuminate\Database\Capsule\Manager::table('tblinvoices')
            ->where('id', $invoiceId)
            ->value('notes');

        if (!$notes) {
            return;
        }

        $decoded = json_decode($notes, true);
        if (!is_array($decoded)) {
            return;
        }
        if (!array_key_exists('pawpayments_url', $decoded)
            && !array_key_exists('pawpayments_ts', $decoded)
        ) {
            return;
        }

        unset($decoded['pawpayments_url'], $decoded['pawpayments_ts']);

        \Illuminate\Database\Capsule\Manager::table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(['notes' => $decoded ? json_encode($decoded) : '']);
    } catch (\Exception $e) {
    }
}
