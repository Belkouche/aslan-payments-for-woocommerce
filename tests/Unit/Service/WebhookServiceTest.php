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
}
