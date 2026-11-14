<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Payment\Dto\CheckoutSession;
use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Service\CheckoutService;
use Aslan\Woo\Service\CheckoutSessionResult;
use Aslan\Woo\Service\NonWholeMadException;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class CheckoutServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function sessionFixture(string $id, string $url, int $amount): array
    {
        return [
            'id' => $id,
            'token' => 'tok_' . $id,
            'url' => $url,
            'status' => 'open',
            'amount' => $amount,
            'currency' => 'MAD',
            'successUrl' => 'https://shop.example.ma/aslan/return',
            'cancelUrl' => 'https://shop.example.ma/aslan/cancel',
            'expiresAt' => '2026-11-13T01:00:00Z',
            'createdAt' => '2026-11-13T00:00:00Z',
        ];
    }

    private function orderMock(string $total, mixed $attemptMeta = ''): WC_Order
    {
        $order = $this->createMock(WC_Order::class);
        $order->method('get_id')->willReturn(42);
        $order->method('get_total')->willReturn($total);
        $order->method('get_billing_email')->willReturn('customer@example.ma');
        $order->method('get_billing_first_name')->willReturn('Aya');
        $order->method('get_billing_last_name')->willReturn('Alaoui');
        $order->method('get_billing_phone')->willReturn('+212600000000');
        $order->method('get_meta')->with('_aslan_attempt_count')->willReturn($attemptMeta);
        return $order;
    }

    public function test_creates_session_for_whole_mad_order(): void
    {
        $order = $this->orderMock('299.00');
        $writes = [];
        $order->method('update_meta_data')->willReturnCallback(
            function (string $key, mixed $value) use (&$writes): void {
                $writes[$key] = $value;
            }
        );
        $order->expects(self::once())->method('save');

        Functions\when('home_url')->alias(fn ($p) => 'https://shop.example.ma' . $p);

        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::once())
            ->method('createCheckoutSession')
            ->with(
                self::callback(function (array $params) {
                    self::assertSame(29900, $params['amount']);
                    self::assertSame('MAD', $params['currency']);
                    self::assertStringStartsWith('https://shop.example.ma', $params['successUrl']);
                    self::assertStringStartsWith('https://shop.example.ma', $params['cancelUrl']);
                    self::assertSame('customer@example.ma', $params['customer']['email']);
                    self::assertSame('Aya Alaoui', $params['customer']['name']);
                    self::assertSame(['wc_order_id' => '42'], $params['metadata']);
                    return true;
                }),
                'order_42_1'
            )
            ->willReturn(CheckoutSession::fromArray(
                $this->sessionFixture('cs_test_01', 'https://pay.aslan.ma/cs_test_01', 29900)
            ));

        $result = (new CheckoutService($client))->createSessionForOrder($order);

        self::assertInstanceOf(CheckoutSessionResult::class, $result);
        self::assertSame('cs_test_01', $result->sessionId);
        self::assertSame('https://pay.aslan.ma/cs_test_01', $result->redirectUrl);
        self::assertSame(['_aslan_session_id' => 'cs_test_01', '_aslan_attempt_count' => '1'], $writes);
    }

    public function test_rejects_fractional_mad_order(): void
    {
        $order = $this->orderMock('299.50');

        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::never())->method('createCheckoutSession');

        $this->expectException(NonWholeMadException::class);
        (new CheckoutService($client))->createSessionForOrder($order);
    }

    public function test_increments_attempt_number_on_retry(): void
    {
        $order = $this->orderMock('100.00', '2');
        $writes = [];
        $order->method('update_meta_data')->willReturnCallback(
            function (string $key, mixed $value) use (&$writes): void {
                $writes[$key] = $value;
            }
        );

        Functions\when('home_url')->alias(fn ($p) => 'https://shop.example.ma' . $p);

        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::once())
            ->method('createCheckoutSession')
            ->with(self::anything(), 'order_42_3')
            ->willReturn(CheckoutSession::fromArray($this->sessionFixture('cs_test_02', 'https://x', 10000)));

        (new CheckoutService($client))->createSessionForOrder($order);

        self::assertSame('cs_test_02', $writes['_aslan_session_id']);
        self::assertSame('3', $writes['_aslan_attempt_count']);
    }
}
