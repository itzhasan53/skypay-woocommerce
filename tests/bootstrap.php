<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'SKYPAY_WC_VERSION', '0.1.0-beta.1' );

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'unit-test-salt-' . $scheme;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-skypay-amount.php';
require_once dirname( __DIR__ ) . '/includes/class-skypay-crypto.php';
require_once dirname( __DIR__ ) . '/includes/class-skypay-webhook-controller.php';
