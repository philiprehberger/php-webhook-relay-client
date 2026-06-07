<?php

declare(strict_types=1);

namespace PhilipRehberger\WebhookRelayClient\Tests;

use PhilipRehberger\WebhookRelayClient\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    private const SECRET = 'whsec_test_round_trip';

    public function test_verify_accepts_a_fresh_signature(): void
    {
        $body = '{"event":"order.created","amount":100}';
        $signed = Signer::sign(self::SECRET, $body);

        $this->assertTrue(Signer::verify(self::SECRET, $body, $signed['header']));
    }

    public function test_verify_rejects_a_tampered_body(): void
    {
        $signed = Signer::sign(self::SECRET, 'original body');

        $this->assertFalse(Signer::verify(self::SECRET, 'tampered body', $signed['header']));
    }

    public function test_verify_rejects_a_wrong_secret(): void
    {
        $signed = Signer::sign(self::SECRET, 'body');

        $this->assertFalse(Signer::verify('whsec_wrong', 'body', $signed['header']));
    }

    public function test_verify_rejects_a_stale_timestamp(): void
    {
        $signed = Signer::sign(self::SECRET, 'body', time() - 1000);

        $this->assertFalse(Signer::verify(self::SECRET, 'body', $signed['header'], 300));
    }

    public function test_verify_rejects_malformed_headers(): void
    {
        $this->assertFalse(Signer::verify(self::SECRET, 'body', 'garbage'));
        $this->assertFalse(Signer::verify(self::SECRET, 'body', 't=abc,v1=xyz'));
        $this->assertFalse(Signer::verify(self::SECRET, 'body', 'v1=xyz'));
        $this->assertFalse(Signer::verify(self::SECRET, 'body', ''));
    }

    public function test_sign_header_matches_independent_hmac(): void
    {
        $body = '{"x":1}';
        $ts = 1717000000;
        $expected = hash_hmac('sha256', $ts.'.'.$body, self::SECRET);

        $signed = Signer::sign(self::SECRET, $body, $ts);

        $this->assertSame($expected, $signed['signature']);
        $this->assertSame("t={$ts},v1={$expected}", $signed['header']);
        $this->assertSame($ts, $signed['timestamp']);
    }
}
