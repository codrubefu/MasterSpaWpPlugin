<?php
namespace MasterSpa\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\stubs(
			array(
				'sanitize_text_field' => function ( $value ) {
					return trim( strip_tags( (string) $value ) );
				},
				'sanitize_email'      => function ( $value ) {
					$value = trim( (string) $value );
					return filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : '';
				},
				'is_email'            => function ( $value ) {
					return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
				},
				'wp_strip_all_tags'   => function ( $value ) {
					return trim( strip_tags( (string) $value ) );
				},
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
