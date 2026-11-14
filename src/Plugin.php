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
        $refundService   = new Service\RefundService($httpClient);
        $keyLifecycle    = new Service\KeyLifecycleService(
            static fn (string $secretKey): AslanHttpClient => new AslanHttpClient(
                AslanHttpClient::sdkFactory($secretKey)
            )
        );

        (new Rest\WebhookRoute($config, new Service\WebhookService(new Service\DiagnosticLog())))->register();
        (new WooCommerce\OrderStatus())->register();
        (new WooCommerce\ThankYouPage())->register();
        (new Rest\OrderStatusRoute())->register();
        (new Shortcode\PaymentCpt())->register();
        (new Rest\ShortcodePayRoute($httpClient))->register();
        $payShortcode = new Shortcode\PayShortcode();
        $payShortcode->register();
        (new Shortcode\PayBlock($payShortcode))->register();

        (new Admin\SetupStatusCard($config))->register();
        (new Admin\TroubleshootingActions($config, $keyLifecycle))->register();

        if (is_admin() && get_option('aslan_woo_scan_pending')) {
            add_action('admin_init', static function (): void {
                (new Admin\PriceScanner())->renderNoticeIfAny();
                delete_option('aslan_woo_scan_pending');
            });
        }

        add_filter(
            'woocommerce_payment_gateways',
            static function (array $gateways) use ($config, $checkoutService, $refundService, $keyLifecycle): array {
                $gateways[] = new WooCommerce\Gateway($config, $checkoutService, $refundService, $keyLifecycle);
                return $gateways;
            }
        );
    }
}
