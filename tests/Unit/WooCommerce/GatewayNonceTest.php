<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\WooCommerce;

use Aslan\Woo\Config;
use Aslan\Woo\Service\CheckoutService;
use Aslan\Woo\Service\KeyLifecycleService;
use Aslan\Woo\Service\RefundService;
use Aslan\Woo\WooCommerce\Gateway;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Admin_Settings;

/**
 * Covers the scoped-nonce guard added to the Aslan settings tab: without a
 * valid `aslan_settings_nonce`, the gateway must refuse to save even if WC's
 * own `woocommerce-settings` nonce would otherwise pass.
 */
final class GatewayNonceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (! defined('ASLAN_WOO_PLUGIN_DIR')) {
            define('ASLAN_WOO_PLUGIN_DIR', dirname(__DIR__, 3) . '/');
        }
        if (! defined('ASLAN_WOO_PLUGIN_URL')) {
            define('ASLAN_WOO_PLUGIN_URL', 'https://example.test/');
        }

        Functions\when('__')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('add_action')->justReturn(true);

        WC_Admin_Settings::reset();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeGateway(): Gateway
    {
        // All four Gateway deps are `final` classes and the nonce path never
        // calls into them. Reflection builds a bare Gateway skipping every
        // constructor, and we fill the private readonly props directly.
        $gateway = (new \ReflectionClass(Gateway::class))->newInstanceWithoutConstructor();
        $put = static function (string $prop, object $value) use ($gateway): void {
            (new \ReflectionProperty(Gateway::class, $prop))->setValue($gateway, $value);
        };
        $put('config', (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor());
        $put('checkoutService', (new \ReflectionClass(CheckoutService::class))->newInstanceWithoutConstructor());
        $put('refundService', (new \ReflectionClass(RefundService::class))->newInstanceWithoutConstructor());
        $put('keyLifecycle', (new \ReflectionClass(KeyLifecycleService::class))->newInstanceWithoutConstructor());
        $gateway->id = Gateway::ID;

        return $gateway;
    }

    public function test_missing_nonce_blocks_save_and_surfaces_error(): void
    {
        Functions\expect('wp_verify_nonce')->never();
        $gateway = $this->makeGateway();

        $result = $gateway->process_admin_options();

        $this->assertFalse($result);
        $this->assertSame(0, $gateway->parent_process_called, 'parent::process_admin_options must NOT run');
        $this->assertNotEmpty(WC_Admin_Settings::$errors);
        $this->assertStringContainsString('security check', WC_Admin_Settings::$errors[0]);
    }

    public function test_empty_nonce_blocks_save(): void
    {
        $_POST[Gateway::SETTINGS_NONCE_FIELD] = '';
        Functions\expect('wp_verify_nonce')->never();
        $gateway = $this->makeGateway();

        $result = $gateway->process_admin_options();

        $this->assertFalse($result);
        $this->assertSame(0, $gateway->parent_process_called);
        $this->assertNotEmpty(WC_Admin_Settings::$errors);
    }

    public function test_bad_nonce_blocks_save(): void
    {
        $_POST[Gateway::SETTINGS_NONCE_FIELD] = 'not-a-real-nonce';
        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('not-a-real-nonce', Gateway::SETTINGS_NONCE_ACTION)
            ->andReturn(false);
        $gateway = $this->makeGateway();

        $result = $gateway->process_admin_options();

        $this->assertFalse($result);
        $this->assertSame(0, $gateway->parent_process_called);
        $this->assertNotEmpty(WC_Admin_Settings::$errors);
    }

    public function test_valid_nonce_lets_save_through(): void
    {
        $_POST[Gateway::SETTINGS_NONCE_FIELD] = 'valid-nonce';
        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('valid-nonce', Gateway::SETTINGS_NONCE_ACTION)
            ->andReturn(1);
        $gateway = $this->makeGateway();
        $gateway->parent_process_result = true;

        $result = $gateway->process_admin_options();

        $this->assertTrue($result);
        $this->assertSame(1, $gateway->parent_process_called, 'parent::process_admin_options must run exactly once');
        $this->assertSame([], WC_Admin_Settings::$errors, 'no error on happy path');
    }

    public function test_admin_options_renders_scoped_nonce_field(): void
    {
        Functions\expect('wp_nonce_field')
            ->once()
            ->with(Gateway::SETTINGS_NONCE_ACTION, Gateway::SETTINGS_NONCE_FIELD)
            ->andReturn('');

        $gateway = $this->makeGateway();
        $gateway->admin_options();

        $this->assertSame(1, $gateway->admin_options_rendered, 'parent::admin_options must still render');
    }
}
