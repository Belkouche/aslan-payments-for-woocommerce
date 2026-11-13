<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Client;

use Aslan\Payment\Aslan;
use Aslan\Payment\AslanException;
use Aslan\Payment\HttpClient\HttpClientInterface;
use Aslan\Woo\Client\AslanAuthException;
use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Client\AslanRequestException;
use Aslan\Woo\Client\AslanTransientException;
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

    public function test_401_response_becomes_auth_exception(): void
    {
        $fakeHttp = $this->createMock(HttpClientInterface::class);
        $fakeHttp->expects(self::once())->method('request')->willThrowException(AslanException::authenticationError('unauthorized', 401));

        $this->expectException(AslanAuthException::class);
        $this->clientFor($fakeHttp)->createCheckoutSession(['amount' => 1]);
    }

    public function test_4xx_response_becomes_request_exception(): void
    {
        $fakeHttp = $this->createMock(HttpClientInterface::class);
        $fakeHttp->expects(self::once())->method('request')
            ->willThrowException(AslanException::validationError('invalid_amount', [], 400, 'req_01'));

        try {
            $this->clientFor($fakeHttp)->createCheckoutSession(['amount' => 1]);
            self::fail('Expected AslanRequestException');
        } catch (AslanRequestException $e) {
            self::assertNotInstanceOf(AslanAuthException::class, $e);
            self::assertSame('VALIDATION_ERROR', $e->aslanCode);
            self::assertSame('req_01', $e->aslanRequestId);
        }
    }

    public function test_5xx_response_becomes_transient_exception(): void
    {
        $fakeHttp = $this->createMock(HttpClientInterface::class);
        $fakeHttp->expects(self::once())->method('request')->willThrowException(AslanException::serverError('upstream_down', 503));

        $this->expectException(AslanTransientException::class);
        $this->clientFor($fakeHttp)->createCheckoutSession(['amount' => 1]);
    }

    public function test_connection_error_retries_twice_then_succeeds(): void
    {
        $fakeHttp = $this->createMock(HttpClientInterface::class);
        $fakeHttp->expects(self::exactly(3))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                self::throwException(AslanException::networkError('ECONNRESET')),
                self::throwException(AslanException::networkError('ECONNRESET')),
                self::sessionPayload('cs_test'),
            );

        $client = $this->clientFor($fakeHttp);
        // Zero-delay backoff for tests
        $client->setBackoffSchedule([0, 0]);

        $result = $client->createCheckoutSession(['amount' => 1]);
        self::assertSame('cs_test', $result->id);
    }

    public function test_connection_error_after_3_attempts_throws_transient(): void
    {
        $fakeHttp = $this->createMock(HttpClientInterface::class);
        $fakeHttp->expects(self::exactly(3))
            ->method('request')
            ->willThrowException(AslanException::networkError('ECONNRESET'));

        $client = $this->clientFor($fakeHttp);
        $client->setBackoffSchedule([0, 0]);

        $this->expectException(AslanTransientException::class);
        $client->createCheckoutSession(['amount' => 1]);
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
