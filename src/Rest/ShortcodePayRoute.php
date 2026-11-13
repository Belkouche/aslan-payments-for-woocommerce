<?php

declare(strict_types=1);

namespace Aslan\Woo\Rest;

use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Client\AslanWooException;
use Aslan\Woo\Service\AmountValidator;
use Aslan\Woo\Service\NonWholeMadException;
use Aslan\Woo\Shortcode\PaymentCpt;
use WP_REST_Request;
use WP_REST_Response;

final class ShortcodePayRoute
{
    public function __construct(private readonly AslanHttpClient $client)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('aslan/v1', '/shortcode/pay', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => static function (WP_REST_Request $r): bool {
                    return wp_verify_nonce((string) $r->get_header('x_wp_nonce'), 'wp_rest') !== false;
                },
            ]);
        });
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $amount      = (int) $request->get_param('amount');
        $description = sanitize_text_field((string) $request->get_param('description'));
        $reference   = sanitize_text_field((string) $request->get_param('reference'));
        // Redirect targets must stay on this site; anything else falls back to the default.
        $successUrl = $this->localUrl((string) $request->get_param('success_url'), home_url('/aslan/thank-you'));
        $cancelUrl  = $this->localUrl((string) $request->get_param('cancel_url'), home_url());

        try {
            AmountValidator::assertWholeMadCentimes($amount);
        } catch (NonWholeMadException $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 400);
        }

        $cptId = wp_insert_post([
            'post_type'   => PaymentCpt::TYPE,
            'post_status' => 'publish',
            'post_title'  => $description !== '' ? $description : sprintf('Aslan payment %d centimes', $amount),
            'meta_input'  => [
                'amount'      => $amount,
                'description' => $description,
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
                'reference'   => $reference,
                'status'      => PaymentCpt::STATUS_PENDING,
            ],
        ]);
        if (! $cptId) {
            return new WP_REST_Response(['error' => 'cpt_create_failed'], 500);
        }

        try {
            $session = $this->client->createCheckoutSession(
                [
                    'amount'     => $amount,
                    'currency'   => 'MAD',
                    'successUrl' => $successUrl,
                    'cancelUrl'  => $cancelUrl,
                    'metadata'   => [
                        'aslan_payment_id' => (string) $cptId,
                        'reference'        => $reference,
                    ],
                ],
                sprintf('shortcode_%d', $cptId)
            );
        } catch (AslanWooException) {
            update_post_meta($cptId, 'status', PaymentCpt::STATUS_FAILED);
            return new WP_REST_Response(['error' => 'session_create_failed'], 502);
        }

        return new WP_REST_Response(['redirect' => $session->url], 200);
    }

    private function localUrl(string $candidate, string $fallback): string
    {
        $candidate = esc_url_raw($candidate);
        return $candidate === '' ? $fallback : wp_validate_redirect($candidate, $fallback);
    }
}
