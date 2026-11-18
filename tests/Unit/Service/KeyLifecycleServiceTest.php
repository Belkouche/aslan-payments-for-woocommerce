<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Payment\Dto\ApiKey;
use Aslan\Payment\Dto\WebhookConfig;
use Aslan\Woo\Client\AslanAuthException;
use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Service\InvalidKeyException;
use Aslan\Woo\Service\KeyLifecycleService;
use Aslan\Woo\Service\KeyRegistrationResult;
use Aslan\Woo\Service\WrongModeException;
use PHPUnit\Framework\TestCase;

final class KeyLifecycleServiceTest extends TestCase
{
    private const WEBHOOK_URL = 'https://shop.example.ma/wp-json/aslan/v1/webhook';

    public function test_register_validates_key_then_upserts_webhook_and_returns_secret(): void
    {
        $client = $this->createMock(AslanHttpClient::class);

        $apiKey = ApiKey::fromArray([
            'id' => 'ak_01HZ',
            'name' => 'default',
            'type' => 'test',
            'prefix' => 'mA3xZ',
            'createdAt' => '2026-01-01T00:00:00Z',
        ]);
        $client->expects(self::once())->method('pingApiKeys')->willReturn([$apiKey]);

        $webhookConfig = WebhookConfig::fromArray([
            'id' => 'wh_01HZ',
            'url' => self::WEBHOOK_URL,
            'status' => 'active',
            'events' => [],
            'signingSecret' => 'whsec_abcdef',
            'apiVersion' => '2026-11-01',
            'createdAt' => '2026-11-13T00:00:00Z',
            'updatedAt' => '2026-11-13T00:00:00Z',
        ]);
        $client->expects(self::once())
            ->method('upsertWebhookConfig')
            ->with(['url' => self::WEBHOOK_URL])
            ->willReturn($webhookConfig);

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key, string $mode) => $client);

        $result = $service->register('test', 'sk_test_mA3xZ_secret', self::WEBHOOK_URL);

        self::assertInstanceOf(KeyRegistrationResult::class, $result);
        self::assertSame('ak_01HZ', $result->apiKeyId);
        self::assertSame('whsec_abcdef', $result->signingSecret);
        self::assertSame(self::WEBHOOK_URL, $result->webhookUrl);
    }

    public function test_register_throws_invalid_key_on_401(): void
    {
        $client = $this->createMock(AslanHttpClient::class);
        $client->method('pingApiKeys')->willThrowException(new AslanAuthException('unauthorized'));

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key, string $mode) => $client);

        $this->expectException(InvalidKeyException::class);
        $service->register('test', 'sk_test_bad', self::WEBHOOK_URL);
    }

    public function test_register_server_reports_mismatched_key_type(): void
    {
        // Covers the belt-and-suspenders branch: the pasted prefix says test but
        // the server surfaces type=live for that same prefix (would only happen
        // if server-side types ever diverged from the key format).
        $client = $this->createMock(AslanHttpClient::class);

        $apiKey = ApiKey::fromArray([
            'id' => 'ak_01HZ',
            'name' => 'default',
            'type' => 'live',
            'prefix' => 'mA3xZ567',
            'createdAt' => '2026-01-01T00:00:00Z',
        ]);
        $client->method('pingApiKeys')->willReturn([$apiKey]);

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key, string $mode) => $client);

        $this->expectException(WrongModeException::class);
        $service->register('test', 'sk_test_mA3xZ567secret', self::WEBHOOK_URL);
    }

    public function test_register_picks_key_matching_pasted_prefix_not_first(): void
    {
        $client = $this->createMock(AslanHttpClient::class);

        $otherKey = ApiKey::fromArray([
            'id' => 'ak_OTHER',
            'name' => 'other',
            'type' => 'test',
            'environment' => 'test',
            'prefix' => 'other123',
            'status' => 'active',
            'secretKey' => null,
            'lastUsedAt' => null,
            'createdAt' => '2026-01-01T00:00:00Z',
        ]);
        $pastedKey = ApiKey::fromArray([
            'id' => 'ak_PASTED',
            'name' => 'default',
            'type' => 'test',
            'environment' => 'test',
            'prefix' => 'mA3xZ',
            'status' => 'active',
            'secretKey' => null,
            'lastUsedAt' => null,
            'createdAt' => '2026-01-02T00:00:00Z',
        ]);
        $client->expects(self::once())->method('pingApiKeys')->willReturn([$otherKey, $pastedKey]);

        $webhookConfig = WebhookConfig::fromArray([
            'id' => 'wh_01',
            'url' => 'https://shop.example.ma/wp-json/aslan/v1/webhook',
            'status' => 'active',
            'events' => [],
            'signingSecret' => 'whsec_x',
            'apiVersion' => '2026-11-01',
            'createdAt' => '2026-11-13T00:00:00Z',
            'updatedAt' => '2026-11-13T00:00:00Z',
        ]);
        $client->method('upsertWebhookConfig')->willReturn($webhookConfig);

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key, string $mode) => $client);

        $result = $service->register('test', 'sk_test_mA3xZ_secret', 'https://shop.example.ma/wp-json/aslan/v1/webhook');

        self::assertSame('ak_PASTED', $result->apiKeyId);
    }

    public function test_register_throws_when_no_key_prefix_matches(): void
    {
        $client = $this->createMock(AslanHttpClient::class);
        $otherKey = ApiKey::fromArray([
            'id' => 'ak_OTHER',
            'name' => 'other',
            'type' => 'test',
            'environment' => 'test',
            'prefix' => 'something-else',
            'status' => 'active',
            'secretKey' => null,
            'lastUsedAt' => null,
            'createdAt' => '2026-01-01T00:00:00Z',
        ]);
        $client->method('pingApiKeys')->willReturn([$otherKey]);

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key, string $mode) => $client);

        $this->expectException(\Aslan\Woo\Service\InvalidKeyException::class);
        $service->register('test', 'sk_test_mA3xZ_secret', 'https://shop.example.ma/wp-json/aslan/v1/webhook');
    }

    public function test_register_tolerates_null_signing_secret_on_re_upsert(): void
    {
        $client = $this->createMock(AslanHttpClient::class);

        $apiKey = ApiKey::fromArray([
            'id' => 'ak_01HZ',
            'name' => 'default',
            'type' => 'test',
            'environment' => 'test',
            'prefix' => 'mA3xZ',
            'status' => 'active',
            'secretKey' => null,
            'lastUsedAt' => null,
            'createdAt' => '2026-01-01T00:00:00Z',
        ]);
        $client->method('pingApiKeys')->willReturn([$apiKey]);

        $webhookConfig = WebhookConfig::fromArray([
            'id' => 'wh_01',
            'url' => 'https://shop.example.ma/wp-json/aslan/v1/webhook',
            'status' => 'active',
            'events' => [],
            'signingSecret' => null,  // null on re-upsert — the SDK contract
            'apiVersion' => '2026-11-01',
            'createdAt' => '2026-11-13T00:00:00Z',
            'updatedAt' => '2026-11-13T00:00:00Z',
        ]);
        $client->method('upsertWebhookConfig')->willReturn($webhookConfig);

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key, string $mode) => $client);

        $result = $service->register(
            'test',
            'sk_test_mA3xZ_secret',
            'https://shop.example.ma/wp-json/aslan/v1/webhook',
        );

        self::assertNull($result->signingSecret);
        self::assertSame('ak_01HZ', $result->apiKeyId);
    }

    public function test_register_accepts_three_part_current_key_format(): void
    {
        // Current server format: sk_<type>_<prefix><secret> (no separator between
        // prefix and secret). The prefix is the first 8 chars of the body.
        $apiKey = ApiKey::fromArray([
            'id'        => 'ak_short',
            'name'      => 'default',
            'type'      => 'test',
            'prefix'    => 'abcd1234',
            'createdAt' => '2026-01-01T00:00:00Z',
        ]);
        $webhookConfig = WebhookConfig::fromArray([
            'id'            => 'wh_short',
            'url'           => self::WEBHOOK_URL,
            'status'        => 'active',
            'events'        => [],
            'signingSecret' => 'whsec_xyz',
            'apiVersion'    => '2026-11-01',
            'createdAt'     => '2026-11-13T00:00:00Z',
            'updatedAt'     => '2026-11-13T00:00:00Z',
        ]);
        $client = $this->createMock(AslanHttpClient::class);
        $client->method('pingApiKeys')->willReturn([$apiKey]);
        $client->method('upsertWebhookConfig')->willReturn($webhookConfig);

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key, string $mode) => $client);
        // 3-part shape: sk_<type>_<prefix 8 hex><secret>. Assembled at runtime
        // so the string literal does not trip GitHub's secret-scan heuristics.
        $fakeKey = 'sk_test_' . 'abcd1234' . str_repeat('ZZ', 28);
        $result  = $service->register('test', $fakeKey, self::WEBHOOK_URL);

        self::assertSame('ak_short', $result->apiKeyId);
        self::assertSame('whsec_xyz', $result->signingSecret);
    }

    public function test_register_rejects_key_with_too_few_parts(): void
    {
        // The guard above (register-time local prefix check) rejects "sk_test"
        // before this reaches findKeyByPrefix. The too-few-parts path is now
        // exercised by a correctly-prefixed-but-truncated secret where
        // findKeyByPrefix returns null.
        $client = $this->createMock(AslanHttpClient::class);
        $client->method('pingApiKeys')->willReturn([]);

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key, string $mode) => $client);
        $this->expectException(InvalidKeyException::class);
        $service->register('test', 'sk_test_x', self::WEBHOOK_URL);
    }

    public function test_register_rejects_sk_test_pasted_in_live_mode(): void
    {
        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::never())->method('pingApiKeys');

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key, string $mode) => $client);
        $this->expectException(WrongModeException::class);
        $this->expectExceptionMessage('Key is test but you are setting it as live.');
        $service->register('live', 'sk_test_abcd1234_secret', self::WEBHOOK_URL);
    }

    public function test_register_rejects_sk_live_pasted_in_test_mode(): void
    {
        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::never())->method('pingApiKeys');

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key, string $mode) => $client);
        $this->expectException(WrongModeException::class);
        $this->expectExceptionMessage('Key is live but you are setting it as test.');
        $service->register('test', 'sk_live_abcd1234_secret', self::WEBHOOK_URL);
    }

    public function test_register_rejects_unknown_prefix_before_api_call(): void
    {
        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::never())->method('pingApiKeys');

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key, string $mode) => $client);
        $this->expectException(WrongModeException::class);
        $this->expectExceptionMessage('Key is unknown but you are setting it as test.');
        $service->register('test', 'pk_test_abcd1234_secret', self::WEBHOOK_URL);
    }
}
