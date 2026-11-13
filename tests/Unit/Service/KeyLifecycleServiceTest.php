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
            'prefix' => 'sk_test_mA3xZ',
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

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key) => $client);

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

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key) => $client);

        $this->expectException(InvalidKeyException::class);
        $service->register('test', 'sk_test_bad', self::WEBHOOK_URL);
    }

    public function test_register_mismatched_key_type_throws_wrong_mode_exception(): void
    {
        $client = $this->createMock(AslanHttpClient::class);

        $apiKey = ApiKey::fromArray([
            'id' => 'ak_01HZ',
            'name' => 'default',
            'type' => 'live',
            'prefix' => 'sk_live_mA3xZ',
            'createdAt' => '2026-01-01T00:00:00Z',
        ]);
        $client->method('pingApiKeys')->willReturn([$apiKey]);

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key) => $client);

        $this->expectException(WrongModeException::class);
        $service->register('test', 'sk_live_mA3xZ_secret', self::WEBHOOK_URL);
    }
}
