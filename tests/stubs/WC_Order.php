<?php

// Unit-test-only stand-in for WooCommerce's WC_Order (the real class is not
// loaded in the unit suite). Only the methods the plugin calls are declared.
// phpcs:ignoreFile

declare(strict_types=1);

class WC_Order
{
    public function get_id()
    {
        return 0;
    }

    public function get_total()
    {
        return '0';
    }

    public function get_billing_email()
    {
        return '';
    }

    public function get_billing_first_name()
    {
        return '';
    }

    public function get_billing_last_name()
    {
        return '';
    }

    public function get_billing_phone()
    {
        return '';
    }

    public function get_meta($key = '')
    {
        return '';
    }

    public function update_meta_data($key, $value)
    {
    }

    public function save()
    {
        return 0;
    }
}
