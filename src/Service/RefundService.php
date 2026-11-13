<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

use Aslan\Woo\Client\AslanHttpClient;
use WC_Order;

final class RefundService
{
    /**
     * Order-meta prefix recording refunds we started from Woo. The refund webhook
     * skips these: Woo creates its own WC_Order_Refund after process_refund returns.
     */
    public const META_INITIATED_PREFIX = '_aslan_initiated_refund_';

    /**
     * Order-meta prefix written BEFORE the API call (keyed by the attempt nonce) so a
     * webhook that races ahead of the post-call save can see a refund is in flight.
     */
    public const META_PENDING_PREFIX = '_aslan_pending_refund_';

    public function __construct(private readonly AslanHttpClient $client)
    {
    }

    public function refund(WC_Order $order, string $amountMad, string $reason = ''): string
    {
        $paymentId = (string) $order->get_meta(CheckoutService::META_PAYMENT_ID);
        if ($paymentId === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new RefundNotPossibleException('Order has no Aslan payment id — cannot refund.');
        }
        $centimes = AmountValidator::madToCentimes($amountMad);

        // Attempt-level idempotency: each refund attempt gets its own key, so two
        // same-amount refunds on one order both land instead of the second being
        // deduped as a replay.
        $nonce          = $this->generateNonce();
        $idempotencyKey = sprintf('refund_%d_%s_%d_%s', (int) $order->get_id(), $paymentId, $centimes, $nonce);

        // Pending marker before the API call: the webhook dedupe sees it while the
        // refund id is not yet known.
        // Format: _aslan_pending_refund_{transactionId}_{centimes}_{unix_timestamp}
        // The webhook handler matches on transactionId + centimes; the timestamp
        // expires stale markers (API call crashed, server restart, etc.).
        $markerKey = sprintf('%s%s_%d_%d', self::META_PENDING_PREFIX, $paymentId, $centimes, time());
        $order->update_meta_data($markerKey, '1');
        $order->save();

        try {
            // The Aslan refunds API takes the original payment's id as `transactionId`.
            $refund = $this->client->createRefund(
                [
                    'transactionId' => $paymentId,
                    'amount'        => $centimes,
                    'reason'        => $reason !== '' ? $reason : 'customer_requested',
                ],
                $idempotencyKey
            );
        } catch (\Throwable $e) {
            $order->delete_meta_data($markerKey);
            $order->save();
            throw $e;
        }

        // Pivot the marker from pending to refund id.
        $order->delete_meta_data($markerKey);
        $order->update_meta_data(self::META_INITIATED_PREFIX . $refund->id, '1');
        $order->save();

        return $refund->id;
    }

    private function generateNonce(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        // Fallback for test context without WordPress.
        return bin2hex(random_bytes(16));
    }
}
