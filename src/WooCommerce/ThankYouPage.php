<?php

declare(strict_types=1);

namespace Aslan\Woo\WooCommerce;

use WC_Order;

final class ThankYouPage
{
    public function register(): void
    {
        add_action('woocommerce_thankyou_' . Gateway::ID, [$this, 'render']);
    }

    public function render(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            return;
        }
        if ($order->is_paid()) {
            return;
        }
        if ($order->get_status() === 'aslan-awaiting-cash') {
            echo '<p class="aslan-notice">';
            echo esc_html__(
                'Your order is reserved. Pay at any cash agent to confirm it.',
                'aslan-payments-for-woocommerce'
            );
            echo '</p>';
            return;
        }

        $nonce = (string) wp_create_nonce('wp_rest');
        $url   = (string) add_query_arg(
            ['key' => (string) $order->get_order_key()],
            rest_url('aslan/v1/order-status/' . $orderId)
        );

        echo '<div id="aslan-await" data-url="' . esc_attr($url) . '"';
        echo ' data-nonce="' . esc_attr($nonce) . '">';
        echo '<p>' . esc_html__('Confirming your payment…', 'aslan-payments-for-woocommerce') . '</p>';
        echo '</div>';
        echo '<script>
            (function(){
                var el=document.getElementById("aslan-await");
                if(!el)return;
                var deadline=Date.now()+10000;
                function tick(){
                    fetch(el.dataset.url,{headers:{"X-WP-Nonce":el.dataset.nonce}})
                        .then(function(r){return r.json();})
                        .then(function(j){
                            if(j.paid){location.reload();return;}
                            if(Date.now()<deadline){setTimeout(tick,1000);return;}
                            el.innerHTML="<p>';
        echo esc_js(
            __('Still processing. We will email you a confirmation shortly.', 'aslan-payments-for-woocommerce')
        );
        echo '</p>";
                        })
                        .catch(function(){if(Date.now()<deadline){setTimeout(tick,1500);}});
                }
                setTimeout(tick,500);
            })();
        </script>';
    }
}
