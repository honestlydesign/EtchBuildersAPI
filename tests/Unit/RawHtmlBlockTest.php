<?php
/**
 * RawHtmlBlock builder tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\RawHtmlBlock;


/**
 * Verifies RawHtmlBlock serializes content as an always-safe self-closing block.
 *
 * The Etch `unsafe` rendering flag must never be serialized, so raw HTML is
 * treated as untrusted by default. These tests pin that contract.
 */
final class RawHtmlBlockTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Asserts that serialized raw-html markup never carries an `unsafe` key.
	 */
	private static function assertUnsafeNeverSerialized( string $markup ): void {
		self::assertStringNotContainsString( '"unsafe"', $markup, 'RawHtmlBlock must never serialize an unsafe flag.' );
	}

	public function test_content_serializes_as_self_closing_raw_html_block(): void {
		$markup = RawHtmlBlock::new()
			->content( '<div>Raw HTML</div>' )
			->to_block()
			->to_string();

		self::assertSame(
			'<!-- wp:etch/raw-html {"content":"<div>Raw HTML</div>"} /-->',
			$markup
		);
	}

	public function test_content_does_not_serialize_unsafe_flag(): void {
		$markup = RawHtmlBlock::new()
			->content( '<span>narrow trusted fragment</span>' )
			->to_block()
			->to_string();

		self::assertStringContainsString( '"content":"<span>narrow trusted fragment</span>"', $markup );
		self::assertUnsafeNeverSerialized( $markup );
	}

	public function test_raw_content_via_has_children_stays_safe(): void {
		$markup = ElementBlock::new()
			->tag( 'div' )
			->raw_content( '<p>y</p>' )
			->to_block()
			->to_string();

		self::assertStringContainsString( '<!-- wp:etch/element', $markup );
		self::assertStringContainsString( '"tag":"div"', $markup );
		self::assertStringContainsString(
			'<!-- wp:etch/raw-html {"content":"<p>y</p>"} /-->',
			$markup
		);
		self::assertUnsafeNeverSerialized( $markup );
	}
}
