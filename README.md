# SkyPay for WooCommerce

Installable WooCommerce gateway for one-time LYD payments through SkyPay-hosted checkout.

## Compatibility

- PHP 8.1+
- WordPress 6.4+
- WooCommerce 9+
- Classic checkout and Checkout Blocks
- HPOS

## Development

Run `npm ci`, `npm run build`, `composer install`, `composer test`, `composer phpstan`, and `composer phpcs`. Then build the distributable with `python3 bin/build-zip.py`.

The generated artifact is `dist/skypay-woocommerce.zip`; its SHA-256 checksum is written beside it.

## Security model

API keys and webhook secrets are encrypted with authenticated encryption derived from WordPress salts. Payment state is never trusted from a browser redirect. Only a correctly signed webhook or an authenticated server-to-server lookup can complete an order.

Do not commit real SkyPay credentials or customer data to this repository.
