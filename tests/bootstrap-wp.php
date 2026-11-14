<?php

declare(strict_types=1);

// WP-PHPUnit bootstrap — expects WP_TESTS_DIR env var pointing at a WordPress test install.
// Local: bin/install-wp-tests.sh sets this up.

$wpTestsDir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
if (! file_exists($wpTestsDir . '/includes/functions.php')) {
    throw new RuntimeException("WP_TESTS_DIR not found at {$wpTestsDir}. Run bin/install-wp-tests.sh");
}

require_once $wpTestsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function (): void {
    // Load WooCommerce + plugin under test. WooCommerce is not on Packagist, so CI
    // installs it into the WP plugins dir; override its location with WC_PLUGIN_FILE.
    $wcFile = getenv('WC_PLUGIN_FILE')
        ?: (getenv('WP_CORE_DIR') ?: '/tmp/wordpress') . '/wp-content/plugins/woocommerce/woocommerce.php';
    require_once $wcFile;
    require_once dirname(__DIR__) . '/aslan-payments-for-woocommerce.php';
});

require $wpTestsDir . '/includes/bootstrap.php';
