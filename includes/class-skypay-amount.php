<?php
/**
 * Exact LYD amount conversion.
 *
 * @package SkyPay_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class SkyPay_WC_Amount {
	public static function to_fils( string $amount ): int {
		$amount = trim( $amount );
		if ( ! preg_match( '/^(0|[1-9][0-9]*)(?:\.([0-9]{1,3}))?$/', $amount, $matches ) ) {
			throw new InvalidArgumentException( 'The LYD amount must have no more than three decimal places.' );
		}

		$whole    = $matches[1];
		$fraction = str_pad( $matches[2] ?? '', 3, '0' );
		if ( strlen( $whole ) > 7 ) {
			throw new InvalidArgumentException( 'The order total is outside SkyPay limits.' );
		}

		$fils = ( (int) $whole * 1000 ) + (int) $fraction;
		if ( $fils < 1 || $fils > 1000000000 ) {
			throw new InvalidArgumentException( 'The order total is outside SkyPay limits.' );
		}

		return $fils;
	}
}
