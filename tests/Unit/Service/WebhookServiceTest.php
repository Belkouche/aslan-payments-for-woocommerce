<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Woo\Service\WebhookService;
use Aslan\Woo\Service\WebhookVerificationException;
use PHPUnit\Framework\TestCase;

final class WebhookServiceTest extends TestCase
{
    private const SECRET = 'whsec_test_abcdef';

    public function test_valid_signature_returns_decoded_event(): void
    {
        $service = new WebhookService();
        $t       = time();
        $body    = '{"id":"evt_01HZ","event":"payment.succeeded","data":{}}';
        $sig     = hash_hmac('sha256', $t . '.' . $body, self::SECRET);
        $header  = sprintf('t=%d,v1=%s', $t, $sig);

        $event = $service->verify($body, $header, self::SECRET);

        self::assertSame('evt_01HZ', $event['id']);
        self::assertSame('payment.succeeded', $event['event']);
    }

    public function test_invalid_signature_throws(): void
    {
        $service = new WebhookService();
        $t       = time();
        $body    = '{"id":"evt_01HZ"}';
        $header  = sprintf('t=%d,v1=%s', $t, 'baadbaadbaadbaad');

        $this->expectException(WebhookVerificationException::class);
        $service->verify($body, $header, self::SECRET);
    }

    public function test_stale_timestamp_throws(): void
    {
        $service = new WebhookService();
        $t       = time() - 600; // 10 min old
        $body    = '{"id":"evt_01HZ"}';
        $sig     = hash_hmac('sha256', $t . '.' . $body, self::SECRET);
        $header  = sprintf('t=%d,v1=%s', $t, $sig);

        $this->expectException(WebhookVerificationException::class);
        $service->verify($body, $header, self::SECRET);
    }

    public function test_malformed_header_throws(): void
    {
        $service = new WebhookService();

        $this->expectException(WebhookVerificationException::class);
        $service->verify('{}', 'not-a-signature', self::SECRET);
    }

    public function test_payment_succeeded_calls_payment_complete_with_order(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->expects(self::once())->method('payment_complete')->with('pay_01HZ');
        $order->expects(self::atLeastOnce())->method('update_meta_data');
        $order->expects(self::atLeastOnce())->method('save');

        $service = new WebhookService();
        $service->handle(
            event: [
                'id'    => 'evt_01HZ',
                'event' => 'payment.succeeded',
                'data'  => [
                    'id'        => 'pay_01HZ',
                    'metadata'  => ['wc_order_id' => '42'],
                    'cardLast4' => '4242',
                ],
            ],
            orderLoader: fn (int $id) => $id === 42 ? $order : null,
        );
    }

    public function test_payment_failed_sets_status_failed(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->expects(self::once())->method('update_status')->with('failed', self::anything());

        $service = new WebhookService();
        $service->handle(
            event: [
                'id'    => 'evt_02HZ',
                'event' => 'payment.failed',
                'data'  => [
                    'id'            => 'pay_02HZ',
                    'metadata'      => ['wc_order_id' => '42'],
                    'failureReason' => 'issuer_declined',
                ],
            ],
            orderLoader: fn (int $id) => $id === 42 ? $order : null,
        );
    }

    public function test_unknown_order_logs_and_returns(): void
    {
        $service = new WebhookService();

        $service->handle(
            event: [
                'id'    => 'evt_03HZ',
                'event' => 'payment.succeeded',
                'data'  => ['metadata' => ['wc_order_id' => '999']],
            ],
            orderLoader: fn (int $id) => null,
        );

        // Reaching here is the assertion: no exception thrown.
        self::assertTrue(true);
    }
}
