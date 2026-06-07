<?php

declare(strict_types=1);

namespace PhilipRehberger\WebhookRelayClient;

/**
 * Sender-side client for the Webhook Relay API. Curl-backed, no Guzzle
 * dependency, no PSR-18 plumbing — the surface is small enough that the
 * extra abstraction doesn't pay for itself.
 *
 * For endpoints the typed surface doesn't cover (manual retry, dead-letter
 * replay, webhook test probe) drop down to {@see request()}.
 */
class WebhookRelayClient
{
    private const DEFAULT_BASE = 'https://api.webhook-relay.dcsuniverse.com';

    private const DEFAULT_TIMEOUT_SECONDS = 15;

    private readonly string $baseUrl;

    private readonly string $apiKey;

    private readonly int $timeoutSeconds;

    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('WebhookRelayClient: apiKey is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE, '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    // ─── events ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function ingest(string $type, array $payload, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->request('POST', '/v1/events', [
            'body' => ['type' => $type, 'payload' => $payload],
            'headers' => $headers,
        ]);
    }

    /**
     * @param  array<string, string|int|null>  $params
     * @return array<string, mixed>
     */
    public function listEvents(array $params = []): array
    {
        return $this->request('GET', '/v1/events'.self::qs($params));
    }

    /**
     * @return array<string, mixed>
     */
    public function getEvent(string $id): array
    {
        return $this->request('GET', '/v1/events/'.rawurlencode($id));
    }

    // ─── subscriptions ───────────────────────────────────────────────────

    /**
     * @return array<string, mixed> Subscription with `signing_secret` field — store it now.
     */
    public function createSubscription(string $url, ?string $name = null, ?string $eventFilter = null): array
    {
        return $this->request('POST', '/v1/subscriptions', [
            'body' => array_filter([
                'url' => $url,
                'name' => $name,
                'event_filter' => $eventFilter,
            ], static fn ($v) => $v !== null),
        ]);
    }

    /**
     * @param  array<string, string|int|null>  $params
     * @return array<string, mixed>
     */
    public function listSubscriptions(array $params = []): array
    {
        return $this->request('GET', '/v1/subscriptions'.self::qs($params));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscription(string $id): array
    {
        return $this->request('GET', '/v1/subscriptions/'.rawurlencode($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function pauseSubscription(string $id): array
    {
        return $this->request('POST', '/v1/subscriptions/'.rawurlencode($id).'/pause');
    }

    /**
     * @return array<string, mixed>
     */
    public function resumeSubscription(string $id): array
    {
        return $this->request('POST', '/v1/subscriptions/'.rawurlencode($id).'/resume');
    }

    /**
     * @return array<string, mixed> Subscription with new `signing_secret`.
     */
    public function rotateSubscriptionSecret(string $id): array
    {
        return $this->request('POST', '/v1/subscriptions/'.rawurlencode($id).'/rotate-secret');
    }

    // ─── deliveries ──────────────────────────────────────────────────────

    /**
     * @param  array<string, string|int|null>  $params
     * @return array<string, mixed>
     */
    public function listDeliveries(array $params = []): array
    {
        return $this->request('GET', '/v1/deliveries'.self::qs($params));
    }

    /**
     * @return array<string, mixed>
     */
    public function getDelivery(string $id): array
    {
        return $this->request('GET', '/v1/deliveries/'.rawurlencode($id));
    }

    // ─── escape hatch ────────────────────────────────────────────────────

    /**
     * @param  array{body?: mixed, headers?: array<string, string>}  $init
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $init = []): array
    {
        $headers = array_merge(
            [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Accept' => 'application/json',
            ],
            isset($init['body']) ? ['Content-Type' => 'application/json'] : [],
            $init['headers'] ?? [],
        );

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name.': '.$value;
        }

        $ch = curl_init($this->baseUrl.$path);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if (isset($init['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($init['body'], JSON_UNESCAPED_SLASHES));
        }

        $rawResponse = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new \RuntimeException('curl error: '.$error);
        }

        if ($status === 204) {
            return [];
        }

        $body = is_string($rawResponse) ? $rawResponse : '';
        /** @var array<string, mixed>|null $decoded */
        $decoded = $body !== '' ? json_decode($body, true) : null;
        if (! is_array($decoded)) {
            $decoded = [];
        }

        if ($status >= 400) {
            throw new WebhookRelayException(
                status: is_numeric($decoded['status'] ?? null) ? (int) $decoded['status'] : $status,
                title: is_string($decoded['title'] ?? null) ? $decoded['title'] : 'HTTP '.$status,
                detail: is_string($decoded['detail'] ?? null) ? $decoded['detail'] : null,
                raw: $decoded,
            );
        }

        return $decoded;
    }

    /**
     * @param  array<string, string|int|null>  $params
     */
    private static function qs(array $params): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($v !== null && $v !== '') {
                $filtered[$k] = (string) $v;
            }
        }
        if ($filtered === []) {
            return '';
        }

        return '?'.http_build_query($filtered);
    }
}
