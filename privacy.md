# Privacy and external service disclosure

SkyPay for WooCommerce sends only the following order-level data to SkyPay:

- Total amount in integer Libyan fils
- Currency (`LYD`)
- Opaque WooCommerce merchant order reference
- Integration name and plugin version
- Opaque, keyed site fingerprint

The plugin does not send the shopper's name, email, phone number, billing address, shipping address, cart contents, or payment credentials by default.

The shopper leaves the store for SkyPay-hosted checkout. SkyPay and the selected payment provider process the payment under their own merchant agreements and privacy terms.

WordPress stores SkyPay checkout identifiers and reconciliation metadata on the WooCommerce order. Uninstalling the plugin removes plugin settings and credentials but deliberately preserves order records and order notes for accounting and audit continuity.

Debug logs contain only redacted request method, route, status, and attempt metadata. They never intentionally contain API keys, webhook secrets, request payloads, or customer data.
