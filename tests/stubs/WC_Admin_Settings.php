<?php

// Unit-test-only stand-in for WC_Admin_Settings. Captures error strings so
// tests can assert what the plugin would surface in the WC admin notice area.
// phpcs:ignoreFile

declare(strict_types=1);

if (! class_exists('WC_Admin_Settings')) {
    class WC_Admin_Settings
    {
        /** @var list<string> */
        public static $errors = [];

        public static function add_error(string $msg): void
        {
            self::$errors[] = $msg;
        }

        public static function reset(): void
        {
            self::$errors = [];
        }
    }
}
