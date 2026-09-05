<?php
/**
 * Signed SkyPay webhook receiver.
 *
 * @package SkyPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class SkyPay_WC_Webhook_Controller {
	private const DELIVERY_LOCK_PREFIX = '_skypay_wc_delivery_lock_';
	private const DELIVERY_LOCK_TTL    = 300;

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'skypay/v1',
			'/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function receive( WP_REST_Request $request ): WP_REST_Response {
		$event     = sanitize_text_field( (string) $request->get_header( 'x-skypay-event' ) );
		$delivery  = sanitize_text_field( (string) $request->get_header( 'x-skypay-delivery' ) );
		$signature = strtolower( sanitize_text_field( (string) $request->get_header( 'x-skypay-signature' ) ) );
		$raw_body  = $request->get_body();

		if ( 'payment.completed' !== $event ) {
			return new WP_REST_Response(
				array(
					'accepted' => false,
					'error'    => 'unsupported_event',
				),
				400
			);
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $signature ) || ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $delivery ) ) {
			return new WP_REST_Response(
				array(
					'accepted' => false,
					'error'    => 'invalid_headers',
				),
				401
			);
		}

		$gateway = self::gateway();
		$secret  = $gateway instanceof SkyPay_WC_Gateway ? $gateway->webhook_secret() : '';
		if ( '' === $secret || ! self::valid_signature( $raw_body, $secret, $signature ) ) {
			return new WP_REST_Response(
				array(
					'accepted' => false,
					'error'    => 'invalid_signature',
				),
				401
			);
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) || empty( $payload['merchantOrderId'] ) ) {
			return new WP_REST_Response(
				array(
					'accepted' => false,
					'error'    => 'invalid_payload',
				),
				400
			);
		}

		$order = self::find_order( (string) $payload['merchantOrderId'] );
		if ( ! $order instanceof WC_Order ) {
			return new WP_REST_Response(
				array(
					'accepted' => false,
					'error'    => 'order_not_found',
				),
				404
			);
		}

		if ( self::delivery_was_processed( $order, $delivery ) ) {
			return new WP_REST_Response(
				array(
					'accepted'  => true,
					'duplicate' => true,
				),
				200
			);
		}

		$lock = self::acquire_delivery_lock( $delivery );
		if ( null === $lock ) {
			$fresh_order = wc_get_order( $order->get_id() );
			if ( $fresh_order instanceof WC_Order && self::delivery_was_processed( $fresh_order, $delivery ) ) {
				return new WP_REST_Response(
					array(
						'accepted'  => true,
						'duplicate' => true,
					),
					200
				);
			}

			return new WP_REST_Response(
				array(
					'accepted' => false,
					'error'    => 'delivery_in_progress',
				),
				409
			);
		}

		try {
			if ( ! SkyPay_WC_Order_Manager::apply_authoritative_status( $order, $payload, 'signed webhook' ) ) {
				return new WP_REST_Response(
					array(
						'accepted' => false,
						'error'    => 'confirmation_mismatch',
					),
					409
				);
			}

			$deliveries   = self::delivery_ids( $order );
			$deliveries[] = $delivery;
			$order->update_meta_data( '_skypay_delivery_ids', array_slice( array_unique( $deliveries ), -50 ) );
			$order->save();
		} finally {
			delete_option( $lock );
		}

		return new WP_REST_Response( array( 'accepted' => true ), 200 );
	}

	public static function valid_signature( string $raw_body, string $secret, string $signature ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $signature )
			&& hash_equals( hash_hmac( 'sha256', $raw_body, $secret ), strtolower( $signature ) );
	}

	/**
	 * Return the delivery IDs already applied to an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return list<string>
	 */
	private static function delivery_ids( WC_Order $order ): array {
		$deliveries = $order->get_meta( '_skypay_delivery_ids', true );
		return is_array( $deliveries ) ? array_values( array_filter( $deliveries, 'is_string' ) ) : array();
	}

	private static function delivery_was_processed( WC_Order $order, string $delivery ): bool {
		return in_array( $delivery, self::delivery_ids( $order ), true );
	}

	private static function acquire_delivery_lock( string $delivery ): ?string {
		$lock      = self::DELIVERY_LOCK_PREFIX . hash( 'sha256', $delivery );
		$timestamp = get_option( $lock, '' );
		if ( '' !== $timestamp && ( ! is_numeric( $timestamp ) || time() - (int) $timestamp > self::DELIVERY_LOCK_TTL ) ) {
			delete_option( $lock );
		}

		return add_option( $lock, (string) time(), '', false ) ? $lock : null;
	}

	private static function find_order( string $merchant_order_id ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'limit'      => 2,
				'return'     => 'objects',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- WooCommerce CRUD keeps this lookup compatible with HPOS and legacy storage.
				'meta_key'   => '_skypay_merchant_order_id',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The collision-resistant reference is the webhook's order lookup key.
				'meta_value' => sanitize_text_field( $merchant_order_id ),
			)
		);
		return 1 === count( $orders ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
	}

	private static function gateway(): ?WC_Payment_Gateway {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return $gateways['skypay'] ?? null;
	}
}
