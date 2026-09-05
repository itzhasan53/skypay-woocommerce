<?php
/**
 * WooCommerce Checkout Blocks integration.
 *
 * @package SkyPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class SkyPay_WC_Blocks_Support extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
	/**
	 * Payment method identifier.
	 *
	 * @var string
	 */
	protected $name = 'skypay';

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_skypay_settings', array() );
	}

	public function is_active(): bool {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['skypay'] ) && $gateways['skypay']->is_available();
	}

	/**
	 * Register and return the Checkout Blocks script.
	 *
	 * @return list<string>
	 */
	public function get_payment_method_script_handles(): array {
		$asset_path = SKYPAY_WC_PATH . 'assets/build/blocks.asset.php';
		$asset      = file_exists( $asset_path ) ? require $asset_path : array(
			'dependencies' => array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities' ),
			'version'      => SKYPAY_WC_VERSION,
		);
		wp_register_script(
			'skypay-wc-blocks',
			SKYPAY_WC_URL . 'assets/build/blocks.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		return array( 'skypay-wc-blocks' );
	}

	/**
	 * Return public payment method data for Checkout Blocks.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => (string) ( $this->settings['title'] ?? __( 'SkyPay', 'skypay-woocommerce' ) ),
			'description' => (string) ( $this->settings['description'] ?? __( 'Pay securely using SkyPay.', 'skypay-woocommerce' ) ),
			'supports'    => array( 'products' ),
		);
	}
}
