<?php
/**
 * WooCommerce order state and reconciliation.
 *
 * @package SkyPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class SkyPay_WC_Order_Manager {
	private const ACTION_HOOK = 'skypay_wc_reconcile_order';
	private const GROUP       = 'skypay-woocommerce';
	private const DELAYS      = array( 60, 300, 900, 1800, 3600 );

	public static function init(): void {
		add_action( self::ACTION_HOOK, array( self::class, 'run_reconciliation' ) );
		add_action( 'woocommerce_api_skypay_return', array( self::class, 'handle_return' ) );
	}

	public static function merchant_order_id( WC_Order $order ): string {
		$existing = (string) $order->get_meta( '_skypay_merchant_order_id', true );
		if ( '' !== $existing ) {
			return $existing;
		}

		$fingerprint = self::site_fingerprint();
		$reference   = sprintf( 'wc-%s-%d-%s', $fingerprint, $order->get_id(), bin2hex( random_bytes( 6 ) ) );
		$order->update_meta_data( '_skypay_merchant_order_id', $reference );
		return $reference;
	}

	public static function site_fingerprint(): string {
		$site = untrailingslashit( network_home_url( '/' ) ) . '|' . get_current_blog_id();
		return substr( hash_hmac( 'sha256', $site, wp_salt( 'nonce' ) ), 0, 16 );
	}

	public static function schedule( int $order_id, int $attempt = 0 ): void {
		if ( $attempt >= count( self::DELAYS ) ) {
			return;
		}

		$timestamp = time() + self::DELAYS[ $attempt ];
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( ! as_has_scheduled_action( self::ACTION_HOOK, array( $order_id ), self::GROUP ) ) {
				as_schedule_single_action( $timestamp, self::ACTION_HOOK, array( $order_id ), self::GROUP, true );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::ACTION_HOOK, array( $order_id ) ) ) {
			wp_schedule_single_event( $timestamp, self::ACTION_HOOK, array( $order_id ) );
		}
	}

	public static function run_reconciliation( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->is_paid() ) {
			return;
		}

		$attempt = (int) $order->get_meta( '_skypay_reconciliation_attempt', true );
		$order->update_meta_data( '_skypay_reconciliation_attempt', (string) ( $attempt + 1 ) );
		$order->save();

		$result = self::fetch_authoritative_status( $order );
		if ( is_wp_error( $result ) ) {
			self::schedule( $order_id, $attempt + 1 );
			return;
		}

		$resolved = self::apply_authoritative_status( $order, $result, 'api' );
		if ( ! $resolved ) {
			self::schedule( $order_id, $attempt + 1 );
		}
	}

	/**
	 * Fetch the order's current state from SkyPay.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function fetch_authoritative_status( WC_Order $order ): array|WP_Error {
		$reference = (string) $order->get_meta( '_skypay_merchant_order_id', true );
		if ( '' === $reference ) {
			return new WP_Error( 'skypay_missing_reference', __( 'SkyPay order reference is missing.', 'skypay-woocommerce' ) );
		}

		$gateway = self::gateway();
		if ( ! $gateway instanceof SkyPay_WC_Gateway ) {
			return new WP_Error( 'skypay_gateway_unavailable', __( 'SkyPay gateway is unavailable.', 'skypay-woocommerce' ) );
		}

		$client = $gateway->client_for_order( $order );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->get_payment_by_order( $reference );
	}

	/**
	 * Validate and apply an authoritative SkyPay payment state.
	 *
	 * @param WC_Order             $order WooCommerce order.
	 * @param array<string, mixed> $payment Authoritative SkyPay payment data.
	 * @param string               $source Trusted confirmation source label.
	 */
	public static function apply_authoritative_status( WC_Order $order, array $payment, string $source ): bool {
		$expected_reference = (string) $order->get_meta( '_skypay_merchant_order_id', true );
		$expected_amount    = (int) $order->get_meta( '_skypay_amount_fils', true );
		$expected_currency  = strtoupper( (string) $order->get_currency() );
		$expected_merchant  = (string) $order->get_meta( '_skypay_merchant_id', true );

		if (
			! isset( $payment['merchantOrderId'], $payment['status'], $payment['amount'], $payment['currency'] ) ||
			! is_string( $payment['merchantOrderId'] ) ||
			! hash_equals( $expected_reference, $payment['merchantOrderId'] ) ||
			(int) $payment['amount'] !== $expected_amount ||
			strtoupper( (string) $payment['currency'] ) !== $expected_currency ||
			( isset( $payment['merchantId'] ) && '' !== $expected_merchant && ! hash_equals( $expected_merchant, (string) $payment['merchantId'] ) )
		) {
			$order->add_order_note( __( 'SkyPay confirmation was rejected because the order reference, merchant, amount, or currency did not match.', 'skypay-woocommerce' ) );
			return false;
		}

		$status     = strtoupper( (string) $payment['status'] );
		$payment_id = isset( $payment['paymentId'] ) ? sanitize_text_field( (string) $payment['paymentId'] ) : '';
		switch ( $status ) {
			case 'COMPLETED':
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $payment_id );
					/* translators: %s: trusted payment confirmation source. */
					$order->add_order_note( sprintf( __( 'SkyPay payment confirmed by %s.', 'skypay-woocommerce' ), $source ) );
				}
				return true;
			case 'FAILED':
				if ( ! $order->has_status( 'failed' ) ) {
					$order->update_status( 'failed', __( 'SkyPay reported that the payment failed.', 'skypay-woocommerce' ) );
				}
				return true;
			case 'REQUIRES_REVIEW':
				if ( ! $order->has_status( 'on-hold' ) ) {
					$order->update_status( 'on-hold', __( 'SkyPay requires a manual payment review.', 'skypay-woocommerce' ) );
				}
				return true;
			default:
				if ( ! $order->has_status( 'pending' ) ) {
					$order->update_status( 'pending', __( 'SkyPay payment is awaiting authoritative confirmation.', 'skypay-woocommerce' ) );
				}
				return false;
		}
	}

	public static function handle_return(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The opaque state token is verified below.
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The opaque state token is verified below.
		$token = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || '' === $token ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$stored_hash = (string) $order->get_meta( '_skypay_return_token_hash', true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, hash( 'sha256', $token ) ) ) {
			$order->add_order_note( __( 'A SkyPay return request with an invalid token was rejected.', 'skypay-woocommerce' ) );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The opaque state token was verified above.
		$is_cancelled = isset( $_GET['cancelled'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['cancelled'] ) );
		if ( $is_cancelled ) {
			$order->add_order_note( __( 'The customer returned from SkyPay using the cancellation path. Payment confirmation is still pending.', 'skypay-woocommerce' ) );
		}

		$result = self::fetch_authoritative_status( $order );
		if ( ! is_wp_error( $result ) ) {
			self::apply_authoritative_status( $order, $result, 'api return verification' );
		}
		if ( ! $order->is_paid() ) {
			self::schedule( $order_id );
		}

		wp_safe_redirect( $is_cancelled && ! $order->is_paid() ? $order->get_checkout_payment_url() : $order->get_checkout_order_received_url() );
		exit;
	}

	private static function gateway(): ?WC_Payment_Gateway {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return $gateways['skypay'] ?? null;
	}
}
