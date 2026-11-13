<?php

declare(strict_types=1);

namespace Aslan\Woo\Client;

use Aslan\Payment\Aslan;
use Aslan\Payment\AslanException;
use Aslan\Payment\Dto\CheckoutSession;
use Aslan\Payment\Dto\Refund;
use Aslan\Payment\Dto\WebhookConfig;
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
        try {
            return $this->sdk()->checkoutSessions->create($params, $idempotencyKey);
        } catch (AslanException $e) {
            throw $this->map($e); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    public function retrieveCheckoutSession(string $id): CheckoutSession
    {
        try {
            return $this->sdk()->checkoutSessions->retrieve($id);
        } catch (AslanException $e) {
            throw $this->map($e); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function upsertWebhookConfig(array $params, ?string $idempotencyKey = null): WebhookConfig
    {
        try {
            return $this->sdk()->webhooksConfig->create($params, $idempotencyKey);
        } catch (AslanException $e) {
            throw $this->map($e); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    public function retrieveWebhookConfig(): WebhookConfig
    {
        try {
            return $this->sdk()->webhooksConfig->retrieve();
        } catch (AslanException $e) {
            throw $this->map($e); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function createRefund(array $params, ?string $idempotencyKey = null): Refund
    {
        try {
            return $this->sdk()->refunds->create($params, $idempotencyKey);
        } catch (AslanException $e) {
            throw $this->map($e); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
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
        try {
            return array_values($this->sdk()->apiKeys->list()->data);
        } catch (AslanException $e) {
            throw $this->map($e); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    private function map(AslanException $e): AslanWooException
    {
        $status = $e->httpStatus;
        $code = $e->errorCode;
        $reqId = $e->requestId;

        if ($status === 401 || $status === 403) {
            return new AslanAuthException($e->getMessage(), $code, $reqId, $e);
        }
        if ($status >= 500 || $status === 0) {
            return new AslanTransientException($e->getMessage(), $code, $reqId, $e);
        }
        return new AslanRequestException($e->getMessage(), $code, $reqId, $e);
    }
}
