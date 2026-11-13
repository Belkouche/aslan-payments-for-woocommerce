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

    /**
     * Dispatch a verified event to the right order mutation.
     *
     * @param array<string, mixed>      $event       Decoded event body.
     * @param callable(int): ?\WC_Order $orderLoader Called with the wc_order_id from metadata.
     */
    public function handle(array $event, callable $orderLoader): void
    {
        $eventType = (string) ($event['event'] ?? '');
        $data      = $event['data'] ?? [];
        if (! is_array($data)) {
            return;
        }
        $wcOrderId = isset($data['metadata']['wc_order_id']) ? (int) $data['metadata']['wc_order_id'] : 0;
        if ($wcOrderId <= 0) {
            return;
        }
        $order = $orderLoader($wcOrderId);
        if ($order === null) {
            return;
        }

        switch ($eventType) {
            case 'payment.succeeded':
                $paymentId = (string) ($data['id'] ?? '');
                $order->update_meta_data('_aslan_payment_id', $paymentId);
                if (isset($data['cardLast4'])) {
                    $order->update_meta_data('_aslan_card_last4', (string) $data['cardLast4']);
                }
                $order->save();
                $order->payment_complete($paymentId);
                break;

            case 'payment.failed':
                $reason = (string) ($data['failureReason'] ?? 'unknown');
                $order->update_status(
                    'failed',
                    sprintf('Aslan reported payment failed: %s', $reason)
                );
                break;

            case 'payment.pending_cash':
                $order->update_status(
                    'aslan-awaiting-cash',
                    'Aslan reported cash payment pending at the agent network'
                );
                break;

            case 'refund.succeeded':
                // Phase 9 implements.
                break;

            default:
                // Ignore unknown events: Aslan may add new types before we support them.
                break;
        }
    }
}
