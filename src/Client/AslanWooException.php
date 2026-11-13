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
