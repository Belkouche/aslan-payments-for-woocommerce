<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

use Aslan\Woo\Client\AslanHttpClient;
use WC_Order;

final class CheckoutService
{
    public const META_SESSION_ID    = '_aslan_session_id';
    public const META_PAYMENT_ID    = '_aslan_payment_id';
    public const META_ATTEMPT_COUNT = '_aslan_attempt_count';

    public function __construct(private readonly AslanHttpClient $client)
    {
    }

    public function createSessionForOrder(WC_Order $order): CheckoutSessionResult
    {
        $orderId  = (int) $order->get_id();
        $centimes = AmountValidator::madToCentimes((float) $order->get_total());

        $attempt = (int) $order->get_meta(self::META_ATTEMPT_COUNT) + 1;

        $payload = [
            'amount'     => $centimes,
            'currency'   => 'MAD',
            'successUrl' => home_url('/aslan/return?order=' . $orderId),
            'cancelUrl'  => home_url('/aslan/cancel?order=' . $orderId),
            'customer'   => [
                'email' => (string) $order->get_billing_email(),
                'name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'phone' => (string) $order->get_billing_phone(),
            ],
            'metadata'   => [
                'wc_order_id' => (string) $orderId,
            ],
        ];

        $session = $this->client->createCheckoutSession($payload, sprintf('order_%d_%d', $orderId, $attempt));

        $order->update_meta_data(self::META_SESSION_ID, $session->id);
        $order->update_meta_data(self::META_ATTEMPT_COUNT, (string) $attempt);
        $order->save();

        return new CheckoutSessionResult(sessionId: $session->id, redirectUrl: $session->url);
    }
}
