<?php
/**
 * Server-side SkyPay API client.
 *
 * @package SkyPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class SkyPay_WC_API_Client {
	private const PRODUCTION_BASE_URL = 'https://payment.skytech.ly/api';

	public function __construct(
		private readonly string $api_key,
		private readonly bool $debug = false
	) {
	}

	/**
	 * Check the configured API key and merchant connection.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function connection(): array|WP_Error {
		return $this->request( 'GET', '/payments/connection' );
	}

	/**
	 * Create a hosted SkyPay payment.
	 *
	 * @param array<string, mixed> $payload Payment creation payload.
	 * @param string               $idempotency_key Stable request idempotency key.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_payment( array $payload, string $idempotency_key ): array|WP_Error {
		return $this->request( 'POST', '/payments', $payload, $idempotency_key, true );
	}

	/**
	 * Fetch a payment using the merchant order reference.
	 *
	 * @param string $merchant_order_id Merchant-scoped order reference.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_payment_by_order( string $merchant_order_id ): array|WP_Error {
		return $this->request( 'GET', '/payments/order/' . rawurlencode( $merchant_order_id ) );
	}

	/**
	 * Send one authenticated request to SkyPay.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path API path.
	 * @param array<string, mixed>|null $payload Request payload.
	 * @param string|null               $idempotency_key Optional idempotency key.
	 * @param bool                      $retry_server_errors Whether one safe retry is permitted.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request(
		string $method,
		string $path,
		?array $payload = null,
		?string $idempotency_key = null,
		bool $retry_server_errors = false
	): array|WP_Error {
		$headers = array(
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $this->api_key,
			'User-Agent'    => 'SkyPay-WooCommerce/' . SKYPAY_WC_VERSION,
		);
		if ( null !== $payload ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( null !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => 20,
			'redirection' => 0,
			'sslverify'   => true,
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$attempts = $retry_server_errors ? 2 : 1;
		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$response = wp_remote_request( $this->base_url() . $path, $args );
			if ( is_wp_error( $response ) ) {
				$this->log(
					'warning',
					'SkyPay API transport error',
					array(
						'method'  => $method,
						'path'    => $path,
						'attempt' => $attempt,
					)
				);
				if ( $attempt < $attempts ) {
					usleep( 200000 );
					continue;
				}
				return new WP_Error( 'skypay_transport_error', __( 'SkyPay could not be reached. Please try again.', 'skypay-woocommerce' ) );
			}

			$status = wp_remote_retrieve_response_code( $response );
			$body   = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( $status >= 500 && $attempt < $attempts ) {
				$this->log(
					'warning',
					'SkyPay API server error; retrying safely',
					array(
						'method'  => $method,
						'path'    => $path,
						'status'  => $status,
						'attempt' => $attempt,
					)
				);
				usleep( 200000 );
				continue;
			}

			if ( $status < 200 || $status >= 300 || ! is_array( $body ) || empty( $body['success'] ) ) {
				$this->log(
					'error',
					'SkyPay API request failed',
					array(
						'method' => $method,
						'path'   => $path,
						'status' => $status,
					)
				);
				$message = is_array( $body ) && isset( $body['error'] ) && is_string( $body['error'] )
					? sanitize_text_field( $body['error'] )
					: __( 'SkyPay rejected the request.', 'skypay-woocommerce' );
				return new WP_Error( 'skypay_api_error', $message, array( 'status' => $status ) );
			}

			return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
		}

		return new WP_Error( 'skypay_api_error', __( 'SkyPay request failed.', 'skypay-woocommerce' ) );
	}

	private function base_url(): string {
		$base_url = self::PRODUCTION_BASE_URL;
		if ( defined( 'SKYPAY_WC_DEVELOPMENT' ) && true === SKYPAY_WC_DEVELOPMENT ) {
			if ( defined( 'SKYPAY_API_BASE_URL' ) ) {
				$base_url = (string) SKYPAY_API_BASE_URL;
			}
			$base_url = (string) apply_filters( 'skypay_wc_api_base_url', $base_url );
		}
		return untrailingslashit( esc_url_raw( $base_url ) );
	}

	/**
	 * Write a redacted WooCommerce log entry when debug logging is enabled.
	 *
	 * @param string                    $level WooCommerce log level.
	 * @param string                    $message Safe log message.
	 * @param array<string, int|string> $context Safe diagnostic context.
	 */
	private function log( string $level, string $message, array $context = array() ): void {
		if ( ! $this->debug || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array_merge( array( 'source' => 'skypay-woocommerce' ), $context ) );
	}
}
