<?php

declare(strict_types=1);

namespace Aslan\Woo\Admin;

/**
 * Pure helpers behind the Gateway settings form: secret keys are never echoed back
 * into the page, and the webhook URL override must be a valid HTTPS URL.
 */
final class SettingsGuard
{
    public const MASK = '••••••••••••';

    /** What the form shows for a stored secret: a fixed placeholder, never any part of the key. */
    public static function mask(string $stored): string
    {
        return $stored === '' ? '' : self::MASK;
    }

    /** A posted placeholder means "unchanged": keep the stored key instead of overwriting it with dots. */
    public static function resolveKey(string $posted, string $stored): string
    {
        $posted = trim($posted);
        return str_contains($posted, '•') ? $stored : $posted;
    }

    /** Returns '' (no override), the sanitized URL, or null when the value is not an acceptable HTTPS URL. */
    public static function webhookOverride(string $posted): ?string
    {
        $url = trim($posted);
        if ($url === '') {
            return '';
        }
        if (! str_starts_with(strtolower($url), 'https://') || wp_http_validate_url($url) === false) {
            return null;
        }
        return esc_url_raw($url);
    }
}
