<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

final class WebhookService
{
    public const MAX_AGE_SECONDS = 300;

    /**
     * Verifies the X-Webhook-Signature header and returns the decoded event.
     * Throws if signature mismatch, stale timestamp, or malformed header.
     *
     * @return array<string, mixed>
     */
    public function verify(string $rawBody, string $signatureHeader, string $secret): array
    {
        $parts = [];
        foreach (explode(',', $signatureHeader) as $p) {
            $kv = explode('=', $p, 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;
        if ($timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new WebhookVerificationException('Malformed signature header');
        }

        $age = abs(time() - (int) $timestamp);
        if ($age > self::MAX_AGE_SECONDS) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new WebhookVerificationException('Webhook timestamp too old');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (! hash_equals($expected, $signature)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new WebhookVerificationException('Invalid signature');
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event) || ! isset($event['id'], $event['event'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new WebhookVerificationException('Event body not well-formed');
        }
        return $event;
    }
}
