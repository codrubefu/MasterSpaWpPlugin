<?php
namespace MasterSpa\Tests\Unit;

use Brain\Monkey\Functions;
use MasterSpa\Tests\TestCase;

class CheckoutValidationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once MASTERSPA_PLUGIN_DIR . 'includes/checkout-fields.php';
		require_once MASTERSPA_PLUGIN_DIR . 'includes/frontend-product.php';
		require_once MASTERSPA_PLUGIN_DIR . 'custom-cart/subscribers-common.php';

		$countries = new class() {
			public function get_states( $country ) {
				return 'RO' === $country ? array( 'IS' => 'Iași', 'B' => 'București' ) : array();
			}
		};
		$wc        = new \stdClass();
		$wc->countries = $countries;
		Functions\when( 'WC' )->justReturn( $wc );
	}

	public function test_address_requires_valid_county_and_phone() {
		$this->assertSame( array(), masterspa_checkout_address_errors( 'RO', 'is', '0700000000' ) );
		$this->assertCount( 2, masterspa_checkout_address_errors( 'RO', '', '' ) );
		$this->assertCount( 1, masterspa_checkout_address_errors( 'RO', 'XX', '0700' ) );
		$this->assertSame( array(), masterspa_checkout_address_errors( 'DE', '', '0700' ) );
	}

	public function test_subscribers_padded_to_quantity() {
		$list = masterspa_get_cart_item_subscribers( array( 'quantity' => 3, 'subscription_user' => array( array( 'prenume' => 'Ana', 'send_email' => '1' ) ) ) );
		$this->assertCount( 3, $list );
		$this->assertSame( 'Ana', $list[0]['prenume'] );
		$this->assertSame( '1', $list[0]['send_email'] );
		$this->assertSame( '', $list[2]['prenume'] );
	}

	public function test_missing_fields() {
		$this->assertSame( array(), masterspa_subscriber_missing_fields( array( 'prenume' => 'A', 'nume' => 'B', 'email' => 'a@b.ro' ) ) );
		$this->assertSame( array( 'prenume', 'nume', 'email valid' ), masterspa_subscriber_missing_fields( array( 'email' => 'nope' ) ) );
	}

	public function test_cart_errors_only_for_spa_products() {
		Functions\when( 'get_post_meta' )->alias(
			function ( $id ) {
				return 10 === $id ? 'yes' : '';
			}
		);
		Functions\when( 'has_term' )->justReturn( false );
		Functions\when( 'get_the_title' )->justReturn( 'Pachet' );

		$cart = array(
			array( 'product_id' => 10, 'quantity' => 2, 'subscription_user' => array( array( 'prenume' => 'A', 'nume' => 'B', 'email' => 'a@b.ro' ) ) ),
			array( 'product_id' => 20, 'quantity' => 1 ),
		);
		$errors = masterspa_cart_subscriber_errors( $cart );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( '2/2', $errors[0] );
		$this->assertStringContainsString( 'Pachet', $errors[0] );
	}

	public function test_invalid_email_detection() {
		$this->assertTrue( masterspa_subscriber_email_invalid( array( 'email' => 'abc' ) ) );
		$this->assertFalse( masterspa_subscriber_email_invalid( array( 'email' => '' ) ) );
		$this->assertFalse( masterspa_subscriber_email_invalid( array( 'email' => 'a@b.ro' ) ) );
	}
}
