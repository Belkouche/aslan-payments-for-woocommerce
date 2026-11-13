<?php

declare(strict_types=1);

namespace Aslan\Woo\Shortcode;

final class PaymentCpt
{
    public const TYPE = 'aslan_payment';

    public const STATUS_PENDING      = 'pending';
    public const STATUS_SUCCEEDED    = 'succeeded';
    public const STATUS_FAILED       = 'failed';
    public const STATUS_PENDING_CASH = 'pending_cash';

    public const META_PAYMENT_ID = 'aslan_payment_ref';

    public function register(): void
    {
        add_action('init', static function (): void {
            register_post_type(self::TYPE, [
                'public'              => false,
                'show_ui'             => false,
                'show_in_rest'        => false,
                'exclude_from_search' => true,
                'publicly_queryable'  => false,
                'supports'            => ['title', 'custom-fields'],
            ]);
        });
    }
}
