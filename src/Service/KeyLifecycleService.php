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

        $apiKey = $this->findKeyByPrefix($apiKeys, $secretKey);
        if ($apiKey === null) {
            throw new InvalidKeyException(
                'API key listing did not contain the key being registered — unexpected SDK state'
            );
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

    /**
     * Pick the ApiKey whose prefix matches the secret the merchant pasted.
     * Secret key format is sk_{type}_{prefix}_{secret}.
     *
     * @param list<\Aslan\Payment\Dto\ApiKey> $apiKeys
     */
    private function findKeyByPrefix(array $apiKeys, string $secretKey): ?\Aslan\Payment\Dto\ApiKey
    {
        $parts = explode('_', $secretKey);
        if (count($parts) < 4) {
            return null;
        }
        $pastedPrefix = $parts[2];
        foreach ($apiKeys as $key) {
            if ($key->prefix === $pastedPrefix) {
                return $key;
            }
        }
        return null;
    }
}
