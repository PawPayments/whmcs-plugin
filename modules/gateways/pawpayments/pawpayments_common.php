<?php
/**
 * Shared by the checkout and top-up callbacks and the top-up addon.
 *
 * The callbacks are thin: the decisions about money live in the pure
 * `pawpayments_*_action()` / `pawpayments_verify_webhook()` functions below,
 * which take every fact they need as an argument and return what to do, so
 * they can be tested without a WHMCS install (see whmcs/tests).
 */

if (!defined("WHMCS")) die("This file cannot be accessed directly");

require_once __DIR__ . '/vendor/pawpayments/sdk/src/Webhook.php';

/** Statuses of a checkout webhook that may book money on the invoice. */
const PAWPAYMENTS_SETTLING_STATUSES = ['success', 'paid_over', 'cancelled'];

/**
 * Checks a webhook request: [$payload, 0, ''] for a signed PawPayments webhook
 * to act on, else [null, $httpCode, $body] to answer with — 503 while $apiKey
 * is empty (an empty HMAC key makes any signature computable), 400/401 for a
 * body or signature that is not a PawPayments webhook, and 200 with no action
 * for permanent-address deposits.
 *
 * @return array{0: ?array, 1: int, 2: string}
 */
function pawpayments_verify_webhook(string $apiKey, string $rawBody, string $signature): array
{
    if ($apiKey === '') {
        return [null, 503, 'Gateway not configured'];
    }
    if ($rawBody === '') {
        return [null, 400, 'Empty body'];
    }
    if (!\PawPayments\Sdk\Webhook::verifyRawBody($rawBody, $signature, $apiKey)) {
        return [null, 401, 'Invalid signature'];
    }

    $payload = \PawPayments\Sdk\Webhook::parsePayload($rawBody);
    if (!empty($payload['permanent_address_id'])) {
        return [null, 200, ''];
    }

    return [$payload, 0, ''];
}

/**
 * The payload of the signed webhook in this request; answers and ends the
 * request when there is nothing to act on (see pawpayments_verify_webhook).
 */
function pawpayments_verified_payload(string $apiKey): array
{
    [$payload, $httpCode, $body] = pawpayments_verify_webhook(
        $apiKey,
        (string) file_get_contents('php://input'),
        (string) ($_SERVER['HTTP_X_PAW_SIGNATURE'] ?? '')
    );
    if ($payload === null) {
        http_response_code($httpCode);
        die($body);
    }

    return $payload;
}

/**
 * What the checkout callback does with a verified webhook for WHMCS invoice
 * $invoiceId (its `extra`).
 *
 * `book` is the amount, in the client's currency, to pass to
 * addInvoicePayment() now, or null to book nothing; `log` is the
 * logTransaction() result; `http`/`body` the response.
 *
 * The reference is the PawPayments invoice this plugin created for the WHMCS
 * invoice ($issued, see pawpayments_record_issued_invoice()): the payment must
 * be in its currency and the invoice issued for its amount — the balance due
 * when the link was made, possibly converted by WHMCS's "Convert To For
 * Processing" — and is booked back at the rate of that moment. Without a
 * record (an invoice created before records were kept) only a payment in the
 * client's currency, issued for at least what is still due, qualifies.
 *
 * Bookings are cumulative per PawPayments invoice: $booked is what is already
 * booked for it, and only the difference is booked, so a repeated delivery
 * books nothing and a later top-up of an invoice that expired after a partial
 * payment books the rest.
 *
 * @param array|null $issued ['invoice_id', 'amount', 'currency', 'client_amount']
 * @param mixed $balance What is due on the invoice now, in the client's currency
 * @param string $clientCurrency ISO code of the client's (the invoice's) currency
 * @param float $booked Already booked on the invoice for this PawPayments invoice
 * @return array{book: ?float, http: int, body: string, log: string}
 */
function pawpayments_checkout_action(
    array $payload,
    int $invoiceId,
    ?array $issued,
    $balance,
    string $clientCurrency,
    float $booked
): array {
    $status = (string) ($payload['status'] ?? '');
    $orderId = (string) ($payload['order_id'] ?? '');
    $noop = ['book' => null, 'http' => 200, 'body' => 'OK'];

    if ($issued !== null && (int) $issued['invoice_id'] !== $invoiceId) {
        return [
            'book' => null,
            'http' => 409,
            'body' => 'Not a payment for this invoice',
            'log' => "Not applied: PawPayments invoice {$orderId} was issued for invoice #{$issued['invoice_id']}",
        ];
    }

    if ($issued !== null) {
        $currency = strtoupper((string) $issued['currency']);
        $issuedFor = (float) $issued['amount'];
        $rate = $issuedFor > 0 ? (float) $issued['client_amount'] / $issuedFor : 1.0;
    } else {
        // No record of what was asked for: it must be at least what is due.
        $currency = $clientCurrency;
        $issuedFor = is_numeric($balance) ? (float) $balance : null;
        $rate = 1.0;
    }

    switch ($status) {
        case 'success':
        case 'paid_over':
            if ($issuedFor === null || !\PawPayments\Sdk\Webhook::coversAmount($payload, $currency, $issuedFor)) {
                return [
                    'book' => null,
                    'http' => 409,
                    'body' => 'Amount or currency does not match the invoice',
                    'log' => sprintf(
                        'Not applied: PawPayments invoice %s was issued for %s %s, expected %s %s',
                        $orderId,
                        $payload['initial_fiat_amount'] ?? '?',
                        $payload['fiat_currency'] ?? '?',
                        $issuedFor ?? '?',
                        $currency
                    ),
                ];
            }
            // `fiat_amount` is what the deposit was worth when credited (live
            // rate, net of commission); on a settled invoice it can land a
            // fraction under the amount the invoice was issued for, and booking
            // that would leave the invoice Unpaid over cents. Also booked on an
            // invoice meanwhile paid another way: WHMCS turns the surplus into
            // client credit, so the crypto the merchant received stays on record.
            $paid = max((float) ($payload['fiat_amount'] ?? 0), (float) ($payload['initial_fiat_amount'] ?? 0));
            $due = round(round($paid * $rate, 2) - $booked, 2);
            if ($due < 0.01) {
                return $noop + ['log' => 'Already recorded (repeated delivery), not applied again'];
            }
            return [
                'book' => $due,
                'http' => 200,
                'body' => 'OK',
                'log' => $booked > 0 ? "Successful: {$due} added to the {$booked} already booked for it" : 'Successful',
            ];

        case 'partially_paid':
            return $noop + ['log' => 'Pending'];

        case 'cancelled':
            // An expired invoice the client paid in part: the merchant keeps the
            // partial, so it is booked as a partial payment of the invoice.
            $partial = (float) ($payload['fiat_amount'] ?? 0);
            if ($partial <= 0) {
                return $noop + ['log' => 'Unsuccessful'];
            }
            $partialCurrency = strtoupper((string) ($payload['fiat_currency'] ?? ''));
            if ($partialCurrency === '' || $partialCurrency !== $currency) {
                return $noop + ['log' => 'Expired after a partial payment in another currency, not applied'];
            }
            $due = round(round($partial * $rate, 2) - $booked, 2);
            if ($due < 0.01) {
                return $noop + ['log' => 'Expired after a partial payment, already applied'];
            }
            return [
                'book' => $due,
                'http' => 200,
                'body' => 'OK',
                'log' => 'Expired after a partial payment, partial applied',
            ];

        case 'failed':
            return $noop + ['log' => 'Unsuccessful'];
    }

    return $noop + ['log' => 'Unknown status: ' . $status];
}

/**
 * The transaction id of the next booking for PawPayments invoice $orderId:
 * its own id, then `<id>-2`, `<id>-3`… for later top-ups of the same invoice.
 */
function pawpayments_transaction_id(string $orderId, int $bookedCount): string
{
    return $bookedCount === 0 ? $orderId : $orderId . '-' . ($bookedCount + 1);
}

/**
 * What the top-up callback does with a verified webhook.
 *
 * `credit` is the amount to AddCredit() to `client_id` (in `currency`), or
 * null to credit nothing; `log` is the logTransaction() result, or null to log
 * nothing. Permanent refusals are answered 200 so the gateway stops retrying
 * them. $clientCurrency is called with the client id (returning its ISO
 * currency code) only once the webhook has passed the field checks.
 *
 * @return array{credit: ?float, currency: ?string, client_id: int, http: int, body: string, log: ?string}
 */
function pawpayments_topup_action(array $payload, callable $clientCurrency): array
{
    $clientId = (int) ($payload['extra'] ?? 0);
    $none = ['credit' => null, 'currency' => null, 'client_id' => $clientId];

    $status = (string) ($payload['status'] ?? '');
    if ($status !== 'success' && $status !== 'paid_over') {
        return $none + ['http' => 200, 'body' => 'OK', 'log' => null];
    }
    if (!$clientId || (string) ($payload['order_id'] ?? '') === '') {
        return $none + ['http' => 400, 'body' => 'Missing client ID or order_id', 'log' => null];
    }

    // Only `fiat_amount` is a fiat value; `amount` is the crypto amount.
    $fiatAmount = (float) ($payload['fiat_amount'] ?? 0);
    if ($fiatAmount <= 0) {
        return $none + ['http' => 200, 'body' => 'Rejected', 'log' => 'Topup Rejected: missing fiat_amount'];
    }

    // AddCredit books the bare number in the client's currency.
    $fiatCurrency = strtoupper((string) ($payload['fiat_currency'] ?? ''));
    $currency = (string) $clientCurrency($clientId);
    if ($currency === '' || $fiatCurrency !== $currency) {
        return $none + [
            'http' => 200,
            'body' => 'Rejected',
            'log' => "Topup Rejected: fiat_currency '{$fiatCurrency}' does not match client currency '{$currency}'",
        ];
    }

    return [
        'credit' => $fiatAmount,
        'currency' => $fiatCurrency,
        'client_id' => $clientId,
        'http' => 200,
        'body' => 'OK',
        'log' => null,
    ];
}

/**
 * ISO code of the client's WHMCS currency: invoices are issued in it and
 * AddCredit books a bare number in it.
 */
function pawpayments_client_currency(int $clientId): string
{
    $details = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
    return strtoupper((string) ($details['client']['currency_code'] ?? ''));
}

/**
 * What is booked on $invoiceId for PawPayments invoice $orderId so far:
 * ['amount' => sum, 'count' => bookings] over the transaction ids
 * pawpayments_transaction_id() hands out.
 */
function pawpayments_booked(int $invoiceId, string $orderId): array
{
    $rows = \Illuminate\Database\Capsule\Manager::table('tblaccounts')
        ->where('invoiceid', $invoiceId)
        ->where(function ($query) use ($orderId) {
            $query->where('transid', $orderId)->orWhere('transid', 'like', $orderId . '-%');
        })
        ->pluck('amountin');

    $amount = 0.0;
    foreach ($rows as $amountIn) {
        $amount += (float) $amountIn;
    }

    return ['amount' => round($amount, 2), 'count' => count($rows)];
}

/**
 * Serialise the bookings of one PawPayments invoice across concurrent webhook
 * deliveries (a MySQL named lock, released by pawpayments_unlock() or when
 * the request's connection closes). False if it could not be had in 10 s.
 */
function pawpayments_lock(string $orderId): bool
{
    try {
        $row = \Illuminate\Database\Capsule\Manager::selectOne(
            'SELECT GET_LOCK(?, 10) AS acquired',
            ['pawpayments:' . $orderId]
        );
    } catch (\Exception $e) {
        return false;
    }

    return $row !== null && (int) $row->acquired === 1;
}

function pawpayments_unlock(string $orderId): void
{
    try {
        \Illuminate\Database\Capsule\Manager::selectOne('SELECT RELEASE_LOCK(?)', ['pawpayments:' . $orderId]);
    } catch (\Exception $e) {
    }
}

if (!defined('PAWPAYMENTS_ISSUED_TABLE')) {
    define('PAWPAYMENTS_ISSUED_TABLE', 'mod_pawpayments_invoices');
}

/**
 * Lazily create the table of PawPayments invoices issued for WHMCS invoices
 * (gateway modules have no activation hook).
 */
function pawpayments_issued_table_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $schema = \Illuminate\Database\Capsule\Manager::schema();
        if (!$schema->hasTable(PAWPAYMENTS_ISSUED_TABLE)) {
            $schema->create(PAWPAYMENTS_ISSUED_TABLE, function ($table) {
                $table->string('order_id', 64)->primary();
                $table->integer('invoice_id')->unsigned()->index();
                $table->decimal('amount', 16, 2);
                $table->string('currency', 8);
                $table->decimal('client_amount', 16, 2);
                $table->integer('created_at')->unsigned();
            });
        }
        $ready = true;
    } catch (\Exception $e) {
        $ready = false;
    }

    return $ready;
}

/**
 * Remember the PawPayments invoice made for WHMCS invoice $invoiceId: the
 * $amount and $currency sent (after any "Convert To For Processing") and what
 * was due then in the client's currency, $clientAmount.
 */
function pawpayments_record_issued_invoice(
    string $orderId,
    int $invoiceId,
    float $amount,
    string $currency,
    float $clientAmount
): void {
    if ($orderId === '' || !pawpayments_issued_table_ready()) {
        return;
    }

    try {
        \Illuminate\Database\Capsule\Manager::table(PAWPAYMENTS_ISSUED_TABLE)->insert([
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'client_amount' => $clientAmount,
            'created_at' => time(),
        ]);
    } catch (\Exception $e) {
    }
}

/** The record pawpayments_record_issued_invoice() kept for $orderId, if any. */
function pawpayments_issued_invoice(string $orderId): ?array
{
    if ($orderId === '' || !pawpayments_issued_table_ready()) {
        return null;
    }

    try {
        $row = \Illuminate\Database\Capsule\Manager::table(PAWPAYMENTS_ISSUED_TABLE)
            ->where('order_id', $orderId)
            ->first();
    } catch (\Exception $e) {
        return null;
    }

    return $row ? (array) $row : null;
}
