<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Client;

use Aslan\Woo\Client\AslanHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mode → base URL mapping that landed in v0.1.1 after Krustys hit
 * a sk_test_ key against the prod URL and got 401. The mode is the single
 * source of truth; the constant and filter stay as dev/CI escape hatches.
 */
final class SdkFactoryTest extends TestCase
{
    public function test_test_mode_points_at_staging(): void
    {
        $sdk = (AslanHttpClient::sdkFactory('sk_test_abcd1234_x', 'test'))();
        self::assertSame(AslanHttpClient::BASE_URL_TEST, $sdk->getBaseUrl());
        self::assertStringContainsString('staging-api.aslanpay.ma', $sdk->getBaseUrl());
    }

    public function test_live_mode_points_at_production(): void
    {
        $sdk = (AslanHttpClient::sdkFactory('sk_live_abcd1234_x', 'live'))();
        self::assertSame(AslanHttpClient::BASE_URL_LIVE, $sdk->getBaseUrl());
        self::assertStringContainsString('api.aslanpay.ma', $sdk->getBaseUrl());
        self::assertStringNotContainsString('staging', $sdk->getBaseUrl());
    }

    /**
     * Separate process: the constant is defined for the whole PHP process, so
     * running this inline would poison every later test. --preserveGlobalState
     * disabled keeps the define() from leaking back.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_constant_override_wins_over_mode(): void
    {
        require_once dirname(__DIR__, 2) . '/../vendor/autoload.php';
        define('ASLAN_WOO_API_BASE_URL', 'https://returned-by-constant.test/pay');
        $sdk = (AslanHttpClient::sdkFactory('sk_test_abcd1234_x', 'test'))();
        self::assertSame('https://returned-by-constant.test/pay', $sdk->getBaseUrl());
    }

    /**
     * Empty constant → fall through to the mode-based URL.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_empty_constant_falls_back_to_mode_url(): void
    {
        require_once dirname(__DIR__, 2) . '/../vendor/autoload.php';
        define('ASLAN_WOO_API_BASE_URL', '');
        $sdk = (AslanHttpClient::sdkFactory('sk_test_abcd1234_x', 'test'))();
        self::assertSame(AslanHttpClient::BASE_URL_TEST, $sdk->getBaseUrl());
    }

    /**
     * The aslan_woo_api_base_url filter wins over everything. We stub
     * apply_filters in the parent namespace for this test only, running in a
     * separate process so the stub does not leak.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_filter_wins_over_constant_and_mode(): void
    {
        require_once dirname(__DIR__, 2) . '/../vendor/autoload.php';
        // eval() is safe here: the payload is a hardcoded string literal with no
        // external input, and this is the only way to declare a function in the
        // GLOBAL namespace from inside a namespaced test file so AslanHttpClient's
        // unprefixed apply_filters() call resolves it. Scoped to this separate
        // process via @runInSeparateProcess, so the shim never leaks to real code.
        eval(
            "namespace { if (!function_exists('apply_filters')) { "
            . "function apply_filters(string \$tag, mixed \$value, mixed ...\$args): mixed { "
            . "return \$tag === 'aslan_woo_api_base_url' ? 'https://returned-by-filter.test/pay' : \$value; "
            . "} } }"
        );
        $sdk = (AslanHttpClient::sdkFactory('sk_test_abcd1234_x', 'test'))();
        self::assertSame('https://returned-by-filter.test/pay', $sdk->getBaseUrl());
    }
}
