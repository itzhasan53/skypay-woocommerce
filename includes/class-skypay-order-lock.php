<?php
/**
 * Connection-owned, site-scoped order mutexes.
 *
 * @package SkyPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class SkyPay_WC_Order_Lock {

	private function __construct( private readonly string $name, private readonly int $connection ) {
	}

	public static function acquire( int $order_id ): ?self {
		global $wpdb;
		// Database and table prefix isolate installations and multisite blogs on one server.
		$database = defined( 'DB_NAME' ) ? (string) constant( 'DB_NAME' ) : '';
		$name     = 'skypay:' . substr( hash( 'sha256', $database . '|' . $wpdb->prefix . '|' . $order_id ), 0, 56 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A database mutex must bypass caches and is released on connection loss.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( '1' !== (string) $acquired ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Capture ownership on the same connection as GET_LOCK.
		$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		return new self( $name, $connection );
	}

	public function assert_owned(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Detect transparent reconnects before persisting a remote response.
		$owned = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d', $this->name, $this->connection ) );
		if ( '1' !== (string) $owned ) {
			throw new RuntimeException( 'SkyPay order lock ownership was lost.' );
		}
	}

	public function release(): void {
		global $wpdb;
		// RELEASE_LOCK cannot release another connection's lock. Never expire or steal a live owner's mutex.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Connection-owned mutex release, not an application data query.
		$wpdb->get_var( $wpdb->prepare( 'SELECT IF(CONNECTION_ID() = %d, RELEASE_LOCK(%s), 0)', $this->connection, $this->name ) );
	}

	public function load_order( int $order_id ): ?WC_Order {
		$this->assert_owned();
		try {
			// Keep the factory-selected subtype and data store, then refresh persisted state under the mutex.
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return null;
			}
			$order->get_data_store()->read( $order );
			$order->read_meta_data( true );
			return $order->get_id() ? $order : null;
		} catch ( Exception $error ) {
			return null;
		}
	}
}
