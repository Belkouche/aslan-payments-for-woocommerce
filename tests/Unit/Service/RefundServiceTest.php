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
        $order->expects(self::once())->method('update_meta_data')->with('_aslan_initiated_refund_rf_01HZ', '1');

        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::once())
            ->method('createRefund')
            ->with(
                [
                    'transactionId' => 'pay_01HZ',
                    'amount'        => 10000,
                    'reason'        => 'customer_requested',
                ],
                'refund_42_pay_01HZ_10000'
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
    }
}
