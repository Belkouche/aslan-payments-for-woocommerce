<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Woo\Service\WebhookService;
use Aslan\Woo\Service\WebhookVerificationException;
use PHPUnit\Framework\TestCase;

final class WebhookServiceTest extends TestCase
{
    private const SECRET = 'whsec_test_abcdef';

    private function aslanOrder(): \WC_Order&\PHPUnit\Framework\MockObject\MockObject
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_total')->willReturn('500.00');
        $order->method('get_payment_method')->willReturn('aslan');
        $order->method('get_currency')->willReturn('MAD');
        $order->method('get_status')->willReturn('pending');
        $order->method('is_paid')->willReturn(false);
        return $order;
    }

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
        $order = $this->aslanOrder();
        $order->expects(self::once())->method('payment_complete')->with('pay_01HZ');
        $order->expects(self::atLeastOnce())->method('update_meta_data');

        $service = new WebhookService();
        $service->handle(
            event: [
                'id'    => 'evt_01HZ',
                'event' => 'payment.succeeded',
                'data'  => [
                    'id'        => 'pay_01HZ',
                    'amount'    => 50000,
                    'currency'  => 'MAD',
                    'metadata'  => ['wc_order_id' => '42'],
                    'cardLast4' => '4242',
                ],
            ],
            orderLoader: fn (int $id) => $id === 42 ? $order : null,
        );
    }

    public function test_payment_failed_sets_status_failed(): void
    {
        $order = $this->aslanOrder();
        $order->expects(self::once())->method('update_status')->with('failed', self::anything());

        $service = new WebhookService();
        $service->handle(
            event: [
                'id'    => 'evt_02HZ',
                'event' => 'payment.failed',
                'data'  => [
                    'id'            => 'pay_02HZ',
                    'amount'        => 50000,
                    'currency'      => 'MAD',
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

    public function test_duplicate_event_id_is_noop(): void
    {
        $order = $this->aslanOrder();
        $order->expects(self::once())->method('payment_complete');

        $seen     = [];
        $recorder = static function (string $eventId) use (&$seen): bool {
            if (in_array($eventId, $seen, true)) {
                return false;
            }
            $seen[] = $eventId;
            return true;
        };

        $service = new WebhookService();
        $event   = [
            'id'    => 'evt_dup',
            'event' => 'payment.succeeded',
            'data'  => ['id' => 'pay_01', 'amount' => 50000, 'currency' => 'MAD', 'metadata' => ['wc_order_id' => '42']],
        ];

        $service->handle($event, fn () => $order, $recorder);
        $service->handle($event, fn () => $order, $recorder);
    }

    public function test_empty_secret_throws(): void
    {
        $service = new WebhookService();
        $t       = time();
        $body    = '{"id":"evt_01","event":"payment.succeeded","data":{}}';
        $sig     = hash_hmac('sha256', $t . '.' . $body, 'any');
        $header  = sprintf('t=%d,v1=%s', $t, $sig);

        $this->expectException(WebhookVerificationException::class);
        $service->verify($body, $header, '');
    }

    public function test_future_timestamp_beyond_skew_throws(): void
    {
        $service = new WebhookService();
        $t       = time() + 120; // 2 min in the future — beyond 60s skew tolerance
        $body    = '{"id":"evt_01","event":"payment.succeeded","data":{}}';
        $sig     = hash_hmac('sha256', $t . '.' . $body, self::SECRET);
        $header  = sprintf('t=%d,v1=%s', $t, $sig);

        $this->expectException(WebhookVerificationException::class);
        $service->verify($body, $header, self::SECRET);
    }

    public function test_handle_ignores_event_for_non_aslan_order(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_payment_method')->willReturn('stripe'); // not aslan
        $order->method('get_total')->willReturn('500.00');
        $order->expects(self::never())->method('payment_complete');
        $order->expects(self::never())->method('update_status');

        $service = new WebhookService();
        $service->handle(
            event: [
                'id'    => 'evt_01',
                'event' => 'payment.succeeded',
                'data'  => [
                    'id'       => 'pay_01',
                    'amount'   => 50000,
                    'currency' => 'MAD',
                    'metadata' => ['wc_order_id' => '42'],
                ],
            ],
            orderLoader: fn (int $id) => $id === 42 ? $order : null,
        );
    }

    public function test_handle_ignores_payment_failed_on_paid_order(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_payment_method')->willReturn('aslan');
        $order->method('get_total')->willReturn('500.00');
        $order->method('get_currency')->willReturn('MAD');
        $order->method('is_paid')->willReturn(true);
        $order->expects(self::never())->method('update_status');

        $service = new WebhookService();
        $service->handle(
            event: [
                'id'    => 'evt_02',
                'event' => 'payment.failed',
                'data'  => [
                    'id'       => 'pay_02',
                    'amount'   => 50000,
                    'currency' => 'MAD',
                    'metadata' => ['wc_order_id' => '42'],
                ],
            ],
            orderLoader: fn (int $id) => $id === 42 ? $order : null,
        );
    }

    public function test_handle_ignores_event_with_amount_mismatch(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->method('get_payment_method')->willReturn('aslan');
        $order->method('get_total')->willReturn('500.00');
        $order->method('get_currency')->willReturn('MAD');
        $order->expects(self::never())->method('payment_complete');

        $service = new WebhookService();
        $service->handle(
            event: [
                'id'    => 'evt_03',
                'event' => 'payment.succeeded',
                'data'  => [
                    'id'       => 'pay_03',
                    'amount'   => 1, // mismatch: order is 50000 centimes
                    'currency' => 'MAD',
                    'metadata' => ['wc_order_id' => '42'],
                ],
            ],
            orderLoader: fn (int $id) => $id === 42 ? $order : null,
        );
    }
}
