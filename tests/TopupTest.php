<?php

use PHPUnit\Framework\TestCase;

/**
 * The top-up addon: pawpayments_topup_action() — what the callback credits —
 * and pawpayments_topup_credentials() — which key its webhooks are verified with.
 */
class TopupTest extends TestCase
{
    /** @var int[] client ids the currency lookup was asked about */
    private $lookups = [];

    protected function setUp(): void
    {
        $this->lookups = [];
        $GLOBALS['whmcs_gateway'] = [];
    }

    private function act(array $payload, string $clientCurrency = 'USD'): array
    {
        $lookup = function (int $clientId) use ($clientCurrency): string {
            $this->lookups[] = $clientId;
            return $clientCurrency;
        };

        return pawpayments_topup_action($payload + ['order_id' => 'po1', 'extra' => '42'], $lookup);
    }

    // ─── pawpayments_topup_action ───────────────────────────────────────────

    public function testPaidDepositInTheClientCurrencyIsCredited(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 25.5, 'fiat_currency' => 'USD']);

        $this->assertSame(25.5, $action['credit']);
        $this->assertSame('USD', $action['currency']);
        $this->assertSame(42, $action['client_id']);
        $this->assertSame(200, $action['http']);
        $this->assertSame([42], $this->lookups);
    }

    public function testCreditIsTheFiatAmountNeverTheCryptoAmount(): void
    {
        $action = $this->act([
            'status' => 'paid_over', 'amount' => 0.00042, 'fiat_amount' => 30.0, 'fiat_currency' => 'usd',
        ]);

        $this->assertSame(30.0, $action['credit']);
        $this->assertSame('USD', $action['currency']);
    }

    /** @dataProvider unpaidStatuses */
    public function testUnpaidStatusCreditsNothingAndLooksNothingUp(string $status): void
    {
        $action = $this->act(['status' => $status, 'fiat_amount' => 25.0, 'fiat_currency' => 'USD']);

        $this->assertNull($action['credit']);
        $this->assertSame([200, 'OK', null], [$action['http'], $action['body'], $action['log']]);
        $this->assertSame([], $this->lookups);
    }

    public function unpaidStatuses(): array
    {
        return [['partially_paid'], ['cancelled'], ['failed'], ['high_risk'], ['']];
    }

    /** @dataProvider missingIds */
    public function testMissingClientOrOrderIsA400(array $override): void
    {
        $action = pawpayments_topup_action(
            $override + ['status' => 'success', 'fiat_amount' => 25.0, 'fiat_currency' => 'USD'],
            function () {
                $this->fail('the currency lookup must not run for a malformed webhook');
            }
        );

        $this->assertNull($action['credit']);
        $this->assertSame(400, $action['http']);
    }

    public function missingIds(): array
    {
        return [
            'no client' => [['order_id' => 'po1']],
            'client 0' => [['order_id' => 'po1', 'extra' => '0']],
            'client not a number' => [['order_id' => 'po1', 'extra' => 'abc']],
            'no order_id' => [['extra' => '42']],
        ];
    }

    /** @dataProvider noFiatAmount */
    public function testMissingFiatAmountIsRejectedForGood(array $payload): void
    {
        $action = $this->act($payload + ['status' => 'success', 'fiat_currency' => 'USD']);

        $this->assertNull($action['credit']);
        // 200 on purpose: a refusal the gateway must not keep retrying.
        $this->assertSame([200, 'Rejected'], [$action['http'], $action['body']]);
        $this->assertSame('Topup Rejected: missing fiat_amount', $action['log']);
        $this->assertSame([], $this->lookups);
    }

    public function noFiatAmount(): array
    {
        return [
            'absent (crypto amount only)' => [['amount' => 0.001]],
            'zero' => [['fiat_amount' => 0]],
            'negative' => [['fiat_amount' => -5]],
        ];
    }

    public function testDepositInAnotherCurrencyIsRejected(): void
    {
        // AddCredit books a bare number: 25 EUR must never become 25 USD.
        $action = $this->act(['status' => 'success', 'fiat_amount' => 25.0, 'fiat_currency' => 'EUR'], 'USD');

        $this->assertNull($action['credit']);
        $this->assertSame([200, 'Rejected'], [$action['http'], $action['body']]);
        $this->assertStringContainsString("'EUR' does not match client currency 'USD'", $action['log']);
    }

    public function testUnknownClientCurrencyIsRejected(): void
    {
        $action = $this->act(['status' => 'success', 'fiat_amount' => 25.0, 'fiat_currency' => 'USD'], '');

        $this->assertNull($action['credit']);
        $this->assertSame('Rejected', $action['body']);
    }

    // ─── pawpayments_topup_credentials ──────────────────────────────────────

    public function testAddonKeyIsUsedWhenSet(): void
    {
        $GLOBALS['whmcs_gateway'] = ['api_key' => 'gateway_key', 'api_base_url' => 'https://gw.example'];

        $this->assertSame(
            ['api_key' => 'addon_key', 'api_base_url' => 'https://addon.example'],
            pawpayments_topup_credentials(['api_key' => 'addon_key', 'api_base_url' => 'https://addon.example'])
        );
    }

    public function testGatewayKeyIsUsedWhenTheAddonHasNone(): void
    {
        $GLOBALS['whmcs_gateway'] = ['api_key' => 'gateway_key', 'api_base_url' => 'https://gw.example'];

        $this->assertSame(
            ['api_key' => 'gateway_key', 'api_base_url' => 'https://gw.example'],
            pawpayments_topup_credentials(['api_key' => '', 'api_base_url' => ''])
        );
    }

    public function testNoKeyAnywhereLeavesItEmptySoTheCallbackAnswers503(): void
    {
        $credentials = pawpayments_topup_credentials([]);

        $this->assertSame('', $credentials['api_key']);
        $this->assertSame('https://api.pawpayments.com', $credentials['api_base_url']);
        $this->assertSame(503, pawpayments_verify_webhook($credentials['api_key'], '{}', str_repeat('0', 64))[1]);
    }
}
