<?php

use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase {
	public function test_accepts_only_the_exact_raw_body_signature(): void {
		$body      = '{"paymentId":"pay_1","status":"COMPLETED"}';
		$secret    = 'whsec_unit_test';
		$signature = hash_hmac( 'sha256', $body, $secret );

		self::assertTrue( SkyPay_WC_Webhook_Controller::valid_signature( $body, $secret, $signature ) );
		self::assertFalse( SkyPay_WC_Webhook_Controller::valid_signature( $body . ' ', $secret, $signature ) );
		self::assertFalse( SkyPay_WC_Webhook_Controller::valid_signature( $body, 'wrong', $signature ) );
	}
}
