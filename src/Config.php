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
