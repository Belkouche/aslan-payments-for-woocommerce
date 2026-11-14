<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Integration\Rest;

use WP_REST_Request;
use WP_Test_REST_TestCase;

final class WebhookRouteTest extends WP_Test_REST_TestCase
{
    public function test_valid_webhook_transitions_order_to_completed(): void
    {
        update_option('aslan_woo_mode', 'test');
        update_option('aslan_woo_test_webhook_secret', 'whsec_test_abc');

        $order = wc_create_order();
        $order->set_payment_method('aslan');
        $order->update_status('pending');
        $order->save();

        $body = json_encode([
            'id'    => 'evt_int_01',
            'event' => 'payment.succeeded',
            'data'  => [
                'id'        => 'pay_int_01',
                'metadata'  => ['wc_order_id' => (string) $order->get_id()],
                'cardLast4' => '4242',
            ],
        ]);
        $t   = time();
        $sig = hash_hmac('sha256', $t . '.' . $body, 'whsec_test_abc');

        $request = new WP_REST_Request('POST', '/aslan/v1/webhook');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-Webhook-Signature', sprintf('t=%d,v1=%s', $t, $sig));
        $request->set_body($body);

        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        $fresh = wc_get_order($order->get_id());
        self::assertSame('completed', $fresh->get_status());
    }

    public function test_bad_signature_returns_401(): void
    {
        update_option('aslan_woo_test_webhook_secret', 'whsec_test_abc');

        $request = new WP_REST_Request('POST', '/aslan/v1/webhook');
        $request->set_body('{"id":"evt_x"}');
        $request->set_header('X-Webhook-Signature', 't=1,v1=deadbeef');

        $response = rest_do_request($request);
        self::assertSame(401, $response->get_status());
    }
}
