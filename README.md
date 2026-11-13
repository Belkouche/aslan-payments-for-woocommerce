# Aslan Payments for WooCommerce

WordPress plugin: accept Moroccan card, mobile wallet, and cash-agent payments through [Aslan](https://aslanpay.ma) on any WooCommerce store, plus an `[aslan_pay]` shortcode for standalone one-off payments.

**Status:** pre-development. The design is in [`docs/superpowers/specs/2026-11-13-wordpress-plugin-design.md`](docs/superpowers/specs/2026-11-13-wordpress-plugin-design.md).

## What this is

- A WooCommerce payment gateway (card data never touches WordPress — PCI SAQ A)
- Plus a minimal `[aslan_pay amount="…" description="…"]` shortcode and matching Gutenberg block for sites that aren't using Woo
- Setup is one step: paste your test and live secret keys. The plugin registers its own webhook endpoint and signing secret automatically — no copy-paste

## Requirements

- WordPress ≥ 6.4
- WooCommerce ≥ 8.0 (gateway only — shortcode works without Woo)
- PHP 8.2.35+ / 8.3.36+ / 8.4.27+ / 8.5.12+ (matches the PHP SDK — patched versions only)

## License

GPL-v2-or-later.
