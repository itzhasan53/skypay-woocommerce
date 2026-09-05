<?php
/**
 * Verify the plugin is loaded correctly inside a clean wp-env site.
 *
 * @package SkyPay_WooCommerce
 */

if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'WooCommerce is not active.' );
}

$gateways = WC()->payment_gateways()->payment_gateways();
if ( ! isset( $gateways['skypay'] ) || ! $gateways['skypay'] instanceof SkyPay_WC_Gateway ) {
	throw new RuntimeException( 'SkyPay gateway is not registered.' );
}

$required_fields = array(
	'enabled',
	'title',
	'description',
	'mode',
	'api_key',
	'webhook_secret',
	'webhook_url',
	'test_connection',
	'debug',
);
$missing_fields  = array_diff( $required_fields, array_keys( $gateways['skypay']->form_fields ) );
if ( array() !== $missing_fields ) {
	throw new RuntimeException( 'SkyPay gateway settings are incomplete.' );
}

echo "gateway-loaded\n";
