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

        // The Aslan refunds API takes the original payment's id as `transactionId`.
        $refund = $this->client->createRefund(
            [
                'transactionId' => $paymentId,
                'amount'        => $centimes,
                'reason'        => $reason !== '' ? $reason : 'customer_requested',
            ],
            sprintf('refund_%d_%s_%d', (int) $order->get_id(), $paymentId, $centimes)
        );

        $order->update_meta_data(self::META_INITIATED_PREFIX . $refund->id, '1');
        $order->save();

        return $refund->id;
    }
}
