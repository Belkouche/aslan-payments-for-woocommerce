<?php

declare(strict_types=1);

namespace Aslan\Woo\Admin;

use Aslan\Woo\Config;
use Aslan\Woo\Service\KeyLifecycleService;

/**
 * admin-post handlers behind the status card's recovery buttons, plus the
 * one-shot notice they flash (transient `aslan_admin_notice`).
 */
final class TroubleshootingActions
{
    public function __construct(
        private readonly Config $config,
        private readonly KeyLifecycleService $keyLifecycle,
    ) {
    }

    public function register(): void
    {
        add_action('admin_post_aslan_re_register_webhook', [$this, 'reRegister']);
        add_action('admin_post_aslan_test_webhook', [$this, 'sendTestWebhook']);
        add_action('admin_post_aslan_download_log', [$this, 'downloadLog']);
        add_action('admin_notices', [$this, 'showNotice']);
    }

    public function reRegister(): void
    {
        $this->authorize();

        $mode = $this->config->mode();
        $key  = $this->config->activeSecretKey();
        if ($key === '') {
            $this->flash('err', __('No secret key saved for the active mode.', 'aslan-payments-for-woocommerce'));
            $this->back();
        }

        try {
            $result = $this->keyLifecycle->register($mode, $key, $this->config->webhookUrl());
            // Null on a non-rotating re-upsert: keep the stored secret.
            if ($result->signingSecret !== null) {
                update_option(
                    $mode === Config::MODE_LIVE ? Config::OPT_LIVE_WEBHOOK_SECRET : Config::OPT_TEST_WEBHOOK_SECRET,
                    $result->signingSecret,
                    false
                );
            }
            $this->flash('ok', __('Webhook re-registered.', 'aslan-payments-for-woocommerce'));
        } catch (\Throwable $e) {
            $this->flash('err', $e->getMessage());
        }
        $this->back();
    }

    public function sendTestWebhook(): void
    {
        $this->authorize();
        // TODO: call Aslan's "deliver test event" API when available. For v1, explain the manual path.
        $this->flash('ok', __(
            'Open your Aslan dashboard → Webhooks → Send test event.',
            'aslan-payments-for-woocommerce'
        ));
        $this->back();
    }

    public function downloadLog(): void
    {
        $this->authorize();

        $log = get_option('aslan_woo_log', []);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="aslan-diagnostic.log"');
        foreach (is_array($log) ? $log : [] as $row) {
            $at  = is_array($row) && is_scalar($row['at'] ?? null) ? (string) $row['at'] : '';
            $msg = is_array($row) && is_scalar($row['msg'] ?? null) ? (string) $row['msg'] : '';
            $line = str_replace(["\r", "\n"], ' ', trim($at . ' ' . $msg));
            // Plain-text download, not HTML: output escaping does not apply.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $line . "\n";
        }
        exit;
    }

    public function showNotice(): void
    {
        $notice = get_transient('aslan_admin_notice');
        if (! is_array($notice) || ! current_user_can('manage_woocommerce')) {
            return;
        }
        delete_transient('aslan_admin_notice');

        $isError = isset($notice['err']);
        $message = $notice['err'] ?? $notice['ok'] ?? '';
        if (! is_string($message) || $message === '') {
            return;
        }
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($isError ? 'notice-error' : 'notice-success'),
            esc_html($message)
        );
    }

    private function authorize(): void
    {
        check_admin_referer('aslan_woo_admin');
        if (! current_user_can('manage_woocommerce')) {
            wp_die(
                esc_html__('You are not allowed to do this.', 'aslan-payments-for-woocommerce'),
                '',
                ['response' => 403]
            );
        }
    }

    private function flash(string $type, string $message): void
    {
        set_transient('aslan_admin_notice', [$type => $message], 30);
    }

    private function back(): never
    {
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
