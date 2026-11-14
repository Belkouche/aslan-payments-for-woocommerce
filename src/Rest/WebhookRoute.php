<?php

declare(strict_types=1);

namespace Aslan\Woo\Rest;

use Aslan\Woo\Config;
use Aslan\Woo\Service\WebhookService;
use Aslan\Woo\Service\WebhookVerificationException;
use WP_REST_Request;
use WP_REST_Response;

final class WebhookRoute
{
    /** Aslan webhook bodies are well under 64KB; anything larger is rejected before HMAC work. */
    public const MAX_BODY_BYTES = 65536;

    /** 48h: longer than Aslan's retry budget (hours), short enough to keep the transient table bounded. */
    public const IDEMPOTENCY_TTL_SECONDS = 172800;

    public function __construct(
        private readonly Config $config,
        private readonly WebhookService $service,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('aslan/v1', '/webhook', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $rawBody = $request->get_body();
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            // Stop before HMAC, JSON-decode, or any DB write.
            return new WP_REST_Response(['error' => 'payload_too_large'], 413);
        }
        $sigHeader = (string) $request->get_header('x_webhook_signature');
        $secret    = $this->config->activeWebhookSecret();
        if ($secret === '') {
            $resp = new WP_REST_Response(['error' => 'not_configured'], 503);
            $resp->header('Retry-After', '3600');
            return $resp;
        }

        try {
            $event = $this->service->verify($rawBody, $sigHeader, $secret);
        } catch (WebhookVerificationException) {
            return new WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        $this->service->handle(
            $event,
            orderLoader: static function (int $id): ?\WC_Order {
                $order = wc_get_order($id);
                return $order instanceof \WC_Order ? $order : null;
            },
            idempotencyRecorder: static function (string $eventId): bool {
                $key = 'aslan_evt_' . $eventId;
                if (get_transient($key) !== false) {
                    return false;
                }
                set_transient($key, 1, self::IDEMPOTENCY_TTL_SECONDS);
                return true;
            },
        );

        return new WP_REST_Response(['ok' => true], 200);
    }
}
