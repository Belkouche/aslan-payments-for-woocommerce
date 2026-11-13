<?php

declare(strict_types=1);

namespace Aslan\Woo\Rest;

use WP_REST_Request;
use WP_REST_Response;

final class OrderStatusRoute
{
    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('aslan/v1', '/order-status/(?P<id>\d+)', [
                'methods'             => 'GET',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true', // public, read-only, returns only {paid: bool}
            ]);
        });
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $orderId = (int) $request['id'];
        $order   = wc_get_order($orderId);
        if (! $order instanceof \WC_Order) {
            return new WP_REST_Response(['paid' => false], 200);
        }
        return new WP_REST_Response(['paid' => $order->is_paid()], 200);
    }
}
