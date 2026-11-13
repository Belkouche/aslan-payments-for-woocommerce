<?php

declare(strict_types=1);

namespace Aslan\Woo\Service;

final class KeyRegistrationResult
{
    public function __construct(
        public readonly string $apiKeyId,
        /**
         * Null when the Aslan webhook endpoint already existed and was re-upserted
         * (the signing secret is only returned at creation / explicit rotation).
         * Callers should KEEP the previously-stored secret when this is null.
         */
        public readonly ?string $signingSecret,
        public readonly string $webhookUrl,
    ) {
    }
}
