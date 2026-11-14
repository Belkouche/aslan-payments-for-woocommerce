<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Integration\WooCommerce;

use WP_UnitTestCase;

final class OrderStatusTest extends WP_UnitTestCase
{
    public function test_aslan_awaiting_cash_is_registered(): void
    {
        $statuses = wc_get_order_statuses();
        self::assertArrayHasKey('wc-aslan-awaiting-cash', $statuses);
    }

    public function test_order_can_move_to_awaiting_cash(): void
    {
        $order = wc_create_order();
        $order->update_status('aslan-awaiting-cash');
        $order->save();

        $fresh = wc_get_order($order->get_id());
        self::assertSame('aslan-awaiting-cash', $fresh->get_status());
    }
}
