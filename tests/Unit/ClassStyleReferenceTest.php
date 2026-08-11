<?php
/**
 * ClassStyleReference tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies exact, read-only class-style identity proofs.
 */
final class ClassStyleReferenceTest extends TestCase {

	public function test_registered_returns_an_opaque_in_memory_reference_without_mutation(): void {
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

			$before    = Style::snapshot_state();
			$reference = ClassStyleReference::registered( 'opaque-style-id' );

			self::assertSame( 'opaque-style-id', $reference->id() );
			self::assertSame( '.visual-class', $reference->selector() );
			self::assertSame( $before, Style::snapshot_state() );
		} finally {
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	public function test_registered_reads_a_legacy_persisted_reference_without_import_or_writes(): void {
		$style_state = Style::snapshot_state();
		$persisted   = array(
			'opaque-style-id' => array(
				'selector'   => '.visual-class',
				'collection' => 'User styles',
				'css'        => 'color:red',
			),
		);
		$storage     = new ClassStyleReferenceStorage( array( 'etch_styles' => $persisted ) );

		try {
			Style::reset();
			Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );
			ClassStyleRegistry::reset_cache();

			$before    = Style::snapshot_state();
			$reference = ClassStyleReference::registered( 'opaque-style-id' );

			self::assertSame( 'opaque-style-id', $reference->id() );
			self::assertSame( '.visual-class', $reference->selector() );
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

	public function test_registered_rejects_a_selector_token_instead_of_an_opaque_id_without_mutation(): void {
		$style_state = Style::snapshot_state();
		$persisted   = array(
			'opaque-style-id' => array(
				'selector' => '.visual-class',
				'type'     => 'class',
			),
		);
		$storage     = new ClassStyleReferenceStorage( array( 'etch_styles' => $persisted ) );

		try {
			Style::reset();
			Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );
			$before = Style::snapshot_state();

			try {
				ClassStyleReference::registered( 'visual-class' );
				self::fail( 'A selector token must not resolve to an opaque Class Style ID.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'visual-class', $exception->getMessage() );
			}

			self::assertSame( $before, Style::snapshot_state() );
			self::assertSame( $persisted, $storage->get( 'etch_styles' ) );
			self::assertSame( 0, $storage->set_calls );
			self::assertSame( 0, $storage->delete_calls );
		} finally {
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	public function test_registered_rejects_an_effective_non_class_style_without_mutation(): void {
		$style_state = Style::snapshot_state();
		$persisted   = array(
			'opaque-style-id' => array(
				'selector' => '#target',
				'type'     => 'id',
			),
		);
		$storage     = new ClassStyleReferenceStorage( array( 'etch_styles' => $persisted ) );

		try {
			Style::reset();
			Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );
			$before = Style::snapshot_state();

			try {
				ClassStyleReference::registered( 'opaque-style-id' );
				self::fail( 'A non-class style must not produce a ClassStyleReference.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'type=class', $exception->getMessage() );
			}

			self::assertSame( $before, Style::snapshot_state() );
			self::assertSame( $persisted, $storage->get( 'etch_styles' ) );
			self::assertSame( 0, $storage->set_calls );
			self::assertSame( 0, $storage->delete_calls );
		} finally {
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	/**
	 * @dataProvider malformed_type_provider
	 */
	public function test_registered_rejects_a_present_but_malformed_type_value_without_legacy_inference( mixed $type ): void {
		$style_state = Style::snapshot_state();
		$persisted   = array(
			'opaque-style-id' => array(
				'selector' => '.visual-class',
				'type'     => $type,
			),
		);
		$storage     = new ClassStyleReferenceStorage( array( 'etch_styles' => $persisted ) );

		try {
			Style::reset();
			Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );
			$before = Style::snapshot_state();

			try {
				ClassStyleReference::registered( 'opaque-style-id' );
				self::fail( 'A present malformed type must not use legacy missing-type inference.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'type=class', $exception->getMessage() );
			}

			self::assertSame( $before, Style::snapshot_state() );
			self::assertSame( $persisted, $storage->get( 'etch_styles' ) );
			self::assertSame( 0, $storage->set_calls );
			self::assertSame( 0, $storage->delete_calls );
		} finally {
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function malformed_type_provider(): array {
		return array(
			'null'            => array( null ),
			'false'           => array( false ),
			'array'           => array( array() ),
			'empty string'    => array( '' ),
			'unknown string'  => array( 'unknown' ),
			'wrong case type' => array( 'CLASS' ),
		);
	}

	/**
	 * @dataProvider invalid_selector_provider
	 */
	public function test_registered_rejects_an_explicit_class_type_without_one_exact_simple_selector( mixed $selector ): void {
		$style_state = Style::snapshot_state();
		$persisted   = array(
			'opaque-style-id' => array(
				'selector' => $selector,
				'type'     => 'class',
			),
		);
		$storage     = new ClassStyleReferenceStorage( array( 'etch_styles' => $persisted ) );

		try {
			Style::reset();
			Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );
			$before = Style::snapshot_state();

			try {
				ClassStyleReference::registered( 'opaque-style-id' );
				self::fail( 'A malformed or compound class selector must not produce a ClassStyleReference.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'exactly one simple class selector', $exception->getMessage() );
			}

			self::assertSame( $before, Style::snapshot_state() );
			self::assertSame( $persisted, $storage->get( 'etch_styles' ) );
			self::assertSame( 0, $storage->set_calls );
			self::assertSame( 0, $storage->delete_calls );
		} finally {
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function invalid_selector_provider(): array {
		return array(
			'pseudo selector'       => array( '.card:hover' ),
			'descendant selector'   => array( '.card .title' ),
			'compound class chain'  => array( '.card.active' ),
			'selector list'         => array( '.card, .other' ),
			'attribute suffix'      => array( '.card[data-state]' ),
			'missing selector'      => array( null ),
			'empty selector'        => array( '' ),
			'non-class selector'    => array( 'card' ),
			'malformed class token' => array( '.1card' ),
		);
	}
}

/**
 * Storage spy proving reference construction is read-only.
 */
final class ClassStyleReferenceStorage implements StorageInterface {

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
