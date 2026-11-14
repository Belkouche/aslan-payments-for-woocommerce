<?php
/**
 * E2E setup — runs inside the Docker wp container.
 *
 * - sets store currency to MAD
 * - mirrors the test key into aslan_woo_*
 * - triggers KeyLifecycleService to register the webhook with Aslan (so
 *   the gateway becomes is_available(): true)
 * - creates one MAD-denominated test product so happy-path has something to buy
 * - creates a page using [aslan_pay] so shortcode tests have a target
 *
 * Invoked via: wp eval-file wp-content/plugins/aslan-payments-for-woocommerce/tests/E2E/setup.php
 *
 * `wp eval-file` wraps contents in eval(), which forbids a declare() at
 * script top. Types are enforced in the plugin source; this file is glue.
 */

$secretKey = getenv('ASLAN_TEST_SECRET_KEY');
if (! is_string($secretKey) || $secretKey === '') {
    fwrite(STDERR, "ASLAN_TEST_SECRET_KEY env var not set\n");
    exit(1);
}

// 1. Currency = MAD — gateway hides itself otherwise.
update_option('woocommerce_currency', 'MAD');
update_option('woocommerce_default_country', 'MA');
update_option('woocommerce_allowed_countries', 'specific');
update_option('woocommerce_specific_allowed_countries', ['MA']);
update_option('woocommerce_enable_guest_checkout', 'yes');
update_option('woocommerce_enable_checkout_login_reminder', 'no');

// 2. Mirror key into canonical aslan_woo_* options.
update_option('aslan_woo_mode', 'test');
update_option('aslan_woo_test_secret_key', $secretKey, false);

// 3. Register the key with Aslan so we get a webhook signing secret.
// The mu-plugin at wp-content/mu-plugins/aslan-staging.php defines
// ASLAN_WOO_API_BASE_URL so AslanHttpClient::sdkFactory points at staging.
$config       = new Aslan\Woo\Config();
$keyLifecycle = new Aslan\Woo\Service\KeyLifecycleService(
    static fn (string $key): Aslan\Woo\Client\AslanHttpClient => new Aslan\Woo\Client\AslanHttpClient(
        Aslan\Woo\Client\AslanHttpClient::sdkFactory($key)
    )
);

try {
    $result = $keyLifecycle->register('test', $secretKey, $config->webhookUrl());
    if ($result->signingSecret !== null) {
        update_option('aslan_woo_test_webhook_secret', $result->signingSecret, false);
    }
    update_option('aslan_woo_last_setup', [
        'at'     => time(),
        'events' => [['ok' => true, 'mode' => 'test', 'apiKeyId' => $result->apiKeyId]],
    ]);
    echo "Key registered. apiKeyId={$result->apiKeyId}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Key registration failed: " . $e->getMessage() . "\n");
    exit(2);
}

// Also write into the WC settings blob so the admin UI reflects the same state.
update_option('woocommerce_aslan_settings', [
    'mode'                 => 'test',
    'test_secret_key'      => $secretKey,
    'live_secret_key'      => '',
    'title'                => 'Pay by card, wallet, or cash agent',
    'show_test_banner'     => 'yes',
    'webhook_url_override' => '',
]);

// 4. Create one MAD product (price in whole MAD; stored as "100.00" but the
//    plugin's whole-MAD validator checks `amount % 100 === 0` on centimes).
$existing = get_posts(['post_type' => 'product', 'name' => 'e2e-widget', 'numberposts' => 1]);
if (empty($existing)) {
    $productId = wp_insert_post([
        'post_type'   => 'product',
        'post_status' => 'publish',
        'post_title'  => 'E2E Widget',
        'post_name'   => 'e2e-widget',
    ]);
    wp_set_object_terms($productId, 'simple', 'product_type');
    update_post_meta($productId, '_regular_price', '100');
    update_post_meta($productId, '_price', '100');
    update_post_meta($productId, '_virtual', 'yes');
    update_post_meta($productId, '_manage_stock', 'no');
    update_post_meta($productId, '_stock_status', 'instock');
    echo "Product created: {$productId}\n";
}

// 5. Create shortcode page.
$shortcodePage = get_page_by_path('aslan-pay');
if (! $shortcodePage) {
    wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => 'Aslan Pay',
        'post_name'    => 'aslan-pay',
        'post_content' => '[aslan_pay amount="10000" description="E2E test" button_text="Pay 100 MAD"]',
    ]);
    echo "Shortcode page created at /aslan-pay\n";
}

echo "E2E setup complete.\n";
