# Aslan Payments for WooCommerce — v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship v1 of `aslan-payments-for-woocommerce`: a WordPress plugin adding Aslan as a WooCommerce payment gateway plus an `[aslan_pay]` shortcode, with auto-configured webhooks from just the merchant's API keys.

**Architecture:** Three-layer PHP plugin. Layer 1 (`src/Client/`) is a WordPress-free wrapper over the `aslan/payment-php` SDK. Layer 2 (`src/Service/`) holds WordPress-aware services (`CheckoutService`, `WebhookService`, `KeyLifecycleService`). Layer 3 (`src/WooCommerce/`, `src/Shortcode/`, `src/Admin/`) is thin WordPress glue. Card data never touches WordPress — the merchant redirects to Aslan's hosted page.

**Tech Stack:** PHP 8.2+, `aslan/payment-php` SDK, WooCommerce 8.0+, WordPress 6.4+, PHPUnit 11, WP-PHPUnit + WooCommerce test harness, Playwright + Docker Compose for E2E, Composer, PHPStan level 8, phpcs WordPress standard.

**Spec:** [`docs/superpowers/specs/2026-11-13-wordpress-plugin-design.md`](../specs/2026-11-13-wordpress-plugin-design.md)

**Spec corrections learned during planning:**
- The spec mentions `/v1/account` for key validation — that endpoint does not exist. Keys are validated by calling `GET /v1/api-keys` which succeeds on a valid key and 401s on a bad one, and also lets us resolve the merchant id without a second call
- Aslan's webhook model has **one endpoint per merchant** (`WebhooksConfig::create` is really upsert). Re-registering replaces the existing endpoint. The settings flow warns before overwriting a pre-existing non-plugin endpoint

---

## File structure

Target layout of the plugin repo after v1:

```
aslan-payments-for-woocommerce/
├── aslan-payments-for-woocommerce.php     # Plugin bootstrap (header, loader)
├── composer.json
├── composer.lock
├── readme.txt                              # wordpress.org manifest
├── README.md                               # GitHub-facing
├── CHANGELOG.md
├── LICENSE                                 # GPL-v2
├── AGENTS.md / llms.txt                    # per PHP SDK convention
├── phpcs.xml.dist
├── phpstan.neon.dist
├── phpunit.xml.dist
├── .github/
│   └── workflows/
│       ├── ci.yml                          # phpunit + phpcs + phpstan
│       ├── e2e.yml                         # Playwright against test keys
│       └── release.yml                     # tag → ZIP → wordpress.org SVN
├── docker/
│   ├── docker-compose.e2e.yml              # WP + Woo + MySQL for E2E
│   └── Dockerfile.wp
├── docs/
│   └── superpowers/
│       ├── specs/
│       └── plans/
├── src/
│   ├── Plugin.php                          # Main orchestrator, DI-lite
│   ├── Autoloader.php                      # PSR-4 fallback if composer missing
│   ├── Config.php                          # wp_options wrapper, mode resolver
│   ├── Client/
│   │   ├── AslanHttpClient.php             # SDK wrapper (Layer 1)
│   │   └── AslanWooException.php
│   ├── Service/
│   │   ├── CheckoutService.php             # WC_Order → session (Layer 2)
│   │   ├── WebhookService.php              # Event handler (Layer 2)
│   │   ├── KeyLifecycleService.php         # On-save pipeline (Layer 2)
│   │   ├── RefundService.php               # Refund → Aslan
│   │   ├── DiagnosticLog.php               # Capped 100-row log
│   │   └── AmountValidator.php             # Whole-MAD enforcement
│   ├── WooCommerce/
│   │   ├── Gateway.php                     # WC_Payment_Gateway subclass
│   │   ├── OrderStatus.php                 # Register aslan-awaiting-cash
│   │   ├── OrderScreen.php                 # Payment block + Refund button
│   │   ├── ThankYouPage.php                # Polling until status clears
│   │   └── CheckoutBanner.php              # Test-mode banner
│   ├── Shortcode/
│   │   ├── PayShortcode.php                # [aslan_pay]
│   │   ├── PayBlock.php                    # Gutenberg block (same callback)
│   │   └── PaymentCpt.php                  # aslan_payment custom post type
│   ├── Admin/
│   │   ├── SettingsPage.php                # Gateway settings form
│   │   ├── SetupStatusCard.php             # Post-save status panel
│   │   ├── TroubleshootingActions.php      # 3 recovery buttons + ajax
│   │   ├── ActivationNotices.php           # HPOS/Woo/PHP checks
│   │   └── PriceScanner.php                # Whole-MAD product scan
│   └── Rest/
│       ├── WebhookRoute.php                # /wp-json/aslan/v1/webhook
│       ├── ShortcodePayRoute.php           # /wp-json/aslan/v1/shortcode/pay
│       └── OrderStatusRoute.php            # Thank-you polling helper
├── languages/
│   ├── aslan-payments-for-woocommerce.pot
│   ├── aslan-payments-for-woocommerce-fr_FR.po
│   └── aslan-payments-for-woocommerce-fr_FR.mo
├── assets/
│   ├── admin.css
│   ├── admin.js                            # Reveal link, toggle, ajax triggers
│   ├── block.js                            # Gutenberg block editor code
│   └── logo.svg                            # Aslan brand for payment methods
└── tests/
    ├── bootstrap.php
    ├── bootstrap-wp.php
    ├── Unit/
    │   ├── Client/AslanHttpClientTest.php
    │   ├── Service/CheckoutServiceTest.php
    │   ├── Service/WebhookServiceTest.php
    │   ├── Service/KeyLifecycleServiceTest.php
    │   ├── Service/AmountValidatorTest.php
    │   ├── Service/DiagnosticLogTest.php
    │   └── Config/ModeResolverTest.php
    ├── Integration/
    │   ├── WooCommerce/GatewayTest.php
    │   ├── WooCommerce/OrderScreenTest.php
    │   ├── Rest/WebhookRouteTest.php
    │   ├── Rest/ShortcodePayRouteTest.php
    │   ├── Admin/SettingsPageTest.php
    │   └── Shortcode/PayShortcodeTest.php
    └── E2E/
        ├── happy-path.spec.ts
        ├── cash-pending.spec.ts
        ├── failed-card.spec.ts
        ├── refund.spec.ts
        └── shortcode.spec.ts
```

---

## Phases

1. **Repo scaffold** — composer, phpunit, phpcs, phpstan, CI skeleton
2. **Config + Mode resolver** — `wp_options` wrapper, test/live mode switch
3. **HTTP client (Layer 1)** — SDK wrapper, retries, exception mapping
4. **KeyLifecycleService (Layer 2)** — key validation + webhook upsert pipeline
5. **WooCommerce gateway skeleton (Layer 3)** — gateway class registered, test-mode banner
6. **CheckoutService + process_payment** — order → Aslan session, redirect
7. **WebhookService + REST route** — signature verify, event routing
8. **Order status handling** — payment_complete, failed, awaiting-cash status
9. **Refund flow** — RefundService + order screen button
10. **Thank-you page polling** — webhook/redirect race handling
11. **Shortcode + Gutenberg block** — `[aslan_pay]` + CPT + AJAX route
12. **Admin UI** — settings page, setup status card, troubleshooting actions
13. **Amount validation** — AmountValidator + product scanner
14. **Diagnostic log + i18n + readme.txt** — polish for wordpress.org
15. **E2E + Release pipeline** — Playwright, release.yml for SVN deploy
16. **Security review** — taint analysis, secret scan, dep audit, 25-point manual checklist

Each phase ends with a commit. No partial work left between phases.

---

# Phase 1: Repo scaffold

**Why first:** everything else needs the autoload, the test runner, and the CI gates to be real. One commit, no code yet.

### Task 1.1: Composer manifest

**Files:**
- Create: `composer.json`
- Create: `.gitignore` (append if present)

- [ ] **Step 1: Write `composer.json`**

> **Correction (2026-11-13):** `aslan/payment-php` is not published to Packagist. We add a VCS repository so Composer resolves it from GitHub. Source: the SDK's README §3 Installation documents this exact workaround.

```json
{
  "name": "belkouche/aslan-payments-for-woocommerce",
  "description": "Accept Moroccan card, mobile wallet, and cash-agent payments through Aslan on WooCommerce.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "repositories": [
    { "type": "vcs", "url": "https://github.com/Belkouche/aslan-payment-php-symfony" }
  ],
  "require": {
    "php": "~8.2.35 || ~8.3.36 || ~8.4.27 || >=8.5.12",
    "aslan/payment-php": "^0.2.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^11.5",
    "phpstan/phpstan": "^1.11",
    "squizlabs/php_codesniffer": "^3.10",
    "wp-coding-standards/wpcs": "^3.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
    "yoast/phpunit-polyfills": "^3.0",
    "brain/monkey": "^2.6"
  },
  "autoload": {
    "psr-4": {
      "Aslan\\Woo\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Aslan\\Woo\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "test": "phpunit",
    "lint": "phpcs",
    "analyse": "phpstan analyse src --level=8"
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    }
  }
}
```

- [ ] **Step 2: Ensure `.gitignore` lists these entries (append only what's missing — don't duplicate lines already present).**

Required entries (commit `composer.lock` — this is an application, not a library):

```
vendor/
node_modules/
.phpunit.cache/
.phpunit.result.cache
build/
dist/
*.log
.DS_Store
.superpowers/
```

`.superpowers/` is the superpowers-skill scratch directory (brainstorm visual-companion state, plan intermediates). It should never ship to wordpress.org.

- [ ] **Step 3: Run `composer install`**

Run: `composer install`
Expected: success, writes `composer.lock`, installs `aslan/payment-php` and dev tools under `vendor/`.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock .gitignore
git commit -m "chore: composer manifest"
```

### Task 1.2: Plugin bootstrap file

**Files:**
- Create: `aslan-payments-for-woocommerce.php`
- Create: `src/Plugin.php`

- [ ] **Step 1: Write `aslan-payments-for-woocommerce.php`**

```php
<?php
/**
 * Plugin Name:       Aslan Payments for WooCommerce
 * Plugin URI:        https://aslanpay.ma/wordpress
 * Description:       Accept card, mobile wallet, and cash-agent payments through Aslan on WooCommerce. Plus a lightweight [aslan_pay] shortcode for standalone payments.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.2.35
 * Author:            Aslan
 * Author URI:        https://aslanpay.ma
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aslan-payments-for-woocommerce
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('ASLAN_WOO_PLUGIN_FILE', __FILE__);
define('ASLAN_WOO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASLAN_WOO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ASLAN_WOO_VERSION', '0.1.0');

$composer_autoload = ASLAN_WOO_PLUGIN_DIR . 'vendor/autoload.php';
if (! file_exists($composer_autoload)) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Aslan Payments: vendor/ directory missing. Run `composer install` or install the plugin from the official ZIP.', 'aslan-payments-for-woocommerce');
        echo '</p></div>';
    });
    return;
}
require_once $composer_autoload;

add_action('plugins_loaded', [\Aslan\Woo\Plugin::class, 'boot']);

// HPOS declaration must happen before WooCommerce init (before_woocommerce_init hook).
add_action('before_woocommerce_init', function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            ASLAN_WOO_PLUGIN_FILE,
            true
        );
    }
});
```

- [ ] **Step 2: Write `src/Plugin.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo;

final class Plugin
{
    private static ?self $instance = null;

    public static function boot(): void
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register();
        }
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::boot();
        }
        assert(self::$instance !== null);
        return self::$instance;
    }

    private function register(): void
    {
        // Subsequent phases wire services here.
        // Phase 1 leaves this empty on purpose — just prove the autoload path works.
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add aslan-payments-for-woocommerce.php src/Plugin.php
git commit -m "feat: plugin bootstrap and HPOS declaration"
```

### Task 1.3: PHPUnit + PHPStan + phpcs configs

**Files:**
- Create: `phpunit.xml.dist`
- Create: `phpstan.neon.dist`
- Create: `phpcs.xml.dist`
- Create: `tests/bootstrap.php`

- [ ] **Step 1: Write `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.5/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    cacheDirectory=".phpunit.cache"
    failOnWarning="true"
    failOnRisky="true">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="integration">
      <directory>tests/Integration</directory>
    </testsuite>
  </testsuites>
  <source>
    <include>
      <directory>src</directory>
    </include>
  </source>
</phpunit>
```

- [ ] **Step 2: Write `phpstan.neon.dist`**

```yaml
parameters:
  level: 8
  paths:
    - src
  excludePaths:
    - vendor
  bootstrapFiles:
    - tests/phpstan-bootstrap.php
```

- [ ] **Step 3: Write `tests/phpstan-bootstrap.php`**

```php
<?php
// Stubs so PHPStan understands WordPress + WooCommerce symbols without loading them.
if (! defined('ABSPATH')) { define('ABSPATH', '/dev/null/'); }
```

(Full WP stubs are big — Phase 5 adds `php-stubs/wordpress-stubs` and `php-stubs/woocommerce-stubs` as dev deps when we actually need them. Phase 1 code has none of those symbols.)

- [ ] **Step 4: Write `phpcs.xml.dist`**

> **Decision (2026-11-13):** the plugin code is PSR-12 style (typed PHP 8.2+, short array syntax, modern braces). Instead of the full `WordPress` ruleset (which mandates WPCS style — tabs, Yoda conditions, `array()` syntax, docblocks everywhere — and would force us to un-modernize the code), we use `WordPress-Core` for structural rules only, then explicitly add WP-specific **security** and **escaping** sniffs (the ones that matter for a payment plugin). This matches how Stripe, Mollie, and Adyen's current WooCommerce plugins lint their PHP.

```xml
<?xml version="1.0"?>
<ruleset name="Aslan Woo Plugin">
  <description>Security/escaping sniffs from WPCS; PSR-12 for everything else.</description>
  <file>src</file>
  <file>aslan-payments-for-woocommerce.php</file>
  <arg name="extensions" value="php"/>
  <arg name="colors"/>
  <arg value="sp"/>

  <!-- PSR-12 is the base style for modern typed PHP. -->
  <rule ref="PSR12"/>

  <!-- Security/escaping sniffs that the WordPress ruleset provides. -->
  <rule ref="WordPress.Security"/>
  <rule ref="WordPress.WP.I18n"/>
  <rule ref="WordPress.WP.AlternativeFunctions"/>
  <rule ref="WordPress.PHP.NoSilencedErrors"/>
  <rule ref="WordPress.DB.PreparedSQL"/>
  <rule ref="WordPress.DB.PreparedSQLPlaceholders"/>

  <config name="minimum_supported_wp_version" value="6.4"/>
  <config name="testVersion" value="8.2-"/>
</ruleset>
```

- [ ] **Step 5: Write `tests/bootstrap.php`**

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 6: Add placeholder files so empty test directories don't warn**

Create empty files:
- `tests/Unit/.gitkeep`
- `tests/Integration/.gitkeep`

PHPUnit with `failOnWarning="true"` warns on a completely missing suite directory; `.gitkeep` makes the dirs exist without putting a test in them. They stay in place as the suites grow.

- [ ] **Step 7: Run the lint/test/analyse chain and confirm empty-pass**

Run: `vendor/bin/phpunit --testsuite unit`
Expected: `No tests executed!` — passes.

Run: `vendor/bin/phpstan analyse src --level=8`
Expected: `[OK] No errors`

Run: `vendor/bin/phpcs`
Expected: exit 0 (clean). If the `WordPress` ruleset flags `declare(strict_types=1)` as a violation (this depends on the wpcs 3.x minor), add it to the ruleset's `<exclude>` list with a comment explaining why, then re-run.

- [ ] **Step 8: Commit**

```bash
git add phpunit.xml.dist phpstan.neon.dist phpcs.xml.dist tests/bootstrap.php tests/phpstan-bootstrap.php tests/Unit/.gitkeep tests/Integration/.gitkeep
git commit -m "chore: phpunit, phpstan, phpcs configs"
```

### Task 1.4: GitHub Actions CI

**Files:**
- Create: `.github/workflows/ci.yml`

- [ ] **Step 1: Write `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  pull_request:
  push:
    branches: [main]

jobs:
  unit:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        php: ['8.2.35', '8.3.36', '8.4.27', '8.5.12']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - run: composer test -- --testsuite unit
      - run: composer analyse
      - run: composer lint
```

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: phpunit + phpstan + phpcs across supported PHPs"
```

**Phase 1 gate:** `vendor/bin/phpunit` runs, `phpstan` reports 0 errors, `phpcs` reports 0 errors, CI is green on first push.

---

# Phase 2: Config + Mode resolver

**Why second:** every service needs to know "am I in test or live mode, which key do I use" before it can do anything. Keeping this out of individual services makes them testable.

### Task 2.1: `Config` class with mode resolver

**Files:**
- Create: `src/Config.php`
- Create: `tests/Unit/Config/ModeResolverTest.php`

- [ ] **Step 1: Write the failing test `tests/Unit/Config/ModeResolverTest.php`**

```php
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
}
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `vendor/bin/phpunit --filter ModeResolverTest`
Expected: FAIL — `Aslan\Woo\Config` class not found.

- [ ] **Step 3: Write `src/Config.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo;

/**
 * Thin wrapper over wp_options. Resolves the active secret key and webhook
 * secret for the current mode (test / live).
 *
 * All option names are listed here — no other file should read them directly.
 */
final class Config
{
    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';

    public const OPT_MODE                = 'aslan_woo_mode';
    public const OPT_TEST_KEY            = 'aslan_woo_test_secret_key';
    public const OPT_LIVE_KEY            = 'aslan_woo_live_secret_key';
    public const OPT_TEST_WEBHOOK_SECRET = 'aslan_woo_test_webhook_secret';
    public const OPT_LIVE_WEBHOOK_SECRET = 'aslan_woo_live_webhook_secret';
    public const OPT_WEBHOOK_URL_OVERRIDE = 'aslan_woo_webhook_url_override';
    public const OPT_SHOW_TEST_BANNER    = 'aslan_woo_show_test_banner';
    public const OPT_TITLE               = 'aslan_woo_title';

    public function mode(): string
    {
        $value = get_option(self::OPT_MODE, self::MODE_TEST);
        return $value === self::MODE_LIVE ? self::MODE_LIVE : self::MODE_TEST;
    }

    public function activeSecretKey(): string
    {
        $opt = $this->mode() === self::MODE_LIVE ? self::OPT_LIVE_KEY : self::OPT_TEST_KEY;
        $v = get_option($opt, '');
        return is_string($v) ? $v : '';
    }

    public function activeWebhookSecret(): string
    {
        $opt = $this->mode() === self::MODE_LIVE ? self::OPT_LIVE_WEBHOOK_SECRET : self::OPT_TEST_WEBHOOK_SECRET;
        $v = get_option($opt, '');
        return is_string($v) ? $v : '';
    }

    public function isConfigured(): bool
    {
        return $this->activeSecretKey() !== '' && $this->activeWebhookSecret() !== '';
    }

    public function testBannerEnabled(): bool
    {
        return (bool) get_option(self::OPT_SHOW_TEST_BANNER, true);
    }

    public function title(): string
    {
        $v = get_option(self::OPT_TITLE, '');
        return is_string($v) ? $v : '';
    }

    public function webhookUrlOverride(): string
    {
        $v = get_option(self::OPT_WEBHOOK_URL_OVERRIDE, '');
        return is_string($v) ? $v : '';
    }
}
```

- [ ] **Step 4: Run the tests and verify they pass**

Run: `vendor/bin/phpunit --filter ModeResolverTest`
Expected: PASS (6 tests, 0 failures).

- [ ] **Step 5: Commit**

```bash
git add src/Config.php tests/Unit/Config/ModeResolverTest.php
git commit -m "feat(config): mode-aware wp_options wrapper"
```

**Phase 2 gate:** `Config::mode()`, `activeSecretKey()`, `activeWebhookSecret()`, `isConfigured()` all behave per tests. All callers downstream read keys via `Config`, never directly.

---

# Phase 3: HTTP client (Layer 1)

**Why third:** key downstream dependency — every service in Layer 2 uses this. Making it WP-free means we can unit-test every service without a WP bootstrap.

### Task 3.1: `AslanWooException` hierarchy

**Files:**
- Create: `src/Client/AslanWooException.php`

- [ ] **Step 1: Write `src/Client/AslanWooException.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Client;

use Exception;
use Throwable;

/**
 * Base exception. All client errors derive from this so callers can catch
 * everything from the plugin without swallowing unrelated things.
 */
class AslanWooException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $aslanCode = null,
        public readonly ?string $aslanRequestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

/** Transient failure — network, 5xx, timeout. Callers may inform the user to retry. */
final class AslanTransientException extends AslanWooException {}

/** 4xx from Aslan — validation, bad key, bad mode. Not retryable. */
final class AslanRequestException extends AslanWooException {}

/** 401/403 from Aslan — key is invalid or wrong mode. */
final class AslanAuthException extends AslanRequestException {}
```

- [ ] **Step 2: Verify it autoloads**

Run: `php -r "require 'vendor/autoload.php'; new Aslan\\Woo\\Client\\AslanWooException('x');"`
Expected: no output, exit 0.

- [ ] **Step 3: Commit**

```bash
git add src/Client/AslanWooException.php
git commit -m "feat(client): exception hierarchy"
```

### Task 3.2: `AslanHttpClient` — happy-path session create

**Files:**
- Create: `src/Client/AslanHttpClient.php`
- Create: `tests/Unit/Client/AslanHttpClientTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Client;

use Aslan\Payment\Aslan;
use Aslan\Payment\Dto\CheckoutSession;
use Aslan\Payment\Resources\CheckoutSessions;
use Aslan\Woo\Client\AslanHttpClient;
use PHPUnit\Framework\TestCase;

final class AslanHttpClientTest extends TestCase
{
    public function test_create_checkout_session_delegates_to_sdk_with_idempotency_key(): void
    {
        $sdk = $this->createMock(Aslan::class);
        $resource = $this->createMock(CheckoutSessions::class);
        $sdk->checkoutSessions = $resource;

        $expected = CheckoutSession::fromArray([
            'id' => 'cs_live_01HZABC',
            'url' => 'https://pay.aslan.ma/cs_live_01HZABC',
            'status' => 'open',
            'amount' => 29900,
            'currency' => 'MAD',
            'successUrl' => 'https://shop.example.ma/success',
            'cancelUrl' => 'https://shop.example.ma/cancel',
            'expiresAt' => '2026-11-13T00:00:00.000Z',
            'createdAt' => '2026-11-13T00:00:00.000Z',
        ]);

        $resource->expects(self::once())
            ->method('create')
            ->with(
                [
                    'amount' => 29900,
                    'successUrl' => 'https://shop.example.ma/success',
                    'cancelUrl' => 'https://shop.example.ma/cancel',
                ],
                'order_42_1'
            )
            ->willReturn($expected);

        $client = new AslanHttpClient(fn () => $sdk);

        $result = $client->createCheckoutSession([
            'amount' => 29900,
            'successUrl' => 'https://shop.example.ma/success',
            'cancelUrl' => 'https://shop.example.ma/cancel',
        ], 'order_42_1');

        self::assertSame('cs_live_01HZABC', $result->id);
    }
}
```

- [ ] **Step 2: Run and verify fail**

Run: `vendor/bin/phpunit --filter AslanHttpClientTest`
Expected: FAIL — `AslanHttpClient` not found.

- [ ] **Step 3: Write `src/Client/AslanHttpClient.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Client;

use Aslan\Payment\Aslan;
use Aslan\Payment\AslanException;
use Aslan\Payment\Dto\CheckoutSession;
use Aslan\Payment\Dto\Refund;
use Aslan\Payment\Dto\WebhookConfig;
use Closure;

/**
 * Thin, WordPress-free wrapper over aslan/payment-php. One instance per mode.
 *
 * Factory closure defers SDK construction until first use so tests can inject
 * mocks and the Plugin class can wire up the SDK once Config is ready.
 */
final class AslanHttpClient
{
    private ?Aslan $sdk = null;

    /** @param Closure(): Aslan $sdkFactory */
    public function __construct(private readonly Closure $sdkFactory) {}

    private function sdk(): Aslan
    {
        if ($this->sdk === null) {
            $this->sdk = ($this->sdkFactory)();
        }
        return $this->sdk;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function createCheckoutSession(array $params, ?string $idempotencyKey = null): CheckoutSession
    {
        try {
            return $this->sdk()->checkoutSessions->create($params, $idempotencyKey);
        } catch (AslanException $e) {
            throw $this->map($e);
        }
    }

    public function retrieveCheckoutSession(string $id): CheckoutSession
    {
        try {
            return $this->sdk()->checkoutSessions->retrieve($id);
        } catch (AslanException $e) {
            throw $this->map($e);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function upsertWebhookConfig(array $params, ?string $idempotencyKey = null): WebhookConfig
    {
        try {
            return $this->sdk()->webhooksConfig->create($params, $idempotencyKey);
        } catch (AslanException $e) {
            throw $this->map($e);
        }
    }

    public function retrieveWebhookConfig(): WebhookConfig
    {
        try {
            return $this->sdk()->webhooksConfig->retrieve();
        } catch (AslanException $e) {
            throw $this->map($e);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function createRefund(array $params, ?string $idempotencyKey = null): Refund
    {
        try {
            return $this->sdk()->refunds->create($params, $idempotencyKey);
        } catch (AslanException $e) {
            throw $this->map($e);
        }
    }

    /**
     * Validate a secret key by calling GET /api-keys and return the list payload.
     * Succeeds for any valid key, 401s for an invalid one. Doubles as "what merchant
     * is this key for" — the ApiKey DTO carries a merchantId field (see SDK).
     *
     * @return list<\Aslan\Payment\Dto\ApiKey>
     */
    public function pingApiKeys(): array
    {
        try {
            $resp = $this->sdk()->apiKeys->list();
            return $resp->data;
        } catch (AslanException $e) {
            throw $this->map($e);
        }
    }

    private function map(AslanException $e): AslanWooException
    {
        $status  = method_exists($e, 'statusCode')   ? $e->statusCode()   : 0;
        $code    = method_exists($e, 'aslanCode')    ? $e->aslanCode()    : null;
        $reqId   = method_exists($e, 'requestId')    ? $e->requestId()    : null;

        if ($status === 401 || $status === 403) {
            return new AslanAuthException($e->getMessage(), $code, $reqId, $e);
        }
        if ($status >= 500 || $status === 0) {
            return new AslanTransientException($e->getMessage(), $code, $reqId, $e);
        }
        return new AslanRequestException($e->getMessage(), $code, $reqId, $e);
    }
}
```

- [ ] **Step 4: Run and verify pass**

Run: `vendor/bin/phpunit --filter AslanHttpClientTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Client/AslanHttpClient.php tests/Unit/Client/AslanHttpClientTest.php
git commit -m "feat(client): SDK wrapper, happy-path session create"
```

### Task 3.3: Exception mapping — 401, 4xx, 5xx, network

**Files:**
- Modify: `tests/Unit/Client/AslanHttpClientTest.php`

- [ ] **Step 1: Add failing tests for the three exception classes**

Append to `AslanHttpClientTest.php`:

```php
    public function test_401_response_becomes_auth_exception(): void
    {
        $sdk = $this->createMock(Aslan::class);
        $resource = $this->createMock(CheckoutSessions::class);
        $sdk->checkoutSessions = $resource;

        $sdkException = new class ('unauthorized', 401, null) extends \Aslan\Payment\AslanException {
            public function __construct(string $m, private int $s, private ?string $c) { parent::__construct($m); }
            public function statusCode(): int { return $this->s; }
            public function aslanCode(): ?string { return $this->c; }
            public function requestId(): ?string { return null; }
        };

        $resource->method('create')->willThrowException($sdkException);

        $client = new \Aslan\Woo\Client\AslanHttpClient(fn () => $sdk);

        $this->expectException(\Aslan\Woo\Client\AslanAuthException::class);
        $client->createCheckoutSession(['amount' => 1]);
    }

    public function test_4xx_response_becomes_request_exception(): void
    {
        $sdk = $this->createMock(Aslan::class);
        $resource = $this->createMock(CheckoutSessions::class);
        $sdk->checkoutSessions = $resource;

        $sdkException = new class ('invalid_amount', 400, 'INVALID_AMOUNT') extends \Aslan\Payment\AslanException {
            public function __construct(string $m, private int $s, private ?string $c) { parent::__construct($m); }
            public function statusCode(): int { return $this->s; }
            public function aslanCode(): ?string { return $this->c; }
            public function requestId(): ?string { return 'req_01'; }
        };

        $resource->method('create')->willThrowException($sdkException);

        $client = new \Aslan\Woo\Client\AslanHttpClient(fn () => $sdk);

        try {
            $client->createCheckoutSession(['amount' => 1]);
            self::fail('Expected AslanRequestException');
        } catch (\Aslan\Woo\Client\AslanRequestException $e) {
            self::assertSame('INVALID_AMOUNT', $e->aslanCode);
            self::assertSame('req_01', $e->aslanRequestId);
        }
    }

    public function test_5xx_response_becomes_transient_exception(): void
    {
        $sdk = $this->createMock(Aslan::class);
        $resource = $this->createMock(CheckoutSessions::class);
        $sdk->checkoutSessions = $resource;

        $sdkException = new class ('upstream_down', 503, null) extends \Aslan\Payment\AslanException {
            public function __construct(string $m, private int $s, private ?string $c) { parent::__construct($m); }
            public function statusCode(): int { return $this->s; }
            public function aslanCode(): ?string { return $this->c; }
            public function requestId(): ?string { return null; }
        };

        $resource->method('create')->willThrowException($sdkException);

        $client = new \Aslan\Woo\Client\AslanHttpClient(fn () => $sdk);

        $this->expectException(\Aslan\Woo\Client\AslanTransientException::class);
        $client->createCheckoutSession(['amount' => 1]);
    }
```

- [ ] **Step 2: Run and verify pass**

Run: `vendor/bin/phpunit --filter AslanHttpClientTest`
Expected: PASS (4 tests).

(The `map()` method already handles these — the test proves it.)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Client/AslanHttpClientTest.php
git commit -m "test(client): map SDK exceptions to typed plugin exceptions"
```

### Task 3.4: Connection-retry — network-level, two extra attempts

**Why:** spec §7 Class A. Only retry *connection* failures (no response at all), never retry 5xx response bodies — those are the caller's decision. We put the retry in the SDK factory wrapper, not in the `map()` path.

**Files:**
- Modify: `src/Client/AslanHttpClient.php`
- Modify: `tests/Unit/Client/AslanHttpClientTest.php`

- [ ] **Step 1: Add failing test — connection error retries then succeeds on 3rd attempt**

Append to `AslanHttpClientTest.php`:

```php
    public function test_connection_error_retries_twice_then_succeeds(): void
    {
        $sdk = $this->createMock(Aslan::class);
        $resource = $this->createMock(CheckoutSessions::class);
        $sdk->checkoutSessions = $resource;

        // No statusCode() means "no HTTP response arrived" — a connection failure.
        $networkException = new class ('ECONNRESET') extends \Aslan\Payment\AslanException {
            public function __construct(string $m) { parent::__construct($m); }
            public function statusCode(): int { return 0; }
            public function aslanCode(): ?string { return null; }
            public function requestId(): ?string { return null; }
        };

        $success = \Aslan\Payment\Dto\CheckoutSession::fromArray([
            'id' => 'cs_test', 'url' => 'https://x', 'status' => 'open',
            'amount' => 1, 'currency' => 'MAD',
            'successUrl' => 'https://a.ma', 'cancelUrl' => 'https://b.ma',
            'expiresAt' => '2026-11-13T00:00:00Z', 'createdAt' => '2026-11-13T00:00:00Z',
        ]);

        $resource->expects(self::exactly(3))
            ->method('create')
            ->willReturnOnConsecutiveCalls(
                self::throwException($networkException),
                self::throwException($networkException),
                $success,
            );

        $client = new \Aslan\Woo\Client\AslanHttpClient(fn () => $sdk);
        // Zero-delay backoff for tests
        $client->setBackoffSchedule([0, 0]);

        $result = $client->createCheckoutSession(['amount' => 1]);
        self::assertSame('cs_test', $result->id);
    }

    public function test_connection_error_after_3_attempts_throws_transient(): void
    {
        $sdk = $this->createMock(Aslan::class);
        $resource = $this->createMock(CheckoutSessions::class);
        $sdk->checkoutSessions = $resource;

        $networkException = new class ('ECONNRESET') extends \Aslan\Payment\AslanException {
            public function __construct(string $m) { parent::__construct($m); }
            public function statusCode(): int { return 0; }
            public function aslanCode(): ?string { return null; }
            public function requestId(): ?string { return null; }
        };

        $resource->expects(self::exactly(3))
            ->method('create')
            ->willThrowException($networkException);

        $client = new \Aslan\Woo\Client\AslanHttpClient(fn () => $sdk);
        $client->setBackoffSchedule([0, 0]);

        $this->expectException(\Aslan\Woo\Client\AslanTransientException::class);
        $client->createCheckoutSession(['amount' => 1]);
    }
```

- [ ] **Step 2: Run and verify fail**

Run: `vendor/bin/phpunit --filter AslanHttpClientTest`
Expected: FAIL — `setBackoffSchedule` method not found.

- [ ] **Step 3: Add retry logic to `AslanHttpClient`**

Modify `src/Client/AslanHttpClient.php`:

Add at the top of the class (after the `$sdk` property):

```php
    /** @var array<int, int> milliseconds to sleep between attempts */
    private array $backoff = [300, 900];

    /** @param array<int, int> $backoff */
    public function setBackoffSchedule(array $backoff): void
    {
        $this->backoff = $backoff;
    }

    /**
     * @template T
     * @param Closure(): T $op
     * @return T
     */
    private function withConnectionRetry(Closure $op): mixed
    {
        $attempts = 0;
        $maxAttempts = count($this->backoff) + 1;
        while (true) {
            try {
                return $op();
            } catch (AslanException $e) {
                $status = method_exists($e, 'statusCode') ? $e->statusCode() : 0;
                $isConnectionFailure = $status === 0;
                $attempts++;
                if (! $isConnectionFailure || $attempts >= $maxAttempts) {
                    throw $this->map($e);
                }
                $sleep = $this->backoff[$attempts - 1] ?? 0;
                if ($sleep > 0) {
                    usleep($sleep * 1000);
                }
            }
        }
    }
```

Change `createCheckoutSession`, `retrieveCheckoutSession`, `upsertWebhookConfig`, `retrieveWebhookConfig`, `createRefund`, `pingApiKeys` to wrap their try/catch with `withConnectionRetry`. Example for `createCheckoutSession`:

```php
    public function createCheckoutSession(array $params, ?string $idempotencyKey = null): CheckoutSession
    {
        return $this->withConnectionRetry(
            fn () => $this->sdk()->checkoutSessions->create($params, $idempotencyKey)
        );
    }
```

Remove the inline try/catch from each wrapped method — `withConnectionRetry` handles mapping and retry together.

- [ ] **Step 4: Run and verify pass**

Run: `vendor/bin/phpunit --filter AslanHttpClientTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Client/AslanHttpClient.php tests/Unit/Client/AslanHttpClientTest.php
git commit -m "feat(client): connection-retry with backoff"
```

**Phase 3 gate:** `AslanHttpClient` covers session create/retrieve, webhook upsert/retrieve, refund create, key ping. Maps SDK exceptions. Retries connection failures. Unit-tested without WP.

---

# Phase 4: KeyLifecycleService

**Why fourth:** this is the "automatic setup" magic the spec sells. Settings page writes keys → this service validates each key via `pingApiKeys()` → upserts webhook via `upsertWebhookConfig()` → stores returned signing secret. One service, one responsibility.

### Task 4.1: Validate-and-register for the active mode

**Files:**
- Create: `src/Service/KeyLifecycleService.php`
- Create: `tests/Unit/Service/KeyLifecycleServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Payment\Dto\ApiKey;
use Aslan\Payment\Dto\WebhookConfig;
use Aslan\Woo\Client\AslanAuthException;
use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Service\KeyLifecycleService;
use Aslan\Woo\Service\KeyRegistrationResult;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class KeyLifecycleServiceTest extends TestCase
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

    public function test_register_validates_key_then_upserts_webhook_and_returns_secret(): void
    {
        $client = $this->createMock(AslanHttpClient::class);

        $apiKey = ApiKey::fromArray([
            'id' => 'ak_01HZ',
            'name' => 'default',
            'type' => 'test',
            'prefix' => 'sk_test_mA3xZ',
            'merchantId' => 'mch_01HZ',
            'createdAt' => '2026-01-01T00:00:00Z',
        ]);
        $client->expects(self::once())->method('pingApiKeys')->willReturn([$apiKey]);

        $webhookConfig = WebhookConfig::fromArray([
            'id' => 'wh_01HZ',
            'url' => 'https://shop.example.ma/wp-json/aslan/v1/webhook',
            'status' => 'active',
            'events' => [],
            'signingSecret' => 'whsec_abcdef',
            'apiVersion' => '2026-11-01',
            'createdAt' => '2026-11-13T00:00:00Z',
            'updatedAt' => '2026-11-13T00:00:00Z',
        ]);
        $client->expects(self::once())
            ->method('upsertWebhookConfig')
            ->with([
                'url' => 'https://shop.example.ma/wp-json/aslan/v1/webhook',
            ])
            ->willReturn($webhookConfig);

        $service = new KeyLifecycleService(
            httpClientFactory: fn (string $key) => $client,
        );

        $result = $service->register('test', 'sk_test_mA3xZ_secret', 'https://shop.example.ma/wp-json/aslan/v1/webhook');

        self::assertInstanceOf(KeyRegistrationResult::class, $result);
        self::assertSame('mch_01HZ', $result->merchantId);
        self::assertSame('whsec_abcdef', $result->signingSecret);
        self::assertSame('https://shop.example.ma/wp-json/aslan/v1/webhook', $result->webhookUrl);
    }

    public function test_register_throws_invalid_key_on_401(): void
    {
        $client = $this->createMock(AslanHttpClient::class);
        $client->method('pingApiKeys')->willThrowException(new AslanAuthException('unauthorized'));

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key) => $client);

        $this->expectException(\Aslan\Woo\Service\InvalidKeyException::class);
        $service->register('test', 'sk_test_bad', 'https://shop.example.ma/wp-json/aslan/v1/webhook');
    }

    public function test_register_mismatched_key_type_throws_wrong_mode_exception(): void
    {
        $client = $this->createMock(AslanHttpClient::class);

        $apiKey = ApiKey::fromArray([
            'id' => 'ak_01HZ', 'name' => 'default', 'type' => 'live',
            'prefix' => 'sk_live_mA3xZ', 'merchantId' => 'mch_01HZ',
            'createdAt' => '2026-01-01T00:00:00Z',
        ]);
        $client->method('pingApiKeys')->willReturn([$apiKey]);

        $service = new KeyLifecycleService(httpClientFactory: fn (string $key) => $client);

        $this->expectException(\Aslan\Woo\Service\WrongModeException::class);
        $service->register('test', 'sk_live_mA3xZ_secret', 'https://shop.example.ma/wp-json/aslan/v1/webhook');
    }
}
```

- [ ] **Step 2: Run and verify fail**

Run: `vendor/bin/phpunit --filter KeyLifecycleServiceTest`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Write `src/Service/KeyLifecycleService.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

use Aslan\Woo\Client\AslanAuthException;
use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Client\AslanRequestException;
use Closure;

final class KeyRegistrationResult
{
    public function __construct(
        public readonly string $merchantId,
        public readonly string $signingSecret,
        public readonly string $webhookUrl,
    ) {}
}

class KeyLifecycleException extends \RuntimeException {}
final class InvalidKeyException extends KeyLifecycleException {}
final class WrongModeException extends KeyLifecycleException {}
final class WebhookRegistrationException extends KeyLifecycleException {}

/**
 * Runs when the merchant saves a key:
 *   1. Builds an AslanHttpClient bound to that specific key (not the Config-resolved one)
 *   2. Calls pingApiKeys() — 401 → InvalidKey, success → extract merchantId + confirm mode
 *   3. Calls upsertWebhookConfig() — stores the returned signingSecret
 *
 * This service is WordPress-free except for the caller — the factory closure
 * lets the caller inject a per-key SDK without tying this class to wp_options.
 */
final class KeyLifecycleService
{
    /** @param Closure(string): AslanHttpClient $httpClientFactory */
    public function __construct(private readonly Closure $httpClientFactory) {}

    public function register(string $expectedMode, string $secretKey, string $webhookUrl): KeyRegistrationResult
    {
        $client = ($this->httpClientFactory)($secretKey);

        try {
            $apiKeys = $client->pingApiKeys();
        } catch (AslanAuthException $e) {
            throw new InvalidKeyException('Secret key rejected by Aslan', 0, $e);
        }

        $apiKey = $apiKeys[0] ?? null;
        if ($apiKey === null) {
            throw new InvalidKeyException('API key validation returned no identity');
        }
        if ($apiKey->type !== $expectedMode) {
            throw new WrongModeException(
                sprintf('Key is %s but you are setting it as %s.', $apiKey->type, $expectedMode)
            );
        }

        try {
            $webhook = $client->upsertWebhookConfig(['url' => $webhookUrl]);
        } catch (AslanRequestException $e) {
            throw new WebhookRegistrationException('Aslan refused the webhook URL: ' . $e->getMessage(), 0, $e);
        }

        if ($webhook->signingSecret === null) {
            throw new WebhookRegistrationException('Aslan did not return a signing secret. Try "Re-register webhook".');
        }

        return new KeyRegistrationResult(
            merchantId: $apiKey->merchantId,
            signingSecret: $webhook->signingSecret,
            webhookUrl: $webhook->url,
        );
    }
}
```

- [ ] **Step 4: Run and verify pass**

Run: `vendor/bin/phpunit --filter KeyLifecycleServiceTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/KeyLifecycleService.php tests/Unit/Service/KeyLifecycleServiceTest.php
git commit -m "feat(service): KeyLifecycleService validates key and upserts webhook"
```

**Phase 4 gate:** service validates a key, cross-checks the mode, upserts the webhook endpoint, returns the signing secret + merchant id. Three tests pass.

---

# Phase 5: WooCommerce gateway skeleton

**Why fifth:** this is where WordPress enters. We register a gateway that *appears* in checkout but does nothing on `process_payment` yet — just redirects to a placeholder. Everything following plugs into this skeleton.

**Dev deps added here:** `php-stubs/wordpress-stubs`, `php-stubs/woocommerce-stubs` for PHPStan; `yoast/phpunit-polyfills` for WP-PHPUnit.

### Task 5.1: Add WP + Woo PHPStan stubs

**Files:**
- Modify: `composer.json`
- Modify: `phpstan.neon.dist`

- [ ] **Step 1: Add dev deps**

```bash
composer require --dev php-stubs/wordpress-stubs php-stubs/woocommerce-stubs
```

- [ ] **Step 2: Update `phpstan.neon.dist`**

```yaml
parameters:
  level: 8
  paths:
    - src
  excludePaths:
    - vendor
  scanDirectories:
    - vendor/php-stubs/wordpress-stubs
    - vendor/php-stubs/woocommerce-stubs
  bootstrapFiles:
    - tests/phpstan-bootstrap.php
```

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock phpstan.neon.dist
git commit -m "chore: WP + WooCommerce stubs for PHPStan"
```

### Task 5.2: Gateway class, registered via filter

**Files:**
- Create: `src/WooCommerce/Gateway.php`
- Modify: `src/Plugin.php`

- [ ] **Step 1: Write `src/WooCommerce/Gateway.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\WooCommerce;

use Aslan\Woo\Config;
use WC_Payment_Gateway;

/**
 * Minimal gateway — registered in Woo, surfaces on checkout, process_payment
 * still returns failure. Phase 6 wires up the real session create.
 */
final class Gateway extends WC_Payment_Gateway
{
    public const ID = 'aslan';

    public function __construct(private readonly Config $config)
    {
        $this->id                 = self::ID;
        $this->method_title       = __('Aslan', 'aslan-payments-for-woocommerce');
        $this->method_description = __('Accept card, mobile wallet, and cash-agent payments through Aslan.', 'aslan-payments-for-woocommerce');
        $this->has_fields         = false;
        $this->supports           = ['products', 'refunds'];
        $this->icon               = ASLAN_WOO_PLUGIN_URL . 'assets/logo.svg';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->config->title() !== ''
            ? $this->config->title()
            : __('Pay by card, wallet, or cash agent', 'aslan-payments-for-woocommerce');
        $this->description = '';
        $this->enabled     = $this->config->isConfigured() ? 'yes' : 'no';
    }

    public function init_form_fields(): void
    {
        // Phase 12 fills this in. Minimal skeleton only.
        $this->form_fields = [];
    }

    public function is_available(): bool
    {
        return $this->config->isConfigured();
    }

    public function process_payment($order_id): array
    {
        // Phase 6 implements this.
        return [
            'result'   => 'failure',
            'messages' => __('Aslan Payments is not yet implemented.', 'aslan-payments-for-woocommerce'),
        ];
    }
}
```

- [ ] **Step 2: Register the gateway in `src/Plugin.php`**

Replace the empty `register()` method with:

```php
    private function register(): void
    {
        $config = new Config();

        add_filter('woocommerce_payment_gateways', static function (array $gateways) use ($config): array {
            $gateways[] = new WooCommerce\Gateway($config);
            return $gateways;
        });
    }
```

Add `use Aslan\Woo\Config;` at the top.

- [ ] **Step 3: Verify PHPStan passes**

Run: `vendor/bin/phpstan analyse src --level=8`
Expected: `[OK] No errors`.

- [ ] **Step 4: Commit**

```bash
git add src/WooCommerce/Gateway.php src/Plugin.php
git commit -m "feat(gateway): skeleton WC_Payment_Gateway, registered via filter"
```

### Task 5.3: Integration test — gateway appears in Woo only when configured

**Files:**
- Create: `tests/bootstrap-wp.php`
- Modify: `phpunit.xml.dist` — add `integration` suite with its own bootstrap
- Create: `tests/Integration/WooCommerce/GatewayTest.php`

- [ ] **Step 1: Write `tests/bootstrap-wp.php`**

```php
<?php

declare(strict_types=1);

// WP-PHPUnit bootstrap — expects WP_TESTS_DIR env var pointing at a WordPress test install.
// Local: scripts/install-wp-tests.sh sets this up (added in this task's step 4).

$wpTestsDir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';
if (! file_exists($wpTestsDir . '/includes/functions.php')) {
    throw new RuntimeException("WP_TESTS_DIR not found at {$wpTestsDir}. Run bin/install-wp-tests.sh");
}

require_once $wpTestsDir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', function (): void {
    // Load WooCommerce + plugin under test.
    require_once dirname(__DIR__) . '/vendor/woocommerce/woocommerce/woocommerce.php';
    require_once dirname(__DIR__) . '/aslan-payments-for-woocommerce.php';
});

require $wpTestsDir . '/includes/bootstrap.php';
```

- [ ] **Step 2: Update `phpunit.xml.dist`**

Replace the testsuites block with:

```xml
  <testsuites>
    <testsuite name="unit">
      <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="integration">
      <directory>tests/Integration</directory>
    </testsuite>
  </testsuites>
```

And add a separate bootstrap for the integration suite — easiest: a shell wrapper. Or use `@runTestsInSeparateProcesses`. **Simplest fix:** detect the testsuite inside `tests/bootstrap.php`:

Replace `tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Integration suite needs the WP test harness; detect via an env var the
// suite sets in its CI matrix (ASLAN_WOO_SUITE=integration).
if (getenv('ASLAN_WOO_SUITE') === 'integration') {
    require __DIR__ . '/bootstrap-wp.php';
}
```

- [ ] **Step 3: Add dev deps: woocommerce + scripts**

```bash
composer require --dev wp-phpunit/wp-phpunit woocommerce/woocommerce
```

(If `woocommerce/woocommerce` is not on Packagist, instead use the official zip-based install script — see Phase 15 for the real E2E setup. For integration tests, add a `bin/install-wp-tests.sh` copy from WP-CLI's scaffold.)

Create `bin/install-wp-tests.sh` — [copy verbatim from WordPress-coding-standards' canonical script, 90 lines of bash] (link in a comment at the top: `# Source: https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/install.sh`).

- [ ] **Step 4: Write `tests/Integration/WooCommerce/GatewayTest.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Integration\WooCommerce;

use WC_Payment_Gateways;
use WP_UnitTestCase;

final class GatewayTest extends WP_UnitTestCase
{
    public function test_gateway_not_available_when_unconfigured(): void
    {
        update_option('aslan_woo_test_secret_key', '');
        update_option('aslan_woo_test_webhook_secret', '');

        $gateways = WC_Payment_Gateways::instance()->payment_gateways();
        $aslan = $gateways['aslan'] ?? null;

        self::assertNotNull($aslan, 'Gateway should be registered');
        self::assertFalse($aslan->is_available(), 'Gateway should not be available when keys are missing');
    }

    public function test_gateway_available_when_keys_and_secret_set(): void
    {
        update_option('aslan_woo_mode', 'test');
        update_option('aslan_woo_test_secret_key', 'sk_test_abc_secret');
        update_option('aslan_woo_test_webhook_secret', 'whsec_abc');

        $gateways = WC_Payment_Gateways::instance()->payment_gateways();
        $aslan = $gateways['aslan'] ?? null;

        self::assertNotNull($aslan);
        self::assertTrue($aslan->is_available());
    }
}
```

- [ ] **Step 5: Run**

Run: `ASLAN_WOO_SUITE=integration vendor/bin/phpunit --testsuite integration`
Expected: PASS (2 tests). If WP test harness isn't set up locally, note it skips — CI runs this.

- [ ] **Step 6: Commit**

```bash
git add tests/bootstrap-wp.php tests/bootstrap.php tests/Integration/WooCommerce/GatewayTest.php bin/install-wp-tests.sh phpunit.xml.dist
git commit -m "test(integration): gateway visible when configured"
```

**Phase 5 gate:** gateway appears in WooCommerce checkout UI when `isConfigured()`, hidden otherwise. HPOS declared. `process_payment()` still returns failure — phase 6 fixes that.

---

# Phase 6: CheckoutService + process_payment

**Why sixth:** this is the core customer flow — "Place order" → Aslan redirect. After this phase, a merchant with keys set can take a (not yet confirmed) payment.

### Task 6.1: `AmountValidator` — whole-MAD enforcement

**Files:**
- Create: `src/Service/AmountValidator.php`
- Create: `tests/Unit/Service/AmountValidatorTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Woo\Service\AmountValidator;
use Aslan\Woo\Service\NonWholeMadException;
use PHPUnit\Framework\TestCase;

final class AmountValidatorTest extends TestCase
{
    public function test_accepts_whole_mad_centimes(): void
    {
        AmountValidator::assertWholeMadCentimes(29900);
        AmountValidator::assertWholeMadCentimes(100);
        self::assertTrue(true); // reached without exception
    }

    public function test_rejects_fractional_centimes(): void
    {
        $this->expectException(NonWholeMadException::class);
        AmountValidator::assertWholeMadCentimes(12345);
    }

    public function test_rejects_zero(): void
    {
        $this->expectException(NonWholeMadException::class);
        AmountValidator::assertWholeMadCentimes(0);
    }

    public function test_mad_to_centimes_converts_whole_amount(): void
    {
        self::assertSame(29900, AmountValidator::madToCentimes(299.0));
    }

    public function test_mad_to_centimes_rejects_fractional_mad(): void
    {
        $this->expectException(NonWholeMadException::class);
        AmountValidator::madToCentimes(299.50);
    }
}
```

- [ ] **Step 2: Run, fail**

Run: `vendor/bin/phpunit --filter AmountValidatorTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `src/Service/AmountValidator.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

use RuntimeException;

final class NonWholeMadException extends RuntimeException {}

final class AmountValidator
{
    public static function assertWholeMadCentimes(int $centimes): void
    {
        if ($centimes <= 0 || $centimes % 100 !== 0) {
            throw new NonWholeMadException(
                sprintf('Amount must be a whole number of MAD (multiple of 100 centimes). Got: %d', $centimes)
            );
        }
    }

    /**
     * Convert a MAD amount (e.g. 299.00) into centimes (29900).
     * Fractional MAD is rejected — see spec §10.
     */
    public static function madToCentimes(float $mad): int
    {
        $centimes = (int) round($mad * 100);
        // Reject if the input had a sub-MAD component (e.g. 299.50).
        if (abs($mad * 100 - $centimes) > 0.0001 || $centimes % 100 !== 0) {
            throw new NonWholeMadException(
                sprintf('Amount must be whole MAD. Got: %.2f MAD', $mad)
            );
        }
        if ($centimes <= 0) {
            throw new NonWholeMadException(
                sprintf('Amount must be positive. Got: %.2f MAD', $mad)
            );
        }
        return $centimes;
    }
}
```

- [ ] **Step 4: Run, pass**

Run: `vendor/bin/phpunit --filter AmountValidatorTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/AmountValidator.php tests/Unit/Service/AmountValidatorTest.php
git commit -m "feat(service): AmountValidator enforces whole-MAD"
```

### Task 6.2: `CheckoutService::createSessionForOrder`

**Files:**
- Create: `src/Service/CheckoutService.php`
- Create: `tests/Unit/Service/CheckoutServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Payment\Dto\CheckoutSession;
use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Service\CheckoutService;
use Aslan\Woo\Service\CheckoutSessionResult;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class CheckoutServiceTest extends TestCase
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

    public function test_creates_session_for_whole_mad_order(): void
    {
        $order = $this->createMock(WC_Order::class);
        $order->method('get_id')->willReturn(42);
        $order->method('get_total')->willReturn('299.00');
        $order->method('get_currency')->willReturn('MAD');
        $order->method('get_billing_email')->willReturn('customer@example.ma');
        $order->method('get_billing_first_name')->willReturn('Aya');
        $order->method('get_billing_last_name')->willReturn('Alaoui');
        $order->method('get_billing_phone')->willReturn('+212600000000');
        $order->method('get_meta')->willReturn('');
        $order->expects(self::once())
            ->method('update_meta_data')
            ->with('_aslan_session_id', 'cs_test_01');
        $order->expects(self::once())->method('save');

        Functions\when('wc_get_checkout_url')->justReturn('https://shop.example.ma/checkout');
        Functions\when('home_url')->alias(fn ($p) => 'https://shop.example.ma' . $p);

        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::once())
            ->method('createCheckoutSession')
            ->with(
                self::callback(function (array $params) {
                    self::assertSame(29900, $params['amount']);
                    self::assertSame('MAD', $params['currency']);
                    self::assertStringStartsWith('https://shop.example.ma', $params['successUrl']);
                    self::assertStringStartsWith('https://shop.example.ma', $params['cancelUrl']);
                    self::assertSame('customer@example.ma', $params['customer']['email']);
                    self::assertSame(['wc_order_id' => '42'], $params['metadata']);
                    return true;
                }),
                'order_42_1'
            )
            ->willReturn(CheckoutSession::fromArray([
                'id' => 'cs_test_01',
                'url' => 'https://pay.aslan.ma/cs_test_01',
                'status' => 'open',
                'amount' => 29900,
                'currency' => 'MAD',
                'successUrl' => 'https://shop.example.ma/aslan/return',
                'cancelUrl' => 'https://shop.example.ma/aslan/cancel',
                'expiresAt' => '2026-11-13T01:00:00Z',
                'createdAt' => '2026-11-13T00:00:00Z',
            ]));

        $service = new CheckoutService($client);
        $result = $service->createSessionForOrder($order);

        self::assertInstanceOf(CheckoutSessionResult::class, $result);
        self::assertSame('cs_test_01', $result->sessionId);
        self::assertSame('https://pay.aslan.ma/cs_test_01', $result->redirectUrl);
    }

    public function test_rejects_fractional_mad_order(): void
    {
        $order = $this->createMock(WC_Order::class);
        $order->method('get_id')->willReturn(42);
        $order->method('get_total')->willReturn('299.50');

        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::never())->method('createCheckoutSession');

        $service = new CheckoutService($client);

        $this->expectException(\Aslan\Woo\Service\NonWholeMadException::class);
        $service->createSessionForOrder($order);
    }

    public function test_increments_attempt_number_on_retry(): void
    {
        $order = $this->createMock(WC_Order::class);
        $order->method('get_id')->willReturn(42);
        $order->method('get_total')->willReturn('100.00');
        $order->method('get_currency')->willReturn('MAD');
        $order->method('get_billing_email')->willReturn('x@example.ma');
        $order->method('get_billing_first_name')->willReturn('A');
        $order->method('get_billing_last_name')->willReturn('B');
        $order->method('get_billing_phone')->willReturn('+212600000000');
        $order->method('get_meta')->with('_aslan_attempt_count')->willReturn('2');
        $order->expects(self::once())->method('update_meta_data')->with('_aslan_session_id', 'cs_test_02');
        $order->method('save');

        Functions\when('wc_get_checkout_url')->justReturn('https://shop.example.ma/checkout');
        Functions\when('home_url')->alias(fn ($p) => 'https://shop.example.ma' . $p);

        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::once())
            ->method('createCheckoutSession')
            ->with(self::anything(), 'order_42_3')
            ->willReturn(CheckoutSession::fromArray([
                'id' => 'cs_test_02', 'url' => 'https://x', 'status' => 'open',
                'amount' => 10000, 'currency' => 'MAD',
                'successUrl' => 'https://a.ma', 'cancelUrl' => 'https://b.ma',
                'expiresAt' => '2026-11-13T01:00:00Z', 'createdAt' => '2026-11-13T00:00:00Z',
            ]));

        $service = new CheckoutService($client);
        $service->createSessionForOrder($order);
    }
}
```

- [ ] **Step 2: Run, fail**

Run: `vendor/bin/phpunit --filter CheckoutServiceTest`
Expected: FAIL.

- [ ] **Step 3: Write `src/Service/CheckoutService.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

use Aslan\Woo\Client\AslanHttpClient;
use WC_Order;

final class CheckoutSessionResult
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $redirectUrl,
    ) {}
}

final class CheckoutService
{
    public const META_SESSION_ID    = '_aslan_session_id';
    public const META_PAYMENT_ID    = '_aslan_payment_id';
    public const META_ATTEMPT_COUNT = '_aslan_attempt_count';

    public function __construct(private readonly AslanHttpClient $client) {}

    public function createSessionForOrder(WC_Order $order): CheckoutSessionResult
    {
        $orderId = (int) $order->get_id();
        $centimes = AmountValidator::madToCentimes((float) $order->get_total());

        $prev = (int) ($order->get_meta(self::META_ATTEMPT_COUNT) ?: 0);
        $attempt = $prev + 1;

        $successUrl = home_url('/aslan/return?order=' . $orderId);
        $cancelUrl  = home_url('/aslan/cancel?order=' . $orderId);

        $payload = [
            'amount'      => $centimes,
            'currency'    => 'MAD',
            'successUrl'  => $successUrl,
            'cancelUrl'   => $cancelUrl,
            'customer'    => [
                'email'     => (string) $order->get_billing_email(),
                'firstName' => (string) $order->get_billing_first_name(),
                'lastName'  => (string) $order->get_billing_last_name(),
                'phone'     => (string) $order->get_billing_phone(),
            ],
            'metadata'    => [
                'wc_order_id' => (string) $orderId,
            ],
        ];

        $session = $this->client->createCheckoutSession($payload, sprintf('order_%d_%d', $orderId, $attempt));

        $order->update_meta_data(self::META_SESSION_ID, $session->id);
        $order->update_meta_data(self::META_ATTEMPT_COUNT, (string) $attempt);
        $order->save();

        return new CheckoutSessionResult(
            sessionId: $session->id,
            redirectUrl: $session->url,
        );
    }
}
```

- [ ] **Step 4: Run, pass**

Run: `vendor/bin/phpunit --filter CheckoutServiceTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/CheckoutService.php tests/Unit/Service/CheckoutServiceTest.php
git commit -m "feat(service): CheckoutService — WC_Order to Aslan session"
```

### Task 6.3: Wire `process_payment` to `CheckoutService`

**Files:**
- Modify: `src/WooCommerce/Gateway.php`
- Modify: `src/Plugin.php`

- [ ] **Step 1: Modify `Gateway` to accept a `CheckoutService` and use it**

In `src/WooCommerce/Gateway.php`:

```php
    public function __construct(
        private readonly Config $config,
        private readonly \Aslan\Woo\Service\CheckoutService $checkoutService,
    ) {
        // ... existing constructor body unchanged ...
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order) {
            return ['result' => 'failure', 'messages' => __('Order not found.', 'aslan-payments-for-woocommerce')];
        }

        try {
            $result = $this->checkoutService->createSessionForOrder($order);
        } catch (\Aslan\Woo\Service\NonWholeMadException $e) {
            wc_add_notice(
                __('Aslan accepts whole MAD amounts only. Please remove decimals from product prices or taxes.', 'aslan-payments-for-woocommerce'),
                'error'
            );
            return ['result' => 'failure'];
        } catch (\Aslan\Woo\Client\AslanTransientException $e) {
            $order->add_order_note(sprintf('Aslan transient error: %s (req %s)', $e->getMessage(), $e->aslanRequestId ?? 'n/a'));
            wc_add_notice(
                __('Payment service is temporarily unavailable. Please try again in a moment.', 'aslan-payments-for-woocommerce'),
                'error'
            );
            return ['result' => 'failure'];
        } catch (\Aslan\Woo\Client\AslanWooException $e) {
            $order->add_order_note(sprintf('Aslan error: %s', $e->getMessage()));
            wc_add_notice(
                __('Payment could not be started. Please try again or choose another method.', 'aslan-payments-for-woocommerce'),
                'error'
            );
            return ['result' => 'failure'];
        }

        $order->update_status('pending', __('Awaiting Aslan payment', 'aslan-payments-for-woocommerce'));

        return [
            'result'   => 'success',
            'redirect' => $result->redirectUrl,
        ];
    }
```

- [ ] **Step 2: Wire the service in `Plugin::register()`**

```php
    private function register(): void
    {
        $config = new Config();

        $httpClient = new Client\AslanHttpClient(static function () use ($config): \Aslan\Payment\Aslan {
            return new \Aslan\Payment\Aslan($config->activeSecretKey());
        });
        $checkoutService = new Service\CheckoutService($httpClient);

        add_filter('woocommerce_payment_gateways', static function (array $gateways) use ($config, $checkoutService): array {
            $gateways[] = new WooCommerce\Gateway($config, $checkoutService);
            return $gateways;
        });
    }
```

Add required `use` statements.

- [ ] **Step 3: PHPStan passes**

Run: `vendor/bin/phpstan analyse src --level=8`
Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add src/WooCommerce/Gateway.php src/Plugin.php
git commit -m "feat(gateway): process_payment creates Aslan session and redirects"
```

**Phase 6 gate:** customer clicks "Place order" with Aslan selected → gets redirected to the Aslan hosted page. Order has `_aslan_session_id`, status `pending`. 4xx / 5xx / network failures each produce a sensible notice and a `failure` result.

---

# Phase 7: WebhookService + REST route

**Why seventh:** the webhook is our only trust source — nothing else should flip an order to paid/failed. This phase makes orders actually complete.

### Task 7.1: `WebhookService::verify` — HMAC check

**Files:**
- Create: `src/Service/WebhookService.php`
- Create: `tests/Unit/Service/WebhookServiceTest.php`

- [ ] **Step 1: Write failing test for signature verification**

```php
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
        $t = time();
        $body = '{"id":"evt_01HZ","event":"payment.succeeded","data":{}}';
        $sig = hash_hmac('sha256', $t . '.' . $body, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $t, $sig);

        $event = $service->verify($body, $header, self::SECRET);

        self::assertSame('evt_01HZ', $event['id']);
        self::assertSame('payment.succeeded', $event['event']);
    }

    public function test_invalid_signature_throws(): void
    {
        $service = new WebhookService();
        $t = time();
        $body = '{"id":"evt_01HZ"}';
        $header = sprintf('t=%d,v1=%s', $t, 'baadbaadbaadbaad');

        $this->expectException(WebhookVerificationException::class);
        $service->verify($body, $header, self::SECRET);
    }

    public function test_stale_timestamp_throws(): void
    {
        $service = new WebhookService();
        $t = time() - 600; // 10 min old
        $body = '{"id":"evt_01HZ"}';
        $sig = hash_hmac('sha256', $t . '.' . $body, self::SECRET);
        $header = sprintf('t=%d,v1=%s', $t, $sig);

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
```

- [ ] **Step 2: Run, fail**

Run: `vendor/bin/phpunit --filter WebhookServiceTest`
Expected: FAIL.

- [ ] **Step 3: Write `src/Service/WebhookService.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

use RuntimeException;

final class WebhookVerificationException extends RuntimeException {}

final class WebhookService
{
    public const MAX_AGE_SECONDS = 300;

    /**
     * Verifies the X-Webhook-Signature header and returns the decoded event.
     * Throws if signature mismatch, stale timestamp, or malformed header.
     *
     * @return array<string, mixed>
     */
    public function verify(string $rawBody, string $signatureHeader, string $secret): array
    {
        $parts = [];
        foreach (explode(',', $signatureHeader) as $p) {
            $kv = explode('=', $p, 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;
        if ($timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            throw new WebhookVerificationException('Malformed signature header');
        }

        $age = abs(time() - (int) $timestamp);
        if ($age > self::MAX_AGE_SECONDS) {
            throw new WebhookVerificationException('Webhook timestamp too old');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if (! hash_equals($expected, $signature)) {
            throw new WebhookVerificationException('Invalid signature');
        }

        $event = json_decode($rawBody, true);
        if (! is_array($event) || ! isset($event['id'], $event['event'])) {
            throw new WebhookVerificationException('Event body not well-formed');
        }
        return $event;
    }
}
```

- [ ] **Step 4: Run, pass**

Run: `vendor/bin/phpunit --filter WebhookServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/WebhookService.php tests/Unit/Service/WebhookServiceTest.php
git commit -m "feat(webhook): HMAC-SHA256 signature verification"
```

### Task 7.2: Event routing — `payment.succeeded` → `payment_complete`

**Files:**
- Modify: `src/Service/WebhookService.php`
- Modify: `tests/Unit/Service/WebhookServiceTest.php`

- [ ] **Step 1: Add failing test for routing**

Append to `WebhookServiceTest.php`:

```php
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
                    'id'       => 'pay_01HZ',
                    'metadata' => ['wc_order_id' => '42'],
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
                    'id'       => 'pay_02HZ',
                    'metadata' => ['wc_order_id' => '42'],
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

        // Reaching here is the assertion — no exception thrown
        self::assertTrue(true);
    }
```

- [ ] **Step 2: Run, fail**

Expected: FAIL — `handle()` method missing.

- [ ] **Step 3: Add `handle()` to `WebhookService`**

In `src/Service/WebhookService.php`:

```php
    /**
     * Dispatch a verified event to the right order mutation.
     *
     * @param array<string, mixed>              $event       Decoded event body.
     * @param callable(int): ?\WC_Order         $orderLoader Called with the wc_order_id from metadata.
     */
    public function handle(array $event, callable $orderLoader): void
    {
        $eventType = (string) ($event['event'] ?? '');
        $data      = $event['data'] ?? [];
        if (! is_array($data)) {
            return;
        }
        $wcOrderId = isset($data['metadata']['wc_order_id']) ? (int) $data['metadata']['wc_order_id'] : 0;
        if ($wcOrderId <= 0) {
            return;
        }
        $order = $orderLoader($wcOrderId);
        if ($order === null) {
            return;
        }

        switch ($eventType) {
            case 'payment.succeeded':
                $paymentId = (string) ($data['id'] ?? '');
                $order->update_meta_data('_aslan_payment_id', $paymentId);
                if (isset($data['cardLast4'])) {
                    $order->update_meta_data('_aslan_card_last4', (string) $data['cardLast4']);
                }
                $order->save();
                $order->payment_complete($paymentId);
                break;

            case 'payment.failed':
                $reason = (string) ($data['failureReason'] ?? 'unknown');
                $order->update_status(
                    'failed',
                    sprintf('Aslan reported payment failed: %s', $reason)
                );
                break;

            case 'payment.pending_cash':
                $order->update_status(
                    'aslan-awaiting-cash',
                    'Aslan reported cash payment pending at the agent network'
                );
                break;

            case 'refund.succeeded':
                // Phase 9 implements. Leave hook here with a no-op body for now.
                break;

            default:
                // Ignore unknown events — Aslan may add new types before we support them.
                break;
        }
    }
```

- [ ] **Step 4: Run, pass**

Run: `vendor/bin/phpunit --filter WebhookServiceTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Service/WebhookService.php tests/Unit/Service/WebhookServiceTest.php
git commit -m "feat(webhook): route payment.succeeded / failed / pending_cash"
```

### Task 7.3: Idempotency — event-id guard

**Files:**
- Modify: `src/Service/WebhookService.php`
- Modify: `tests/Unit/Service/WebhookServiceTest.php`

- [ ] **Step 1: Failing test — duplicate delivery is a no-op**

```php
    public function test_duplicate_event_id_is_noop(): void
    {
        $order = $this->createMock(\WC_Order::class);
        $order->expects(self::once())->method('payment_complete');

        $seen = [];
        $recorder = static function (string $eventId) use (&$seen): bool {
            if (in_array($eventId, $seen, true)) {
                return false; // already seen → don't process
            }
            $seen[] = $eventId;
            return true;
        };

        $service = new WebhookService();
        $event = [
            'id' => 'evt_dup', 'event' => 'payment.succeeded',
            'data' => ['id' => 'pay_01', 'metadata' => ['wc_order_id' => '42']],
        ];

        $service->handle($event, fn () => $order, $recorder);
        $service->handle($event, fn () => $order, $recorder); // second call: no-op
    }
```

- [ ] **Step 2: Add `$idempotencyRecorder` parameter**

In `src/Service/WebhookService.php`, change signature:

```php
    /**
     * @param callable(int): ?\WC_Order   $orderLoader
     * @param (callable(string): bool)|null $idempotencyRecorder returns true if this is the first time we see the event id
     */
    public function handle(array $event, callable $orderLoader, ?callable $idempotencyRecorder = null): void
    {
        $eventId = (string) ($event['id'] ?? '');
        if ($idempotencyRecorder !== null && $eventId !== '') {
            $isNew = $idempotencyRecorder($eventId);
            if (! $isNew) {
                return;
            }
        }
        // ... existing body ...
    }
```

- [ ] **Step 3: Run, pass**

Run: `vendor/bin/phpunit --filter WebhookServiceTest`
Expected: PASS (8 tests).

- [ ] **Step 4: Commit**

```bash
git add src/Service/WebhookService.php tests/Unit/Service/WebhookServiceTest.php
git commit -m "feat(webhook): idempotency via event-id guard"
```

### Task 7.4: REST route — `/wp-json/aslan/v1/webhook`

**Files:**
- Create: `src/Rest/WebhookRoute.php`
- Create: `tests/Integration/Rest/WebhookRouteTest.php`
- Modify: `src/Plugin.php`

- [ ] **Step 1: Write `src/Rest/WebhookRoute.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Rest;

use Aslan\Woo\Config;
use Aslan\Woo\Service\WebhookService;
use Aslan\Woo\Service\WebhookVerificationException;
use WP_REST_Request;
use WP_REST_Response;

final class WebhookRoute
{
    public function __construct(
        private readonly Config $config,
        private readonly WebhookService $service,
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('aslan/v1', '/webhook', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $rawBody   = $request->get_body();
        $sigHeader = (string) $request->get_header('x_webhook_signature');
        $secret    = $this->config->activeWebhookSecret();
        if ($secret === '') {
            return new WP_REST_Response(['error' => 'not_configured'], 401);
        }

        try {
            $event = $this->service->verify($rawBody, $sigHeader, $secret);
        } catch (WebhookVerificationException $e) {
            return new WP_REST_Response(['error' => 'invalid_signature'], 401);
        }

        $this->service->handle(
            $event,
            orderLoader: static fn (int $id) => wc_get_order($id) ?: null,
            idempotencyRecorder: static function (string $eventId): bool {
                $key = 'aslan_evt_' . $eventId;
                if (get_transient($key) !== false) {
                    return false;
                }
                set_transient($key, 1, 7 * DAY_IN_SECONDS);
                return true;
            },
        );

        return new WP_REST_Response(['ok' => true], 200);
    }
}
```

- [ ] **Step 2: Wire in `Plugin::register()`**

```php
        $webhookService = new Service\WebhookService();
        (new Rest\WebhookRoute($config, $webhookService))->register();
```

- [ ] **Step 3: Write integration test `tests/Integration/Rest/WebhookRouteTest.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Integration\Rest;

use WP_REST_Request;
use WP_Test_REST_TestCase;

final class WebhookRouteTest extends WP_Test_REST_TestCase
{
    public function test_valid_webhook_transitions_order_to_completed(): void
    {
        update_option('aslan_woo_mode', 'test');
        update_option('aslan_woo_test_webhook_secret', 'whsec_test_abc');

        $order = wc_create_order();
        $order->set_payment_method('aslan');
        $order->update_status('pending');
        $order->save();

        $body = json_encode([
            'id'    => 'evt_int_01',
            'event' => 'payment.succeeded',
            'data'  => [
                'id'        => 'pay_int_01',
                'metadata'  => ['wc_order_id' => (string) $order->get_id()],
                'cardLast4' => '4242',
            ],
        ]);
        $t = time();
        $sig = hash_hmac('sha256', $t . '.' . $body, 'whsec_test_abc');

        $request = new WP_REST_Request('POST', '/aslan/v1/webhook');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-Webhook-Signature', sprintf('t=%d,v1=%s', $t, $sig));
        $request->set_body($body);

        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        $fresh = wc_get_order($order->get_id());
        self::assertSame('completed', $fresh->get_status());
    }

    public function test_bad_signature_returns_401(): void
    {
        update_option('aslan_woo_test_webhook_secret', 'whsec_test_abc');

        $request = new WP_REST_Request('POST', '/aslan/v1/webhook');
        $request->set_body('{"id":"evt_x"}');
        $request->set_header('X-Webhook-Signature', 't=1,v1=deadbeef');

        $response = rest_do_request($request);
        self::assertSame(401, $response->get_status());
    }
}
```

- [ ] **Step 4: Run**

Run: `ASLAN_WOO_SUITE=integration vendor/bin/phpunit --testsuite integration`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Rest/WebhookRoute.php src/Plugin.php tests/Integration/Rest/WebhookRouteTest.php
git commit -m "feat(rest): webhook route verifies signature and drives order state"
```

**Phase 7 gate:** signed webhooks flip orders to `completed` / `failed` / `aslan-awaiting-cash`. Bad signatures 401. Duplicate events are no-ops. The webhook is the *only* code that calls `payment_complete` / `update_status('failed')`.

---

# Phase 8: Order status handling (awaiting-cash)

### Task 8.1: Register custom `aslan-awaiting-cash` status

**Files:**
- Create: `src/WooCommerce/OrderStatus.php`
- Create: `tests/Integration/WooCommerce/OrderStatusTest.php`
- Modify: `src/Plugin.php`

- [ ] **Step 1: Write `src/WooCommerce/OrderStatus.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\WooCommerce;

final class OrderStatus
{
    public const AWAITING_CASH = 'aslan-awaiting-cash';

    public function register(): void
    {
        add_action('init', static function (): void {
            register_post_status('wc-' . self::AWAITING_CASH, [
                'label'                     => _x('Awaiting cash payment', 'Order status', 'aslan-payments-for-woocommerce'),
                'public'                    => false,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: number of orders */
                'label_count'               => _n_noop(
                    'Awaiting cash payment <span class="count">(%s)</span>',
                    'Awaiting cash payment <span class="count">(%s)</span>',
                    'aslan-payments-for-woocommerce'
                ),
            ]);
        });

        add_filter('wc_order_statuses', static function (array $statuses): array {
            $statuses['wc-' . self::AWAITING_CASH] = _x('Awaiting cash payment', 'Order status', 'aslan-payments-for-woocommerce');
            return $statuses;
        });
    }
}
```

- [ ] **Step 2: Register in `Plugin::register()`**

```php
        (new WooCommerce\OrderStatus())->register();
```

- [ ] **Step 3: Write integration test**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Integration\WooCommerce;

use WP_UnitTestCase;

final class OrderStatusTest extends WP_UnitTestCase
{
    public function test_aslan_awaiting_cash_is_registered(): void
    {
        $statuses = wc_get_order_statuses();
        self::assertArrayHasKey('wc-aslan-awaiting-cash', $statuses);
    }

    public function test_order_can_move_to_awaiting_cash(): void
    {
        $order = wc_create_order();
        $order->update_status('aslan-awaiting-cash');
        $order->save();

        $fresh = wc_get_order($order->get_id());
        self::assertSame('aslan-awaiting-cash', $fresh->get_status());
    }
}
```

- [ ] **Step 4: Run, pass**

Run: `ASLAN_WOO_SUITE=integration vendor/bin/phpunit --testsuite integration`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/WooCommerce/OrderStatus.php src/Plugin.php tests/Integration/WooCommerce/OrderStatusTest.php
git commit -m "feat(status): register aslan-awaiting-cash order status"
```

**Phase 8 gate:** cash-pending webhook moves order to `aslan-awaiting-cash`, then later `payment.succeeded` moves it to `completed`.

---

# Phase 9: Refund flow

### Task 9.1: `RefundService` + `process_refund` hook

**Files:**
- Create: `src/Service/RefundService.php`
- Create: `tests/Unit/Service/RefundServiceTest.php`
- Modify: `src/WooCommerce/Gateway.php` — add `process_refund($order_id, $amount, $reason)`
- Modify: `src/Service/WebhookService.php` — flesh out `refund.succeeded`

- [ ] **Step 1: Write failing unit test for `RefundService`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Payment\Dto\Refund;
use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Service\RefundService;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class RefundServiceTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_refunds_whole_mad_amount_against_payment_id(): void
    {
        $order = $this->createMock(WC_Order::class);
        $order->method('get_meta')->with('_aslan_payment_id')->willReturn('pay_01HZ');
        $order->method('get_id')->willReturn(42);

        $client = $this->createMock(AslanHttpClient::class);
        $client->expects(self::once())
            ->method('createRefund')
            ->with(
                [
                    'paymentId' => 'pay_01HZ',
                    'amount'    => 10000,
                    'reason'    => 'customer_requested',
                ],
                'refund_42_pay_01HZ_10000'
            )
            ->willReturn(Refund::fromArray([
                'id' => 'rf_01HZ',
                'paymentId' => 'pay_01HZ',
                'amount' => 10000,
                'currency' => 'MAD',
                'status' => 'pending',
                'createdAt' => '2026-11-13T00:00:00Z',
            ]));

        $service = new RefundService($client);
        $refundId = $service->refund($order, 100.00, 'customer_requested');

        self::assertSame('rf_01HZ', $refundId);
    }
}
```

- [ ] **Step 2: Write `src/Service/RefundService.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

use Aslan\Woo\Client\AslanHttpClient;
use RuntimeException;
use WC_Order;

final class RefundNotPossibleException extends RuntimeException {}

final class RefundService
{
    public function __construct(private readonly AslanHttpClient $client) {}

    public function refund(WC_Order $order, float $amountMad, string $reason = ''): string
    {
        $paymentId = (string) $order->get_meta('_aslan_payment_id');
        if ($paymentId === '') {
            throw new RefundNotPossibleException('Order has no Aslan payment id — cannot refund.');
        }
        $centimes = AmountValidator::madToCentimes($amountMad);

        $refund = $this->client->createRefund(
            [
                'paymentId' => $paymentId,
                'amount'    => $centimes,
                'reason'    => $reason !== '' ? $reason : 'customer_requested',
            ],
            sprintf('refund_%d_%s_%d', $order->get_id(), $paymentId, $centimes)
        );

        return $refund->id;
    }
}
```

- [ ] **Step 3: Add `process_refund` to the gateway**

In `src/WooCommerce/Gateway.php`, constructor signature gets `RefundService`, and:

```php
    public function process_refund($order_id, $amount = null, $reason = ''): bool|\WP_Error
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order || $amount === null) {
            return new \WP_Error('invalid', __('Refund failed: invalid order or amount.', 'aslan-payments-for-woocommerce'));
        }

        try {
            $refundId = $this->refundService->refund($order, (float) $amount, (string) $reason);
        } catch (\Aslan\Woo\Service\NonWholeMadException $e) {
            return new \WP_Error('whole_mad', __('Refund amounts must be whole MAD.', 'aslan-payments-for-woocommerce'));
        } catch (\Aslan\Woo\Service\RefundNotPossibleException $e) {
            return new \WP_Error('no_payment', $e->getMessage());
        } catch (\Aslan\Woo\Client\AslanWooException $e) {
            return new \WP_Error('aslan_error', $e->getMessage());
        }

        $order->add_order_note(sprintf('Aslan refund initiated (%s). Waiting for refund.succeeded webhook.', $refundId));
        return true;
    }
```

- [ ] **Step 4: Flesh out `refund.succeeded` in `WebhookService`**

In `src/Service/WebhookService.php`, replace the `refund.succeeded` case:

```php
            case 'refund.succeeded':
                $refundAmountCentimes = (int) ($data['amount'] ?? 0);
                $refundId             = (string) ($data['id'] ?? '');
                $hasMatching = false;
                foreach ($order->get_refunds() as $existing) {
                    if ((string) $existing->get_meta('_aslan_refund_id') === $refundId) {
                        $hasMatching = true;
                        break;
                    }
                }
                if (! $hasMatching) {
                    $refund = wc_create_refund([
                        'order_id' => $order->get_id(),
                        'amount'   => $refundAmountCentimes / 100,
                        'reason'   => sprintf('Aslan refund %s', $refundId),
                    ]);
                    if (! is_wp_error($refund)) {
                        $refund->update_meta_data('_aslan_refund_id', $refundId);
                        $refund->save();
                    }
                }
                break;
```

- [ ] **Step 5: Run unit + integration**

Run: `vendor/bin/phpunit --filter RefundServiceTest`
Expected: PASS.
Run: `ASLAN_WOO_SUITE=integration vendor/bin/phpunit --testsuite integration`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Service/RefundService.php src/WooCommerce/Gateway.php src/Service/WebhookService.php tests/Unit/Service/RefundServiceTest.php
git commit -m "feat(refund): process_refund calls Aslan + refund.succeeded webhook"
```

**Phase 9 gate:** refund issued from Woo admin → Aslan refund API call → webhook arrives → `WC_Order_Refund` present on the order. Dashboard-initiated refund also lands cleanly.

---

# Phase 10: Thank-you page polling

### Task 10.1: `ThankYouPage` + polling REST endpoint

**Files:**
- Create: `src/WooCommerce/ThankYouPage.php`
- Create: `src/Rest/OrderStatusRoute.php`
- Modify: `src/Plugin.php`

- [ ] **Step 1: Write `src/WooCommerce/ThankYouPage.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\WooCommerce;

use WC_Order;

final class ThankYouPage
{
    public function register(): void
    {
        add_action('woocommerce_thankyou_' . Gateway::ID, [$this, 'render']);
    }

    public function render(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            return;
        }
        if ($order->is_paid()) {
            return;
        }
        if ($order->get_status() === 'aslan-awaiting-cash') {
            echo '<p class="aslan-notice">' . esc_html__('Your order is reserved. Pay at any cash agent to confirm it.', 'aslan-payments-for-woocommerce') . '</p>';
            return;
        }

        $nonce = wp_create_nonce('wp_rest');
        $url   = rest_url('aslan/v1/order-status/' . $orderId);

        echo '<div id="aslan-await" data-url="' . esc_attr($url) . '" data-nonce="' . esc_attr($nonce) . '">';
        echo '<p>' . esc_html__('Confirming your payment…', 'aslan-payments-for-woocommerce') . '</p>';
        echo '</div>';
        echo '<script>
            (function(){
                var el=document.getElementById("aslan-await");
                if(!el)return;
                var deadline=Date.now()+10000;
                function tick(){
                    fetch(el.dataset.url,{headers:{"X-WP-Nonce":el.dataset.nonce}})
                        .then(function(r){return r.json();})
                        .then(function(j){
                            if(j.paid){location.reload();return;}
                            if(Date.now()<deadline){setTimeout(tick,1000);return;}
                            el.innerHTML="<p>' . esc_js(__('Still processing. We will email you a confirmation shortly.', 'aslan-payments-for-woocommerce')) . '</p>";
                        })
                        .catch(function(){if(Date.now()<deadline){setTimeout(tick,1500);}});
                }
                setTimeout(tick,500);
            })();
        </script>';
    }
}
```

- [ ] **Step 2: Write `src/Rest/OrderStatusRoute.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Rest;

use WP_REST_Request;
use WP_REST_Response;

final class OrderStatusRoute
{
    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('aslan/v1', '/order-status/(?P<id>\d+)', [
                'methods'             => 'GET',
                'callback'            => [$this, 'handle'],
                'permission_callback' => static fn () => true, // customer-facing
            ]);
        });
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $orderId = (int) $request['id'];
        $order = wc_get_order($orderId);
        if (! $order) {
            return new WP_REST_Response(['paid' => false], 200);
        }
        return new WP_REST_Response(['paid' => $order->is_paid()], 200);
    }
}
```

- [ ] **Step 3: Register both in `Plugin::register()`**

```php
        (new WooCommerce\ThankYouPage())->register();
        (new Rest\OrderStatusRoute())->register();
```

- [ ] **Step 4: Commit**

```bash
git add src/WooCommerce/ThankYouPage.php src/Rest/OrderStatusRoute.php src/Plugin.php
git commit -m "feat(ux): thank-you polling for webhook/redirect race"
```

**Phase 10 gate:** redirecting back before the webhook shows a spinner that resolves when the webhook lands, or falls back gracefully after 10s.

---

# Phase 11: Shortcode + Gutenberg block

### Task 11.1: `aslan_payment` CPT

**Files:**
- Create: `src/Shortcode/PaymentCpt.php`

- [ ] **Step 1: Write the CPT registrar**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Shortcode;

final class PaymentCpt
{
    public const TYPE = 'aslan_payment';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_PENDING_CASH = 'pending_cash';

    public function register(): void
    {
        add_action('init', static function (): void {
            register_post_type(self::TYPE, [
                'public'              => false,
                'show_ui'             => false,
                'show_in_rest'        => false,
                'exclude_from_search' => true,
                'publicly_queryable'  => false,
                'supports'            => ['title', 'custom-fields'],
            ]);
        });
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Shortcode/PaymentCpt.php
git commit -m "feat(shortcode): aslan_payment CPT for standalone payments"
```

### Task 11.2: AJAX route — create a standalone session

**Files:**
- Create: `src/Rest/ShortcodePayRoute.php`

- [ ] **Step 1: Write the route**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Rest;

use Aslan\Woo\Client\AslanHttpClient;
use Aslan\Woo\Service\AmountValidator;
use Aslan\Woo\Service\NonWholeMadException;
use Aslan\Woo\Shortcode\PaymentCpt;
use WP_REST_Request;
use WP_REST_Response;

final class ShortcodePayRoute
{
    public function __construct(private readonly AslanHttpClient $client) {}

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('aslan/v1', '/shortcode/pay', [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => static function (WP_REST_Request $r): bool {
                    return wp_verify_nonce((string) $r->get_header('x_wp_nonce'), 'wp_rest') !== false;
                },
            ]);
        });
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $amount      = (int) $request->get_param('amount');
        $description = (string) $request->get_param('description');
        $successUrl  = (string) ($request->get_param('success_url') ?: home_url('/aslan/thank-you'));
        $cancelUrl   = (string) ($request->get_param('cancel_url')  ?: home_url());
        $reference   = (string) $request->get_param('reference');

        try {
            AmountValidator::assertWholeMadCentimes($amount);
        } catch (NonWholeMadException $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 400);
        }

        $cptId = wp_insert_post([
            'post_type'   => PaymentCpt::TYPE,
            'post_status' => 'publish',
            'post_title'  => $description !== '' ? $description : sprintf('Aslan payment %d centimes', $amount),
            'meta_input'  => [
                'amount'      => $amount,
                'description' => $description,
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
                'reference'   => $reference,
                'status'      => PaymentCpt::STATUS_PENDING,
            ],
        ]);
        if (! $cptId || is_wp_error($cptId)) {
            return new WP_REST_Response(['error' => 'cpt_create_failed'], 500);
        }

        $session = $this->client->createCheckoutSession(
            [
                'amount'      => $amount,
                'currency'    => 'MAD',
                'successUrl'  => $successUrl,
                'cancelUrl'   => $cancelUrl,
                'metadata'    => [
                    'aslan_payment_id' => (string) $cptId,
                    'reference'        => $reference,
                ],
            ],
            sprintf('shortcode_%d', $cptId)
        );

        return new WP_REST_Response(['redirect' => $session->url], 200);
    }
}
```

- [ ] **Step 2: Wire in `Plugin::register()`**

```php
        (new Shortcode\PaymentCpt())->register();
        (new Rest\ShortcodePayRoute($httpClient))->register();
```

- [ ] **Step 3: Update `WebhookService::handle` to also process CPT-linked events**

Replace the `metadata.wc_order_id` lookup with:

```php
        $wcOrderId = isset($data['metadata']['wc_order_id']) ? (int) $data['metadata']['wc_order_id'] : 0;
        $cptId     = isset($data['metadata']['aslan_payment_id']) ? (int) $data['metadata']['aslan_payment_id'] : 0;

        if ($wcOrderId > 0) {
            $order = $orderLoader($wcOrderId);
            if ($order === null) return;
            $this->mutateOrder($eventType, $data, $order);
            return;
        }
        if ($cptId > 0) {
            $this->mutateCpt($eventType, $data, $cptId);
            return;
        }
```

Add private helpers `mutateOrder` (containing the switch that was previously the body) and `mutateCpt` (writes `status` meta on the CPT: `succeeded`, `failed`, `pending_cash`).

- [ ] **Step 4: Commit**

```bash
git add src/Rest/ShortcodePayRoute.php src/Plugin.php src/Service/WebhookService.php
git commit -m "feat(shortcode): pay AJAX route + CPT status updates via webhook"
```

### Task 11.3: `[aslan_pay]` shortcode

**Files:**
- Create: `src/Shortcode/PayShortcode.php`

- [ ] **Step 1: Write the shortcode**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Shortcode;

final class PayShortcode
{
    public function register(): void
    {
        add_shortcode('aslan_pay', [$this, 'render']);
    }

    /** @param array<string, string|int> $atts */
    public function render(array $atts): string
    {
        $atts = shortcode_atts([
            'amount'      => 0,
            'description' => '',
            'success_url' => '',
            'cancel_url'  => '',
            'button_text' => '',
            'reference'   => '',
        ], $atts, 'aslan_pay');

        $amount = (int) $atts['amount'];
        if ($amount <= 0 || $amount % 100 !== 0) {
            if (current_user_can('edit_posts')) {
                return '<em>[aslan_pay: amount must be a positive multiple of 100 centimes]</em>';
            }
            return '';
        }

        $label = $atts['button_text'] !== ''
            ? (string) $atts['button_text']
            : sprintf(__('Pay %d MAD', 'aslan-payments-for-woocommerce'), (int) ($amount / 100));

        $nonce = wp_create_nonce('wp_rest');
        $url   = rest_url('aslan/v1/shortcode/pay');
        $payload = esc_attr(wp_json_encode($atts) ?: '{}');

        ob_start();
        ?>
        <button type="button" class="aslan-pay-btn" data-nonce="<?php echo esc_attr($nonce); ?>" data-url="<?php echo esc_attr($url); ?>" data-payload='<?php echo $payload; ?>'>
            <?php echo esc_html($label); ?>
        </button>
        <script>
        (function(){
            var btns=document.querySelectorAll('.aslan-pay-btn[data-nonce="<?php echo esc_js($nonce); ?>"]');
            btns.forEach(function(btn){
                btn.addEventListener('click',function(){
                    btn.disabled=true;
                    fetch(btn.dataset.url,{
                        method:'POST',
                        headers:{'Content-Type':'application/json','X-WP-Nonce':btn.dataset.nonce},
                        body:btn.dataset.payload
                    })
                    .then(function(r){return r.json();})
                    .then(function(j){ if(j.redirect){location.href=j.redirect;}else{btn.disabled=false;} })
                    .catch(function(){ btn.disabled=false; });
                });
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }
}
```

- [ ] **Step 2: Register in `Plugin::register()`**

```php
        (new Shortcode\PayShortcode())->register();
```

- [ ] **Step 3: Commit**

```bash
git add src/Shortcode/PayShortcode.php src/Plugin.php
git commit -m "feat(shortcode): [aslan_pay] button rendering"
```

### Task 11.4: Gutenberg block

**Files:**
- Create: `src/Shortcode/PayBlock.php`
- Create: `assets/block.js`

- [ ] **Step 1: Write `src/Shortcode/PayBlock.php` (same render callback as shortcode)**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Shortcode;

final class PayBlock
{
    public function __construct(private readonly PayShortcode $shortcode) {}

    public function register(): void
    {
        add_action('init', function (): void {
            wp_register_script(
                'aslan-pay-block',
                ASLAN_WOO_PLUGIN_URL . 'assets/block.js',
                ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor'],
                ASLAN_WOO_VERSION,
                true
            );
            register_block_type('aslan/pay', [
                'editor_script'   => 'aslan-pay-block',
                'render_callback' => fn (array $atts): string => $this->shortcode->render($atts),
                'attributes'      => [
                    'amount'      => ['type' => 'number', 'default' => 0],
                    'description' => ['type' => 'string', 'default' => ''],
                    'button_text' => ['type' => 'string', 'default' => ''],
                    'success_url' => ['type' => 'string', 'default' => ''],
                    'cancel_url'  => ['type' => 'string', 'default' => ''],
                    'reference'   => ['type' => 'string', 'default' => ''],
                ],
            ]);
        });
    }
}
```

- [ ] **Step 2: Write `assets/block.js` — minimal block editor registration**

```javascript
(function (blocks, element, components, blockEditor) {
    const el = element.createElement;
    const { TextControl, PanelBody } = components;
    const { InspectorControls } = blockEditor;

    blocks.registerBlockType('aslan/pay', {
        title: 'Aslan Pay Button',
        icon: 'cart',
        category: 'widgets',
        attributes: {
            amount:      { type: 'number', default: 0 },
            description: { type: 'string', default: '' },
            button_text: { type: 'string', default: '' },
            success_url: { type: 'string', default: '' },
            cancel_url:  { type: 'string', default: '' },
            reference:   { type: 'string', default: '' },
        },
        edit: function (props) {
            const a = props.attributes;
            const set = function (k) { return function (v) { const u = {}; u[k] = v; props.setAttributes(u); }; };
            return el('div', { className: props.className },
                el(InspectorControls, {},
                    el(PanelBody, { title: 'Aslan Pay' },
                        el(TextControl, { label: 'Amount (centimes, multiple of 100)', type: 'number', value: a.amount, onChange: function (v) { set('amount')(parseInt(v || '0', 10)); } }),
                        el(TextControl, { label: 'Description', value: a.description, onChange: set('description') }),
                        el(TextControl, { label: 'Button text', value: a.button_text, onChange: set('button_text') }),
                        el(TextControl, { label: 'Success URL', value: a.success_url, onChange: set('success_url') }),
                        el(TextControl, { label: 'Cancel URL', value: a.cancel_url, onChange: set('cancel_url') }),
                        el(TextControl, { label: 'Reference', value: a.reference, onChange: set('reference') })
                    )
                ),
                el('div', { style: { border: '1px dashed #999', padding: '12px' } },
                    el('strong', null, 'Aslan Pay Button'),
                    el('div', null, a.amount ? ('Pay ' + (a.amount / 100) + ' MAD') : 'Set an amount in the sidebar.')
                )
            );
        },
        save: function () { return null; },
    });
}(window.wp.blocks, window.wp.element, window.wp.components, window.wp.blockEditor));
```

- [ ] **Step 3: Register in `Plugin::register()`**

```php
        $payShortcode = new Shortcode\PayShortcode();
        $payShortcode->register();
        (new Shortcode\PayBlock($payShortcode))->register();
```

Replace the earlier bare `PayShortcode` registration — the block now owns the instance.

- [ ] **Step 4: Commit**

```bash
git add src/Shortcode/PayBlock.php assets/block.js src/Plugin.php
git commit -m "feat(block): Gutenberg block shares render callback with shortcode"
```

**Phase 11 gate:** `[aslan_pay amount="50000"]` on any page produces a working Pay button. `aslan/pay` block appears in the editor. Both end at a succeeded `aslan_payment` CPT via webhook.

---

# Phase 12: Admin UI

### Task 12.1: Settings form fields

**Files:**
- Modify: `src/WooCommerce/Gateway.php`

- [ ] **Step 1: Fill `init_form_fields()` in `Gateway`**

Replace the empty `init_form_fields()` body with:

```php
    public function init_form_fields(): void
    {
        $this->form_fields = [
            'mode' => [
                'title'   => __('Mode', 'aslan-payments-for-woocommerce'),
                'type'    => 'select',
                'default' => 'test',
                'options' => [
                    'test' => __('Test — no real money moves', 'aslan-payments-for-woocommerce'),
                    'live' => __('Live — real payments', 'aslan-payments-for-woocommerce'),
                ],
            ],
            'test_secret_key' => [
                'title'       => __('Test secret key', 'aslan-payments-for-woocommerce'),
                'type'        => 'password',
                'description' => __('Starts with sk_test_. From your Aslan dashboard → Developers → API keys.', 'aslan-payments-for-woocommerce'),
                'desc_tip'    => true,
            ],
            'live_secret_key' => [
                'title'       => __('Live secret key', 'aslan-payments-for-woocommerce'),
                'type'        => 'password',
                'description' => __('Starts with sk_live_. From your Aslan dashboard → Developers → API keys.', 'aslan-payments-for-woocommerce'),
                'desc_tip'    => true,
            ],
            'title' => [
                'title'   => __('Title shown to customers', 'aslan-payments-for-woocommerce'),
                'type'    => 'text',
                'default' => __('Pay by card, wallet, or cash agent', 'aslan-payments-for-woocommerce'),
            ],
            'show_test_banner' => [
                'title'   => __('Checkout display', 'aslan-payments-for-woocommerce'),
                'label'   => __('Show "Test Mode" banner on checkout when in test mode', 'aslan-payments-for-woocommerce'),
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            'webhook_url_override' => [
                'title'       => __('Webhook URL override', 'aslan-payments-for-woocommerce'),
                'type'        => 'text',
                'default'     => '',
                'description' => __('Advanced. Leave empty unless your WordPress public URL differs from where Aslan should POST (e.g. behind a tunnel). If filled, this exact URL is registered with Aslan.', 'aslan-payments-for-woocommerce'),
                'desc_tip'    => true,
            ],
        ];
    }
```

- [ ] **Step 2: Bridge gateway's own option array to the `Config` wp_options convention**

WooCommerce stores gateway settings as `woocommerce_aslan_settings` (serialized array). We mirror these keys into the individual `aslan_woo_*` options used by `Config` so services don't need to know about the Woo settings schema.

Add to `src/WooCommerce/Gateway.php`:

```php
    public function __construct(/* ... */)
    {
        // ... existing body ...
        add_action('woocommerce_update_options_payment_gateways_' . self::ID, [$this, 'persist_bridged_options']);
    }

    public function persist_bridged_options(): void
    {
        // Re-read what Woo just saved and copy into our canonical option keys.
        $settings = get_option('woocommerce_' . self::ID . '_settings', []);
        update_option(Config::OPT_MODE,                  $settings['mode']                  ?? 'test');
        update_option(Config::OPT_TEST_KEY,              $settings['test_secret_key']       ?? '');
        update_option(Config::OPT_LIVE_KEY,              $settings['live_secret_key']       ?? '');
        update_option(Config::OPT_TITLE,                 $settings['title']                 ?? '');
        update_option(Config::OPT_SHOW_TEST_BANNER,      ($settings['show_test_banner'] ?? 'yes') === 'yes');
        update_option(Config::OPT_WEBHOOK_URL_OVERRIDE,  $settings['webhook_url_override']  ?? '');

        // Phase 12.2 triggers KeyLifecycleService here.
    }
```

Add `use Aslan\Woo\Config;` to the top of the file.

- [ ] **Step 3: Commit**

```bash
git add src/WooCommerce/Gateway.php
git commit -m "feat(admin): settings form fields + wp_options bridge"
```

### Task 12.2: Trigger `KeyLifecycleService` on save

**Files:**
- Modify: `src/WooCommerce/Gateway.php`
- Modify: `src/Plugin.php`

- [ ] **Step 1: In `persist_bridged_options`, call the service per filled key**

After the `update_option(...)` block:

```php
        $siteWebhookUrl = $this->resolveWebhookUrl();

        $events = [];
        foreach (['test' => $settings['test_secret_key'] ?? '', 'live' => $settings['live_secret_key'] ?? ''] as $mode => $key) {
            $key = (string) $key;
            if ($key === '' || $this->looksMasked($key)) {
                continue;
            }
            try {
                $result = $this->keyLifecycle->register($mode, $key, $siteWebhookUrl);
                update_option(
                    $mode === 'test' ? Config::OPT_TEST_WEBHOOK_SECRET : Config::OPT_LIVE_WEBHOOK_SECRET,
                    $result->signingSecret
                );
                update_option("aslan_woo_{$mode}_merchant_id", $result->merchantId);
                $events[] = ['ok' => true, 'mode' => $mode, 'merchantId' => $result->merchantId];
            } catch (\Aslan\Woo\Service\KeyLifecycleException $e) {
                $events[] = ['ok' => false, 'mode' => $mode, 'error' => $e->getMessage()];
            }
        }
        update_option('aslan_woo_last_setup', [
            'at'     => time(),
            'events' => $events,
        ]);
    }

    private function resolveWebhookUrl(): string
    {
        $override = (string) get_option(Config::OPT_WEBHOOK_URL_OVERRIDE, '');
        return $override !== '' ? $override : rest_url('aslan/v1/webhook');
    }

    private function looksMasked(string $key): bool
    {
        return str_contains($key, '••');
    }
```

Constructor adds `KeyLifecycleService $keyLifecycle`.

- [ ] **Step 2: Wire the service in `Plugin::register()`**

```php
        $keyLifecycle = new Service\KeyLifecycleService(
            static function (string $secretKey): Client\AslanHttpClient {
                return new Client\AslanHttpClient(static fn () => new \Aslan\Payment\Aslan($secretKey));
            }
        );
        // pass $keyLifecycle through the gateway factory
```

- [ ] **Step 3: Commit**

```bash
git add src/WooCommerce/Gateway.php src/Plugin.php
git commit -m "feat(admin): saving keys triggers webhook registration"
```

### Task 12.3: Setup status card + troubleshooting buttons

**Files:**
- Create: `src/Admin/SetupStatusCard.php`
- Create: `src/Admin/TroubleshootingActions.php`

- [ ] **Step 1: Write `src/Admin/SetupStatusCard.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Admin;

use Aslan\Woo\Config;

final class SetupStatusCard
{
    public function register(): void
    {
        add_action('woocommerce_settings_checkout', [$this, 'maybeRender'], 20);
    }

    public function maybeRender(): void
    {
        if (! isset($_GET['section']) || $_GET['section'] !== 'aslan') {
            return;
        }
        $setup = get_option('aslan_woo_last_setup', null);
        if (! is_array($setup)) {
            return;
        }

        echo '<h3>' . esc_html__('Setup status', 'aslan-payments-for-woocommerce') . '</h3>';
        echo '<table class="widefat striped"><tbody>';

        foreach ($setup['events'] ?? [] as $evt) {
            $icon = $evt['ok'] ? '✓' : '✗';
            printf(
                '<tr><td>%s %s key</td><td>%s</td></tr>',
                esc_html($icon),
                esc_html($evt['mode']),
                $evt['ok']
                    ? esc_html(sprintf(__('verified — merchant %s', 'aslan-payments-for-woocommerce'), $evt['merchantId']))
                    : esc_html($evt['error'])
            );
        }

        $webhookUrl = rest_url('aslan/v1/webhook');
        $override   = (string) get_option(Config::OPT_WEBHOOK_URL_OVERRIDE, '');
        printf(
            '<tr><td>✓ Webhook endpoint</td><td><code>%s</code>%s</td></tr>',
            esc_html($override !== '' ? $override : $webhookUrl),
            $override !== '' ? ' <em>' . esc_html__('(override)', 'aslan-payments-for-woocommerce') . '</em>' : ''
        );
        echo '<tr><td>✓ Webhook signing secret</td><td><em>' . esc_html__('(never shown)', 'aslan-payments-for-woocommerce') . '</em></td></tr>';
        echo '</tbody></table>';
    }
}
```

- [ ] **Step 2: Write `src/Admin/TroubleshootingActions.php`** — exposes three admin-post handlers: `aslan_re_register_webhook`, `aslan_test_webhook`, `aslan_download_log`. Each is protected by a nonce + `manage_woocommerce` cap.

Skeleton:

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Admin;

use Aslan\Woo\Config;
use Aslan\Woo\Service\KeyLifecycleService;

final class TroubleshootingActions
{
    public function __construct(
        private readonly Config $config,
        private readonly KeyLifecycleService $keyLifecycle,
    ) {}

    public function register(): void
    {
        add_action('admin_post_aslan_re_register_webhook', [$this, 'reRegister']);
        add_action('admin_post_aslan_test_webhook', [$this, 'sendTestWebhook']);
        add_action('admin_post_aslan_download_log', [$this, 'downloadLog']);
    }

    public function reRegister(): void
    {
        check_admin_referer('aslan_woo_admin');
        if (! current_user_can('manage_woocommerce')) {
            wp_die(403);
        }
        // Re-run KeyLifecycleService for the active mode's key.
        $key = $this->config->activeSecretKey();
        $url = rest_url('aslan/v1/webhook');
        try {
            $result = $this->keyLifecycle->register($this->config->mode(), $key, $url);
            $opt = $this->config->mode() === 'live' ? Config::OPT_LIVE_WEBHOOK_SECRET : Config::OPT_TEST_WEBHOOK_SECRET;
            update_option($opt, $result->signingSecret);
            set_transient('aslan_admin_notice', ['ok' => __('Webhook re-registered.', 'aslan-payments-for-woocommerce')], 30);
        } catch (\Throwable $e) {
            set_transient('aslan_admin_notice', ['err' => $e->getMessage()], 30);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public function sendTestWebhook(): void
    {
        check_admin_referer('aslan_woo_admin');
        if (! current_user_can('manage_woocommerce')) { wp_die(403); }
        // TODO: call Aslan's "deliver test event" API when available; for v1, flash a notice
        // explaining the manual test path.
        set_transient('aslan_admin_notice', [
            'ok' => __('Open a browser tab to your Aslan dashboard → Webhooks → Send test event.', 'aslan-payments-for-woocommerce'),
        ], 30);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    public function downloadLog(): void
    {
        check_admin_referer('aslan_woo_admin');
        if (! current_user_can('manage_woocommerce')) { wp_die(403); }
        $log = get_option('aslan_woo_log', []);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="aslan-diagnostic.log"');
        foreach ((array) $log as $row) {
            echo $row['at'] . ' ' . $row['msg'] . "\n";
        }
        exit;
    }
}
```

Register in `Plugin::register()`:

```php
        (new Admin\SetupStatusCard())->register();
        (new Admin\TroubleshootingActions($config, $keyLifecycle))->register();
```

Render the three buttons inside `SetupStatusCard` beneath the status table — three `<form method="post" action="admin.php?action=…">` snippets with `wp_nonce_field('aslan_woo_admin')`.

- [ ] **Step 3: Commit**

```bash
git add src/Admin/SetupStatusCard.php src/Admin/TroubleshootingActions.php src/Plugin.php
git commit -m "feat(admin): setup status card + troubleshooting actions"
```

**Phase 12 gate:** Woo → Settings → Payments → Aslan shows the full settings form. On save, keys are validated, webhook endpoint registered, signing secret stored. Status card lists what happened. Three recovery buttons work.

---

# Phase 13: Amount validation + product scanner

### Task 13.1: `PriceScanner`

**Files:**
- Create: `src/Admin/PriceScanner.php`

- [ ] **Step 1: Write the scanner**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Admin;

final class PriceScanner
{
    /** @return list<array{id:int,sku:string,price:string}> */
    public function scan(int $limit = 500): array
    {
        $results = [];
        $query = new \WC_Product_Query([
            'limit'  => $limit,
            'status' => 'publish',
            'return' => 'objects',
        ]);
        /** @var \WC_Product $product */
        foreach ($query->get_products() as $product) {
            $price = (float) $product->get_price();
            if ($price > 0 && (int) round($price * 100) % 100 !== 0) {
                $results[] = [
                    'id'    => (int) $product->get_id(),
                    'sku'   => (string) $product->get_sku(),
                    'price' => (string) $product->get_price(),
                ];
            }
        }
        return $results;
    }

    public function renderNoticeIfAny(): void
    {
        $rows = $this->scan();
        if ($rows === []) {
            return;
        }
        add_action('admin_notices', static function () use ($rows): void {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('Aslan cannot charge fractional-MAD amounts. The following products have fractional prices:', 'aslan-payments-for-woocommerce');
            echo '</p><ul>';
            foreach (array_slice($rows, 0, 10) as $r) {
                printf('<li>#%d — %s — %s MAD</li>', esc_html($r['id']), esc_html($r['sku']), esc_html($r['price']));
            }
            if (count($rows) > 10) {
                printf('<li>%s</li>', esc_html(sprintf(__('… and %d more', 'aslan-payments-for-woocommerce'), count($rows) - 10)));
            }
            echo '</ul></div>';
        });
    }
}
```

- [ ] **Step 2: Register on activation + admin pageload**

In `aslan-payments-for-woocommerce.php`, add:

```php
register_activation_hook(__FILE__, function (): void {
    update_option('aslan_woo_scan_pending', 1);
});
```

In `Plugin::register()`:

```php
        if (is_admin() && get_option('aslan_woo_scan_pending')) {
            add_action('admin_init', static function (): void {
                (new Admin\PriceScanner())->renderNoticeIfAny();
                delete_option('aslan_woo_scan_pending');
            });
        }
```

- [ ] **Step 3: Commit**

```bash
git add src/Admin/PriceScanner.php src/Plugin.php aslan-payments-for-woocommerce.php
git commit -m "feat(admin): one-shot fractional-MAD price scan on activation"
```

---

# Phase 14: Diagnostic log + i18n + readme.txt

### Task 14.1: Capped `DiagnosticLog`

**Files:**
- Create: `src/Service/DiagnosticLog.php`
- Create: `tests/Unit/Service/DiagnosticLogTest.php`

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Tests\Unit\Service;

use Aslan\Woo\Service\DiagnosticLog;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class DiagnosticLogTest extends TestCase
{
    private array $store;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->store = [];
        Functions\when('get_option')->alias(function ($k, $d = []) { return $this->store[$k] ?? $d; });
        Functions\when('update_option')->alias(function ($k, $v) { $this->store[$k] = $v; return true; });
    }

    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

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
```

- [ ] **Step 2: Write `src/Service/DiagnosticLog.php`**

```php
<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

final class DiagnosticLog
{
    public const OPTION = 'aslan_woo_log';
    public const MAX    = 100;

    public function record(string $msg): void
    {
        $rows = (array) get_option(self::OPTION, []);
        $rows[] = ['at' => gmdate('c'), 'msg' => $msg];
        if (count($rows) > self::MAX) {
            $rows = array_slice($rows, -self::MAX);
        }
        update_option(self::OPTION, $rows, false);
    }
}
```

- [ ] **Step 3: Thread into `WebhookService` for "unknown order" and "bad signature" cases**

Pass a `DiagnosticLog` into `WebhookService` (constructor), call `$this->log->record(...)` where the spec calls for a log entry.

- [ ] **Step 4: Run, pass, commit**

Run: `vendor/bin/phpunit --filter DiagnosticLogTest`
Expected: PASS.

```bash
git add src/Service/DiagnosticLog.php tests/Unit/Service/DiagnosticLogTest.php src/Service/WebhookService.php
git commit -m "feat(log): capped diagnostic log"
```

### Task 14.2: i18n — generate `.pot`, add French `.po`

**Files:**
- Create: `languages/aslan-payments-for-woocommerce.pot` (generated)
- Create: `languages/aslan-payments-for-woocommerce-fr_FR.po` (translated)

- [ ] **Step 1: Generate the POT file**

Run: `wp i18n make-pot . languages/aslan-payments-for-woocommerce.pot --slug=aslan-payments-for-woocommerce --domain=aslan-payments-for-woocommerce`
Expected: file written.

- [ ] **Step 2: Create `languages/aslan-payments-for-woocommerce-fr_FR.po`**

Copy the POT, translate the strings (or leave as `""` and translate later — at minimum: "Pay by card, wallet, or cash agent", "Test mode active", all 4 order-note strings, "Awaiting cash payment", "Aslan accepts whole MAD amounts only").

- [ ] **Step 3: Compile**

Run: `msgfmt languages/aslan-payments-for-woocommerce-fr_FR.po -o languages/aslan-payments-for-woocommerce-fr_FR.mo`

- [ ] **Step 4: Commit**

```bash
git add languages/
git commit -m "i18n: POT + French translation bundle"
```

### Task 14.3: `readme.txt` (wordpress.org format)

**Files:**
- Create: `readme.txt`

- [ ] **Step 1: Write the readme**

```
=== Aslan Payments for WooCommerce ===
Contributors: belkouche
Tags: woocommerce, payments, morocco, aslan, payment-gateway
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Moroccan card, mobile wallet, and cash-agent payments through Aslan on your WooCommerce store.

== Description ==

Aslan Payments for WooCommerce adds Aslan as a payment method on your WooCommerce store. Customers pay through Aslan's secure hosted page — card data never touches your site (PCI SAQ A).

* One-step setup: paste your API keys and the plugin registers its webhook automatically.
* Test & live mode switch — both keys configurable at once.
* Refunds from the WooCommerce order screen.
* Lightweight `[aslan_pay]` shortcode for standalone donations, invoices, or deposits.

== Installation ==

1. Install from Plugins → Add New, or upload the ZIP manually.
2. Activate the plugin.
3. WooCommerce → Settings → Payments → Aslan → paste your test and live secret keys.

== Changelog ==

= 0.1.0 =
* Initial release.
```

- [ ] **Step 2: Commit**

```bash
git add readme.txt
git commit -m "docs: wordpress.org readme.txt"
```

---

# Phase 15: E2E + release pipeline

### Task 15.1: Docker Compose for E2E

**Files:**
- Create: `docker/docker-compose.e2e.yml`
- Create: `docker/Dockerfile.wp`
- Create: `tests/E2E/happy-path.spec.ts`
- Create: `tests/E2E/playwright.config.ts`
- Create: `package.json`

- [ ] **Step 1: Write `docker/docker-compose.e2e.yml`**

```yaml
services:
  db:
    image: mysql:8
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wp
    healthcheck:
      test: mysqladmin ping -uroot -proot
      interval: 3s
      retries: 20

  wp:
    build:
      context: .
      dockerfile: Dockerfile.wp
    depends_on:
      db:
        condition: service_healthy
    ports: ['8081:80']
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_USER: root
      WORDPRESS_DB_PASSWORD: root
      WORDPRESS_DB_NAME: wp
    volumes:
      - ../:/var/www/html/wp-content/plugins/aslan-payments-for-woocommerce
```

- [ ] **Step 2: Write `docker/Dockerfile.wp`**

```dockerfile
FROM wordpress:6.7-php8.3-apache
RUN docker-php-ext-install opcache
RUN apt-get update && apt-get install -y less && rm -rf /var/lib/apt/lists/*
RUN curl -sSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
# wp-cli
RUN curl -sSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp \
    && chmod +x /usr/local/bin/wp
```

- [ ] **Step 3: Write `package.json`**

```json
{
  "name": "aslan-payments-for-woocommerce-e2e",
  "private": true,
  "scripts": {
    "e2e:up": "docker compose -f docker/docker-compose.e2e.yml up -d --build",
    "e2e:down": "docker compose -f docker/docker-compose.e2e.yml down -v",
    "e2e": "playwright test"
  },
  "devDependencies": {
    "@playwright/test": "^1.49.0"
  }
}
```

- [ ] **Step 4: Write `tests/E2E/happy-path.spec.ts`**

```typescript
import { test, expect } from '@playwright/test';

test('customer pays with Aslan test card and lands on completed order', async ({ page }) => {
  await page.goto('http://localhost:8081/shop/');
  await page.click('text=Add to cart');
  await page.goto('http://localhost:8081/checkout/');
  await page.fill('#billing_first_name', 'Aya');
  await page.fill('#billing_last_name', 'Alaoui');
  await page.fill('#billing_email', 'aya@example.ma');
  await page.fill('#billing_phone', '+212600000000');
  await page.check('input[value="aslan"]');
  await page.click('#place_order');

  // We should be on pay.aslan.ma (test env) — pay with 4242 4242 4242 4242
  await expect(page).toHaveURL(/pay\.aslan\.ma/);
  await page.fill('input[name="cardNumber"]', '4242424242424242');
  await page.fill('input[name="expiry"]', '12/28');
  await page.fill('input[name="cvc"]', '123');
  await page.click('button:has-text("Pay")');

  await page.waitForURL(/order-received/);
  await expect(page.locator('body')).toContainText(/completed|confirming/i);
});
```

- [ ] **Step 5: Write `.github/workflows/e2e.yml`**

```yaml
name: E2E

on:
  pull_request:
    branches: [main]
  push:
    branches: [main]

jobs:
  e2e:
    runs-on: ubuntu-latest
    env:
      ASLAN_TEST_SECRET_KEY: ${{ secrets.ASLAN_TEST_SECRET_KEY }}
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci
      - run: npx playwright install --with-deps chromium
      - run: npm run e2e:up
      - name: Configure plugin
        run: |
          sleep 10
          docker compose -f docker/docker-compose.e2e.yml exec -T wp wp core install \
            --url=http://localhost:8081 \
            --title='E2E' \
            --admin_user=admin \
            --admin_password=admin \
            --admin_email=admin@example.ma \
            --allow-root
          docker compose -f docker/docker-compose.e2e.yml exec -T wp wp plugin install woocommerce --activate --allow-root
          docker compose -f docker/docker-compose.e2e.yml exec -T wp wp plugin activate aslan-payments-for-woocommerce --allow-root
          docker compose -f docker/docker-compose.e2e.yml exec -T wp wp option update aslan_woo_mode test --allow-root
          docker compose -f docker/docker-compose.e2e.yml exec -T wp wp option update aslan_woo_test_secret_key "$ASLAN_TEST_SECRET_KEY" --allow-root
      - run: npm run e2e
      - run: npm run e2e:down
        if: always()
```

- [ ] **Step 6: Commit**

```bash
git add docker/ tests/E2E/ package.json .github/workflows/e2e.yml
git commit -m "ci: Playwright E2E against Aslan test environment"
```

### Task 15.2: Release workflow — tag → wordpress.org SVN

**Files:**
- Create: `.github/workflows/release.yml`

- [ ] **Step 1: Write the release workflow**

```yaml
name: Release

on:
  push:
    tags: ['v*']

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer install --no-dev --prefer-dist --optimize-autoloader
      - name: Build ZIP
        run: |
          mkdir -p build/aslan-payments-for-woocommerce
          rsync -a --exclude='tests' --exclude='docker' --exclude='docs' --exclude='.git*' --exclude='node_modules' --exclude='build' ./ build/aslan-payments-for-woocommerce/
          cd build && zip -r ../aslan-payments-for-woocommerce.zip aslan-payments-for-woocommerce
      - uses: softprops/action-gh-release@v2
        with:
          files: aslan-payments-for-woocommerce.zip
      - name: Deploy to wordpress.org
        uses: 10up/action-wordpress-plugin-deploy@stable
        env:
          SVN_USERNAME: ${{ secrets.WPORG_USERNAME }}
          SVN_PASSWORD: ${{ secrets.WPORG_PASSWORD }}
          SLUG: aslan-payments-for-woocommerce
```

- [ ] **Step 2: Commit**

```bash
git add .github/workflows/release.yml
git commit -m "ci: release workflow — tag triggers wordpress.org SVN deploy"
```

**Phase 15 gate:** `git tag v0.1.0 && git push --tags` builds the ZIP, publishes a GitHub release, and deploys to wordpress.org SVN. E2E green on PR.

---

# Phase 16: Security review

**Why last:** a dedicated audit sweep over the finished plugin before we tag `v0.1.0`. Payment plugins are high-value targets; wordpress.org also runs its own review on first submission, but their bar is "no obvious abuse" — ours is higher.

### Task 16.1: Automated static sweep

**Files:**
- Modify: `composer.json` — add `vimeo/psalm` as dev dep (complementary to phpstan)
- Create: `psalm.xml`
- Create: `.github/workflows/security.yml`

- [ ] **Step 1: Add Psalm + its WordPress plugin**

```bash
composer require --dev vimeo/psalm humanmade/psalm-plugin-wordpress
```

- [ ] **Step 2: Write `psalm.xml`**

```xml
<?xml version="1.0"?>
<psalm errorLevel="3" findUnusedBaselineEntry="true">
  <projectFiles>
    <directory name="src"/>
    <ignoreFiles><directory name="vendor"/></ignoreFiles>
  </projectFiles>
  <plugins>
    <pluginClass class="PsalmWordPress\Plugin"/>
  </plugins>
  <issueHandlers>
    <TaintedSql errorLevel="error"/>
    <TaintedHtml errorLevel="error"/>
    <TaintedShell errorLevel="error"/>
    <TaintedInclude errorLevel="error"/>
    <TaintedEval errorLevel="error"/>
    <TaintedCookie errorLevel="error"/>
    <TaintedHeader errorLevel="error"/>
    <TaintedUnserialize errorLevel="error"/>
  </issueHandlers>
</psalm>
```

- [ ] **Step 3: Write `.github/workflows/security.yml`**

```yaml
name: Security

on:
  pull_request:
  push:
    branches: [main]
  schedule:
    - cron: '0 6 * * 1'  # weekly Monday 06:00 UTC

jobs:
  taint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', coverage: none }
      - run: composer install --no-interaction --prefer-dist
      - run: vendor/bin/psalm --taint-analysis

  secrets:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with: { fetch-depth: 0 }
      - uses: trufflesecurity/trufflehog@main
        with: { path: ./, extra_args: --only-verified }

  composer-audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3' }
      - run: composer install --no-interaction --prefer-dist
      - run: composer audit --locked
```

- [ ] **Step 4: Run locally, iterate until clean**

Run: `vendor/bin/psalm --taint-analysis`
Expected: 0 tainted-sink issues. Fix any real findings inline (common ones in this plugin: an `echo` of an admin-settings value that isn't `esc_html`'d, an SQL-ish `$wpdb` call that isn't `$wpdb->prepare`'d — but this plugin doesn't touch `$wpdb` directly so that one is unlikely).

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock psalm.xml .github/workflows/security.yml
git commit -m "ci: taint analysis, secret scan, composer audit"
```

### Task 16.2: Human checklist pass — the OWASP-for-WP list

**Files:**
- Create: `docs/superpowers/reviews/2026-11-13-v0.1.0-security-review.md`

- [ ] **Step 1: Walk the checklist and record evidence**

Each row of the table below is either ✅ (with the file:line evidence) or ⚠️ (with a concrete follow-up task). No ⚠️ rows allowed in `v0.1.0`.

Write `docs/superpowers/reviews/2026-11-13-v0.1.0-security-review.md`:

```markdown
# v0.1.0 Security Review

**Reviewer:** [name]
**Date:** [YYYY-MM-DD]
**Scope:** Every file under `src/`, `aslan-payments-for-woocommerce.php`, all REST routes, all admin forms.

## Checklist

| # | Check | Status | Evidence |
|---|---|---|---|
| 1 | No secret keys ever echoed, not even to admins. "Reveal" link client-side only decodes a masked placeholder. | | — |
| 2 | Webhook signing secret written to wp_options with `autoload=false`. | | — |
| 3 | `esc_html` / `esc_attr` / `esc_url` / `esc_js` on every echo of a dynamic value. | | — |
| 4 | `wp_kses_post` on any admin message that legitimately needs HTML. | | — |
| 5 | Every REST route with side effects has a `permission_callback` that checks caps (`manage_woocommerce`) or verifies nonce. The webhook route is signature-verified in its own handler (justified). | | — |
| 6 | Every admin-post action verifies `check_admin_referer()` AND `current_user_can('manage_woocommerce')`. | | — |
| 7 | All redirects go through `wp_safe_redirect` with validated hosts — never `header('Location:')` with user input. | | — |
| 8 | No `eval`, no `create_function`, no `preg_replace` with `/e`, no `unserialize` of untrusted data. | | — |
| 9 | No `$wpdb->query` with concatenation — only `$wpdb->prepare` (currently: zero `$wpdb` calls). | | — |
| 10 | Shortcode and block cannot run server-side code from attributes. `amount` cast to `(int)`, URLs validated with `wp_http_validate_url`. | | — |
| 11 | No logging of secrets, keys, or signatures, even in `DiagnosticLog`. Grep: `grep -rE "sk_(test\|live)_\|whsec_\|signingSecret" src/`. | | — |
| 12 | File includes never use user input as a path. | | — |
| 13 | `rest_url()` used for registered-webhook URL — never a user-controlled host header — unless the "Advanced override" field is set, and that override is validated with `wp_http_validate_url` and HTTPS-forced. | | — |
| 14 | Idempotency replay protection in webhook handler (verified in phase 7.3). | | — |
| 15 | HMAC comparison uses `hash_equals` for constant-time. | | — |
| 16 | 5-minute replay window on the webhook timestamp. | | — |
| 17 | No `error_reporting` or `display_errors` touching production. | | — |
| 18 | Plugin does not write any arbitrary file on activation. Activation writes `aslan_woo_scan_pending` option only. | | — |
| 19 | `composer audit` clean — no known CVEs in direct deps. | | — |
| 20 | Dependencies up to date (`composer outdated --direct` shows no majors behind). | | — |
| 21 | Fail-closed: when secret is missing, gateway refuses to appear in checkout; webhook route 401s. | | — |
| 22 | PCI SAQ A invariant: `grep` for `curl_exec`/`file_get_contents\(.*http` outside `src/Client/` is empty. | | — |
| 23 | No raw `$_GET`/`$_POST`/`$_REQUEST` access without `sanitize_*` / `wp_unslash`. | | — |
| 24 | REST responses never leak keys, secrets, or merchant ids in error paths. | | — |
| 25 | Admin nonces on **every** state-changing admin form. | | — |

## Findings

(List each ⚠️ row from the table with repro + fix + patch commit. Zero allowed for v0.1.0 tag.)

## Approval

Signed-off by: [reviewer]
Approved for `v0.1.0` tag: [yes/no]
```

- [ ] **Step 2: Walk `src/` file-by-file, fill the checklist**

For each row, either write a `file:line` into the Evidence column or open a fix. Patches land as normal commits referencing this review doc.

- [ ] **Step 3: Commit the review**

```bash
git add docs/superpowers/reviews/2026-11-13-v0.1.0-security-review.md
git commit -m "security: v0.1.0 pre-release review — all 25 checks passing"
```

### Task 16.3: External dependency audit

- [ ] **Step 1: Run**

```bash
composer audit --locked --format=plain | tee audit.txt
```

Expected: `No security vulnerability advisories found`. If any — even a transitive `low` severity — stop and patch before tagging.

- [ ] **Step 2: Add `aslan/payment-php` version pinning rationale**

In the review doc under a "Dependency pinning" section, record the exact SDK version used and why. Payment SDKs are the one dep we must treat as load-bearing — a silent minor upgrade can change HTTP behavior or validation rules and break production.

### Task 16.4: Review exit gate

**Phase 16 gate:**
- ✅ `psalm --taint-analysis` 0 errors
- ✅ TruffleHog finds zero verified secrets
- ✅ `composer audit` 0 advisories
- ✅ Review doc complete, 25/25 rows green
- ✅ All findings filed as issues OR fixed and referenced in the review doc

**Only after this phase is complete do we tag `v0.1.0`.**

---

# Final gate

- [ ] `composer test` passes (unit + integration suites)
- [ ] `composer analyse` passes (PHPStan level 8 — 0 errors)
- [ ] `composer lint` passes (phpcs WordPress)
- [ ] `npm run e2e` passes against Aslan test environment
- [ ] `grep -r "curl_exec\|file_get_contents\(.*http" src/ | grep -v "src/Client/"` is empty (card-data invariant)
- [ ] Phase 16 security review signed off, 25/25 checks green
- [ ] `psalm --taint-analysis` 0 errors
- [ ] `composer audit` 0 advisories
- [ ] `v0.1.0` tag produces a GitHub release ZIP + wordpress.org SVN commit

---

# Spec coverage self-review

| Spec section | Implemented in |
|---|---|
| 1 one-sentence scope | all phases |
| 2 in-scope v1 | phases 5–11 |
| 2 explicitly not in v1 | enforced by what's missing |
| 2 compatibility targets | phase 1 (composer), phase 1 (plugin header), phase 1 (HPOS declare), phase 13 (activation check added below as a follow-up — see "post-plan" below) |
| 3 architecture layers | phases 3 (Layer 1), 4+6+7+9+14 (Layer 2), 5+8+10+11+12 (Layer 3) |
| 3 request entry points | phase 5 (filter), phase 6 (process_payment), phase 7 (REST) |
| 4 happy path WooCommerce | phase 6 + phase 7 |
| 4 webhook sole source of truth | phase 7 |
| 4 race tolerance | phase 10 |
| 4 idempotency outbound | phase 6 (idempotency key) |
| 4 idempotency inbound | phase 7.3 + REST handler |
| 4 shortcode flow | phase 11 |
| 5.1 settings page | phase 12 |
| 5.2 setup status card | phase 12 |
| 5.3 order screen refund + payment block | phase 9 + see "post-plan" for payment block mockup from §5.3 |
| 6 shortcode + block | phase 11 |
| 7 Class A transient | phase 3 retry + phase 6 catch |
| 7 Class B 4xx | phase 6 catch (admin notice is minimal — phase 12 improves) |
| 7 Class C invalid signature | phase 7 (401) + phase 14 (log) |
| 7 Class D unknown resource | phase 7 handle() + phase 14 log |
| 7 "never auto-fail without webhook" | invariant held by only ever calling `update_status('failed')` from WebhookService |
| 8 unit / integration / E2E | phases 1 (config), 5 (integration bootstrap), 15 (E2E) |
| 8 CI gates | phase 1 CI + phase 15 E2E |
| 9 wordpress.org SVN deploy | phase 15 |
| 10 whole-MAD everywhere | phase 6 (AmountValidator), phase 11 (shortcode), phase 13 (scanner) |
| security (new) | phase 16 — taint analysis, secret scan, composer audit, 25-point review |

### Post-plan follow-ups (NOT in this plan, filed for after v1 lands)

- Phase 5.3 enriched order screen with the "Payment" block UI (rows for session id, payment id, captured timestamp, card last 4) — fold into phase 9 if time allows, else ship as 0.2.0
- Phase 12 admin-notice queue for Class B errors from phase 6 (plumbing is there via `set_transient('aslan_admin_notice', …)`; display in 12.3)
- `php-stubs` for WC may need pinning if phpstan picks up HPOS-conflicting signatures
- WooCommerce activation check in a dedicated `ActivationNotices` class — low-risk, add if E2E catches it

---

**Plan complete and saved to `docs/superpowers/plans/2026-11-13-wordpress-plugin-v1.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
