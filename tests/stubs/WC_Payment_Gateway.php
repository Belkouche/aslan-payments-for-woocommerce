<?php

// Unit-test-only stand-in for WooCommerce's WC_Payment_Gateway. Only the
// pieces our Gateway actually reaches through `parent::` are declared, with
// side-effects captured in public properties so tests can assert them.
// phpcs:ignoreFile

declare(strict_types=1);

if (! class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway
    {
        /** @var string */
        public $id = '';

        /** @var string */
        public $method_title = '';

        /** @var string */
        public $method_description = '';

        /** @var string */
        public $title = '';

        /** @var string */
        public $description = '';

        /** @var string */
        public $icon = '';

        /** @var bool */
        public $has_fields = false;

        /** @var string */
        public $enabled = 'no';

        /** @var array<string,string|array> */
        public $supports = [];

        /** @var array<string,array<string,mixed>> */
        public $form_fields = [];

        /** @var array<string,mixed> */
        public $settings = [];

        /** @var list<string> Captured errors so tests can assert what WC would show. */
        public $errors = [];

        /** @var int Count of parent::admin_options() invocations. */
        public $admin_options_rendered = 0;

        /** @var int Count of parent::process_admin_options() invocations. */
        public $parent_process_called = 0;

        /** @var bool|null Return value the next parent::process_admin_options() will yield. */
        public $parent_process_result = true;

        public function init_form_fields(): void
        {
        }

        public function init_settings(): void
        {
        }

        /**
         * @param string $key
         * @param mixed  $empty_value
         * @return mixed
         */
        public function get_option($key, $empty_value = null)
        {
            return $this->settings[$key] ?? $empty_value;
        }

        public function admin_options(): void
        {
            ++$this->admin_options_rendered;
        }

        public function process_admin_options(): bool
        {
            ++$this->parent_process_called;
            return (bool) $this->parent_process_result;
        }

        public function add_error(string $msg): void
        {
            $this->errors[] = $msg;
        }
    }
}
