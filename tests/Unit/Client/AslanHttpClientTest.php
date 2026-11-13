<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Client;

use Aslan\Payment\Aslan;
use Aslan\Payment\HttpClient\HttpClientInterface;
use Aslan\Woo\Client\AslanHttpClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AslanHttpClientTest extends TestCase
{
    public function test_create_checkout_session_delegates_to_sdk_with_idempotency_key(): void
    {
        $fakeHttp = $this->createMock(HttpClientInterface::class);
        $fakeHttp->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                self::stringEndsWith('/api/v1/checkout/sessions'),
                [
                    'amount' => 29900,
                    'successUrl' => 'https://shop.example.ma/success',
                    'cancelUrl' => 'https://shop.example.ma/cancel',
                ],
                self::callback(static fn (array $headers): bool => ($headers['Idempotency-Key'] ?? null) === 'order_42_1'),
            )
            ->willReturn(self::sessionPayload('cs_live_01HZABC'));

        $client = $this->clientFor($fakeHttp);

        $result = $client->createCheckoutSession([
            'amount' => 29900,
            'successUrl' => 'https://shop.example.ma/success',
            'cancelUrl' => 'https://shop.example.ma/cancel',
        ], 'order_42_1');

        self::assertSame('cs_live_01HZABC', $result->id);
    }

    private function clientFor(MockObject&HttpClientInterface $fakeHttp): AslanHttpClient
    {
        return new AslanHttpClient(
            static fn (): Aslan => new Aslan('sk_test_x', maxRetries: 0, httpClient: $fakeHttp)
        );
    }

    /** @return array<string, mixed> */
    private static function sessionPayload(string $id): array
    {
        return [
            'id' => $id,
            'token' => 'tok_abc',
            'url' => 'https://pay.aslan.ma/' . $id,
            'status' => 'open',
            'amount' => 29900,
            'currency' => 'MAD',
            'successUrl' => 'https://shop.example.ma/success',
            'cancelUrl' => 'https://shop.example.ma/cancel',
            'expiresAt' => '2026-11-13T00:00:00.000Z',
            'createdAt' => '2026-11-13T00:00:00.000Z',
        ];
    }
}
