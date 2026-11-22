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
}
