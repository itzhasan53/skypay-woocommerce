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
		add_action( self::ACTION_HOOK, array( self::class, 'run_reconciliation' ), 10, 2 );
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

	public static function schedule( int $order_id, int $attempt = 0, ?WC_Order $order = null ): bool {
		if ( $attempt < 0 || $attempt >= count( self::DELAYS ) ) {
			if ( $order instanceof WC_Order ) {
				self::note_once( $order, '_skypay_reconciliation_exhausted', __( 'SkyPay automatic confirmation attempts are exhausted. Verify the payment in SkyPay before changing this order.', 'skypay-woocommerce' ) );
			}
			return false;
		}

		$args      = array( $order_id, $attempt );
		$timestamp = time() + self::DELAYS[ $attempt ];
		if ( function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_schedule_single_action' ) ) {
			// Only pending jobs suppress enqueueing. A running job may need to retry lock contention.
			$pending = as_get_scheduled_actions( array( 'hook' => self::ACTION_HOOK, 'args' => $args, 'group' => self::GROUP, 'status' => 'pending', 'per_page' => 1 ), 'ids' );
			$queued  = ! empty( $pending ) || 0 < as_schedule_single_action( $timestamp, self::ACTION_HOOK, $args, self::GROUP, false );
		} else {
			$queued = (bool) wp_next_scheduled( self::ACTION_HOOK, $args ) || true === wp_schedule_single_event( $timestamp, self::ACTION_HOOK, $args, true );
		}
		if ( ! $queued ) {
			if ( $order instanceof WC_Order ) {
				self::note_once( $order, '_skypay_reconciliation_enqueue_failed', __( 'SkyPay could not schedule payment confirmation. Check scheduled actions and verify this payment manually.', 'skypay-woocommerce' ) );
			}
			wc_get_logger()->error( 'SkyPay reconciliation enqueue failed.', array( 'source' => self::GROUP, 'order_id' => $order_id, 'attempt' => $attempt ) );
		}
		return $queued;
	}

	private static function note_once( WC_Order $order, string $key, string $message ): void {
		if ( ! $order->get_meta( $key, true ) ) {
			$order->update_meta_data( $key, 'yes' );
			$order->save();
			$order->add_order_note( $message );
		}
	}

	public static function is_protected( WC_Order $order ): bool {
		return $order->is_paid() || $order->has_status( 'refunded' ) || null !== $order->get_date_paid() || 'yes' === $order->get_meta( '_skypay_payment_completed', true );
	}

	public static function run_reconciliation( int $order_id, ?int $attempt = null ): void {
		$lock = SkyPay_WC_Order_Lock::acquire( $order_id );
		if ( null === $lock ) {
			self::schedule( $order_id, $attempt ?? 0 );
			return;
		}
		try {
			$order = $lock->load_order( $order_id );
			if ( ! $order instanceof WC_Order || self::is_protected( $order ) ) {
				return;
			}
			$next_attempt = (int) $order->get_meta( '_skypay_reconciliation_attempt', true );
			// Existing one-argument jobs continue from persisted progress.
			$attempt = $attempt ?? $next_attempt;
			if ( $attempt < $next_attempt || $attempt < 0 ) {
				return;
			}
			if ( $attempt >= count( self::DELAYS ) ) {
				self::schedule( $order_id, $attempt, $order );
				return;
			}
			$result = self::fetch_authoritative_status( $order );
			$lock->assert_owned();
			$order->update_meta_data( '_skypay_reconciliation_attempt', (string) ( $attempt + 1 ) );
			$order->save();
			$resolved = ! is_wp_error( $result ) && self::apply_authoritative_status( $order, $result, 'api' );
			if ( ! $resolved ) {
				self::schedule( $order_id, $attempt + 1, $order );
			}
		} finally {
			$lock->release();
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
		$result = $client->get_payment_by_order( $reference );
		// Legacy bindings are learned only from this authenticated, order-scoped API read.
		// The caller persists this metadata only after validating lock ownership and the full response.
		if ( ! is_wp_error( $result ) && '' === (string) $order->get_meta( '_skypay_merchant_id', true ) && self::matches_payment( $order, $result, false ) ) {
			$order->update_meta_data( '_skypay_merchant_id', $result['merchantId'] );
		}
		return $result;
	}

	/**
	 * Validate the immutable payment identity.
	 *
	 * @param WC_Order             $order Order under the mutex.
	 * @param array<string, mixed> $payment API or signed webhook payload.
	 * @param bool                 $require_merchant Whether an existing binding is required.
	 */
	private static function matches_payment( WC_Order $order, array $payment, bool $require_merchant = true ): bool {
		$reference = (string) $order->get_meta( '_skypay_merchant_order_id', true );
		$merchant  = (string) $order->get_meta( '_skypay_merchant_id', true );
		$amount    = (int) $order->get_meta( '_skypay_amount_fils', true );
		return isset( $payment['merchantId'], $payment['merchantOrderId'], $payment['status'], $payment['amount'], $payment['currency'] )
			&& is_string( $payment['merchantId'] ) && '' !== $payment['merchantId']
			&& ( ! $require_merchant || ( '' !== $merchant && hash_equals( $merchant, $payment['merchantId'] ) ) )
			&& is_string( $payment['merchantOrderId'] ) && '' !== $reference && hash_equals( $reference, $payment['merchantOrderId'] )
			&& is_int( $payment['amount'] ) && $amount > 0 && $payment['amount'] === $amount
			&& is_string( $payment['currency'] ) && strtoupper( $payment['currency'] ) === strtoupper( (string) $order->get_currency() )
			&& is_string( $payment['status'] )
			&& ( ! isset( $payment['mode'] ) || $payment['mode'] === $order->get_meta( '_skypay_mode', true ) );
	}

	/**
	 * Validate and apply an authoritative SkyPay payment state.
	 *
	 * @param WC_Order             $order WooCommerce order.
	 * @param array<string, mixed> $payment Authoritative SkyPay payment data.
	 * @param string               $source Trusted confirmation source label.
	 */
	public static function apply_authoritative_status( WC_Order $order, array $payment, string $source ): bool {
		if ( ! self::matches_payment( $order, $payment ) ) {
			$order->add_order_note( __( 'SkyPay confirmation was rejected because the order reference, merchant, amount, or currency did not match.', 'skypay-woocommerce' ) );
			return false;
		}
		if ( self::is_protected( $order ) ) {
			return true;
		}
		$order->save();

		$status     = strtoupper( (string) $payment['status'] );
		$payment_id = isset( $payment['paymentId'] ) ? sanitize_text_field( (string) $payment['paymentId'] ) : '';
		switch ( $status ) {
			case 'COMPLETED':
				if ( ! $order->is_paid() ) {
					if ( ! $order->payment_complete( $payment_id ) ) {
						return false;
					}
					// payment_complete() returns only after saving, but refresh before
					// recording the SkyPay marker so a failing store extension cannot
					// strand an unpaid order behind an irreversible local marker.
					$order->get_data_store()->read( $order );
					$order->read_meta_data( true );
					if ( ! $order->is_paid() || null === $order->get_date_paid() ) {
						return false;
					}
					$order->update_meta_data( '_skypay_payment_completed', 'yes' );
					$order->save();
					/* translators: %s: trusted payment confirmation source. */
					$order->add_order_note( sprintf( __( 'SkyPay payment confirmed by %s.', 'skypay-woocommerce' ), $source ) );
				}
				return true;
			case 'FAILED':
				if ( $order->has_status( 'cancelled' ) ) {
					return true;
				}
				if ( ! $order->has_status( 'failed' ) ) {
					$order->update_status( 'failed', __( 'SkyPay reported that the payment failed.', 'skypay-woocommerce' ) );
				}
				return true;
			case 'REQUIRES_REVIEW':
				if ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
					return true;
				}
				if ( ! $order->has_status( 'on-hold' ) ) {
					$order->update_status( 'on-hold', __( 'SkyPay requires a manual payment review.', 'skypay-woocommerce' ) );
				}
				return true;
			default:
				// Pending and unknown statuses never undo a review, failure, cancellation or payment.
				return false;
		}
	}

	public static function handle_return(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The opaque state token is verified below.
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The opaque state token is verified below.
		$token = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The opaque state token is verified under the order mutex.
		$is_cancelled = isset( $_GET['cancelled'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['cancelled'] ) );
		wp_safe_redirect( self::verify_return( $order_id, $token, $is_cancelled ) );
		exit;
	}

	public static function verify_return( int $order_id, string $token, bool $is_cancelled = false ): string {
		$lock = SkyPay_WC_Order_Lock::acquire( $order_id );
		if ( null === $lock ) {
			return wc_get_checkout_url();
		}
		try {
			$order = $lock->load_order( $order_id );
			if ( ! $order instanceof WC_Order || '' === $token ) {
				return wc_get_checkout_url();
			}
			$stored_hash = (string) $order->get_meta( '_skypay_return_token_hash', true );
			if ( '' === $stored_hash || ! hash_equals( $stored_hash, hash( 'sha256', $token ) ) ) {
				return wc_get_checkout_url();
			}
			if ( ! self::is_protected( $order ) ) {
				if ( $is_cancelled ) {
					self::note_once( $order, '_skypay_cancel_return_seen', __( 'The customer returned from SkyPay using the cancellation path. Payment confirmation is still pending.', 'skypay-woocommerce' ) );
				}
				$result = self::fetch_authoritative_status( $order );
				$lock->assert_owned();
				// Persist a legacy merchant binding learned from an authenticated
				// API response even when the provider is still pending.
				$order->save();
				$resolved = ! is_wp_error( $result ) && self::apply_authoritative_status( $order, $result, 'api return verification' );
				if ( ! $resolved ) {
					self::schedule( $order_id, (int) $order->get_meta( '_skypay_reconciliation_attempt', true ), $order );
				}
			}
			return $is_cancelled && ! self::is_protected( $order ) ? $order->get_checkout_payment_url() : $order->get_checkout_order_received_url();
		} finally {
			$lock->release();
		}
	}

	private static function gateway(): ?WC_Payment_Gateway {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return $gateways['skypay'] ?? null;
	}
}
