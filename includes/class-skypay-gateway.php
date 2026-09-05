<?php
/**
 * SkyPay hosted-checkout payment gateway.
 *
 * @package SkyPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class SkyPay_WC_Gateway extends WC_Payment_Gateway {
	private const MASK = '••••••••••••';

	public function __construct() {
		$this->id                 = 'skypay';
		$this->method_title       = __( 'SkyPay', 'skypay-woocommerce' );
		$this->method_description = __( 'Accept one-time LYD payments through SkyPay-hosted checkout.', 'skypay-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title', __( 'SkyPay', 'skypay-woocommerce' ) );
		$this->description = (string) $this->get_option( 'description', __( 'Pay securely using SkyPay.', 'skypay-woocommerce' ) );
		$this->enabled     = (string) $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_ajax_skypay_wc_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable SkyPay', 'skypay-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer SkyPay at checkout', 'skypay-woocommerce' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Checkout title', 'skypay-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'SkyPay', 'skypay-woocommerce' ),
				'desc_tip'    => true,
				'description' => __( 'The payment method title shown to shoppers.', 'skypay-woocommerce' ),
			),
			'description'     => array(
				'title'       => __( 'Checkout description', 'skypay-woocommerce' ),
				'type'        => 'textarea',
				'default'     => __( 'Pay securely using SkyPay.', 'skypay-woocommerce' ),
				'description' => __( 'The short description shown at checkout.', 'skypay-woocommerce' ),
			),
			'mode'            => array(
				'title'       => __( 'Mode', 'skypay-woocommerce' ),
				'type'        => 'select',
				'default'     => 'TEST',
				'options'     => array(
					'TEST' => __( 'TEST', 'skypay-woocommerce' ),
					'LIVE' => __( 'LIVE', 'skypay-woocommerce' ),
				),
				'description' => __( 'The API key must match this mode.', 'skypay-woocommerce' ),
			),
			'api_key'         => array(
				'title'       => __( 'SkyPay API key', 'skypay-woocommerce' ),
				'type'        => 'secret',
				'description' => __( 'Create this key in SkyPay Developers. Leave blank to preserve the saved value.', 'skypay-woocommerce' ),
			),
			'webhook_secret'  => array(
				'title'       => __( 'Webhook signing secret', 'skypay-woocommerce' ),
				'type'        => 'secret',
				'description' => __( 'Paste the secret shown once when the webhook endpoint is created.', 'skypay-woocommerce' ),
			),
			'webhook_url'     => array(
				'title' => __( 'Webhook URL', 'skypay-woocommerce' ),
				'type'  => 'webhook_url',
			),
			'test_connection' => array(
				'title' => __( 'Connection', 'skypay-woocommerce' ),
				'type'  => 'connection_test',
			),
			'debug'           => array(
				'title'       => __( 'Debug logging', 'skypay-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Write redacted SkyPay events to WooCommerce logs', 'skypay-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Do not leave debug logging enabled longer than necessary.', 'skypay-woocommerce' ),
			),
		);
	}

	public function admin_options(): void {
		wp_enqueue_script(
			'skypay-wc-admin',
			SKYPAY_WC_URL . 'assets/build/admin.js',
			array(),
			SKYPAY_WC_VERSION,
			true
		);
		wp_localize_script(
			'skypay-wc-admin',
			'skypayWooAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'skypay_wc_test_connection' ),
				'testing' => __( 'Testing…', 'skypay-woocommerce' ),
				'failed'  => __( 'Connection failed.', 'skypay-woocommerce' ),
			)
		);
		parent::admin_options();
	}

	/**
	 * Render an encrypted credential field without exposing its saved value.
	 *
	 * @param string               $key Field key.
	 * @param array<string, mixed> $data Field configuration.
	 */
	public function generate_secret_html( string $key, array $data ): string {
		$field_key = $this->get_field_key( $key );
		$stored    = (string) $this->get_option( $key, '' );
		$data      = wp_parse_args( $data, array( 'description' => '' ) );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label></th>
			<td class="forminp">
				<input class="input-text regular-input" type="password" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="" placeholder="<?php echo '' !== $stored ? esc_attr( self::MASK ) : ''; ?>" autocomplete="new-password" />
				<p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	public function validate_secret_field( string $key, mixed $value ): string {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value || self::MASK === $value ) {
			return (string) $this->get_option( $key, '' );
		}
		return SkyPay_WC_Crypto::encrypt( $value );
	}

	/**
	 * Render the generated REST webhook URL.
	 *
	 * @param string               $key Field key.
	 * @param array<string, mixed> $data Field configuration.
	 */
	public function generate_webhook_url_html( string $key, array $data ): string {
		unset( $key, $data );
		$url = rest_url( 'skypay/v1/webhook' );
		return '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__( 'Webhook URL', 'skypay-woocommerce' ) .
			'</th><td class="forminp"><code style="user-select:all">' . esc_html( $url ) . '</code><p class="description">' .
			esc_html__( 'Register this URL for payment.completed in SkyPay Developers.', 'skypay-woocommerce' ) . '</p></td></tr>';
	}

	/**
	 * Render the server-side connection test control.
	 *
	 * @param string               $key Field key.
	 * @param array<string, mixed> $data Field configuration.
	 */
	public function generate_connection_test_html( string $key, array $data ): string {
		unset( $key, $data );
		return '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__( 'Connection', 'skypay-woocommerce' ) .
			'</th><td class="forminp"><button type="button" class="button" id="skypay-test-connection">' .
			esc_html__( 'Test connection', 'skypay-woocommerce' ) . '</button> <span id="skypay-test-result" aria-live="polite"></span></td></tr>';
	}

	public function is_available(): bool {
		return parent::is_available()
			&& 'LYD' === strtoupper( get_woocommerce_currency() )
			&& '' !== $this->api_key()
			&& '' !== $this->webhook_secret();
	}

	/**
	 * Create or reuse the order's hosted checkout.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array{result: string, redirect: string}|null
	 */
	public function process_payment( $order_id ): ?array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'The WooCommerce order could not be loaded.', 'skypay-woocommerce' ), 'error' );
			return null;
		}
		if ( 'LYD' !== strtoupper( (string) $order->get_currency() ) ) {
			wc_add_notice( __( 'SkyPay supports LYD orders only.', 'skypay-woocommerce' ), 'error' );
			return null;
		}

		try {
			$amount_fils = SkyPay_WC_Amount::to_fils( (string) $order->get_total() );
		} catch ( InvalidArgumentException $error ) {
			wc_add_notice( esc_html( $error->getMessage() ), 'error' );
			return null;
		}

		$mode            = $this->mode();
		$existing_url    = (string) $order->get_meta( '_skypay_checkout_url', true );
		$existing_mode   = (string) $order->get_meta( '_skypay_mode', true );
		$existing_amount = (int) $order->get_meta( '_skypay_amount_fils', true );
		if ( '' !== $existing_url ) {
			if (
				! $order->is_paid() &&
				$mode === $existing_mode &&
				$amount_fils === $existing_amount &&
				$this->checkout_url_is_allowed( $existing_url )
			) {
				return array(
					'result'   => 'success',
					'redirect' => esc_url_raw( $existing_url ),
				);
			}

			wc_add_notice( __( 'The existing SkyPay checkout no longer matches this order. Please contact the store administrator.', 'skypay-woocommerce' ), 'error' );
			return null;
		}

		$reference       = SkyPay_WC_Order_Manager::merchant_order_id( $order );
		$idempotency_key = (string) $order->get_meta( '_skypay_idempotency_key', true );
		if ( '' === $idempotency_key ) {
			$idempotency_key = wp_generate_uuid4();
			$order->update_meta_data( '_skypay_idempotency_key', $idempotency_key );
		}

		$stored_return_token = (string) $order->get_meta( '_skypay_return_token_encrypted', true );
		$return_token        = SkyPay_WC_Crypto::decrypt( $stored_return_token );
		if ( '' === $return_token ) {
			try {
				$return_token = bin2hex( random_bytes( 32 ) );
				$order->update_meta_data( '_skypay_return_token_encrypted', SkyPay_WC_Crypto::encrypt( $return_token ) );
			} catch ( Throwable $error ) {
				wc_add_notice( __( 'SkyPay could not secure the checkout return. Please try again.', 'skypay-woocommerce' ), 'error' );
				return null;
			}
		}
		$order->update_meta_data( '_skypay_return_token_hash', hash( 'sha256', $return_token ) );
		$order->update_meta_data( '_skypay_amount_fils', (string) $amount_fils );
		$order->update_meta_data( '_skypay_mode', $mode );
		$connected_merchant_id = sanitize_text_field( (string) $this->get_option( 'connected_merchant_id', '' ) );
		if ( '' !== $connected_merchant_id ) {
			$order->update_meta_data( '_skypay_merchant_id', $connected_merchant_id );
		}
		$order->save();

		$return_base = WC()->api_request_url( 'skypay_return' );
		$success_url = add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'state'    => $return_token,
			),
			$return_base
		);
		$cancel_url  = add_query_arg(
			array(
				'order_id'  => $order->get_id(),
				'state'     => $return_token,
				'cancelled' => '1',
			),
			$return_base
		);
		$payload     = array(
			'amount'          => $amount_fils,
			'currency'        => 'LYD',
			'merchantOrderId' => $reference,
			/* translators: %s: WooCommerce order number. */
			'title'           => sprintf( __( 'WooCommerce order #%s', 'skypay-woocommerce' ), $order->get_order_number() ),
			'successUrl'      => $success_url,
			'cancelUrl'       => $cancel_url,
			'metadata'        => array(
				'integration'        => 'woocommerce',
				'integrationVersion' => SKYPAY_WC_VERSION,
				'siteFingerprint'    => SkyPay_WC_Order_Manager::site_fingerprint(),
				'orderReference'     => $reference,
			),
		);

		$result = $this->client()->create_payment( $payload, $idempotency_key );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( esc_html( $result->get_error_message() ), 'error' );
			return null;
		}
		if (
			empty( $result['checkoutUrl'] ) ||
			! is_string( $result['checkoutUrl'] ) ||
			! $this->checkout_url_is_allowed( $result['checkoutUrl'] ) ||
			(string) ( $result['merchantOrderId'] ?? '' ) !== $reference ||
			(int) ( $result['amount'] ?? -1 ) !== $amount_fils ||
			'LYD' !== strtoupper( (string) ( $result['currency'] ?? '' ) ) ||
			strtoupper( (string) ( $result['mode'] ?? '' ) ) !== $mode
		) {
			wc_add_notice( __( 'SkyPay returned an invalid checkout response.', 'skypay-woocommerce' ), 'error' );
			return null;
		}

		$order->update_meta_data( '_skypay_checkout_id', sanitize_text_field( (string) ( $result['id'] ?? '' ) ) );
		$order->update_meta_data( '_skypay_checkout_url', esc_url_raw( $result['checkoutUrl'] ) );
		$order->update_status( 'pending', __( 'SkyPay hosted checkout created. Awaiting signed or server-verified confirmation.', 'skypay-woocommerce' ) );
		$order->save();
		SkyPay_WC_Order_Manager::schedule( $order->get_id() );

		return array(
			'result'   => 'success',
			'redirect' => esc_url_raw( $result['checkoutUrl'] ),
		);
	}

	public function client_for_order( WC_Order $order ): SkyPay_WC_API_Client|WP_Error {
		$order_mode = strtoupper( (string) $order->get_meta( '_skypay_mode', true ) );
		if ( '' !== $order_mode && $this->mode() !== $order_mode ) {
			return new WP_Error( 'skypay_mode_changed', __( 'The configured SkyPay mode no longer matches this order.', 'skypay-woocommerce' ) );
		}
		if ( '' === $this->api_key() ) {
			return new WP_Error( 'skypay_key_missing', __( 'The SkyPay API key is unavailable.', 'skypay-woocommerce' ) );
		}
		return $this->client();
	}

	public function webhook_secret(): string {
		return SkyPay_WC_Crypto::decrypt( (string) $this->get_option( 'webhook_secret', '' ) );
	}

	public function ajax_test_connection(): void {
		check_ajax_referer( 'skypay_wc_test_connection', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage WooCommerce payments.', 'skypay-woocommerce' ) ), 403 );
		}

		$result = $this->client()->connection();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		if (
			empty( $result['connected'] ) ||
			empty( $result['merchantId'] ) ||
			empty( $result['businessName'] ) ||
			strtoupper( (string) ( $result['mode'] ?? '' ) ) !== $this->mode()
		) {
			wp_send_json_error( array( 'message' => __( 'The API key is valid but does not match the selected mode.', 'skypay-woocommerce' ) ), 400 );
		}

		$settings                          = get_option( $this->get_option_key(), array() );
		$settings['connected_merchant_id'] = sanitize_text_field( (string) $result['merchantId'] );
		update_option( $this->get_option_key(), $settings, false );
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: SkyPay business name, 2: API key mode. */
					__( 'Connected to %1$s in %2$s mode.', 'skypay-woocommerce' ),
					sanitize_text_field( (string) $result['businessName'] ),
					$this->mode()
				),
			)
		);
	}

	private function client(): SkyPay_WC_API_Client {
		return new SkyPay_WC_API_Client( $this->api_key(), 'yes' === (string) $this->get_option( 'debug', 'no' ) );
	}

	private function api_key(): string {
		return SkyPay_WC_Crypto::decrypt( (string) $this->get_option( 'api_key', '' ) );
	}

	private function mode(): string {
		$mode = strtoupper( (string) $this->get_option( 'mode', 'TEST' ) );
		return in_array( $mode, array( 'TEST', 'LIVE' ), true ) ? $mode : 'TEST';
	}

	private function checkout_url_is_allowed( string $url ): bool {
		if ( defined( 'SKYPAY_WC_DEVELOPMENT' ) && true === SKYPAY_WC_DEVELOPMENT ) {
			return false !== wp_http_validate_url( $url );
		}

		return str_starts_with( $url, 'https://payment.skytech.ly/pay/' );
	}
}
