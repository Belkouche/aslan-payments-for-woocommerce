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

    public function get_payment_method()
    {
        return '';
    }

    public function get_currency()
    {
        return '';
    }

    public function get_status()
    {
        return '';
    }

    public function is_paid()
    {
        return false;
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

    public function get_meta($key = '', $single = true)
    {
        return '';
    }

    public function update_meta_data($key, $value)
    {
    }

    public function delete_meta_data($key)
    {
    }

    public function get_meta_data()
    {
        return [];
    }

    public function save()
    {
        return 0;
    }

    public function payment_complete($transaction_id = '')
    {
        return true;
    }

    public function update_status($new_status, $note = '', $manual = false)
    {
        return true;
    }

    public function get_refunds()
    {
        return [];
    }
}
