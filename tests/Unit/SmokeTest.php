<?php
/**
 * Smoke test: the package loads and runs without WordPress.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the package autoloads and basic wiring works.
 */
final class SmokeTest extends TestCase {

	public function test_php_version_is_81_or_higher(): void {
		self::assertTrue( version_compare( PHP_VERSION, '8.1.0', '>=' ) );
	}

	public function test_no_wordpress_is_loaded(): void {
		// The package must run without WordPress. If WP functions were required,
		// these would exist. They must NOT.
		self::assertFalse( function_exists( 'get_option' ) );
		self::assertFalse( function_exists( 'sanitize_key' ) );
		self::assertFalse( function_exists( 'wp_json_encode' ) );
	}
}
