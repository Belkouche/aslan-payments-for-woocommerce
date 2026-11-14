<?php

declare(strict_types=1);

namespace Aslan\Woo\Client;

/** 401/403 from Aslan — key is invalid or wrong mode. */
final class AslanAuthException extends AslanRequestException
{
}
