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
        $secretKey = trim($secretKey);
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

        // signingSecret is null on a non-rotating re-upsert. Caller must keep the
        // previously stored secret unchanged when that happens.
        return new KeyRegistrationResult(
            apiKeyId: $apiKey->id,
            signingSecret: $webhook->signingSecret,
            webhookUrl: $webhook->url,
        );
    }
    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

    /**
     * Pick the ApiKey whose prefix matches the pasted secret.
     *
     * The server's current key format is `sk_<type>_<prefix><secret>` with the
     * 8-char hex prefix joined directly to the secret body (3 underscore parts).
     * The legacy split-key form `sk_<type>_<prefix>_<secret>` (4 parts) is
     * accepted too for stores that stored keys issued under the older scheme.
     *
     * Assumes the Aslan API guarantees unique prefixes across a merchant's
     * active keys. If that invariant ever changes server-side, we'd silently
     * pick the first match.
     *
     * @param list<\Aslan\Payment\Dto\ApiKey> $apiKeys
     */
    private function findKeyByPrefix(array $apiKeys, string $secretKey): ?\Aslan\Payment\Dto\ApiKey
    {
        $parts = explode('_', $secretKey);
        if (count($parts) < 3) {
            return null;
        }
        // Legacy split-key (4+ parts): prefix is the third underscore-separated field.
        // Current format (3 parts): first 8 chars of the hex body is the prefix.
        $pastedPrefix = count($parts) >= 4 ? $parts[2] : substr($parts[2], 0, 8);
        if ($pastedPrefix === '') {
            return null;
        }
        foreach ($apiKeys as $key) {
            if ($key->prefix === $pastedPrefix) {
                return $key;
            }
        }
        return null;
    }
}
