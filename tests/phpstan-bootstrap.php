<?php
// Stubs so PHPStan understands WordPress + WooCommerce symbols without loading them.
if (! defined('ABSPATH')) { define('ABSPATH', '/dev/null/'); }

if (! function_exists('get_option')) {
    /**
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $option, $default = false)
    {
        return $default;
    }
}
