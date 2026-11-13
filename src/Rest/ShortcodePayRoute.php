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
        $token = (string) $request->get_param('token');
        if ($token === '' || ! preg_match('/^[a-f0-9-]{36}$/i', $token)) {
            return new WP_REST_Response(['error' => 'invalid_token'], 400);
        }

        $stash = get_transient('aslan_pay_' . $token);
        if (! is_array($stash)) {
            // Not found, expired, or already consumed.
            return new WP_REST_Response(['error' => 'token_expired'], 400);
        }

        // Single-use: delete immediately so the token cannot be replayed.
        delete_transient('aslan_pay_' . $token);

        // Attributes come from the server-side stash only, never from the request body.
        $amount      = (int) ($stash['amount'] ?? 0);
        $description = (string) ($stash['description'] ?? '');
        $successUrl  = (string) ($stash['success_url'] ?? home_url('/aslan/thank-you'));
        $cancelUrl   = (string) ($stash['cancel_url'] ?? home_url());
        $reference   = (string) ($stash['reference'] ?? '');

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
                PaymentCpt::META_AMOUNT => $amount,
                '_aslan_description'    => $description,
                '_aslan_success_url'    => $successUrl,
                '_aslan_cancel_url'     => $cancelUrl,
                '_aslan_reference'      => $reference,
                PaymentCpt::META_STATUS => PaymentCpt::STATUS_PENDING,
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
            update_post_meta($cptId, PaymentCpt::META_STATUS, PaymentCpt::STATUS_FAILED);
            return new WP_REST_Response(['error' => 'session_create_failed'], 502);
        }

        return new WP_REST_Response(['redirect' => $session->url], 200);
    }
}
