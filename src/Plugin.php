<?php

declare(strict_types=1);

namespace Aslan\Woo;

use Aslan\Woo\Config;

final class Plugin
{
    private static ?self $instance = null;

    public static function boot(): void
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register();
        }
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::boot();
        }
        /** @phpstan-var self $instance */
        $instance = self::$instance;
        return $instance;
    }

    private function register(): void
    {
        $config = new Config();

        add_filter('woocommerce_payment_gateways', static function (array $gateways) use ($config): array {
            $gateways[] = new WooCommerce\Gateway($config);
            return $gateways;
        });
    }
}
