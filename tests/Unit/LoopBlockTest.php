<?php
/**
 * LoopBlock tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\LoopBlock;
use HonestlyDesign\EtchBuilders\LoopPreset;
use PHPUnit\Framework\TestCase;

/**
 * Verifies LoopBlock loopId binding to LoopPreset.
 */
final class LoopBlockTest extends TestCase {

	public function test_loop_id_validates_against_registered_preset(): void {
		$snapshot = LoopPreset::snapshot();

		try {
			LoopPreset::reset();

			LoopPreset::new( 'Recent Posts' )
				->wp_query( array( 'post_type' => 'post' ) )
				->register_internal();

			$block = LoopBlock::new()
				->loop_id( 'recent-posts' )
				->child( ElementBlock::new()->tag( 'div' )->to_block() )
				->to_block();

			$attrs = $this->extract_block_attrs( $block->to_string() );

			self::assertSame( 'recent-posts', $attrs['loopId'] );
		} finally {
			LoopPreset::restore( $snapshot );
		}
	}

	public function test_loop_id_throws_on_unregistered_key(): void {
		$snapshot = LoopPreset::snapshot();

		try {
			LoopPreset::reset();

			LoopPreset::new( 'Recent Posts' )
				->wp_query( array( 'post_type' => 'post' ) )
				->register_internal();

			$this->expectException( \InvalidArgumentException::class );
			$this->expectExceptionMessage( 'typo' );

			LoopBlock::new()->loop_id( 'typo' );
		} finally {
			LoopPreset::restore( $snapshot );
		}
	}

	public function test_loop_id_error_lists_registered_keys(): void {
		$snapshot = LoopPreset::snapshot();

		try {
			LoopPreset::reset();

			LoopPreset::new( 'Recent Posts' )
				->wp_query( array( 'post_type' => 'post' ) )
				->register_internal();

			try {
				LoopBlock::new()->loop_id( 'missing' );
				self::fail( 'Expected InvalidArgumentException.' );
			} catch ( \InvalidArgumentException $e ) {
				self::assertStringContainsString( 'recent-posts', $e->getMessage() );
			}
		} finally {
			LoopPreset::restore( $snapshot );
		}
	}

	public function test_target_stays_free_form(): void {
		$snapshot = LoopPreset::snapshot();

		try {
			LoopPreset::reset();

			$block = LoopBlock::new()
				->target( '{props.items}' )
				->child( ElementBlock::new()->tag( 'div' )->to_block() )
				->to_block();

			$attrs = $this->extract_block_attrs( $block->to_string() );

			self::assertSame( '{props.items}', $attrs['target'] );
		} finally {
			LoopPreset::restore( $snapshot );
		}
	}

	/**
	 * Parse the JSON attrs out of a serialized wp:block comment.
	 *
	 * @param string $markup Serialized block.
	 * @return array<string, mixed>
	 */
	private function extract_block_attrs( string $markup ): array {
		preg_match( '/<!-- wp:etch\/loop (\{.*?\}) -->/s', $markup, $matches );
		self::assertNotEmpty( $matches, 'Failed to find loop block attrs in: ' . $markup );

		return json_decode( $matches[1], true );
	}
}
