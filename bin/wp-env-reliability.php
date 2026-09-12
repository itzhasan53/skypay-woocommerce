<?php
/**
 * Exercise the order-safety paths against a real WooCommerce data store.
 *
 * Run inside wp-env after WooCommerce and the plugin are activated.
 *
 * @package SkyPay_WooCommerce
 */

if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
	throw new RuntimeException( 'WooCommerce is not active.' );
}

$gateway = WC()->payment_gateways()->payment_gateways()['skypay'] ?? null;
if ( ! $gateway instanceof SkyPay_WC_Gateway ) {
	throw new RuntimeException( 'SkyPay gateway is not registered.' );
}

$order = wc_create_order();
if ( ! $order instanceof WC_Order ) {
	throw new RuntimeException( 'Fixture order could not be created.' );
}

$secret    = 'wp-env-reliability-webhook-secret';
$reference = 'wc-reliability-' . wp_generate_uuid4();
$order->set_currency( 'LYD' );
$order->set_total( 1 );
$order->update_meta_data( '_skypay_merchant_order_id', $reference );
$order->update_meta_data( '_skypay_merchant_id', 'merchant-reliability' );
$order->update_meta_data( '_skypay_amount_fils', '1000' );
$order->update_meta_data( '_skypay_mode', 'TEST' );
$order->save();

// The active gateway instance reads settings during construction. Set the
// in-memory test-only value so the webhook path verifies a real HMAC.
$settings = new ReflectionProperty( WC_Settings_API::class, 'settings' );
$settings->setAccessible( true );
$current_settings                   = (array) $settings->getValue( $gateway );
$current_settings['webhook_secret'] = SkyPay_WC_Crypto::encrypt( $secret );
$settings->setValue( $gateway, $current_settings );

$lock = SkyPay_WC_Order_Lock::acquire( $order->get_id() );
if ( ! $lock instanceof SkyPay_WC_Order_Lock ) {
	throw new RuntimeException( 'Order mutex could not be acquired.' );
}
try {
	$reloaded = $lock->load_order( $order->get_id() );
	if ( ! $reloaded instanceof WC_Order || $reloaded->get_id() !== $order->get_id() ) {
		throw new RuntimeException( 'Order mutex did not reload the WooCommerce order.' );
	}
} finally {
	$lock->release();
}

// A separate database connection represents another PHP worker. It must not
// obtain the same order mutex while the first connection owns it.
global $wpdb;
$other_connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lock_name        = 'skypay:' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $order->get_id() ), 0, 56 );
if ( '1' !== (string) $other_connection->get_var( $other_connection->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ) ) {
	throw new RuntimeException( 'Fixture could not acquire an independent database lock.' );
}
try {
	// A concurrent checkout must report a retryable failure before it can create
	// a second idempotency key or reach the SkyPay API.
	if ( null !== $gateway->process_payment( $order->get_id() ) || '' !== (string) $order->get_meta( '_skypay_idempotency_key', true ) ) {
		throw new RuntimeException( 'A lock-contended checkout started a second payment attempt.' );
	}
	if ( null !== SkyPay_WC_Order_Lock::acquire( $order->get_id() ) ) {
		throw new RuntimeException( 'A second database connection acquired the SkyPay order mutex.' );
	}
} finally {
	$other_connection->get_var( $other_connection->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
	$other_connection->close();
}

$payload = array(
	'merchantId'      => 'merchant-reliability',
	'merchantOrderId' => $reference,
	'paymentId'       => 'payment-reliability',
	'amount'          => 1000,
	'currency'        => 'LYD',
	'mode'            => 'TEST',
	'status'          => 'COMPLETED',
);

// The original encrypted request must survive a plugin update or translation
// change after SkyPay accepts it but the store loses the response.
$return_token = bin2hex( random_bytes( 32 ) );
$recovery_payload = array(
	'amount'          => 1000,
	'currency'        => 'LYD',
	'merchantOrderId' => $reference,
	'title'           => 'Original translated checkout title',
	'successUrl'      => add_query_arg( array( 'order_id' => $order->get_id(), 'state' => $return_token ), WC()->api_request_url( 'skypay_return' ) ),
	'cancelUrl'       => add_query_arg( array( 'order_id' => $order->get_id(), 'state' => $return_token, 'cancelled' => '1' ), WC()->api_request_url( 'skypay_return' ) ),
	'metadata'        => array( 'integration' => 'woocommerce', 'integrationVersion' => 'old-version', 'siteFingerprint' => 'fixture', 'orderReference' => $reference ),
);
$encoded_recovery_payload = wp_json_encode( $recovery_payload );
if ( ! is_string( $encoded_recovery_payload ) ) {
	throw new RuntimeException( 'Fixture checkout payload could not be encoded.' );
}
$order->update_meta_data( '_skypay_create_payload', SkyPay_WC_Crypto::encrypt( $encoded_recovery_payload ) );
$order->save();
$stored_payload = new ReflectionMethod( SkyPay_WC_Gateway::class, 'stored_checkout_payload' );
$stored_payload->setAccessible( true );
$reused_payload = $stored_payload->invoke( $gateway, $order, $reference, 1000, $return_token );
if ( $recovery_payload !== $reused_payload ) {
	throw new RuntimeException( 'The original encrypted checkout payload was not reused exactly.' );
}
$order->delete_meta_data( '_skypay_create_payload' );
$order->save();
$body    = wp_json_encode( $payload );
if ( ! is_string( $body ) ) {
	throw new RuntimeException( 'Fixture webhook body could not be encoded.' );
}

$request = static function ( string $delivery ) use ( $body, $secret ): WP_REST_Request {
	$webhook = new WP_REST_Request( 'POST', '/skypay/v1/webhook' );
	$webhook->set_header( 'x-skypay-event', 'payment.completed' );
	$webhook->set_header( 'x-skypay-delivery', $delivery );
	$webhook->set_header( 'x-skypay-signature', hash_hmac( 'sha256', $body, $secret ) );
	$webhook->set_body( $body );
	return $webhook;
};

$first = SkyPay_WC_Webhook_Controller::receive( $request( 'delivery-reliability-0001' ) );
if ( 200 !== $first->get_status() || true !== $first->get_data()['accepted'] ) {
	throw new RuntimeException( 'Signed completion webhook was not accepted.' );
}

$duplicate = SkyPay_WC_Webhook_Controller::receive( $request( 'delivery-reliability-0001' ) );
if ( 200 !== $duplicate->get_status() || true !== $duplicate->get_data()['duplicate'] ) {
	throw new RuntimeException( 'Duplicate signed completion webhook was not deduplicated.' );
}

$paid_order = wc_get_order( $order->get_id() );
if ( ! $paid_order instanceof WC_Order ) {
	throw new RuntimeException( 'Paid fixture order could not be reloaded.' );
}
$paid_at             = $paid_order->get_date_paid()?->getTimestamp();
$different_delivery = SkyPay_WC_Webhook_Controller::receive( $request( 'delivery-reliability-0002' ) );
$paid_order = wc_get_order( $order->get_id() );
if ( ! $paid_order instanceof WC_Order || 200 !== $different_delivery->get_status() || $paid_at !== $paid_order->get_date_paid()?->getTimestamp() ) {
	throw new RuntimeException( 'A different delivery repeated or changed the completed payment effect.' );
}

$reloaded = wc_get_order( $order->get_id() );
if ( ! $reloaded instanceof WC_Order || ! $reloaded->is_paid() ) {
	throw new RuntimeException( 'Accepted completion webhook did not pay the order.' );
}

// WooCommerce extensions can reject payment_complete(). The SkyPay marker must
// remain absent so the signed webhook and reconciliation can retry safely.
$failed_completion_order = wc_create_order();
if ( ! $failed_completion_order instanceof WC_Order ) {
	throw new RuntimeException( 'Completion-failure fixture order could not be created.' );
}
$failed_reference = 'wc-reliability-failure-' . wp_generate_uuid4();
$failed_completion_order->set_currency( 'LYD' );
$failed_completion_order->set_total( 1 );
$failed_completion_order->update_meta_data( '_skypay_merchant_order_id', $failed_reference );
$failed_completion_order->update_meta_data( '_skypay_merchant_id', 'merchant-reliability' );
$failed_completion_order->update_meta_data( '_skypay_amount_fils', '1000' );
$failed_completion_order->update_meta_data( '_skypay_mode', 'TEST' );
$failed_completion_order->save();
$break_completion = static function ( int $order_id ) use ( $failed_completion_order ): void {
	if ( $order_id === $failed_completion_order->get_id() ) {
		throw new Exception( 'Intentional reliability test failure.' );
	}
};
add_action( 'woocommerce_pre_payment_complete', $break_completion );
$failed_payload                    = $payload;
$failed_payload['merchantOrderId'] = $failed_reference;
try {
	$completion_result = SkyPay_WC_Order_Manager::apply_authoritative_status( $failed_completion_order, $failed_payload, 'reliability test' );
} catch ( Throwable $error ) {
	// Either WooCommerce's documented false result or an extension exception is
	// retryable. In both cases the durable completion marker must remain absent.
	$completion_result = false;
}
remove_action( 'woocommerce_pre_payment_complete', $break_completion );
$failed_completion_order = wc_get_order( $failed_completion_order->get_id() );
if ( ! $failed_completion_order instanceof WC_Order ) {
	throw new RuntimeException( 'Completion-failure fixture order could not be reloaded.' );
}
if ( true === $completion_result || $failed_completion_order->is_paid() || 'yes' === $failed_completion_order->get_meta( '_skypay_payment_completed', true ) ) {
	throw new RuntimeException( 'A failed WooCommerce completion was incorrectly recorded as paid.' );
}
$failed_completion_order->delete( true );

$failure = $payload;
$failure['status'] = 'FAILED';
SkyPay_WC_Order_Manager::apply_authoritative_status( $reloaded, $failure, 'reliability test' );
$reloaded = wc_get_order( $order->get_id() );
if ( ! $reloaded instanceof WC_Order || ! $reloaded->is_paid() ) {
	throw new RuntimeException( 'A stale failure downgraded a paid order.' );
}

if ( function_exists( 'as_get_scheduled_actions' ) && function_exists( 'as_unschedule_all_actions' ) ) {
	SkyPay_WC_Order_Manager::schedule( $order->get_id(), 0, $reloaded );
	SkyPay_WC_Order_Manager::schedule( $order->get_id(), 1, $reloaded );
	$first_attempt = as_get_scheduled_actions(
		array( 'hook' => 'skypay_wc_reconcile_order', 'args' => array( $order->get_id(), 0 ), 'group' => 'skypay-woocommerce', 'status' => 'pending', 'per_page' => 1 ),
		'ids'
	);
	$second_attempt = as_get_scheduled_actions(
		array( 'hook' => 'skypay_wc_reconcile_order', 'args' => array( $order->get_id(), 1 ), 'group' => 'skypay-woocommerce', 'status' => 'pending', 'per_page' => 1 ),
		'ids'
	);
	if ( empty( $first_attempt ) || empty( $second_attempt ) ) {
		throw new RuntimeException( 'Distinct reconciliation attempts were not queued.' );
	}
	as_unschedule_all_actions( 'skypay_wc_reconcile_order', array( $order->get_id(), 0 ), 'skypay-woocommerce' );
	as_unschedule_all_actions( 'skypay_wc_reconcile_order', array( $order->get_id(), 1 ), 'skypay-woocommerce' );
}

$reloaded->delete( true );
echo "reliability-checked\n";
