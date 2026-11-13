<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

final class CheckoutSessionResult
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $redirectUrl,
    ) {
    }
}
