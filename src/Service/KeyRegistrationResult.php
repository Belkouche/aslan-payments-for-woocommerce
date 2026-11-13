<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

final class KeyRegistrationResult
{
    public function __construct(
        public readonly string $apiKeyId,
        public readonly string $signingSecret,
        public readonly string $webhookUrl,
    ) {
    }
}
