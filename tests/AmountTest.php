<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase {
	public static function valid_amounts(): array {
		return array(
			'one fils'       => array( '0.001', 1 ),
			'one dinar'      => array( '1', 1000 ),
			'three decimals' => array( '12.345', 12345 ),
			'trailing zero'  => array( '12.30', 12300 ),
			'maximum'        => array( '1000000', 1000000000 ),
		);
	}

	#[DataProvider( 'valid_amounts' )]
	public function test_exact_conversion( string $amount, int $expected ): void {
		self::assertSame( $expected, SkyPay_WC_Amount::to_fils( $amount ) );
	}

	public function test_rejects_floating_point_ambiguity(): void {
		$this->expectException( InvalidArgumentException::class );
		SkyPay_WC_Amount::to_fils( '12.3456' );
	}

	public function test_rejects_zero(): void {
		$this->expectException( InvalidArgumentException::class );
		SkyPay_WC_Amount::to_fils( '0' );
	}
}
