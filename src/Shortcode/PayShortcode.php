<?php

declare(strict_types=1);

namespace Aslan\Woo\Shortcode;

final class PayShortcode
{
    public function register(): void
    {
        add_shortcode('aslan_pay', [$this, 'render']);
    }

    /**
     * @param array<string, mixed>|string $atts Shortcode attributes (WP passes '' when none are given).
     */
    public function render(array|string $atts = []): string
    {
        $atts = shortcode_atts([
            'amount'      => 0,
            'description' => '',
            'success_url' => '',
            'cancel_url'  => '',
            'button_text' => '',
            'reference'   => '',
        ], is_array($atts) ? $atts : [], 'aslan_pay');

        $amount = (int) $atts['amount'];
        if ($amount <= 0 || $amount % 100 !== 0) {
            if (current_user_can('edit_posts')) {
                return '<em>' . esc_html__(
                    '[aslan_pay: amount must be a positive multiple of 100 centimes]',
                    'aslan-payments-for-woocommerce'
                ) . '</em>';
            }
            return '';
        }

        $label = (string) $atts['button_text'] !== ''
            ? (string) $atts['button_text']
            /* translators: %d: amount in MAD */
            : sprintf(__('Pay %d MAD', 'aslan-payments-for-woocommerce'), intdiv($amount, 100));

        // Authoritative attributes live server-side; the page only carries an opaque single-use token.
        $token = wp_generate_uuid4();
        set_transient('aslan_pay_' . $token, [
            'amount'      => $amount,
            'description' => sanitize_text_field((string) $atts['description']),
            'success_url' => $this->localUrl((string) $atts['success_url'], home_url('/aslan/thank-you')),
            'cancel_url'  => $this->localUrl((string) $atts['cancel_url'], home_url()),
            'reference'   => sanitize_text_field((string) $atts['reference']),
        ], 600);

        ob_start();
        ?>
        <button
            type="button"
            class="aslan-pay-btn"
            data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
            data-url="<?php echo esc_url(rest_url('aslan/v1/shortcode/pay')); ?>"
            data-token="<?php echo esc_attr($token); ?>"
        >
            <?php echo esc_html($label); ?>
        </button>
        <script>
        (function(){
            document.querySelectorAll('.aslan-pay-btn:not([data-aslan-bound])').forEach(function(btn){
                btn.setAttribute('data-aslan-bound','1');
                btn.addEventListener('click',function(){
                    btn.disabled=true;
                    fetch(btn.dataset.url,{
                        method:'POST',
                        headers:{'Content-Type':'application/json','X-WP-Nonce':btn.dataset.nonce},
                        body:JSON.stringify({token:btn.dataset.token})
                    })
                    .then(function(r){return r.json();})
                    .then(function(j){ if(j.redirect){location.href=j.redirect;}else{btn.disabled=false;} })
                    .catch(function(){ btn.disabled=false; });
                });
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /** Redirect targets must stay on this site; anything else falls back to the default. */
    private function localUrl(string $candidate, string $fallback): string
    {
        $candidate = esc_url_raw($candidate);
        return $candidate === '' ? $fallback : wp_validate_redirect($candidate, $fallback);
    }
}
