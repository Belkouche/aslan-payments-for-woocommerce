<?php

declare(strict_types=1);

namespace Aslan\Woo\WooCommerce;

use Aslan\Woo\Client\AslanTransientException;
use Aslan\Woo\Client\AslanWooException;
use Aslan\Woo\Config;
use Aslan\Woo\Service\CheckoutService;
use Aslan\Woo\Service\NonWholeMadException;
use WC_Order;
use WC_Payment_Gateway;

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
        // Note: we deliberately do NOT write $this->enabled here.
        // Enabled is a merchant-controlled setting stored in woocommerce_aslan_settings;
        // Phase 12 wires it up as a form_field checkbox. Checkout availability is
        // gated by is_available() which reads our Config.
    }

    public function init_form_fields(): void
    {
        // Phase 12 fills this in. Minimal skeleton only.
        $this->form_fields = [];
    }

    public function is_available(): bool
    {
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
}
