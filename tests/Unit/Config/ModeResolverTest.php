<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Config;

use Aslan\Woo\Config;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ModeResolverTest extends TestCase
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

    public function test_mode_defaults_to_test_when_option_absent(): void
    {
        Functions\when('get_option')->justReturn(false);

        $config = new Config();

        self::assertSame('test', $config->mode());
    }

    public function test_mode_is_live_when_option_says_live(): void
    {
        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return $name === 'aslan_woo_mode' ? 'live' : $default;
        });

        $config = new Config();

        self::assertSame('live', $config->mode());
    }

    public function test_active_secret_key_returns_test_key_in_test_mode(): void
    {
        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return match ($name) {
                'aslan_woo_mode' => 'test',
                'aslan_woo_test_secret_key' => 'sk_test_abc_secret',
                'aslan_woo_live_secret_key' => 'sk_live_xyz_secret',
                default => $default,
            };
        });

        $config = new Config();

        self::assertSame('sk_test_abc_secret', $config->activeSecretKey());
    }

    public function test_active_secret_key_returns_live_key_in_live_mode(): void
    {
        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return match ($name) {
                'aslan_woo_mode' => 'live',
                'aslan_woo_test_secret_key' => 'sk_test_abc_secret',
                'aslan_woo_live_secret_key' => 'sk_live_xyz_secret',
                default => $default,
            };
        });

        $config = new Config();

        self::assertSame('sk_live_xyz_secret', $config->activeSecretKey());
    }

    public function test_active_webhook_secret_matches_mode(): void
    {
        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return match ($name) {
                'aslan_woo_mode' => 'test',
                'aslan_woo_test_webhook_secret' => 'whsec_test_abc',
                'aslan_woo_live_webhook_secret' => 'whsec_live_xyz',
                default => $default,
            };
        });

        $config = new Config();

        self::assertSame('whsec_test_abc', $config->activeWebhookSecret());
    }

    public function test_is_configured_true_only_when_active_key_and_secret_exist(): void
    {
        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return match ($name) {
                'aslan_woo_mode' => 'test',
                'aslan_woo_test_secret_key' => 'sk_test_abc_secret',
                'aslan_woo_test_webhook_secret' => '',
                default => $default,
            };
        });

        $config = new Config();

        self::assertFalse($config->isConfigured());
    }

    public function test_mode_is_test_when_option_is_uppercase_LIVE(): void
    {
        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return $name === 'aslan_woo_mode' ? 'LIVE' : $default;
        });

        $config = new Config();

        // Case-sensitive match is intentional — only exact "live" flips the mode.
        self::assertSame('test', $config->mode());
    }

    public function test_active_secret_key_returns_empty_string_when_option_is_array(): void
    {
        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return match ($name) {
                'aslan_woo_mode' => 'test',
                'aslan_woo_test_secret_key' => ['corrupted' => 'array'],
                default => $default,
            };
        });

        $config = new Config();

        self::assertSame('', $config->activeSecretKey());
    }

    public function test_is_configured_true_when_live_key_and_secret_present(): void
    {
        Functions\when('get_option')->alias(function (string $name, $default = false) {
            return match ($name) {
                'aslan_woo_mode' => 'live',
                'aslan_woo_live_secret_key' => 'sk_live_xyz_secret',
                'aslan_woo_live_webhook_secret' => 'whsec_live_xyz',
                default => $default,
            };
        });

        $config = new Config();

        self::assertTrue($config->isConfigured());
    }
}
