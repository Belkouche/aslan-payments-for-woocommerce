<?php

declare(strict_types=1);

namespace Aslan\Woo\Client;

/** 4xx from Aslan — validation, bad key, bad mode. Not retryable. */
class AslanRequestException extends AslanWooException
{
}
