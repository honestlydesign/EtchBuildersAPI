<?php
/**
 * ComponentBlock tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentBlock;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ComponentBlock class prop linkage.
 */
final class ComponentBlockTest extends TestCase {

	public function test_prop_class_serializes_an_in_memory_opaque_style_id_without_mutating_style_state(): void {
		$style_state = Style::snapshot_state();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			Style::new()
				->id( 'opaque-style-id' )
				->selector( '.visual-class' )
				->css( 'color:red' )
				->type( 'class' )
				->collection( 'User styles' )
				->add();

			$before = Style::snapshot_state();
			$block  = ComponentBlock::new()
				->ref( 1 )
				->prop_class( 'classes', array( 'opaque-style-id' ) )
				->to_block();

			$attrs = $this->extract_block_attrs( $block->to_string() );

			self::assertSame( 'opaque-style-id', $attrs['attributes']['classes'] );
			self::assertSame( $before, Style::snapshot_state() );
		} finally {
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	public function test_prop_class_reads_a_legacy_persisted_opaque_style_id_without_importing_or_writing_it(): void {
		$style_state = Style::snapshot_state();
		$persisted   = array(
			'opaque-style-id' => array(
				'selector'   => '.visual-class',
				'collection' => 'User styles',
				'css'        => 'color:red',
			),
		);
		$storage     = new ComponentClassReadOnlyStorage( array( 'etch_styles' => $persisted ) );

		try {
			Style::reset();
			Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );
			ClassStyleRegistry::reset_cache();

			$before = Style::snapshot_state();
			$block  = ComponentBlock::new()
				->ref( 1 )
				->prop_class( 'classes', array( 'opaque-style-id' ) )
				->to_block();

			$attrs = $this->extract_block_attrs( $block->to_string() );

			self::assertSame( 'opaque-style-id', $attrs['attributes']['classes'] );
			self::assertSame( $before, Style::snapshot_state() );
			self::assertSame( $persisted, $storage->get( 'etch_styles' ) );
			self::assertSame( 0, $storage->set_calls );
			self::assertSame( 0, $storage->delete_calls );
		} finally {
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	public function test_prop_class_rejects_a_selector_token_instead_of_claiming_its_opaque_style_id(): void {
		$style_state = Style::snapshot_state();
		$persisted   = array(
			'opaque-style-id' => array(
				'selector'   => '.visual-class',
				'collection' => 'User styles',
				'css'        => 'color:red',
				'type'       => 'class',
			),
		);
		$storage     = new ComponentClassReadOnlyStorage( array( 'etch_styles' => $persisted ) );

		try {
			Style::reset();
			Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );
			ClassStyleRegistry::reset_cache();

			$before = Style::snapshot_state();

			try {
				ComponentBlock::new()
					->ref( 1 )
					->prop_class( 'classes', array( 'visual-class' ) );
				self::fail( 'A selector token must not resolve a component class property by selector.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'visual-class', $exception->getMessage() );
			}

			self::assertSame( $before, Style::snapshot_state() );
			self::assertSame( $persisted, $storage->get( 'etch_styles' ) );
			self::assertSame( 0, $storage->set_calls );
			self::assertSame( 0, $storage->delete_calls );
		} finally {
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	public function test_prop_class_rejects_an_unknown_style_id_without_creating_a_placeholder(): void {
		$style_state = Style::snapshot_state();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$before = Style::snapshot_state();

			try {
				ComponentBlock::new()
					->ref( 1 )
					->prop_class( 'classes', array( 'missing-style-id' ) );
				self::fail( 'An unknown component class style ID must not be auto-registered.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'missing-style-id', $exception->getMessage() );
			}

			self::assertSame( $before, Style::snapshot_state() );
		} finally {
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	public function test_prop_class_preserves_registered_style_id_order(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			Style::new()
				->id( 'first-opaque-id' )
				->selector( '.first-visual-class' )
				->css( 'display:block' )
				->type( 'class' )
				->collection( 'OhMyIDEtch' )
				->add();
			Style::new()
				->id( 'second-opaque-id' )
				->selector( '.second-visual-class' )
				->css( 'display:flex' )
				->type( 'class' )
				->collection( 'OhMyIDEtch' )
				->add();

			$block = ComponentBlock::new()
				->ref( 1 )
				->prop_class( 'classes', array( 'second-opaque-id', 'first-opaque-id' ) )
				->to_block();

			$attrs = $this->extract_block_attrs( $block->to_string() );

			self::assertSame( 'second-opaque-id first-opaque-id', $attrs['attributes']['classes'] );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_prop_class_rejects_a_registered_non_class_style_without_mutating_it(): void {
		$style_state = Style::snapshot_state();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			Style::new()
				->id( 'non-class-style-id' )
				->selector( '#target' )
				->css( 'color:red' )
				->type( 'id' )
				->add();

			$before = Style::snapshot_state();

			try {
				ComponentBlock::new()
					->ref( 1 )
					->prop_class( 'classes', array( 'non-class-style-id' ) );
				self::fail( 'A non-class style ID must not be accepted by a component class property.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'type=class', $exception->getMessage() );
			}

			self::assertSame( $before, Style::snapshot_state() );
		} finally {
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
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

/**
 * Storage spy proving component class serialization does not write.
 */
final class ComponentClassReadOnlyStorage implements StorageInterface {

	public int $set_calls = 0;

	public int $delete_calls = 0;

	/**
	 * @param array<string, mixed> $values Initial values.
	 */
	public function __construct( private array $values = array() ) {
	}

	public function get( string $key, mixed $default = null ): mixed {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
	}

	public function set( string $key, mixed $value ): bool {
		++$this->set_calls;
		$this->values[ $key ] = $value;

		return true;
	}

	public function delete( string $key ): bool {
		++$this->delete_calls;
		unset( $this->values[ $key ] );

		return true;
	}
}
