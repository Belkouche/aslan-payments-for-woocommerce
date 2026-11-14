<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Integration\WooCommerce;

use WC_Payment_Gateways;
use WP_UnitTestCase;

final class GatewayTest extends WP_UnitTestCase
{
    public function test_gateway_not_available_when_unconfigured(): void
    {
        update_option('aslan_woo_test_secret_key', '');
        update_option('aslan_woo_test_webhook_secret', '');

        $gateways = WC_Payment_Gateways::instance()->payment_gateways();
        $aslan = $gateways['aslan'] ?? null;

        self::assertNotNull($aslan, 'Gateway should be registered');
        self::assertFalse($aslan->is_available(), 'Gateway should not be available when keys are missing');
    }

    public function test_gateway_available_when_keys_and_secret_set(): void
    {
        update_option('aslan_woo_mode', 'test');
        update_option('aslan_woo_test_secret_key', 'sk_test_abc_secret');
        update_option('aslan_woo_test_webhook_secret', 'whsec_abc');

        $gateways = WC_Payment_Gateways::instance()->payment_gateways();
        $aslan = $gateways['aslan'] ?? null;

        self::assertNotNull($aslan);
        self::assertTrue($aslan->is_available());
    }
}
