<?php
/**
 * Fired when the plugin is deleted from the WordPress admin.
 *
 * Scrubs every option this plugin writes. Keys and webhook signing
 * secrets go first; the WooCommerce settings blob (which also holds
 * secret keys for compatibility) is removed last. Transients clean
 * themselves up on TTL; a direct $wpdb DELETE for `_transient_aslan_*`
 * is deliberately avoided (unbounded delete on a shared table).
 *
 * This file is only loaded by WordPress during uninstall — not on
 * deactivation — and only when the user explicitly deletes the plugin.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    // Secrets first.
    'aslan_woo_test_secret_key',
    'aslan_woo_live_secret_key',
    'aslan_woo_test_webhook_secret',
    'aslan_woo_live_webhook_secret',
    // Non-secret config.
    'aslan_woo_mode',
    'aslan_woo_title',
    'aslan_woo_show_test_banner',
    'aslan_woo_webhook_url_override',
    // Operational state.
    'aslan_woo_last_setup',
    'aslan_woo_scan_pending',
    'aslan_woo_log',
    // WooCommerce settings blob (holds secret keys for WC_Payment_Gateway compatibility).
    'woocommerce_aslan_settings',
];

foreach ($options as $option) {
    delete_option($option);
}

// Short-lived transients clean themselves up; nothing persistent is scoped.
