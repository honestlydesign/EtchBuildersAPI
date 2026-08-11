<?php
/**
 * Style persistence tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Style;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Etch style option merge and pruning behavior.
 */
final class StyleTest extends TestCase {

	public function test_add_rejects_existing_in_memory_id_with_different_selector_without_mutation(): void {
		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.original-selector' )
			->css( 'color: red;' )
			->add();

		$before = Style::snapshot();

		$this->assert_identity_conflict_without_mutation(
			static function (): void {
				Style::new()
					->id( 'opaque-style-id' )
					->selector( '.replacement-selector' )
					->css( 'color: blue;' )
					->add();
			},
			$before,
			array()
		);
	}

	public function test_add_rejects_existing_in_memory_id_with_different_type_without_mutation(): void {
		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.shared-selector' )
			->css( 'color: red;' )
			->add();

		$before = Style::snapshot();

		$this->assert_identity_conflict_without_mutation(
			static function (): void {
				Style::new()
					->id( 'opaque-style-id' )
					->selector( '.shared-selector' )
					->type( 'custom' )
					->css( 'color: blue;' )
					->add();
			},
			$before,
			array()
		);
	}

	public function test_add_remembers_id_identity_after_selector_conflict_evicts_the_registry_entry(): void {
		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.shared-selector' )
			->css( 'color: red;' )
			->add();

		Style::new()
			->id( 'replacement-style-id' )
			->selector( '.shared-selector' )
			->css( 'color: blue;' )
			->add();

		$before = Style::snapshot();
		self::assertArrayNotHasKey( 'opaque-style-id', $before );

		$this->assert_identity_conflict_without_mutation(
			static function (): void {
				Style::new()
					->id( 'opaque-style-id' )
					->selector( '.different-selector' )
					->css( 'color: green;' )
					->add();
			},
			$before,
			array()
		);
	}

	public function test_full_state_snapshot_round_trips_evicted_identity_claims(): void {
		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.shared-selector' )
			->css( 'color: red;' )
			->add();

		Style::new()
			->id( 'replacement-style-id' )
			->selector( '.shared-selector' )
			->css( 'color: blue;' )
			->add();

		$state  = Style::snapshot_state();
		$before = Style::snapshot();

		Style::reset();
		Style::restore_state( $state );

		$this->assert_identity_conflict_without_mutation(
			static function (): void {
				Style::new()
					->id( 'opaque-style-id' )
					->selector( '.different-selector' )
					->css( 'color: green;' )
					->add();
			},
			$before,
			array()
		);
	}

	public function test_add_rejects_persisted_id_with_different_selector_without_mutation(): void {
		$persisted = array(
			'opaque-style-id' => array(
				'selector'   => '.persisted-selector',
				'collection' => 'user',
				'css'        => 'color: red;',
				'type'       => 'class',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );

		$this->assert_identity_conflict_without_mutation(
			static function (): void {
				Style::new()
					->id( 'opaque-style-id' )
					->selector( '.replacement-selector' )
					->css( 'color: blue;' )
					->add();
			},
			array(),
			$persisted
		);
	}

	public function test_add_rejects_persisted_id_with_different_type_without_mutation(): void {
		$persisted = array(
			'opaque-style-id' => array(
				'selector'   => '.shared-selector',
				'collection' => 'user',
				'css'        => 'color: red;',
				'type'       => 'custom',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );

		$this->assert_identity_conflict_without_mutation(
			static function (): void {
				Style::new()
					->id( 'opaque-style-id' )
					->selector( '.shared-selector' )
					->css( 'color: blue;' )
					->readonly()
					->add();
			},
			array(),
			$persisted
		);
	}

	public function test_add_rejects_an_occupied_persisted_id_when_its_identity_is_malformed(): void {
		$persisted = array(
			'opaque-style-id' => array(
				'collection' => 'user',
				'css'        => 'color: red;',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );

		$this->assert_identity_conflict_without_mutation(
			static function (): void {
				Style::new()
					->id( 'opaque-style-id' )
					->selector( '.replacement-selector' )
					->css( 'color: blue;' )
					->readonly()
					->add();
			},
			array(),
			$persisted,
			'malformed'
		);
	}

	public function test_register_all_rechecks_persisted_identity_before_writing(): void {
		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.registry-selector' )
			->css( 'color: blue;' )
			->readonly()
			->add();

		$registry  = Style::snapshot();
		$persisted = array(
			'opaque-style-id' => array(
				'selector'   => '.concurrent-selector',
				'collection' => 'user',
				'css'        => 'color: red;',
				'type'       => 'class',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );

		$this->assert_identity_conflict_without_mutation(
			static function (): void {
				Style::register_all();
			},
			$registry,
			$persisted
		);
	}

	public function test_register_all_rechecks_evicted_identity_claims_before_writing(): void {
		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.shared-selector' )
			->css( 'color: red;' )
			->add();

		Style::new()
			->id( 'replacement-style-id' )
			->selector( '.shared-selector' )
			->css( 'color: blue;' )
			->add();

		$registry  = Style::snapshot();
		$persisted = array(
			'opaque-style-id' => array(
				'selector'   => '.concurrent-selector',
				'collection' => 'user',
				'css'        => 'color: green;',
				'type'       => 'class',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );

		$this->assert_identity_conflict_without_mutation(
			static function (): void {
				Style::register_all();
			},
			$registry,
			$persisted
		);
	}

	public function test_add_allows_updating_an_in_memory_style_with_the_same_normalized_identity(): void {
		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.card   >   .item' )
			->css( 'color: red;' )
			->add();

		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.card > .item' )
			->css( 'color: blue;' )
			->add();

		self::assertSame(
			array(
				'selector'   => '.card > .item',
				'collection' => 'default',
				'css'        => 'color: blue;',
				'type'       => 'custom',
			),
			Style::registered_styles()['opaque-style-id']
		);
	}

	public function test_register_all_allows_overwriting_persisted_css_for_the_same_normalized_identity(): void {
		Environment::storage()->set(
			'etch_styles',
			array(
				'opaque-style-id' => array(
					'selector'   => '.card   >   .item',
					'collection' => 'user',
					'css'        => 'color: red;',
					'type'       => 'custom',
				),
			)
		);

		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.card > .item' )
			->collection( 'OhMyIDEtch' )
			->css( 'color: blue;' )
			->overwrite_on_register()
			->add();

		self::assertTrue( Style::register_all() );
		self::assertSame(
			array(
				'selector'   => '.card > .item',
				'collection' => 'OhMyIDEtch',
				'css'        => 'color: blue;',
				'type'       => 'custom',
			),
			Environment::storage()->get( 'etch_styles', array() )['opaque-style-id']
		);
	}

	public function test_register_all_preserves_user_owned_content_for_the_same_mutable_identity(): void {
		$persisted = array(
			'opaque-style-id' => array(
				'selector'   => '.mutable-selector',
				'collection' => 'user',
				'css'        => 'color: user-customized;',
				'type'       => 'class',
				'name'       => 'User label',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );

		Style::new()
			->id( 'opaque-style-id' )
			->selector( '.mutable-selector' )
			->css( 'color: code-default;' )
			->add();

		self::assertTrue( Style::register_all() );
		self::assertSame( $persisted, Environment::storage()->get( 'etch_styles', array() ) );
	}

	public function test_add_infers_a_missing_persisted_type_before_comparing_identity(): void {
		Environment::storage()->set(
			'etch_styles',
			array(
				'legacy-style-id' => array(
					'selector'   => '.legacy-selector',
					'collection' => 'user',
					'css'        => 'color: red;',
				),
			)
		);

		self::assertSame(
			'legacy-style-id',
			Style::new()
				->id( 'legacy-style-id' )
				->selector( '.legacy-selector' )
				->css( 'color: blue;' )
				->add()
		);
		self::assertSame( 'class', Style::registered_styles()['legacy-style-id']['type'] );
	}

	public function test_register_all_preserves_external_readonly_styles_when_registry_is_empty(): void {
		Environment::reset();
		Style::reset();

		Environment::storage()->set(
			'etch_styles',
			array(
				'etch-section-style' => array(
					'selector'   => ':where([data-etch-element="section"])',
					'collection' => 'default',
					'css'        => 'inline-size: 100%;',
					'readonly'   => true,
					'type'       => 'element',
				),
				'omide-stale-style' => array(
					'selector'   => '.omide-stale-style',
					'collection' => 'default',
					'css'        => 'display: block;',
					'readonly'   => true,
					'type'       => 'class',
				),
			)
		);

		self::assertTrue( Style::register_all() );

		$styles = Environment::storage()->get( 'etch_styles', array() );
		self::assertIsArray( $styles );
		self::assertArrayHasKey( 'etch-section-style', $styles );
		self::assertArrayNotHasKey( 'omide-stale-style', $styles );
	}

	/**
	 * Assert that an identity collision is reported before either state changes.
	 *
	 * @param callable(): void $operation          Operation expected to fail.
	 * @param array<string, array<string, mixed>> $expected_registry Expected registry state.
	 * @param array<array-key, mixed>              $expected_persisted Expected persisted state.
	 * @param string|null                          $expected_message Additional message fragment.
	 */
	private function assert_identity_conflict_without_mutation(
		callable $operation,
		array $expected_registry,
		array $expected_persisted,
		?string $expected_message = null
	): void {
		try {
			$operation();
			self::fail( 'Expected conflicting style identity to be rejected.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'opaque-style-id', $exception->getMessage() );
			if ( null !== $expected_message ) {
				self::assertStringContainsString( $expected_message, $exception->getMessage() );
			}
		}

		self::assertSame( $expected_registry, Style::snapshot() );
		self::assertSame( $expected_persisted, Environment::storage()->get( 'etch_styles', array() ) );
	}

	protected function tearDown(): void {
		Style::reset();
		Environment::reset();
		parent::tearDown();
	}
}
