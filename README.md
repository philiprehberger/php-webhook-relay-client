# philiprehberger/php-webhook-relay-client

[![Tests](https://github.com/philiprehberger/php-webhook-relay-client/actions/workflows/tests.yml/badge.svg)](https://github.com/philiprehberger/php-webhook-relay-client/actions/workflows/tests.yml)
[![Packagist Version](https://img.shields.io/packagist/v/philiprehberger/php-webhook-relay-client.svg)](https://packagist.org/packages/philiprehberger/php-webhook-relay-client)
[![Last updated](https://img.shields.io/github/last-commit/philiprehberger/php-webhook-relay-client)](https://github.com/philiprehberger/php-webhook-relay-client/commits/main)

PHP SDK + HMAC verifier for the [Webhook Relay API](https://webhook-relay.dcsuniverse.com). PHP 8.2+, zero dependencies (curl + json extensions only).

## Installation

```bash
composer require philiprehberger/php-webhook-relay-client
```

## Verify an incoming webhook (receiver side)

```php
use PhilipRehberger\WebhookRelayClient\Signer;

$body = file_get_contents('php://input');   // raw bytes — DO NOT json_decode + re-encode
$header = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

if (! Signer::verify(getenv('WEBHOOK_SECRET'), $body, $header)) {
    http_response_code(400);
    exit('Bad signature');
}

$event = json_decode($body, true);
// ... handle $event
```

The body must be the exact bytes received. `json_encode(json_decode(...))` will reorder keys or change whitespace and break the signature.

The format matches Stripe and Svix (`t=<ts>,v1=<hex>` over `"{ts}.{body}"` with HMAC-SHA256), so the same verifier accepts signatures from any sender using that convention.

## Send an event (sender side)

```php
use PhilipRehberger\WebhookRelayClient\WebhookRelayClient;

$relay = new WebhookRelayClient(getenv('WEBHOOK_RELAY_KEY'));

$event = $relay->ingest(
    type: 'order.created',
    payload: ['order_id' => 42],
    idempotencyKey: 'order-42-created',
);

print $event['id'].PHP_EOL;
```

The client throws `WebhookRelayException` on 4xx/5xx with the RFC 7807 problem payload preserved:

```php
use PhilipRehberger\WebhookRelayClient\WebhookRelayException;

try {
    $relay->ingest('', []);
} catch (WebhookRelayException $err) {
    echo $err->status.' '.$err->title.': '.$err->detail.PHP_EOL;
}
```

## Subscriptions, deliveries, and the rest

```php
$sub = $relay->createSubscription(
    url: 'https://my-app.example.com/webhooks',
    name: 'orders inbound',
    eventFilter: 'order.*',
);
echo $sub['signing_secret'].PHP_EOL;   // store this now — shown only once

$page = $relay->listDeliveries(['status' => 'failed']);

$relay->pauseSubscription($sub['id']);
$relay->resumeSubscription($sub['id']);
$rotated = $relay->rotateSubscriptionSecret($sub['id']);
```

For endpoints the typed surface doesn't cover (manual retry, dead-letters, webhook test probe) drop down to `request()`:

```php
$relay->request('POST', '/v1/deliveries/'.$id.'/retry');
```

## Compatible senders

Use `Signer::sign()` to stand up a compatible sender for testing, or to verify your verifier matches the wire format:

```php
$signed = Signer::sign('whsec_shared', $rawBody);
$ch = curl_init($receiverUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $rawBody,
    CURLOPT_HTTPHEADER => ['X-Webhook-Signature: '.$signed['header']],
    CURLOPT_RETURNTRANSFER => true,
]);
curl_exec($ch);
```

## Pointing at a different host

```php
$relay = new WebhookRelayClient(
    apiKey: $apiKey,
    baseUrl: 'https://relay.staging.internal',
);
```

## Links

- API: https://api.webhook-relay.dcsuniverse.com
- Docs: https://webhook-relay.dcsuniverse.com
- OpenAPI spec: https://webhook-relay.dcsuniverse.com/openapi.yaml
- Source: https://github.com/philiprehberger/php-webhook-relay-client

## License

MIT
