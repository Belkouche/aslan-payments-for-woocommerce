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
        /** @phpstan-var self $instance */
        $instance = self::$instance;
        return $instance;
    }

    private function register(): void
    {
        // Subsequent phases wire services here.
        // Phase 1 leaves this empty on purpose — just prove the autoload path works.
    }
}
