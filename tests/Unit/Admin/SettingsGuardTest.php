<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Admin;

use Aslan\Woo\Admin\SettingsGuard;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SettingsGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_http_validate_url')->alias(
            static fn (string $u) => filter_var($u, FILTER_VALIDATE_URL) !== false ? $u : false
        );
        Functions\when('esc_url_raw')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_mask_hides_the_whole_key(): void
    {
        $mask = SettingsGuard::mask('sk_live_abcd_secret');
        $this->assertSame(SettingsGuard::MASK, $mask);
        $this->assertStringNotContainsString('abcd', $mask);
        $this->assertSame('', SettingsGuard::mask(''));
    }

    public function test_posted_placeholder_keeps_stored_key(): void
    {
        $this->assertSame('sk_live_abcd_secret', SettingsGuard::resolveKey(SettingsGuard::MASK, 'sk_live_abcd_secret'));
    }

    public function test_new_or_cleared_key_replaces_stored(): void
    {
        $this->assertSame('sk_live_new_x', SettingsGuard::resolveKey(' sk_live_new_x ', 'sk_live_abcd_secret'));
        $this->assertSame('', SettingsGuard::resolveKey('', 'sk_live_abcd_secret'));
    }

    public function test_webhook_override_accepts_empty_and_https(): void
    {
        $this->assertSame('', SettingsGuard::webhookOverride('  '));
        $this->assertSame(
            'https://tunnel.example.com/hook',
            SettingsGuard::webhookOverride('https://tunnel.example.com/hook')
        );
    }

    public function test_webhook_override_rejects_http_and_garbage(): void
    {
        $this->assertNull(SettingsGuard::webhookOverride('http://tunnel.example.com/hook'));
        $this->assertNull(SettingsGuard::webhookOverride('javascript:alert(1)'));
        $this->assertNull(SettingsGuard::webhookOverride('not a url'));
    }
}
