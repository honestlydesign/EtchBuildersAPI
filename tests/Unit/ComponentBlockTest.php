<?php
/**
 * ComponentBlock tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentBlock;
use HonestlyDesign\EtchBuilders\Style;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ComponentBlock class prop linkage.
 */
final class ComponentBlockTest extends TestCase {

	public function test_prop_class_resolves_to_style_ids(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			Style::new()
				->id( 'omide-card' )
				->selector( '.omide-card' )
				->css( 'display:block' )
				->type( 'class' )
				->collection( 'OhMyIDEtch' )
				->add();

			$block = ComponentBlock::new()
				->ref( 1 )
				->prop_class( 'classes', array( 'omide-card' ) )
				->to_block();

			$attrs = $this->extract_block_attrs( $block->to_string() );

			self::assertSame( 'omide-card', $attrs['attributes']['classes'] );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_prop_class_auto_registers_missing_token(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$block = ComponentBlock::new()
				->ref( 1 )
				->prop_class( 'classes', array( 'stack' ) )
				->to_block();

			$attrs = $this->extract_block_attrs( $block->to_string() );

			self::assertSame( 'stack', $attrs['attributes']['classes'] );
			self::assertArrayHasKey( 'stack', Style::registered_styles() );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_prop_class_passes_through_dynamic_token(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$block = ComponentBlock::new()
				->ref( 1 )
				->prop_class( 'classes', array( '{props.extraClasses}' ) )
				->to_block();

			$attrs = $this->extract_block_attrs( $block->to_string() );

			self::assertSame( '{props.extraClasses}', $attrs['attributes']['classes'] );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_prop_class_passes_through_runtime_token(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$block = ComponentBlock::new()
				->ref( 1 )
				->prop_class( 'classes', array( 'rt-active' ) )
				->to_block();

			$attrs = $this->extract_block_attrs( $block->to_string() );

			self::assertSame( 'rt-active', $attrs['attributes']['classes'] );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_prop_class_throws_on_invalid_token(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$this->expectException( \InvalidArgumentException::class );
			$this->expectExceptionMessage( 'invalid!' );

			ComponentBlock::new()
				->ref( 1 )
				->prop_class( 'classes', array( 'invalid!token' ) );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	/**
	 * Parse the JSON attrs out of a serialized wp:block comment.
	 *
	 * @param string $markup Serialized block.
	 * @return array<string, mixed>
	 */
	private function extract_block_attrs( string $markup ): array {
		preg_match( '/<!-- wp:etch\/component (\{.*?\}) -->/s', $markup, $matches );
		self::assertNotEmpty( $matches, 'Failed to find component block attrs in: ' . $markup );

		return json_decode( $matches[1], true );
	}
}
