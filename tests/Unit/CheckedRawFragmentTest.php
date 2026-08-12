<?php
/**
 * Checked raw fragment tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\BlockSequence;
use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\RawHtmlBlock;
use HonestlyDesign\EtchBuilders\RawFragment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies reason-bearing narrow raw HTML escapes.
 */
final class CheckedRawFragmentTest extends TestCase {

	public function test_checked_fragment_preserves_wire_shape_and_structural_reason(): void {
		$fragment = RawFragment::new(
			'<span data-icon="check">✓</span>',
			'Etch has no typed builder for this trusted inline icon fragment.'
		);
		$block = RawHtmlBlock::new()->fragment( $fragment )->to_block();

		self::assertSame(
			'<!-- wp:etch/raw-html {"content":"<span data-icon=\\"check\\">✓</span>"} /-->',
			$block->to_string()
		);
		self::assertSame( $fragment, $block->raw_fragment() );
		self::assertSame( $fragment->reason(), $block->raw_fragment()->reason() );
	}

	public function test_checked_fragment_reason_survives_detached_copies_and_sequences(): void {
		$fragment = RawFragment::new( '<span>icon</span>', 'Trusted inline icon.' );
		$block    = RawHtmlBlock::new()->fragment( $fragment )->to_block();
		$copy     = $block->detached_copy();
		$sequence = BlockSequence::from( array( $block ) );

		self::assertSame( 'Trusted inline icon.', $copy->raw_fragment()?->reason() );
		self::assertSame( 'Trusted inline icon.', $sequence->raw_fragments()[0]->reason() );
	}

	public function test_checked_fragment_can_be_used_as_a_typed_child(): void {
		$fragment = RawFragment::new( '<span>icon</span>', 'Trusted inline icon.' );
		$block = ElementBlock::new()
			->tag( 'div' )
			->raw_fragment( $fragment )
			->to_block();

		self::assertStringContainsString( '<!-- wp:etch/raw-html {"content":"<span>icon</span>"} /-->', $block->to_string() );
		self::assertSame( $fragment, $block->children_raw_fragments()[0] );
	}

	/**
	 * @dataProvider invalid_fragment_provider
	 */
	public function test_checked_fragment_rejects_broad_markup_routes( string $html ): void {
		$this->expectException( InvalidArgumentException::class );

		RawFragment::new( $html, 'This reason is present but the fragment is too broad.' );
	}

	public function test_checked_fragment_requires_a_non_empty_reason(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'non-empty reason' );

		RawFragment::new( '<span>icon</span>', '   ' );
	}

	public function test_checked_fragment_requires_non_empty_content(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'non-empty' );

		RawFragment::new( '   ', 'A reason.' );
	}

	public function test_legacy_raw_html_content_remains_compatible(): void {
		self::assertSame(
			'<!-- wp:etch/raw-html {"content":"<section>legacy</section>"} /-->',
			RawHtmlBlock::new()->content( '<section>legacy</section>' )->to_block()->to_string()
		);
	}

	public function test_checked_route_cannot_be_downgraded_by_chaining_legacy_content(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'checked RawFragment' );

		RawHtmlBlock::new()
			->fragment( RawFragment::new( '<span>icon</span>', 'Trusted inline icon.' ) )
			->content( '<section>whole section</section>' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalid_fragment_provider(): array {
		return array(
			'gutenberg-open'   => array( '<!-- wp:etch/text {"content":"whole tree"} -->' ),
			'gutenberg-close'  => array( '<!-- /wp:etch/text -->' ),
			'doctype'          => array( '<!doctype html><html><body>page</body></html>' ),
			'document'         => array( '<html><head></head><body>page</body></html>' ),
			'whole-section'    => array( '<section><div>section</div></section>' ),
			'whole-main'       => array( '<main><div>page</div></main>' ),
			'whole-component'  => array( '<article data-etch-component="Card"><div>component</div></article>' ),
			'component-host'   => array( '<div data-etch-component="Card"><span>component</span></div>' ),
		);
	}
}
