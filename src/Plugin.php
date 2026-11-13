<?php

declare(strict_types=1);

namespace Aslan\Woo;

use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Config;
use Aslan\Woo\Service\CheckoutService;

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

    // TODO(Phase 12): if admin saves settings mid-request, the SDK factory captures the pre-save key.
    // Reload of /wp-admin or next request picks up the new key. Acceptable because WP admin saves
    // always follow a full page reload.
    private function register(): void
    {
        $config          = new Config();
        $httpClient      = new AslanHttpClient(AslanHttpClient::sdkFactory($config->activeSecretKey()));
        $checkoutService = new CheckoutService($httpClient);

        (new Rest\WebhookRoute($config, new Service\WebhookService()))->register();
        (new WooCommerce\OrderStatus())->register();

        add_filter(
            'woocommerce_payment_gateways',
            static function (array $gateways) use ($config, $checkoutService): array {
                $gateways[] = new WooCommerce\Gateway($config, $checkoutService);
                return $gateways;
            }
        );
    }
}
