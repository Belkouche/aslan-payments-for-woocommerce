<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- WP entry file must define constants and register hooks.
// phpcs:disable Generic.Files.LineLength.TooLong -- plugin header Description and a translatable string must stay on one line.

/**
 * Plugin Name:       Aslan Payments for WooCommerce
 * Plugin URI:        https://aslanpay.ma/wordpress
 * Description:       Accept card, mobile wallet, and cash-agent payments through Aslan on WooCommerce. Plus a lightweight [aslan_pay] shortcode for standalone payments.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2.35
 * Author:            Aslan
 * Author URI:        https://aslanpay.ma
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aslan-payments-for-woocommerce
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('ASLAN_WOO_PLUGIN_FILE', __FILE__);
define('ASLAN_WOO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASLAN_WOO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ASLAN_WOO_VERSION', '0.1.0');

$composer_autoload = ASLAN_WOO_PLUGIN_DIR . 'vendor/autoload.php';
if (! file_exists($composer_autoload)) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Aslan Payments: vendor/ directory missing. Run `composer install` or install the plugin from the official ZIP.', 'aslan-payments-for-woocommerce');
        echo '</p></div>';
    });
    return;
}
require_once $composer_autoload;

add_action('plugins_loaded', [\Aslan\Woo\Plugin::class, 'boot']);

// HPOS declaration must happen before WooCommerce init (before_woocommerce_init hook).
add_action('before_woocommerce_init', function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            ASLAN_WOO_PLUGIN_FILE,
            true
        );
    }
});
