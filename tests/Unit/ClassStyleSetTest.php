<?php
/**
 * ClassStyleSet tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\ClassStyleSet;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Style;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Stringable;

/**
 * Verifies ordered, immutable component class-property values.
 */
final class ClassStyleSetTest extends TestCase {

	public function test_of_preserves_reference_and_opaque_id_order_with_defensive_arrays(): void {
		$style_state = Style::snapshot_state();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$first  = $this->register_reference( 'first-opaque-id', '.first-visual-class' );
			$second = $this->register_reference( 'second-opaque-id', '.second-visual-class' );
			$set    = ClassStyleSet::of( $second, $first );

			self::assertSame( array( $second, $first ), $set->references() );
			self::assertSame( array( 'second-opaque-id', 'first-opaque-id' ), $set->ids() );
			self::assertFalse( $set->is_empty() );
			self::assertNotInstanceOf( Stringable::class, $set );

			$references = $set->references();
			$ids        = $set->ids();
			array_pop( $references );
			array_pop( $ids );

			self::assertSame( array( $second, $first ), $set->references() );
			self::assertSame( array( 'second-opaque-id', 'first-opaque-id' ), $set->ids() );
		} finally {
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	public function test_of_rejects_a_duplicate_opaque_style_id(): void {
		$style_state = Style::snapshot_state();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$reference = $this->register_reference( 'opaque-style-id', '.visual-class' );

			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessage( 'duplicate Class Style ID "opaque-style-id"' );

			ClassStyleSet::of( $reference, $reference );
		} finally {
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	public function test_of_rejects_different_ids_with_the_same_validated_selector(): void {
		$style_state = Style::snapshot_state();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$first  = $this->register_reference( 'first-opaque-id', '.shared-class' );
			$second = $this->register_reference( 'second-opaque-id', '.shared-class' );

			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessage( 'duplicate class selector ".shared-class"' );

			ClassStyleSet::of( $first, $second );
		} finally {
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	public function test_of_rejects_a_generic_state_selector_before_retaining_the_set(): void {
		$style_state = Style::snapshot_state();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$reference = $this->register_reference( 'state-opaque-id', '.is-active' );

			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessage( 'generic state class' );

			ClassStyleSet::of( $reference );
		} finally {
			ClassStyleRegistry::reset_cache();
			Environment::reset();
			Style::restore_state( $style_state );
		}
	}

	public function test_none_is_the_explicit_zero_reference_value(): void {
		$set = ClassStyleSet::none();

		self::assertTrue( $set->is_empty() );
		self::assertSame( array(), $set->references() );
		self::assertSame( array(), $set->ids() );
		self::assertNotInstanceOf( Stringable::class, $set );

		$constructor = ( new ReflectionClass( ClassStyleSet::class ) )->getConstructor();
		self::assertNotNull( $constructor );
		self::assertTrue( $constructor->isPrivate() );
		self::assertSame( 1, ( new ReflectionMethod( ClassStyleSet::class, 'of' ) )->getNumberOfRequiredParameters() );
	}

	private function register_reference( string $id, string $selector ): ClassStyleReference {
		Style::new()
			->id( $id )
			->selector( $selector )
			->css( 'color:red' )
			->type( 'class' )
			->add();

		return ClassStyleReference::registered( $id );
	}
}
