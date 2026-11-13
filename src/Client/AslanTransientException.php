<?php

declare(strict_types=1);

namespace Aslan\Woo\Client;

/** Transient failure — network, 5xx, timeout. Callers may inform the user to retry. */
final class AslanTransientException extends AslanWooException
{
}
