<?php

declare(strict_types=1);

namespace Aslan\Woo\Shortcode;

final class PayBlock
{
    public function __construct(private readonly PayShortcode $shortcode)
    {
    }

    public function register(): void
    {
        add_action('init', function (): void {
            wp_register_script(
                'aslan-pay-block',
                ASLAN_WOO_PLUGIN_URL . 'assets/block.js',
                ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor'],
                ASLAN_WOO_VERSION,
                true
            );
            register_block_type('aslan/pay', [
                'editor_script'   => 'aslan-pay-block',
                'render_callback' => fn (array $atts): string => $this->shortcode->render($atts),
                'attributes'      => [
                    'amount'      => ['type' => 'number', 'default' => 0],
                    'description' => ['type' => 'string', 'default' => ''],
                    'button_text' => ['type' => 'string', 'default' => ''],
                    'success_url' => ['type' => 'string', 'default' => ''],
                    'cancel_url'  => ['type' => 'string', 'default' => ''],
                    'reference'   => ['type' => 'string', 'default' => ''],
                ],
            ]);
        });
    }
}
