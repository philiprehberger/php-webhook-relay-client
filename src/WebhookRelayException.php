<?php

declare(strict_types=1);

namespace PhilipRehberger\WebhookRelayClient;

use RuntimeException;

/**
 * Thrown on 4xx / 5xx responses. The RFC 7807 problem payload (title,
 * detail, etc.) is preserved on the exception so handlers can branch on it.
 */
final class WebhookRelayException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly ?string $detail = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $raw = null,
    ) {
        $message = $detail !== null
            ? sprintf('%d %s: %s', $status, $title, $detail)
            : sprintf('%d %s', $status, $title);

        parent::__construct($message, $status);
    }
}
