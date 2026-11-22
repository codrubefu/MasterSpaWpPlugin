<?php
namespace MasterSpa\Tests\Unit;

use Brain\Monkey\Functions;
use MasterSpa\Tests\TestCase;

require_once MASTERSPA_PLUGIN_DIR . 'includes/class-importer.php';

class ImporterTest extends TestCase {

	private function importer() {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		return new \MasterSpa_Importer();
	}

	private function call( $object, $method, array $args ) {
		$m = new \ReflectionMethod( $object, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true ); // Needed before PHP 8.1 only (deprecated in 8.5).
		}
		return $m->invokeArgs( $object, $args );
	}

	public function test_single_price_is_regular_price() {
		$prices = $this->call( $this->importer(), 'extract_prices', array( array( 'pret' => array( array( 'pret' => '1200,50', 'pretdesc' => 'Sejur 01-12' ) ) ) ) );
		$this->assertSame( 1200.5, $prices['regular'] );
		$this->assertSame( 0, $prices['sale'] );
		$this->assertSame( 'Sejur 01-12', $prices['desc'] );
	}

	public function test_multiple_prices_min_is_sale_and_desc_follows_charged_price() {
		$prices = $this->call(
			$this->importer(),
			'extract_prices',
			array(
				array(
					'pret' => array(
						array( 'pret' => 1500, 'pretdesc' => 'Standard' ),
						array( 'pret' => 999.999, 'pretdesc' => 'Ofertă' ),
						array( 'pret' => 0 ),
					),
				),
			)
		);
		$this->assertSame( 1500.0, $prices['regular'] );
		$this->assertSame( 1000.0, $prices['sale'] );
		$this->assertSame( 'Ofertă', $prices['desc'] );
	}

	public function test_missing_prices() {
		$prices = $this->call( $this->importer(), 'extract_prices', array( array() ) );
		$this->assertSame( array( 'regular' => 0, 'sale' => 0, 'desc' => '' ), $prices );
	}

	public function test_sku_prefers_api_fields_then_hash() {
		$importer = $this->importer();
		$this->assertSame( 'ABC', $this->call( $importer, 'generate_sku', array( array( 'sku' => 'ABC', 'id' => 5 ), 't', 'c', 'g' ) ) );
		$this->assertSame( 'MSPA-5', $this->call( $importer, 'generate_sku', array( array( 'id' => 5 ), 't', 'c', 'g' ) ) );
		$this->assertSame( 'MSPA-X1', $this->call( $importer, 'generate_sku', array( array( 'cod' => 'X1' ), 't', 'c', 'g' ) ) );

		$hashed = $this->call( $importer, 'generate_sku', array( array(), 'Titlu', 'Clasa', 'Grupa' ) );
		$this->assertSame( 'MSPA-' . strtoupper( substr( md5( 'Titlu-Clasa-Grupa' ), 0, 10 ) ), $hashed );
	}

	public function test_product_hash_changes_with_data_and_status() {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );

		Functions\when( 'get_option' )->justReturn( array( 'product_status' => 'publish' ) );
		$publish = new \MasterSpa_Importer();
		Functions\when( 'get_option' )->justReturn( array( 'product_status' => 'draft' ) );
		$draft = new \MasterSpa_Importer();

		$a = $this->call( $publish, 'product_hash', array( array( 'art' => 'A' ) ) );
		$this->assertSame( $a, $this->call( $publish, 'product_hash', array( array( 'art' => 'A' ) ) ) );
		$this->assertNotSame( $a, $this->call( $publish, 'product_hash', array( array( 'art' => 'B' ) ) ) );
		$this->assertNotSame( $a, $this->call( $draft, 'product_hash', array( array( 'art' => 'A' ) ) ) );
	}
}
