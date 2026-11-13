<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Payment\Dto\Refund;
use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Service\RefundService;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class RefundServiceTest extends TestCase
{
    public function test_refunds_whole_mad_amount_against_payment_id(): void
    {
        $order = $this->createMock(WC_Order::class);
        $order->method('get_meta')->with('_aslan_payment_id')->willReturn('pay_01HZ');
        $order->method('get_id')->willReturn(42);
        $metaWrites = [];
        $order->method('update_meta_data')->willReturnCallback(
            static function ($key, $value) use (&$metaWrites, $order) {
                $metaWrites[] = $key;
                return $order;
            }
        );
        $order->expects(self::once())->method('delete_meta_data')
            ->with(self::matchesRegularExpression('/^_aslan_pending_refund_pay_01HZ_10000_\d+$/'));

        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::once())
            ->method('createRefund')
            ->with(
                [
                    'transactionId' => 'pay_01HZ',
                    'amount'        => 10000,
                    'reason'        => 'customer_requested',
                ],
                self::matchesRegularExpression('/^refund_42_pay_01HZ_10000_[0-9a-f-]{32,36}$/')
            )
            ->willReturn(Refund::fromArray([
                'id'            => 'rf_01HZ',
                'transactionId' => 'pay_01HZ',
                'amount'        => 10000,
                'currency'      => 'MAD',
                'status'        => 'pending',
                'createdAt'     => '2026-11-13T00:00:00Z',
            ]));

        $service  = new RefundService($client);
        $refundId = $service->refund($order, '100.00', 'customer_requested');

        self::assertSame('rf_01HZ', $refundId);
        self::assertCount(2, $metaWrites);
        self::assertMatchesRegularExpression('/^_aslan_pending_refund_pay_01HZ_10000_\d+$/', $metaWrites[0]);
        self::assertSame('_aslan_initiated_refund_rf_01HZ', $metaWrites[1]);
    }

    public function test_same_amount_refunds_use_distinct_idempotency_keys(): void
    {
        $order = $this->createMock(WC_Order::class);
        $order->method('get_meta')->willReturn('pay_01HZ');
        $order->method('get_id')->willReturn(42);

        $keys   = [];
        $client = $this->createMock(AslanHttpClient::class);
        $client->method('createRefund')->willReturnCallback(
            static function (array $payload, string $key) use (&$keys) {
                $keys[] = $key;
                return Refund::fromArray([
                    'id' => 'rf_' . count($keys), 'transactionId' => 'pay_01HZ', 'amount' => 10000,
                    'currency' => 'MAD', 'status' => 'pending', 'createdAt' => '2026-11-13T00:00:00Z',
                ]);
            }
        );

        $service = new RefundService($client);
        $service->refund($order, '100.00');
        $service->refund($order, '100.00');

        self::assertCount(2, array_unique($keys));
    }
}
