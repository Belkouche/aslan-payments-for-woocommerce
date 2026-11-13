<?php

declare(strict_types=1);

namespace Aslan\Woo\WooCommerce;

use Aslan\Woo\Client\AslanAuthException;
use Aslan\Woo\Client\AslanTransientException;
use Aslan\Woo\Client\AslanWooException;
use Aslan\Woo\Config;
use Aslan\Woo\Service\CheckoutService;
use Aslan\Woo\Service\KeyLifecycleService;
use Aslan\Woo\Service\NonWholeMadException;
use Aslan\Woo\Service\RefundNotPossibleException;
use Aslan\Woo\Service\RefundService;
use WC_Order;
use WC_Payment_Gateway;
use WP_Error;

/**
 * Gateway — registered in Woo, surfaces on checkout, and process_payment
 * creates an Aslan checkout session and redirects the customer to it.
 */
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- method names are fixed by WC_Payment_Gateway.
final class Gateway extends WC_Payment_Gateway
{
    public const ID = 'aslan';

    public function __construct(
        private readonly Config $config,
        private readonly CheckoutService $checkoutService,
        private readonly RefundService $refundService,
        private readonly KeyLifecycleService $keyLifecycle,
    ) {
        $this->id                 = self::ID;
        $this->method_title       = __('Aslan', 'aslan-payments-for-woocommerce');
        $this->method_description = __(
            'Accept card, mobile wallet, and cash-agent payments through Aslan.',
            'aslan-payments-for-woocommerce'
        );
        $this->has_fields         = false;
        $this->supports           = ['products', 'refunds'];
        $logoPath                 = ASLAN_WOO_PLUGIN_DIR . 'assets/logo.svg';
        $this->icon               = file_exists($logoPath) ? ASLAN_WOO_PLUGIN_URL . 'assets/logo.svg' : '';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->config->title() !== ''
            ? $this->config->title()
            : __('Pay by card, wallet, or cash agent', 'aslan-payments-for-woocommerce');
        $this->description = '';
        // Note: we deliberately do NOT write $this->enabled here, and there is no
        // `enabled` form field. Checkout availability is gated by is_available(),
        // which reads our Config.

        add_action('woocommerce_update_options_payment_gateways_' . self::ID, [$this, 'persist_bridged_options']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'mode' => [
                'title'   => __('Mode', 'aslan-payments-for-woocommerce'),
                'type'    => 'select',
                'default' => 'test',
                'options' => [
                    'test' => __('Test — no real money moves', 'aslan-payments-for-woocommerce'),
                    'live' => __('Live — real payments', 'aslan-payments-for-woocommerce'),
                ],
            ],
            'test_secret_key' => [
                'title'       => __('Test secret key', 'aslan-payments-for-woocommerce'),
                'type'        => 'password',
                'description' => __(
                    'Starts with sk_test_. From your Aslan dashboard → Developers → API keys.',
                    'aslan-payments-for-woocommerce'
                ),
                'desc_tip'    => true,
            ],
            'live_secret_key' => [
                'title'       => __('Live secret key', 'aslan-payments-for-woocommerce'),
                'type'        => 'password',
                'description' => __(
                    'Starts with sk_live_. From your Aslan dashboard → Developers → API keys.',
                    'aslan-payments-for-woocommerce'
                ),
                'desc_tip'    => true,
            ],
            'title' => [
                'title'   => __('Title shown to customers', 'aslan-payments-for-woocommerce'),
                'type'    => 'text',
                'default' => __('Pay by card, wallet, or cash agent', 'aslan-payments-for-woocommerce'),
            ],
            'show_test_banner' => [
                'title'   => __('Checkout display', 'aslan-payments-for-woocommerce'),
                'label'   => __(
                    'Show "Test Mode" banner on checkout when in test mode',
                    'aslan-payments-for-woocommerce'
                ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            'webhook_url_override' => [
                'title'       => __('Webhook URL override', 'aslan-payments-for-woocommerce'),
                'type'        => 'text',
                'default'     => '',
                'description' => __(
                    // phpcs:ignore Generic.Files.LineLength.TooLong -- i18n requires a single string literal.
                    'Advanced. Leave empty unless your WordPress public URL differs from where Aslan should POST (e.g. behind a tunnel). If filled, this exact URL is registered with Aslan.',
                    'aslan-payments-for-woocommerce'
                ),
                'desc_tip'    => true,
            ],
        ];
    }

    /**
     * Mirrors the settings blob WooCommerce just saved into our canonical
     * aslan_woo_* options, then registers each changed key with Aslan.
     */
    public function persist_bridged_options(): void
    {
        $settings = get_option('woocommerce_' . self::ID . '_settings', []);
        if (! is_array($settings)) {
            $settings = [];
        }

        $previousUrl  = $this->config->webhookUrl();
        $previousKeys = [
            Config::MODE_TEST => $this->rawOption(Config::OPT_TEST_KEY),
            Config::MODE_LIVE => $this->rawOption(Config::OPT_LIVE_KEY),
        ];

        update_option(Config::OPT_MODE, $this->setting($settings, 'mode', 'test'));
        update_option(Config::OPT_TEST_KEY, $this->setting($settings, 'test_secret_key'));
        update_option(Config::OPT_LIVE_KEY, $this->setting($settings, 'live_secret_key'));
        update_option(Config::OPT_TITLE, $this->setting($settings, 'title'));
        update_option(Config::OPT_SHOW_TEST_BANNER, $this->setting($settings, 'show_test_banner', 'yes') === 'yes');
        update_option(Config::OPT_WEBHOOK_URL_OVERRIDE, $this->setting($settings, 'webhook_url_override'));

        $siteWebhookUrl = $this->config->webhookUrl();
        $secretOptions  = [
            Config::MODE_TEST => Config::OPT_TEST_WEBHOOK_SECRET,
            Config::MODE_LIVE => Config::OPT_LIVE_WEBHOOK_SECRET,
        ];

        $events = [];
        foreach ([Config::MODE_TEST => 'test_secret_key', Config::MODE_LIVE => 'live_secret_key'] as $mode => $field) {
            $key = trim($this->setting($settings, $field));
            if ($key === '' || $this->looksMasked($key)) {
                continue;
            }
            // Unchanged key + unchanged URL + secret already stored: nothing to re-register.
            if (
                $key === $previousKeys[$mode]
                && $siteWebhookUrl === $previousUrl
                && $this->rawOption($secretOptions[$mode]) !== ''
            ) {
                continue;
            }
            try {
                $result = $this->keyLifecycle->register($mode, $key, $siteWebhookUrl);
                // Null on a non-rotating re-upsert: keep the stored secret.
                if ($result->signingSecret !== null) {
                    update_option($secretOptions[$mode], $result->signingSecret);
                }
                $events[$mode] = ['ok' => true, 'mode' => $mode, 'apiKeyId' => $result->apiKeyId];
            } catch (\Throwable $e) {
                $events[$mode] = ['ok' => false, 'mode' => $mode, 'error' => $e->getMessage()];
            }
        }

        if ($events !== []) {
            // Keep the previous outcome for a mode that was not touched by this save.
            $previous = get_option('aslan_woo_last_setup', []);
            foreach (is_array($previous) ? ($previous['events'] ?? []) : [] as $evt) {
                if (is_array($evt) && isset($evt['mode']) && ! isset($events[$evt['mode']])) {
                    $events[$evt['mode']] = $evt;
                }
            }
            update_option('aslan_woo_last_setup', ['at' => time(), 'events' => array_values($events)]);
        }
    }

    /** @param array<mixed> $settings */
    private function setting(array $settings, string $name, string $default = ''): string
    {
        $v = $settings[$name] ?? $default;
        return is_string($v) ? $v : $default;
    }

    private function rawOption(string $name): string
    {
        $v = get_option($name, '');
        return is_string($v) ? $v : '';
    }

    private function looksMasked(string $key): bool
    {
        return str_contains($key, '••') || str_contains($key, '●') || str_contains($key, '****');
    }

    public function is_available(): bool
    {
        if (function_exists('get_woocommerce_currency') && get_woocommerce_currency() !== 'MAD') {
            return false;
        }
        return $this->config->isConfigured();
    }

    /**
     * @param int $order_id
     * @return array{result: string, messages?: string, redirect?: string}
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order) {
            return [
                'result'   => 'failure',
                'messages' => __('Order not found.', 'aslan-payments-for-woocommerce'),
            ];
        }

        if ($order->get_currency() !== 'MAD') {
            $order->add_order_note(sprintf(
                'Aslan refused: order currency is %s, only MAD is supported',
                $order->get_currency()
            ));
            wc_add_notice(
                __('Aslan only accepts orders in MAD. Please contact the merchant.', 'aslan-payments-for-woocommerce'),
                'error'
            );
            return ['result' => 'failure'];
        }

        try {
            $result = $this->checkoutService->createSessionForOrder($order);
        } catch (NonWholeMadException) {
            wc_add_notice(
                __(
                    'Aslan accepts whole MAD amounts only. Please remove decimals from product prices or taxes.',
                    'aslan-payments-for-woocommerce'
                ),
                'error'
            );
            return ['result' => 'failure'];
        } catch (AslanTransientException $e) {
            $order->add_order_note(
                sprintf('Aslan transient error: %s (req %s)', $e->getMessage(), $e->aslanRequestId ?? 'n/a')
            );
            wc_add_notice(
                __(
                    'Payment service is temporarily unavailable. Please try again in a moment.',
                    'aslan-payments-for-woocommerce'
                ),
                'error'
            );
            return ['result' => 'failure'];
        } catch (AslanAuthException $e) {
            $order->add_order_note(sprintf('Aslan auth error (merchant key invalid): %s', $e->getMessage()));
            // TODO(Phase 12): raise persistent admin notice for configuration errors
            wc_add_notice(
                __(
                    'This payment method is temporarily unavailable. Please choose another method.',
                    'aslan-payments-for-woocommerce'
                ),
                'error'
            );
            return ['result' => 'failure'];
        // TODO(Phase 12): raise persistent admin notice
        } catch (AslanWooException $e) {
            $order->add_order_note(sprintf('Aslan error: %s', $e->getMessage()));
            wc_add_notice(
                __(
                    'Payment could not be started. Please try again or choose another method.',
                    'aslan-payments-for-woocommerce'
                ),
                'error'
            );
            return ['result' => 'failure'];
        }

        $order->update_status('pending', __('Awaiting Aslan payment', 'aslan-payments-for-woocommerce'));

        return [
            'result'   => 'success',
            'redirect' => $result->redirectUrl,
        ];
    }

    /**
     * @param int         $order_id
     * @param float|string|null $amount WooCommerce passes a decimal string (e.g. '100.00').
     * @param string      $reason
     * @return bool|WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof WC_Order || $amount === null) {
            return new WP_Error(
                'invalid',
                __('Refund failed: invalid order or amount.', 'aslan-payments-for-woocommerce')
            );
        }

        try {
            $refundId = $this->refundService->refund($order, (string) $amount, (string) $reason);
        } catch (NonWholeMadException) {
            return new WP_Error(
                'whole_mad',
                __('Refund amounts must be whole MAD.', 'aslan-payments-for-woocommerce')
            );
        } catch (RefundNotPossibleException $e) {
            return new WP_Error('no_payment', $e->getMessage());
        } catch (AslanWooException $e) {
            return new WP_Error('aslan_error', $e->getMessage());
        }

        $order->add_order_note(sprintf('Aslan refund initiated (%s).', $refundId));
        return true;
    }
}
