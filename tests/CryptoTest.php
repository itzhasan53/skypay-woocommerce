<?php

use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase {
	public function test_round_trip_and_tamper_rejection(): void {
		$encrypted = SkyPay_WC_Crypto::encrypt( 'sk_test_example' );
		self::assertNotSame( 'sk_test_example', $encrypted );
		self::assertSame( 'sk_test_example', SkyPay_WC_Crypto::decrypt( $encrypted ) );

		$tampered = substr( $encrypted, 0, -1 ) . ( str_ends_with( $encrypted, 'A' ) ? 'B' : 'A' );
		self::assertSame( '', SkyPay_WC_Crypto::decrypt( $tampered ) );
	}
}
