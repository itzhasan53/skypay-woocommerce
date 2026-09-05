=== SkyPay for WooCommerce ===
Contributors: skypay
Tags: payments, libya, lyd, hosted checkout, woocommerce
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
WC requires at least: 9.0
WC tested up to: 10.0
Stable tag: 0.1.0-beta.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept one-time LYD payments through SkyPay-hosted checkout with signed webhook confirmation.

== Description ==

SkyPay for WooCommerce creates a hosted SkyPay checkout for each WooCommerce order. The plugin does not collect or embed card, wallet, or OTP credentials.

Payment completion is authoritative only after a valid signed `payment.completed` webhook or a server-to-server SkyPay API verification. Browser success and cancellation redirects never mark an order paid.

Version 1 supports:

* Classic checkout and Checkout Blocks.
* High-Performance Order Storage (HPOS).
* TEST and LIVE SkyPay API keys.
* Exact three-decimal LYD to fils conversion.
* Idempotent checkout creation and webhook replay handling.
* Action Scheduler reconciliation for pending orders.
* Encrypted API key and webhook-secret storage.

Version 1 does not support automatic refunds, subscriptions, saved cards, recurring payments, or embedded checkout.

== Installation ==

1. Upload `skypay-woocommerce.zip` from WordPress Plugins > Add New > Upload Plugin.
2. Activate SkyPay for WooCommerce.
3. Open WooCommerce > Settings > Payments > SkyPay.
4. Select TEST or LIVE and paste the matching SkyPay API key.
5. Copy the displayed webhook URL into SkyPay Developers and subscribe it to `payment.completed`.
6. Paste the one-time webhook signing secret into the plugin settings.
7. Save, use Test connection, and enable the gateway.

== Frequently Asked Questions ==

= Which currencies are supported? =

LYD only. Orders in other currencies do not show SkyPay as an available gateway.

= Does a success redirect mean the order is paid? =

No. The plugin waits for a signed webhook or verifies the payment through the SkyPay API.

= What happens after a cancellation redirect? =

The order remains pending because a late provider confirmation may still arrive.

= Where is the webhook URL? =

It is displayed in WooCommerce > Settings > Payments > SkyPay and normally ends in `/wp-json/skypay/v1/webhook`.

= What data is sent to SkyPay? =

The amount, LYD currency, an opaque WooCommerce order reference, an integration identifier, plugin version, and an opaque site fingerprint. Customer name, email, phone, address, and cart contents are not sent by default.

== External services ==

This plugin connects to the SkyPay API at `https://payment.skytech.ly/api` to create hosted checkouts, test API-key connectivity, and reconcile payment status. Shoppers are redirected to the SkyPay-hosted checkout page to complete payment.

Use of the service is subject to SkyPay merchant terms and privacy documentation. API credentials remain on the WordPress server.

== Privacy ==

See `privacy.md` included with the plugin.

== Changelog ==

= 0.1.0-beta.1 =

* Initial sandbox acceptance release.
