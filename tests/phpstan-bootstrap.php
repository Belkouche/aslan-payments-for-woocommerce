<?php
// Minimal PHPStan bootstrap. WordPress + WooCommerce symbols come from
// vendor/php-stubs/wordpress-stubs and vendor/php-stubs/woocommerce-stubs
// (see phpstan.neon.dist scanDirectories). Only define constants here.
if (! defined('ABSPATH')) { define('ABSPATH', '/dev/null/'); }
if (! defined('ASLAN_WOO_PLUGIN_URL')) { define('ASLAN_WOO_PLUGIN_URL', 'http://localhost/'); }
if (! defined('ASLAN_WOO_PLUGIN_DIR')) { define('ASLAN_WOO_PLUGIN_DIR', '/dev/null/'); }
if (! defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (! defined('ASLAN_WOO_VERSION')) { define('ASLAN_WOO_VERSION', '0.1.0'); }
