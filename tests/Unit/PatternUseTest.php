<?php
/**
 * Typed Pattern Use composition tests.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\BlockSequence;
use HonestlyDesign\EtchBuilders\Component;
use HonestlyDesign\EtchBuilders\Content\ContentBuffer;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;
use HonestlyDesign\EtchBuilders\Pattern;
use HonestlyDesign\EtchBuilders\PatternUse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies structural registered-pattern composition without markup copying.
 */
final class PatternUseTest extends TestCase {

	private function pattern( string $key, string $content ): Pattern {
		return Pattern::new( $key, $key . ' pattern.' )
			->key( $key )
			->blocks( BlockSequence::new()->append( TextBlock::new()->content( $content ) ) );
	}

	public function test_registered_use_expands_typed_blocks_and_keeps_dependency_metadata(): void {
		$pattern = $this->pattern( 'Hero', 'Hero content' );
		$use     = PatternUse::registered( $pattern );
		$sequence = BlockSequence::new()->append( TextBlock::new()->content( 'Before' ) )->append( $use );

		self::assertSame(
			'<!-- wp:etch/text {"content":"Before"} /--><!-- wp:etch/text {"content":"Hero content"} /-->',
			$sequence->to_markup()
		);
		self::assertSame( array( $use ), $sequence->pattern_uses() );
		self::assertSame( array( 'type' => 'registered_pattern_use', 'pattern_key' => 'Hero' ), $use->to_array() );
	}

	public function test_use_captures_a_detached_typed_snapshot(): void {
		$pattern = $this->pattern( 'Hero', 'Original' );
		$use     = PatternUse::registered( $pattern );

		$pattern->blocks( BlockSequence::new()->append( TextBlock::new()->content( 'Changed later' ) ) );

		self::assertSame( '<!-- wp:etch/text {"content":"Original"} /-->', $use->sequence()->to_markup() );
		self::assertSame( 'Hero', $use->pattern_key() );
	}

	public function test_use_identity_remains_the_captured_key_after_source_mutation(): void {
		$pattern = $this->pattern( 'Hero', 'Original' );
		$use     = PatternUse::registered( $pattern );
		$pattern->key( 'Changed' );

		self::assertSame( 'Hero', $use->pattern_key() );
		self::assertSame( array( 'type' => 'registered_pattern_use', 'pattern_key' => 'Hero' ), $use->to_array() );
	}

	public function test_nested_pattern_uses_are_preserved_in_expansion_order(): void {
		$inner      = $this->pattern( 'Inner', 'Inner content' );
		$inner_use  = PatternUse::registered( $inner );
		$outer      = Pattern::new( 'Outer', 'Outer pattern' )
			->key( 'Outer' )
			->blocks( BlockSequence::new()->append( TextBlock::new()->content( 'Outer content' ) )->append( $inner_use ) );
		$outer_use = PatternUse::registered( $outer );
		$sequence   = BlockSequence::new()->append( $outer_use );

		self::assertSame(
			array( $outer_use, $inner_use ),
			$sequence->pattern_uses()
		);
		self::assertStringContainsString( 'Inner content', $sequence->to_markup() );
	}

	public function test_components_and_content_buffers_expose_pattern_uses_without_raw_concatenation(): void {
		$use       = PatternUse::registered( $this->pattern( 'Hero', 'Hero content' ) );
		$component = Component::new( 'Shell', 'Shell component' )->pattern_use( $use );
		$buffer    = ContentBuffer::new()->pattern_use( $use );

		self::assertSame( array( $use ), $component->get_pattern_uses() );
		self::assertSame( array( $use ), $buffer->pattern_uses() );
		self::assertSame( $component->get_blocks(), $buffer->to_markup() );
	}

	public function test_pattern_builder_exposes_nested_pattern_use_dependencies(): void {
		$use     = PatternUse::registered( $this->pattern( 'Hero', 'Hero content' ) );
		$pattern = Pattern::new( 'Shell', 'Shell pattern' )->pattern_use( $use );

		self::assertSame( array( $use ), $pattern->get_pattern_uses() );
		self::assertStringContainsString( 'Hero content', $pattern->get_blocks() );
	}

	public function test_raw_serialized_patterns_cannot_be_used_as_typed_dependencies(): void {
		$pattern = Pattern::new( 'Raw', 'Raw pattern' )->blocks( '<!-- wp:etch/text /-->' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'typed Pattern block sequence' );
		PatternUse::registered( $pattern );
	}

	public function test_raw_content_mode_remains_exclusive_when_adding_pattern_use(): void {
		$buffer = ContentBuffer::new()->blocks_markup( '<!-- wp:paragraph /-->' );
		$use    = PatternUse::registered( $this->pattern( 'Hero', 'Hero content' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot mix blocks_markup() with pattern_use()' );
		$buffer->pattern_use( $use );
	}

	public function test_pattern_use_expansion_is_atomic_for_invalid_sequence_items(): void {
		$use     = PatternUse::registered( $this->pattern( 'Hero', 'Hero content' ) );
		$sequence = BlockSequence::new();

		$this->expectException( InvalidArgumentException::class );
		try {
			$sequence->append_many( array( $use, 'raw markup' ) );
		} finally {
			self::assertTrue( $sequence->is_empty() );
		}
	}
}
