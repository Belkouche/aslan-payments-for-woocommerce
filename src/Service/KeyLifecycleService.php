<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

use Aslan\Woo\Client\AslanAuthException;
use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Client\AslanRequestException;
use Closure;

/**
 * Runs when the merchant saves a key:
 *   1. Builds an AslanHttpClient bound to that specific key (not the Config-resolved one)
 *   2. Calls pingApiKeys() — 401 → InvalidKey, success → confirm mode
 *   3. Calls upsertWebhookConfig() — stores the returned signingSecret
 *
 * WordPress-free: the factory closure lets the caller inject a per-key SDK
 * without tying this class to wp_options.
 */
final class KeyLifecycleService
{
    /** @param Closure(string): AslanHttpClient $httpClientFactory */
    public function __construct(private readonly Closure $httpClientFactory)
    {
    }

    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are not HTML output.
    public function register(string $expectedMode, string $secretKey, string $webhookUrl): KeyRegistrationResult
    {
        $client = ($this->httpClientFactory)($secretKey);

        try {
            $apiKeys = $client->pingApiKeys();
        } catch (AslanAuthException $e) {
            throw new InvalidKeyException('Secret key rejected by Aslan', 0, $e);
        }

        $apiKey = $apiKeys[0] ?? null;
        if ($apiKey === null) {
            throw new InvalidKeyException('API key validation returned no identity');
        }
        if ($apiKey->type !== $expectedMode) {
            throw new WrongModeException(
                sprintf('Key is %s but you are setting it as %s.', $apiKey->type, $expectedMode)
            );
        }

        try {
            $webhook = $client->upsertWebhookConfig(['url' => $webhookUrl]);
        } catch (AslanRequestException $e) {
            throw new WebhookRegistrationException(
                'Aslan refused the webhook URL: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if ($webhook->signingSecret === null) {
            throw new WebhookRegistrationException(
                'Aslan did not return a signing secret. Try "Re-register webhook".'
            );
        }

        return new KeyRegistrationResult(
            apiKeyId: $apiKey->id,
            signingSecret: $webhook->signingSecret,
            webhookUrl: $webhook->url,
        );
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
