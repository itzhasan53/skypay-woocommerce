<?php
use PHPUnit\Framework\TestCase;

/**
 * WooCommerce does not create gateway objects while it serves admin-ajax.php, so an AJAX hook added in the gateway
 * constructor does not exist when the Test connection request arrives (the button then fails with HTTP 400 "0").
 * These tests keep the hook registered by the plugin bootstrap.
 */
final class TestConnectionHookTest extends TestCase {
	private static function source( string $relative ): string {
		$contents = file_get_contents( dirname( __DIR__ ) . '/' . $relative );
		self::assertIsString( $contents, $relative . ' must be readable.' );
		return $contents;
	}

	public function test_bootstrap_registers_the_ajax_handler_without_a_gateway_object(): void {
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_ajax_skypay_wc_test_connection',\s*array\(\s*'SkyPay_WC_Gateway',\s*'handle_test_connection_request'\s*\)\s*\)/",
			self::source( 'skypay-woocommerce.php' )
		);
	}

	public function test_gateway_constructor_does_not_register_ajax_handlers(): void {
		$matched = preg_match( '/public function __construct\(\)\s*\{(.*?)\n\t\}/s', self::source( 'includes/class-skypay-gateway.php' ), $constructor );
		$this->assertSame( 1, $matched, 'The gateway constructor must be found.' );
		$this->assertStringNotContainsString( 'wp_ajax_', $constructor[1] );
	}

	public function test_the_handler_is_a_public_static_method_that_builds_its_own_gateway(): void {
		$this->assertMatchesRegularExpression(
			'/public static function handle_test_connection_request\(\): void \{\s*\( new self\(\) \)->ajax_test_connection\(\);\s*\}/',
			self::source( 'includes/class-skypay-gateway.php' )
		);
	}

	public function test_the_administrator_messages_do_not_reuse_the_shopper_messages(): void {
		$gateway = self::source( 'includes/class-skypay-gateway.php' );
		$this->assertStringContainsString( 'private function connection_error_message( WP_Error $error ): string', $gateway );
		$this->assertStringNotContainsString( "'message' => \$result->get_error_message()", $gateway );
		$this->assertStringNotContainsString( 'unavailable for this order', $gateway );
	}
}
