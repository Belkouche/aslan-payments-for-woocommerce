<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Woo\Service\DiagnosticLog;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class DiagnosticLogTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->store = [];
        Functions\when('get_option')->alias(function ($k, $d = []) {
            return $this->store[$k] ?? $d;
        });
        Functions\when('update_option')->alias(function ($k, $v) {
            $this->store[$k] = $v;
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_log_appends(): void
    {
        $log = new DiagnosticLog();
        $log->record('one');
        $log->record('two');
        self::assertCount(2, $this->store['aslan_woo_log']);
    }

    public function test_log_caps_at_100(): void
    {
        $log = new DiagnosticLog();
        for ($i = 0; $i < 110; $i++) {
            $log->record('msg ' . $i);
        }
        self::assertCount(100, $this->store['aslan_woo_log']);
        self::assertSame('msg 109', end($this->store['aslan_woo_log'])['msg']);
    }
}
