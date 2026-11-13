<?php

declare(strict_types=1);

namespace Aslan\Woo\Client;

use Aslan\Payment\Aslan;
use Aslan\Payment\AslanException;
use Aslan\Payment\Dto\CheckoutSession;
use Aslan\Payment\Dto\Refund;
use Aslan\Payment\Dto\WebhookConfig;
use Aslan\Payment\HttpClient\HttpClientInterface;
use Closure;

/**
 * Thin, WordPress-free wrapper over aslan/payment-php. One instance per mode.
 *
 * Factory closure defers SDK construction until first use so tests can inject
 * an SDK wired to a fake HTTP client and the Plugin class can wire up the SDK
 * once Config is ready. The factory must build the SDK with maxRetries: 0 —
 * this class owns connection retries.
 */
final class AslanHttpClient
{
    private ?Aslan $sdk = null;

    /**
     * Build an SDK-factory closure guaranteed to disable the SDK's own retry.
     * AslanHttpClient owns connection retries; the SDK's built-in retry would
     * compound ours (3 * 3 = 9 attempts on a NETWORK_ERROR, several seconds wasted).
     *
     * Use this instead of hand-rolling `new Aslan($key)` in Plugin::register().
     */
    public static function sdkFactory(string $secretKey, ?HttpClientInterface $httpClient = null): Closure
    {
        return static fn (): Aslan => new Aslan(
            $secretKey,
            maxRetries: 0,
            httpClient: $httpClient,
        );
    }

    /** @var array<int, int> milliseconds to sleep between attempts */
    private array $backoff = [300, 900];

    /**
     * @internal Test-only seam. Production callers must not touch this —
     * backoff is set from the default schedule for reliability.
     * @see tests/Unit/Client/AslanHttpClientTest.php
     * @param array<int, int> $backoff
     */
    public function setBackoffSchedule(array $backoff): void
    {
        $this->backoff = $backoff;
    }

    /**
     * Retry only connection failures (no HTTP response); everything else is mapped and thrown.
     *
     * @template T
     * @param Closure(): T $op
     * @return T
     */
    private function withConnectionRetry(Closure $op): mixed
    {
        $attempts = 0;
        $maxAttempts = count($this->backoff) + 1;
        while (true) {
            try {
                return $op();
            } catch (AslanException $e) {
                $isConnectionFailure = $e->errorCode === 'NETWORK_ERROR';
                $attempts++;
                if (! $isConnectionFailure || $attempts >= $maxAttempts) {
                    throw $this->map($e); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }
                $sleep = $this->backoff[$attempts - 1] ?? 0;
                if ($sleep > 0) {
                    usleep($sleep * 1000);
                }
            }
        }
    }

    /** @param Closure(): Aslan $sdkFactory */
    public function __construct(private readonly Closure $sdkFactory)
    {
    }

    private function sdk(): Aslan
    {
        if ($this->sdk === null) {
            $this->sdk = ($this->sdkFactory)();
        }
        return $this->sdk;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function createCheckoutSession(array $params, ?string $idempotencyKey = null): CheckoutSession
    {
        return $this->withConnectionRetry(
            fn () => $this->sdk()->checkoutSessions->create($params, $idempotencyKey)
        );
    }

    public function retrieveCheckoutSession(string $id): CheckoutSession
    {
        return $this->withConnectionRetry(
            fn () => $this->sdk()->checkoutSessions->retrieve($id)
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public function upsertWebhookConfig(array $params, ?string $idempotencyKey = null): WebhookConfig
    {
        return $this->withConnectionRetry(
            fn () => $this->sdk()->webhooksConfig->create($params, $idempotencyKey)
        );
    }

    public function retrieveWebhookConfig(): WebhookConfig
    {
        return $this->withConnectionRetry(
            fn () => $this->sdk()->webhooksConfig->retrieve()
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public function createRefund(array $params, ?string $idempotencyKey = null): Refund
    {
        return $this->withConnectionRetry(
            fn () => $this->sdk()->refunds->create($params, $idempotencyKey)
        );
    }

    /**
     * Validate a secret key by calling GET /api-keys and return the list payload.
     * Succeeds for any valid key, 401s for an invalid one. Doubles as "what merchant
     * is this key for" — the ApiKey DTO carries a merchantId field (see SDK).
     *
     * @return list<\Aslan\Payment\Dto\ApiKey>
     */
    public function pingApiKeys(): array
    {
        return $this->withConnectionRetry(
            fn () => array_values($this->sdk()->apiKeys->list()->data)
        );
    }

    private function map(AslanException $e): AslanWooException
    {
        $status = $e->httpStatus;
        $code = $e->errorCode;
        $reqId = $e->requestId;

        if ($status === 401 || $status === 403) {
            return new AslanAuthException($e->getMessage(), $code, $reqId, $e);
        }
        // Only true network failures are transient — status 0 alone is not enough,
        // because the SDK also returns status 0 for webhook-signature and
        // unsupported-operation errors which are not retryable and not transient.
        if ($status >= 500 || $code === 'NETWORK_ERROR') {
            return new AslanTransientException($e->getMessage(), $code, $reqId, $e);
        }
        return new AslanRequestException($e->getMessage(), $code, $reqId, $e);
    }
}
