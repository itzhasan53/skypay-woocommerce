<?php
/**
 * Plugin Name: SkyPay for WooCommerce
 * Plugin URI: https://github.com/itzhasan53/skypay-woocommerce
 * Description: Accept one-time LYD payments through SkyPay-hosted checkout.
 * Version: 0.1.0-beta.1
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 9.0
 * WC tested up to: 10.0
 * Author: SkyPay
 * Author URI: https://payment.skytech.ly
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: skypay-woocommerce
 * Domain Path: /languages
 *
 * @package SkyPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'SKYPAY_WC_VERSION', '0.1.0-beta.1' );
define( 'SKYPAY_WC_FILE', __FILE__ );
define( 'SKYPAY_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'SKYPAY_WC_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'SkyPay for WooCommerce requires PHP 8.1 or newer.', 'skypay-woocommerce' ) );
		}

		if ( version_compare( get_bloginfo( 'version' ), '6.4', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'SkyPay for WooCommerce requires WordPress 6.4 or newer.', 'skypay-woocommerce' ) );
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Load the gateway after WooCommerce is available.
 */
function skypay_wc_bootstrap(): void {
	load_plugin_textdomain( 'skypay-woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-error"><p>' .
					esc_html__( 'SkyPay for WooCommerce requires WooCommerce 9.0 or newer.', 'skypay-woocommerce' ) .
					'</p></div>';
			}
		);
		return;
	}

	require_once SKYPAY_WC_PATH . 'includes/class-skypay-crypto.php';
	require_once SKYPAY_WC_PATH . 'includes/class-skypay-amount.php';
	require_once SKYPAY_WC_PATH . 'includes/class-skypay-api-client.php';
	require_once SKYPAY_WC_PATH . 'includes/class-skypay-order-manager.php';
	require_once SKYPAY_WC_PATH . 'includes/class-skypay-webhook-controller.php';
	require_once SKYPAY_WC_PATH . 'includes/class-skypay-gateway.php';

	add_filter(
		'woocommerce_payment_gateways',
		static function ( array $gateways ): array {
			$gateways[] = 'SkyPay_WC_Gateway';
			return $gateways;
		}
	);

	SkyPay_WC_Order_Manager::init();
	SkyPay_WC_Webhook_Controller::init();
}
add_action( 'plugins_loaded', 'skypay_wc_bootstrap', 20 );

add_action(
	'woocommerce_blocks_loaded',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}

		require_once SKYPAY_WC_PATH . 'includes/class-skypay-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ): void {
				$registry->register( new SkyPay_WC_Blocks_Support() );
			}
		);
	}
);
