<?php
/**
 * Key helper tests — golden values verified against real WordPress sanitize_key.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit\Support;

use HonestlyDesign\EtchBuilders\Support\Key;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Key::sanitize matches WordPress sanitize_key exactly.
 *
 * Golden values were captured from wp-env: `wp eval 'echo sanitize_key(...)'`.
 * WP sanitize_key: lowercases, removes any char not in [a-z0-9_-] (does NOT
 * replace with hyphen), and strips all whitespace.
 */
final class KeyTest extends TestCase {

	/**
	 * Golden cases straight from a live WordPress instance.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	public function golden_cases(): array {
		return array(
			array( 'Foo Bar', 'foobar' ),
			array( 'foo-bar', 'foo-bar' ),
			array( 'FOO_BAR', 'foo_bar' ),
			array( 'foo.bar.baz', 'foobarbaz' ),
			array( 'Foo--Bar!!', 'foo--bar' ),
			array( '  spaced  ', 'spaced' ),
			array( 'café', 'caf' ),
			array( 'MixedCASE123', 'mixedcase123' ),
			array( 'with-dash_under.score', 'with-dash_underscore' ),
			array( '', '' ),
		);
	}

	/**
	 * @dataProvider golden_cases
	 */
	public function test_sanitize_matches_wordpress( string $input, string $expected ): void {
		self::assertSame( $expected, Key::sanitize( $input ), sprintf( 'Key::sanitize(%s) must match WordPress sanitize_key.', json_encode( $input ) ) );
	}
}
