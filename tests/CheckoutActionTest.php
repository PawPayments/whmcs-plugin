<?php

use PHPUnit\Framework\TestCase;

/**
 * pawpayments_checkout_action(): what the checkout callback books on invoice
 * #42. Unless a test says otherwise the client pays in USD, 100.00 is due, and
 * PawPayments invoice po1 was issued for that by this plugin.
 */
class CheckoutActionTest extends TestCase
{
    private const ISSUED = ['invoice_id' => 42, 'amount' => 100.0, 'currency' => 'USD', 'client_amount' => 100.0];

    /** A webhook for PawPayments invoice po1. */
    private function paid(array $over = []): array
    {
        return $over + [
            'status' => 'success',
            'order_id' => 'po1',
            'fiat_amount' => 100.0,
            'initial_fiat_amount' => 100.0,
            'fiat_currency' => 'USD',
        ];
    }

    /**
     * @param array|null|false $issued false: the default record; null: none
     */
    private function act(array $payload, $issued = false, $balance = '100.00', string $currency = 'USD', float $booked = 0.0): array
    {
        return pawpayments_checkout_action(
            $payload,
            42,
            $issued === false ? self::ISSUED : $issued,
            $balance,
            $currency,
            $booked
        );
    }

    // ─── success / paid_over, invoice issued by this plugin ─────────────────

    public function testSuccessIsBooked(): void
    {
        $action = $this->act($this->paid());

        $this->assertSame(100.0, $action['book']);
        $this->assertSame(200, $action['http']);
        $this->assertSame('Successful', $action['log']);
    }

    public function testSuccessABitUnderBooksTheIssuedAmount(): void
    {
        // Credited at the live rate, net of commission: a hair under what the
        // invoice was issued for. Booking 99.2 would leave it Unpaid over cents.
        $this->assertSame(100.0, $this->act($this->paid(['fiat_amount' => 99.2]))['book']);
    }

    public function testSuccessWithinTheMerchantsOwnToleranceBooksTheInvoice(): void
    {
        // The merchant accepts 20% short (dashboard setting, not in the webhook).
        $this->assertSame(100.0, $this->act($this->paid(['fiat_amount' => 80.0]))['book']);
    }

    public function testPaidOverBooksWhatWasReceived(): void
    {
        $this->assertSame(130.0, $this->act($this->paid(['status' => 'paid_over', 'fiat_amount' => 130.0]))['book']);
    }

    public function testInvoiceIssuedForTheBalanceIsBookedForIt(): void
    {
        // 100.00 invoice, 40.00 paid another way: the link asked for the 60.00
        // still due, which is what the webhook must be checked against.
        $issued = ['amount' => 60.0, 'client_amount' => 60.0] + self::ISSUED;
        $action = $this->act($this->paid(['fiat_amount' => 60.0, 'initial_fiat_amount' => 60.0]), $issued, '60.00');

        $this->assertSame(60.0, $action['book']);
    }

    public function testConvertedForProcessingIsBookedBackInTheClientsCurrency(): void
    {
        // Client in EUR, gateway set to "Convert To For Processing" USD: 100.00
        // EUR due became a 108.00 USD invoice; the payment books 100.00 EUR.
        $issued = ['amount' => 108.0, 'currency' => 'USD', 'client_amount' => 100.0] + self::ISSUED;
        $payload = $this->paid(['fiat_amount' => 108.0, 'initial_fiat_amount' => 108.0]);

        $this->assertSame(100.0, $this->act($payload, $issued, '100.00', 'EUR')['book']);
        // A hair short within the tolerance still settles it at the issued rate.
        $this->assertSame(100.0, $this->act(['fiat_amount' => 107.5] + $payload, $issued, '100.00', 'EUR')['book']);
    }

    public function testConvertedInvoicePaidInTheClientsCurrencyIsRefused(): void
    {
        $issued = ['amount' => 108.0, 'currency' => 'USD', 'client_amount' => 100.0] + self::ISSUED;
        $action = $this->act($this->paid(['fiat_currency' => 'EUR']), $issued, '100.00', 'EUR');

        $this->assertNull($action['book']);
        $this->assertSame(409, $action['http']);
    }

    public function testPawPaymentsInvoiceIssuedForAnotherWhmcsInvoiceIsRefused(): void
    {
        $action = $this->act($this->paid(), ['invoice_id' => 7] + self::ISSUED);

        $this->assertNull($action['book']);
        $this->assertSame(409, $action['http']);
        $this->assertStringContainsString('issued for invoice #7', $action['log']);
    }

    /** @dataProvider notCovering */
    public function testPaymentThatDoesNotCoverTheIssuedInvoiceIsRefused(array $payload): void
    {
        $action = $this->act($payload);

        $this->assertNull($action['book']);
        $this->assertSame(409, $action['http']);
        $this->assertStringStartsWith('Not applied:', $action['log']);
    }

    public function notCovering(): array
    {
        $paid = $this->paid();

        return [
            'another currency' => [['fiat_currency' => 'EUR'] + $paid],
            'issued two cents short' => [['initial_fiat_amount' => 99.98] + $paid],
            'no fiat_currency' => [array_diff_key($paid, ['fiat_currency' => 0])],
            'no initial_fiat_amount' => [array_diff_key($paid, ['initial_fiat_amount' => 0])],
        ];
    }

    // ─── cumulative bookings ────────────────────────────────────────────────

    public function testRepeatedDeliveryBooksNothing(): void
    {
        $action = $this->act($this->paid(), false, '0.00', 'USD', 100.0);

        $this->assertNull($action['book']);
        $this->assertSame(200, $action['http']);
        $this->assertStringStartsWith('Already recorded', $action['log']);
    }

    public function testTopUpAfterAPartialKeptOnExpiryBooksTheRest(): void
    {
        // 40.00 was booked when the invoice expired part-paid; support then
        // applied the missing 60.00 to it and the gateway reports `success`
        // for the whole 100.00. Only the 60.00 is new.
        $action = $this->act($this->paid(), false, '60.00', 'USD', 40.0);

        $this->assertSame(60.0, $action['book']);
        $this->assertStringContainsString('60 added to the 40 already booked', $action['log']);
    }

    public function testTransactionIdsOfLaterBookingsAreNumbered(): void
    {
        $this->assertSame('po1', pawpayments_transaction_id('po1', 0));
        $this->assertSame('po1-2', pawpayments_transaction_id('po1', 1));
        $this->assertSame('po1-3', pawpayments_transaction_id('po1', 2));
    }

    // ─── invoices created before records were kept ──────────────────────────

    public function testUnrecordedInvoiceInTheClientsCurrencyIsBookedAgainstWhatIsDue(): void
    {
        $this->assertSame(100.0, $this->act($this->paid(), null)['book']);
        // 40.00 paid another way before the link was made for the 60.00 due.
        $this->assertSame(60.0, $this->act($this->paid(['fiat_amount' => 60.0, 'initial_fiat_amount' => 60.0]), null, '60.00')['book']);
    }

    public function testUnrecordedInvoiceToppedUpAfterItsOwnPartialBooksTheRest(): void
    {
        // Due 60.00 now because this same PawPayments invoice already paid 40.00.
        $this->assertSame(60.0, $this->act($this->paid(), null, '60.00', 'USD', 40.0)['book']);
    }

    /** @dataProvider unrecordedRefusals */
    public function testUnrecordedInvoiceIsRefused(array $payload, $balance, string $currency): void
    {
        $action = $this->act($payload, null, $balance, $currency);

        $this->assertNull($action['book']);
        $this->assertSame(409, $action['http']);
    }

    public function unrecordedRefusals(): array
    {
        $paid = $this->paid();

        return [
            // A genuine webhook for a 1.00 invoice whose `extra` names this one.
            'a cheaper invoice' => [['fiat_amount' => 1.0, 'initial_fiat_amount' => 1.0] + $paid, '60.00', 'USD'],
            'another currency' => [['fiat_currency' => 'EUR'] + $paid, '100.00', 'USD'],
            'client currency unknown' => [$paid, '100.00', ''],
            'balance unknown' => [$paid, null, 'USD'],
        ];
    }

    // ─── cancelled (expired after a partial payment) ────────────────────────

    public function testExpiredAfterAPartialBooksThePartial(): void
    {
        $action = $this->act($this->paid(['status' => 'cancelled', 'fiat_amount' => 40.0]));

        $this->assertSame(40.0, $action['book']);
        $this->assertSame('Expired after a partial payment, partial applied', $action['log']);
    }

    public function testExpiredPartialOfAConvertedInvoiceIsBookedBackAtTheIssuedRate(): void
    {
        $issued = ['amount' => 108.0, 'currency' => 'USD', 'client_amount' => 100.0] + self::ISSUED;
        $action = $this->act($this->paid(['status' => 'cancelled', 'fiat_amount' => 43.2]), $issued, '100.00', 'EUR');

        $this->assertSame(40.0, $action['book']);
    }

    public function testExpiredPartialRedeliveredBooksNothing(): void
    {
        $action = $this->act($this->paid(['status' => 'cancelled', 'fiat_amount' => 40.0]), false, '60.00', 'USD', 40.0);

        $this->assertNull($action['book']);
        $this->assertSame('Expired after a partial payment, already applied', $action['log']);
    }

    public function testExpiredPartialInAnotherCurrencyIsNotBooked(): void
    {
        $action = $this->act($this->paid(['status' => 'cancelled', 'fiat_amount' => 40.0, 'fiat_currency' => 'EUR']));

        $this->assertNull($action['book']);
        $this->assertStringContainsString('another currency', $action['log']);
    }

    public function testExpiredPartialWithoutAnyCurrencyIsNotBooked(): void
    {
        // Neither side knows the currency: that is not a match.
        $payload = array_diff_key($this->paid(['status' => 'cancelled', 'fiat_amount' => 40.0]), ['fiat_currency' => 0]);

        $this->assertNull($this->act($payload, null, '100.00', '')['book']);
    }

    public function testExpiredWithNothingPaidBooksNothing(): void
    {
        $action = $this->act($this->paid(['status' => 'cancelled', 'fiat_amount' => 0]));

        $this->assertNull($action['book']);
        $this->assertSame('Unsuccessful', $action['log']);
    }

    // ─── statuses that never book ───────────────────────────────────────────

    /** @dataProvider nonBookingStatuses */
    public function testStatusBooksNothing(string $status, string $log): void
    {
        $action = $this->act($this->paid(['status' => $status]));

        $this->assertNull($action['book']);
        $this->assertSame(200, $action['http']);
        $this->assertSame($log, $action['log']);
    }

    public function nonBookingStatuses(): array
    {
        return [
            'partially_paid' => ['partially_paid', 'Pending'],
            'failed' => ['failed', 'Unsuccessful'],
            'high_risk' => ['high_risk', 'Unknown status: high_risk'],
            'expired' => ['expired', 'Unknown status: expired'],
            'empty' => ['', 'Unknown status: '],
        ];
    }

    public function testOnlySettlingStatusesNeedTheInvoiceFacts(): void
    {
        // The callback looks the invoice up only for these; a status outside
        // the list must therefore never book, whatever facts it is given.
        $this->assertSame(['success', 'paid_over', 'cancelled'], PAWPAYMENTS_SETTLING_STATUSES);
        foreach (['partially_paid', 'failed', 'high_risk'] as $status) {
            $this->assertNull($this->act($this->paid(['status' => $status]))['book']);
        }
    }
}
