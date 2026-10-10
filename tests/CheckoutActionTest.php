<?php

use PHPUnit\Framework\TestCase;

/**
 * pawpayments_checkout_action(): what the checkout callback books on an invoice.
 * An invoice of 100.00 USD unless a test says otherwise.
 */
class CheckoutActionTest extends TestCase
{
    private function act(array $payload, $total = '100.00', string $currency = 'USD', bool $recorded = false): array
    {
        return pawpayments_checkout_action($payload + ['order_id' => 'po1'], $total, $currency, $recorded);
    }

    // ─── success / paid_over ────────────────────────────────────────────────

    public function testSuccessCoveringTheTotalIsBooked(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 100.0, 'initial_fiat_amount' => 100.0, 'fiat_currency' => 'USD']);

        $this->assertSame(100.0, $action['book']);
        $this->assertSame(200, $action['http']);
        $this->assertSame('Successful', $action['log']);
    }

    public function testSuccessABitUnderTheTotalBooksTheIssuedAmount(): void
    {
        // Credited at the live rate, net of commission: a hair under what the
        // invoice was issued for. Booking 99.2 would leave it Unpaid over cents.
        $action = $this->act([
            'status' => 'success', 'fiat_amount' => 99.2, 'initial_fiat_amount' => 100.0, 'fiat_currency' => 'USD',
        ]);

        $this->assertSame(100.0, $action['book']);
    }

    public function testPaidOverBooksWhatWasReceived(): void
    {
        $action = $this->act([
            'status' => 'paid_over', 'fiat_amount' => 130.0, 'initial_fiat_amount' => 100.0, 'fiat_currency' => 'USD',
        ]);

        $this->assertSame(130.0, $action['book']);
    }

    public function testCurrencyIsComparedCaseInsensitively(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 100.0, 'initial_fiat_amount' => 100.0, 'fiat_currency' => 'usd']);

        $this->assertSame(100.0, $action['book']);
    }

    public function testSuccessWithinTheMerchantsOwnToleranceBooksTheInvoice(): void
    {
        // The merchant accepts 20% short (dashboard setting, not in the webhook):
        // the gateway sends `success` for 80 received, and the invoice is paid.
        $action = $this->act(['status' => 'success', 'fiat_amount' => 80.0, 'initial_fiat_amount' => 100.0, 'fiat_currency' => 'USD']);

        $this->assertSame(100.0, $action['book']);
        $this->assertSame('Successful', $action['log']);
    }

    /** @dataProvider notCovering */
    public function testPaymentThatDoesNotCoverTheInvoiceIsRefused(array $payload, string $currency): void
    {
        $action = $this->act($payload, '100.00', $currency);

        $this->assertNull($action['book']);
        $this->assertSame(409, $action['http']);
        $this->assertStringStartsWith('Not applied:', $action['log']);
    }

    public function notCovering(): array
    {
        $paid = ['status' => 'success', 'fiat_amount' => 100.0, 'initial_fiat_amount' => 100.0, 'fiat_currency' => 'USD'];

        return [
            'another currency' => [['fiat_currency' => 'EUR'] + $paid, 'USD'],
            // A genuine webhook for a 1.00 invoice whose `extra` names this one.
            'a cheaper invoice' => [['fiat_amount' => 1.0, 'initial_fiat_amount' => 1.0] + $paid, 'USD'],
            'issued two cents short' => [['initial_fiat_amount' => 99.98] + $paid, 'USD'],
            'no fiat_currency' => [array_diff_key($paid, ['fiat_currency' => 0]), 'USD'],
            'no initial_fiat_amount' => [array_diff_key($paid, ['initial_fiat_amount' => 0]), 'USD'],
            'client currency unknown' => [['status' => 'paid_over'] + $paid, ''],
        ];
    }

    public function testRepeatedDeliveryIsNotBookedAgain(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 100.0, 'fiat_currency' => 'USD'], '100.00', 'USD', true);

        $this->assertNull($action['book']);
        $this->assertSame(200, $action['http']);
        $this->assertStringStartsWith('Already recorded', $action['log']);
    }

    public function testMissingInvoiceTotalIsRefused(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 100.0, 'initial_fiat_amount' => 100.0, 'fiat_currency' => 'USD'], null);

        $this->assertNull($action['book']);
        $this->assertSame(409, $action['http']);
    }

    // ─── cancelled (expired after a partial payment) ────────────────────────

    public function testExpiredAfterAPartialBooksThePartial(): void
    {
        $action = $this->act(['status' => 'cancelled', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD']);

        $this->assertSame(40.0, $action['book']);
        $this->assertSame('Expired after a partial payment, partial applied', $action['log']);
    }

    public function testExpiredPartialInAnotherCurrencyIsNotBooked(): void
    {
        $action = $this->act(['status' => 'cancelled', 'fiat_amount' => 40.0, 'fiat_currency' => 'EUR']);

        $this->assertNull($action['book']);
        $this->assertStringContainsString('another currency', $action['log']);
    }

    public function testExpiredPartialWithoutAnyCurrencyIsNotBooked(): void
    {
        // Neither side knows the currency: that is not a match.
        $action = $this->act(['status' => 'cancelled', 'fiat_amount' => 40.0], '100.00', '');

        $this->assertNull($action['book']);
    }

    public function testExpiredPartialAlreadyBookedIsNotBookedAgain(): void
    {
        $action = $this->act(['status' => 'cancelled', 'fiat_amount' => 40.0, 'fiat_currency' => 'USD'], '100.00', 'USD', true);

        $this->assertNull($action['book']);
        $this->assertSame('Unsuccessful', $action['log']);
    }

    public function testExpiredWithNothingPaidBooksNothing(): void
    {
        $action = $this->act(['status' => 'cancelled', 'fiat_amount' => 0, 'fiat_currency' => 'USD']);

        $this->assertNull($action['book']);
        $this->assertSame('Unsuccessful', $action['log']);
    }

    // ─── statuses that never book ───────────────────────────────────────────

    /** @dataProvider nonBookingStatuses */
    public function testStatusBooksNothing(string $status, string $log): void
    {
        $action = $this->act(['status' => $status, 'fiat_amount' => 100.0, 'fiat_currency' => 'USD']);

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
            $this->assertNull($this->act(['status' => $status, 'fiat_amount' => 100.0, 'fiat_currency' => 'USD'])['book']);
        }
    }
}
