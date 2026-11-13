<?php

declare(strict_types=1);

namespace Aslan\Woo\Admin;

use Aslan\Woo\Config;

/**
 * Renders the setup status and recovery buttons beneath the Aslan settings form.
 * Reads the outcome of the last key registration (aslan_woo_last_setup).
 */
final class SetupStatusCard
{
    public function __construct(private readonly Config $config)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_settings_checkout', [$this, 'maybeRender'], 20);
    }

    public function maybeRender(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, no state change.
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        if ($section !== 'aslan') {
            return;
        }
        $setup = get_option('aslan_woo_last_setup', null);
        if (! is_array($setup)) {
            return;
        }

        echo '<h3>' . esc_html__('Setup status', 'aslan-payments-for-woocommerce') . '</h3>';
        echo '<table class="widefat striped"><tbody>';

        $events = is_array($setup['events'] ?? null) ? $setup['events'] : [];
        foreach ($events as $evt) {
            if (! is_array($evt)) {
                continue;
            }
            $ok   = ! empty($evt['ok']);
            $mode = is_string($evt['mode'] ?? null) ? $evt['mode'] : '';
            if ($ok) {
                $apiKeyId = is_string($evt['apiKeyId'] ?? null) ? $evt['apiKeyId'] : '';
                $detail   = $apiKeyId !== ''
                    /* translators: %s: Aslan API key id */
                    ? sprintf(__('verified — API key %s', 'aslan-payments-for-woocommerce'), $apiKeyId)
                    : __('verified', 'aslan-payments-for-woocommerce');
            } else {
                $detail = is_string($evt['error'] ?? null) ? $evt['error'] : '';
            }
            printf(
                '<tr><td>%s %s</td><td>%s</td></tr>',
                esc_html($ok ? '✓' : '✗'),
                esc_html(sprintf(
                    /* translators: %s: key mode (test or live) */
                    __('%s key', 'aslan-payments-for-woocommerce'),
                    $mode
                )),
                esc_html($detail)
            );
        }

        $override = trim($this->config->webhookUrlOverride());
        printf(
            '<tr><td>%s</td><td><code>%s</code>%s</td></tr>',
            esc_html('✓ ' . __('Webhook endpoint', 'aslan-payments-for-woocommerce')),
            esc_html($this->config->webhookUrl()),
            $override !== '' ? ' <em>' . esc_html__('(override)', 'aslan-payments-for-woocommerce') . '</em>' : ''
        );

        $hasSecret = $this->config->activeWebhookSecret() !== '';
        printf(
            '<tr><td>%s</td><td><em>%s</em></td></tr>',
            esc_html(
                ($hasSecret ? '✓ ' : '✗ ') . __('Webhook signing secret stored', 'aslan-payments-for-woocommerce')
            ),
            esc_html__('(never shown)', 'aslan-payments-for-woocommerce')
        );
        echo '</tbody></table>';

        $this->renderButtons();
    }

    private function renderButtons(): void
    {
        $buttons = [
            'aslan_re_register_webhook' => __('Re-register webhook', 'aslan-payments-for-woocommerce'),
            'aslan_test_webhook'        => __('Send test webhook', 'aslan-payments-for-woocommerce'),
            'aslan_download_log'        => __('Download diagnostic log', 'aslan-payments-for-woocommerce'),
        ];

        // WooCommerce wraps this page in its own <form> and forms cannot nest, so the buttons
        // point (via the `form` attribute) at a form printed in the footer, outside it.
        echo '<p>';
        foreach ($buttons as $action => $label) {
            printf(
                '<button type="submit" class="button" name="action" value="%s" form="aslan-admin-actions">%s</button> ',
                esc_attr($action),
                esc_html($label)
            );
        }
        echo '</p>';

        add_action('admin_footer', [$this, 'renderActionsForm']);
    }

    public function renderActionsForm(): void
    {
        echo '<form id="aslan-admin-actions" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('aslan_woo_admin');
        echo '</form>';
    }
}
