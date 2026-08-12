<?php
/**
 * Typed sibling block sequence tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\BlockSequence;
use HonestlyDesign\EtchBuilders\Content\ContentBuffer;
use HonestlyDesign\EtchBuilders\Component;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;
use HonestlyDesign\EtchBuilders\Pattern;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies typed sibling composition without serialized-string concatenation.
 */
final class BlockSequenceTest extends TestCase {

	public function test_sequence_preserves_typed_order_and_serializes_at_the_boundary(): void {
		$sequence = BlockSequence::new()
			->append( TextBlock::new()->content( 'First' ) )
			->append( Block::new_self_closing( 'text', array( 'content' => 'Second' ) ) );

		self::assertSame(
			'<!-- wp:etch/text {"content":"First"} /--><!-- wp:etch/text {"content":"Second"} /-->',
			$sequence->to_markup()
		);
		self::assertCount( 2, $sequence->to_blocks() );
		self::assertFalse( $sequence->is_empty() );
	}

	public function test_sequence_detaches_source_trees_and_keeps_class_metadata(): void {
		$source = Block::new( 'element' );
		$sequence = BlockSequence::from( array( $source ) );

		$source->add_child( TextBlock::new()->content( 'mutated later' )->to_block() );

		self::assertSame( '<!-- wp:etch/element --><!-- /wp:etch/element -->', $sequence->to_markup() );
		self::assertSame( array(), $sequence->class_tokens() );
	}

	public function test_sequence_rejects_invalid_items_and_cycles_before_mutation(): void {
		$sequence = BlockSequence::new();

		try {
			$sequence->append_many( array( 'serialized markup' ) );
			self::fail( 'Expected invalid sequence item to be rejected.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'BlockSequence expects', $exception->getMessage() );
		}

		self::assertTrue( $sequence->is_empty() );
	}

	public function test_sequence_rejects_a_cyclic_block_tree_when_detaching(): void {
		$block = Block::new( 'element' );
		$block->add_child( $block );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'finite, non-recursive' );
		BlockSequence::from( array( $block ) );
	}

	public function test_content_buffer_accepts_one_typed_sequence_and_keeps_raw_mode_exclusive(): void {
		$sequence = BlockSequence::from(
			array( TextBlock::new()->content( 'First' )->to_block() )
		);
		$buffer = ContentBuffer::new()->sequence( $sequence );

		self::assertSame( '<!-- wp:etch/text {"content":"First"} /-->', $buffer->to_markup() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot mix block() with blocks_markup()' );
		$buffer->blocks_markup( '<!-- wp:paragraph /-->' );
	}

	public function test_component_and_pattern_retain_the_same_typed_sequence_until_markup_access(): void {
		$sequence = BlockSequence::from(
			array( TextBlock::new()->content( 'Shared sibling' ) )
		);

		$component = Component::new( 'Shared', 'Typed siblings.' )->blocks( $sequence );
		$pattern   = Pattern::new( 'Shared', 'Typed siblings.' )->blocks( $sequence );

		self::assertSame( $component->get_blocks(), $pattern->get_blocks() );
		self::assertSame( '<!-- wp:etch/text {"content":"Shared sibling"} /-->', $component->get_blocks() );
	}

	public function test_component_rejects_an_empty_sequence_before_mutating_content(): void {
		$component = Component::new( 'Shared', 'Typed siblings.' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'requires at least one block' );
		$component->blocks( BlockSequence::new() );
	}
}
