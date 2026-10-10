<?php

use PHPUnit\Framework\TestCase;

/** pawpayments_verify_webhook(): what reaches the callbacks at all. */
class VerifyWebhookTest extends TestCase
{
    private const KEY = 'whmcs_test_key_0123456789';

    private function sign(string $body, string $key = self::KEY): string
    {
        return hash_hmac('sha256', $body, $key);
    }

    public function testSignedWebhookYieldsItsPayload(): void
    {
        $body = '{"order_id":"abc","status":"success","extra":"42"}';

        [$payload, $http] = pawpayments_verify_webhook(self::KEY, $body, $this->sign($body));

        $this->assertSame(['order_id' => 'abc', 'status' => 'success', 'extra' => '42'], $payload);
        $this->assertSame(0, $http);
    }

    public function testEmptyKeyAnswers503EvenForAMatchingSignature(): void
    {
        // With an empty HMAC key anyone can compute the "right" signature.
        $body = '{"order_id":"abc","status":"success"}';

        $this->assertSame(
            [null, 503, 'Gateway not configured'],
            pawpayments_verify_webhook('', $body, hash_hmac('sha256', $body, ''))
        );
    }

    public function testEmptyBodyAnswers400(): void
    {
        $this->assertSame([null, 400, 'Empty body'], pawpayments_verify_webhook(self::KEY, '', $this->sign('')));
    }

    /** @dataProvider badSignatures */
    public function testBadSignatureAnswers401(string $signature): void
    {
        $body = '{"order_id":"abc","status":"success"}';

        $this->assertSame([null, 401, 'Invalid signature'], pawpayments_verify_webhook(self::KEY, $body, $signature));
    }

    public function badSignatures(): array
    {
        $body = '{"order_id":"abc","status":"success"}';

        return [
            'missing' => [''],
            'another key' => [hash_hmac('sha256', $body, 'someone_elses_key')],
            'another body' => [hash_hmac('sha256', '{"order_id":"abc","status":"failed"}', self::KEY)],
            'not hex' => [str_repeat('z', 64)],
            'truncated' => [substr(hash_hmac('sha256', $body, self::KEY), 0, 40)],
        ];
    }

    public function testPermanentAddressDepositIsAcknowledgedWithoutAction(): void
    {
        $body = '{"order_id":"abc","status":"success","permanent_address_id":"pa1"}';

        $this->assertSame([null, 200, ''], pawpayments_verify_webhook(self::KEY, $body, $this->sign($body)));
    }
}
