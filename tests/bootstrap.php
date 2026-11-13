<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Integration suite needs the WP test harness; detect via an env var the
// suite sets in its CI matrix (ASLAN_WOO_SUITE=integration).
if (getenv('ASLAN_WOO_SUITE') === 'integration') {
    require __DIR__ . '/bootstrap-wp.php';
}
