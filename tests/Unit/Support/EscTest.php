<?php
/**
 * Esc helper tests.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit\Support;

use HonestlyDesign\EtchBuilders\Support\Esc;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Esc::html matches esc_html for the inputs Etch CSS uses.
 */
final class EscTest extends TestCase {

	public function test_escapes_ampersand(): void {
		self::assertSame( 'a&amp;b', Esc::html( 'a&b' ) );
	}

	public function test_escapes_double_quote(): void {
		self::assertSame( '&quot;', Esc::html( '"' ) );
	}

	public function test_escapes_single_quote(): void {
		self::assertSame( '&#039;', Esc::html( "'" ) );
	}

	public function test_escapes_lt_gt(): void {
		self::assertSame( '&lt;style&gt;', Esc::html( '<style>' ) );
	}

	public function test_passes_plain_text_through(): void {
		self::assertSame( 'plain text 123', Esc::html( 'plain text 123' ) );
	}
}
