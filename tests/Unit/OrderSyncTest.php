<?php
namespace MasterSpa\Tests\Unit;

use MasterSpa\Tests\TestCase;

require_once MASTERSPA_PLUGIN_DIR . 'includes/MasterSpaCurlHelper.php';

class OrderSyncTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once MASTERSPA_PLUGIN_DIR . 'finaliseOrder/finalise.php';
	}

	public function test_retry_backoff() {
		$this->assertSame( 5 * 60, masterspa_sync_retry_delay( 1 ) );
		$this->assertSame( 15 * 60, masterspa_sync_retry_delay( 2 ) );
		$this->assertSame( 30 * 60, masterspa_sync_retry_delay( 3 ) );
		$this->assertSame( 60 * 60, masterspa_sync_retry_delay( 4 ) );
		$this->assertSame( 60 * 60, masterspa_sync_retry_delay( MASTERSPA_SYNC_MAX_TRIES - 1 ) );
		$this->assertNull( masterspa_sync_retry_delay( MASTERSPA_SYNC_MAX_TRIES ) );
		$this->assertNull( masterspa_sync_retry_delay( 0 ) );
	}

	public function test_backoff_covers_about_a_day() {
		$total = 0;
		for ( $attempt = 1; null !== masterspa_sync_retry_delay( $attempt ); $attempt++ ) {
			$total += masterspa_sync_retry_delay( $attempt );
		}
		$this->assertGreaterThanOrEqual( 23 * 3600, $total );
		$this->assertLessThanOrEqual( 25 * 3600, $total );
	}

	public function test_headers_accept_both_formats() {
		$this->assertSame(
			array( 'Idempotency-Key' => 'wc_order_1', 'X-Test' => 'a: b' ),
			\MasterSpaCurlHelper::normalize_headers( array( 'Idempotency-Key' => 'wc_order_1', 'X-Test: a: b', 'broken' ) )
		);
	}

	public function test_secrets_masked_in_log_headers() {
		$lines = \MasterSpaCurlHelper::headers_for_log( array( 'Content-Type' => 'application/json', 'X-API-Secret' => 's3cr3t', 'Idempotency-Key' => 'wc_order_x' ) );
		$this->assertSame( array( 'Content-Type: application/json', 'X-API-Secret: ***', 'Idempotency-Key: ***' ), $lines );
	}

	public function test_payload_description_has_no_personal_data() {
		$data = array( 'id' => 42, 'total' => '100.00', 'items' => array( array(), array() ), 'billing' => array( 'email' => 'ana@example.com' ) );
		$text = \MasterSpaCurlHelper::describe_payload( $data, json_encode( $data ) );
		$this->assertStringContainsString( 'order #42', $text );
		$this->assertStringContainsString( '2 items', $text );
		$this->assertStringNotContainsString( 'ana@example.com', $text );
	}

	public function test_order_meta_whitelist() {
		\Brain\Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->assertTrue( masterspa_order_meta_key_allowed( '_billing_cui' ) );
		$this->assertTrue( masterspa_order_meta_key_allowed( '_shipping_phone' ) );
		$this->assertTrue( masterspa_order_meta_key_allowed( '_order_info' ) );
		$this->assertFalse( masterspa_order_meta_key_allowed( '_wc_order_attribution_user_agent' ) );
		$this->assertFalse( masterspa_order_meta_key_allowed( '_masterspa_sync_error' ) );
		$this->assertFalse( masterspa_order_meta_key_allowed( '_stripe_customer_id' ) );
	}

	public function test_webhook_has_no_fallback_to_product_feed() {
		\Brain\Monkey\Functions\when( 'get_option' )->justReturn( array( 'api_endpoint' => 'http://api/products' ) );
		$this->assertSame( '', masterspa_get_order_webhook_url() );
	}
}
