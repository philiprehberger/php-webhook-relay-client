<?php

declare(strict_types=1);

namespace PhilipRehberger\WebhookRelayClient;

/**
 * Sign and verify webhook payloads using the Webhook Relay header format.
 *
 * Header format: t={unix_ts},v1={hex_hmac_sha256} where the HMAC is
 * computed over "{t}.{raw_body}" using the subscription's signing
 * secret. Matches Stripe and Svix byte-for-byte.
 *
 * The raw body must be the exact bytes received. json_encode(json_decode(...))
 * is NOT safe — key order or whitespace can change and break verification.
 */
final class Signer
{
    /**
     * Verify a signature header against a body + secret.
     *
     * @param  int  $toleranceSeconds  Reject signatures older than this. Default 300.
     */
    public static function verify(
        string $secret,
        string $body,
        string $header,
        int $toleranceSeconds = 300,
    ): bool {
        if ($header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $segment) {
            $kv = explode('=', trim($segment), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        if (! isset($parts['t'], $parts['v1'])) {
            return false;
        }

        $ts = filter_var($parts['t'], FILTER_VALIDATE_INT);
        if ($ts === false) {
            return false;
        }

        if (abs(time() - $ts) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $ts.'.'.$body, $secret);

        return hash_equals($expected, $parts['v1']);
    }

    /**
     * Produce the X-Webhook-Signature header for a body + secret. Useful
     * for tests and compatible senders.
     *
     * @return array{timestamp: int, signature: string, header: string}
     */
    public static function sign(string $secret, string $body, ?int $timestamp = null): array
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return [
            'timestamp' => $timestamp,
            'signature' => $signature,
            'header' => "t={$timestamp},v1={$signature}",
        ];
    }
}
