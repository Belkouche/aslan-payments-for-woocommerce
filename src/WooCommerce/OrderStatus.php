<?php

declare(strict_types=1);

namespace Aslan\Woo\WooCommerce;

final class OrderStatus
{
    public const AWAITING_CASH = 'aslan-awaiting-cash';

    public function register(): void
    {
        add_action('init', static function (): void {
            register_post_status('wc-' . self::AWAITING_CASH, [
                'label'                     => self::label(),
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: number of orders */
                'label_count'               => _n_noop(
                    'Awaiting cash payment <span class="count">(%s)</span>',
                    'Awaiting cash payment <span class="count">(%s)</span>',
                    'aslan-payments-for-woocommerce'
                ),
            ]);
        });

        add_filter('wc_order_statuses', static function (array $statuses): array {
            $statuses['wc-' . self::AWAITING_CASH] = self::label();
            return $statuses;
        });
    }

    private static function label(): string
    {
        return _x('Awaiting cash payment', 'Order status', 'aslan-payments-for-woocommerce');
    }
}
