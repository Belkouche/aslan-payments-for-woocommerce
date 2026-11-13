<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

final class WebhookService
{
    public const MAX_AGE_SECONDS = 300;

    /** A pending-refund marker older than this is orphaned (crashed attempt) and is swept. */
    // 300s covers the worst case of AslanHttpClient's retry loop (connection failures
    // with 300ms + 900ms backoff × 3 attempts + typical API latency), plus headroom
    // for unusual network conditions. See docs/superpowers/plans/... for the rationale.
    private const PENDING_MARKER_TTL_SECONDS = 300;

    /**
     * Verifies the X-Webhook-Signature header and returns the decoded event.
     * Throws if signature mismatch, stale timestamp, or malformed header.
     *
     * @return array<string, mixed>
     */
    public function verify(string $rawBody, string $signatureHeader, string $secret): array
    {
        if ($secret === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new WebhookVerificationException('Webhook secret not configured');
        }

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

        $age = time() - (int) $timestamp;
        // Allow up to 60s of forward clock skew; anything older than 300s is rejected.
        if ($age < -60 || $age > self::MAX_AGE_SECONDS) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new WebhookVerificationException('Webhook timestamp out of range');
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
        if (
            ! is_string($event['id']) || $event['id'] === ''
            || ! is_string($event['event']) || $event['event'] === ''
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new WebhookVerificationException('Event body missing required fields');
        }
        return $event;
    }

    /**
     * Dispatch a verified event to the right order mutation.
     *
     * @param array<string, mixed>      $event       Decoded event body.
     * @param callable(int): ?\WC_Order      $orderLoader         Called with the wc_order_id from metadata.
     * @param (callable(string): bool)|null $idempotencyRecorder Returns true the first time an event id is seen.
     */
    public function handle(array $event, callable $orderLoader, ?callable $idempotencyRecorder = null): void
    {
        $eventId = (string) ($event['id'] ?? '');
        if ($idempotencyRecorder !== null && $eventId !== '' && ! $idempotencyRecorder($eventId)) {
            return;
        }

        $eventType = strtolower((string) ($event['event'] ?? ''));
        $data      = $event['data'] ?? [];
        if (! is_array($data)) {
            return;
        }
        // Refund events nest the refund under data.refund; payment events are flat.
        $isRefundEvent = str_starts_with($eventType, 'refund.');
        if ($isRefundEvent && isset($data['refund']) && is_array($data['refund'])) {
            $data = $data['refund'];
        }
        $wcOrderId = isset($data['metadata']['wc_order_id']) ? (int) $data['metadata']['wc_order_id'] : 0;
        if ($wcOrderId <= 0) {
            return;
        }
        $order = $orderLoader($wcOrderId);
        if ($order === null) {
            return;
        }

        // Reject events for orders not using the Aslan gateway — a forged webhook
        // with a wc_order_id metadata pointing at a non-Aslan order must not mutate it.
        if ($order->get_payment_method() !== 'aslan') {
            return;
        }

        // Validate amount + currency match the order. Guards against an attacker who
        // somehow produced a valid-signature event for a mismatched amount.
        $eventAmountCentimes = isset($data['amount']) ? (int) $data['amount'] : 0;
        $eventCurrency       = isset($data['currency']) ? (string) $data['currency'] : '';
        if ($eventCurrency !== 'MAD') {
            return;
        }
        $orderCentimes = (int) round((float) $order->get_total() * 100);
        // Payment events must match the order total exactly. Refund events may be
        // partial, so they are bounded (0 < amount <= total) in applyRefund() instead.
        if (! $isRefundEvent && $eventAmountCentimes !== $orderCentimes) {
            return;
        }

        switch ($eventType) {
            case 'payment.succeeded':
                // Idempotency belt-and-suspenders: if the order is already paid,
                // skip to avoid re-running completion hooks.
                if ($order->is_paid()) {
                    break;
                }
                $paymentId = (string) ($data['id'] ?? '');
                if ($paymentId === '') {
                    return;
                }
                $order->update_meta_data(CheckoutService::META_PAYMENT_ID, $paymentId);
                if (isset($data['cardLast4'])) {
                    $order->update_meta_data('_aslan_card_last4', (string) $data['cardLast4']);
                }
                // payment_complete() itself persists — no explicit save() needed
                $order->payment_complete($paymentId);
                break;

            case 'payment.failed':
                // Order-state regression guard: don't flip a paid/completed order to failed.
                if ($order->is_paid() || ! in_array($order->get_status(), ['pending', 'on-hold'], true)) {
                    break;
                }
                $reason = (string) ($data['failureReason'] ?? 'unknown');
                $order->update_status(
                    'failed',
                    sprintf('Aslan reported payment failed: %s', $reason)
                );
                break;

            case 'payment.pending_cash':
                if ($order->is_paid() || ! in_array($order->get_status(), ['pending', 'on-hold'], true)) {
                    break;
                }
                $order->update_status(
                    'aslan-awaiting-cash',
                    'Aslan reported cash payment pending at the agent network'
                );
                break;

            // `refund.succeeded` is the plan's name; the Aslan API emits refund.created and
            // refund.updated carrying a status, so all three funnel into one handler.
            case 'refund.succeeded':
            case 'refund.created':
            case 'refund.updated':
                $this->applyRefund($order, $data, $eventAmountCentimes, $orderCentimes);
                break;

            default:
                // Ignore unknown events: Aslan may add new types before we support them.
                break;
        }
    }

    /**
     * @param array<string, mixed> $data Flat refund object (id, transactionId, amount, status).
     */
    private function applyRefund(\WC_Order $order, array $data, int $amountCentimes, int $orderCentimes): void
    {
        $refundId = (string) ($data['id'] ?? '');
        if ($refundId === '') {
            return;
        }
        // Only a settled refund touches the order; pending/processing/failed are ignored.
        // (refund.succeeded events from the plan's shape carry no status.)
        if (isset($data['status']) && $data['status'] !== 'succeeded') {
            return;
        }
        // Bind the refund to this order's own Aslan payment, not just its wc_order_id.
        $orderPaymentId = (string) $order->get_meta(CheckoutService::META_PAYMENT_ID);
        if ($orderPaymentId === '' || (string) ($data['transactionId'] ?? '') !== $orderPaymentId) {
            return;
        }
        if ($amountCentimes <= 0 || $amountCentimes > $orderCentimes) {
            return;
        }
        // Refunds started from Woo get their WC_Order_Refund from Woo itself.
        if ((string) $order->get_meta(RefundService::META_INITIATED_PREFIX . $refundId) !== '') {
            return;
        }
        // A refund attempt of ours is in flight (API call made, initiated marker not yet
        // saved): the webhook raced ahead. Match markers specifically by transactionId +
        // centimes; sweep stale ones so an orphaned marker cannot block refunds forever.
        $now            = time();
        $matchedPending = false;
        $sweptAny       = false;
        foreach ($order->get_meta_data() as $meta) {
            $key = is_object($meta) && method_exists($meta, 'get_data')
                ? (string) ($meta->get_data()['key'] ?? '')
                : '';
            if (! str_starts_with($key, RefundService::META_PENDING_PREFIX)) {
                continue;
            }
            $parts = explode('_', substr($key, strlen(RefundService::META_PENDING_PREFIX)));
            if (count($parts) < 4) {
                continue;
            }
            // Suffix format: {paymentId}_{centimes}_{timestamp}_{nonce}
            // paymentId may contain underscores; parse from the right.
            array_pop($parts);                          // nonce — discarded for matching
            $markerTimestamp = (int) array_pop($parts); // timestamp
            $markerCentimes  = (int) array_pop($parts); // centimes
            $markerTxn       = implode('_', $parts);    // remainder = paymentId

            if ($now - $markerTimestamp > self::PENDING_MARKER_TTL_SECONDS) {
                $order->delete_meta_data($key);
                $sweptAny = true;
                continue;
            }
            if ($markerTxn === $orderPaymentId && $markerCentimes === $amountCentimes) {
                $matchedPending = true;
            }
        }
        if ($sweptAny) {
            $order->save();
        }
        if ($matchedPending) {
            return;
        }
        foreach ($order->get_refunds() as $existing) {
            if ((string) $existing->get_meta('_aslan_refund_id') === $refundId) {
                return;
            }
        }

        $refund = wc_create_refund([
            'order_id' => $order->get_id(),
            'amount'   => $amountCentimes / 100,
            'reason'   => sprintf('Aslan refund %s', $refundId),
        ]);
        if (! is_wp_error($refund)) {
            $refund->update_meta_data('_aslan_refund_id', $refundId);
            $refund->save();
        }
    }
}
