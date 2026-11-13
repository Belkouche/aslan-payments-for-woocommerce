<?php

declare(strict_types=1);

namespace Aslan\Woo\WooCommerce;

use Aslan\Woo\Config;
use WC_Payment_Gateway;

/**
 * Minimal gateway — registered in Woo, surfaces on checkout, process_payment
 * still returns failure. Phase 6 wires up the real session create.
 */
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- method names are fixed by WC_Payment_Gateway.
final class Gateway extends WC_Payment_Gateway
{
    public const ID = 'aslan';

    public function __construct(private readonly Config $config)
    {
        $this->id                 = self::ID;
        $this->method_title       = __('Aslan', 'aslan-payments-for-woocommerce');
        $this->method_description = __(
            'Accept card, mobile wallet, and cash-agent payments through Aslan.',
            'aslan-payments-for-woocommerce'
        );
        $this->has_fields         = false;
        $this->supports           = ['products', 'refunds'];
        $this->icon               = ASLAN_WOO_PLUGIN_URL . 'assets/logo.svg';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->config->title() !== ''
            ? $this->config->title()
            : __('Pay by card, wallet, or cash agent', 'aslan-payments-for-woocommerce');
        $this->description = '';
        $this->enabled     = $this->config->isConfigured() ? 'yes' : 'no';
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
     * @return array{result: string, messages: string}
     */
    public function process_payment($order_id): array
    {
        // Phase 6 implements this.
        return [
            'result'   => 'failure',
            'messages' => __('Aslan Payments is not yet implemented.', 'aslan-payments-for-woocommerce'),
        ];
    }
}
