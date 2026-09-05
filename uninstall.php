<?php
/**
 * Remove plugin credentials while preserving all WooCommerce orders and order metadata.
 *
 * @package SkyPay_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cleanup = static function (): void {
	delete_option( 'woocommerce_skypay_settings' );
	wp_clear_scheduled_hook( 'skypay_wc_reconcile_order' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'skypay_wc_reconcile_order', array(), 'skypay-woocommerce' );
	}
};

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		$cleanup();
		restore_current_blog();
	}
} else {
	$cleanup();
}
