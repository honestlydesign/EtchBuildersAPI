<?php
/**
 * Schema-backed component class-property path tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\ClassStyleSet;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ComponentProperties\ComponentInstanceValue;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;
use HonestlyDesign\EtchBuilders\Contracts\ComponentRefResolverInterface;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentBlock;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\AcceptedComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\InMemoryComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies exact Component Contract paths own class-property values.
 */
final class SchemaBackedComponentClassPathTest extends TestCase {

	/**
	 * @var array{
	 *     registry: array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>,
	 *     claimed_identities: array<array-key, array{selector: string, type: string}>,
	 *     retained_persisted_identities: array<array-key, array{selector: string, type: string}>
	 * }
	 */
	private array $style_state;

	private SchemaClassPathStorage $storage;

	private ClassStyleSet $classes;

	protected function setUp(): void {
		parent::setUp();

		$this->style_state = Style::snapshot_state();
		$this->storage     = new SchemaClassPathStorage(
			array(
				'etch_styles' => array(
					'first-opaque-id'  => array( 'selector' => '.first-visual-class', 'type' => 'class' ),
					'second-opaque-id' => array( 'selector' => '.second-visual-class', 'type' => 'class' ),
				),
			)
		);

		Style::reset();
		ClassStyleRegistry::reset_cache();
		$this->configure( $this->provider( 'memory' ) );

		$first         = ClassStyleReference::registered( 'first-opaque-id' );
		$second        = ClassStyleReference::registered( 'second-opaque-id' );
		$this->classes = ClassStyleSet::of( $second, $first );
	}

	protected function tearDown(): void {
		ClassStyleRegistry::reset_cache();
		Environment::reset();
		Style::restore_state( $this->style_state );

		parent::tearDown();
	}

	/**
	 * @dataProvider catalog_provider
	 */
	public function test_exact_top_level_and_group_paths_compile_through_both_catalog_providers( string $provider_kind ): void {
		$this->configure( $this->provider( $provider_kind ) );
		$before = Style::snapshot_state();

		$block = ComponentBlock::for_key( 'FeatureCard' )
			->class_prop( 'rootClass', $this->classes )
			->class_prop( 'styling.cardClass', $this->classes )
			->class_prop( 'styling.advanced.labelClass', ClassStyleSet::none() )
			->to_block();

		$attributes = $this->extract_attributes( $block->to_string() );

		self::assertSame( 'second-opaque-id first-opaque-id', $attributes['rootClass'] );
		self::assertSame(
			'{{"cardClass":"second-opaque-id first-opaque-id","advanced":{"labelClass":""}}}',
			$attributes['styling']
		);
		self::assertSame( $before, Style::snapshot_state() );
		self::assertSame( 0, $this->storage->set_calls );
		self::assertSame( 0, $this->storage->delete_calls );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function catalog_provider(): array {
		return array(
			'in-memory catalog' => array( 'memory' ),
			'accepted catalog'  => array( 'accepted' ),
		);
	}

	public function test_concrete_repeater_and_nested_repeater_class_paths_compile_in_schema_order(): void {
		$block = ComponentBlock::for_key( 'FeatureCard' )
			->class_prop( 'items[1].itemClass', ClassStyleSet::none() )
			->class_prop( 'items[0].styling.innerClass', $this->classes )
			->class_prop( 'items[0].itemClass', $this->classes )
			->class_prop( 'items[0].children[0].childClass', $this->classes )
			->to_block();

		$attributes = $this->extract_attributes( $block->to_string() );

		self::assertSame(
			'{[{"itemClass":"second-opaque-id first-opaque-id","styling":"{{\\"innerClass\\":\\"second-opaque-id first-opaque-id\\"}}","children":"{[{\\"childClass\\":\\"second-opaque-id first-opaque-id\\"}]}"},{"itemClass":""}]}',
			$attributes['items']
		);
	}

	public function test_transparent_condition_child_uses_its_effective_value_path(): void {
		$attributes = $this->extract_attributes(
			ComponentBlock::for_key( 'FeatureCard' )
				->class_prop( 'conditionalClass', $this->classes )
				->to_block()
				->to_string()
		);

		self::assertSame( 'second-opaque-id first-opaque-id', $attributes['conditionalClass'] );
		self::assertArrayNotHasKey( 'visibility', $attributes );
	}

	/**
	 * @dataProvider invalid_class_path
	 */
	public function test_unknown_inexact_non_class_and_non_concrete_paths_fail_closed( string $path, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		ComponentBlock::for_key( 'FeatureCard' )
			->class_prop( $path, $this->classes );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function invalid_class_path(): array {
		return array(
			'unknown path'               => array( 'inventedClass', 'no exact instance value path "inventedClass"' ),
			'wrong case'                 => array( 'styling.CardClass', 'no exact instance value path "styling.CardClass"' ),
			'non-class leaf'             => array( 'title', 'exact array/class property' ),
			'group path'                 => array( 'styling', 'exact array/class property' ),
			'repeater missing row'       => array( 'items.itemClass', 'requires a concrete row index' ),
			'path ends at row'           => array( 'items[0]', 'must end at a property' ),
			'transparent declaration'    => array( 'visibility', 'declaration-only transparent' ),
		);
	}

	public function test_duplicate_and_literal_class_path_assignments_conflict(): void {
		$duplicate = ComponentBlock::for_key( 'FeatureCard' )
			->class_prop( 'styling.cardClass', $this->classes );

		try {
			$duplicate->class_prop( 'styling.cardClass', ClassStyleSet::none() );
			self::fail( 'Duplicate exact class paths must not overwrite.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'already has an assignment', $exception->getMessage() );
		}

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'conflicts with an existing assignment' );

		ComponentBlock::for_key( 'FeatureCard' )
			->prop_value( 'items', ComponentInstanceValue::empty_repeater() )
			->class_prop( 'items[0].itemClass', $this->classes );
	}

	public function test_class_path_then_conflicting_structural_literal_fails_closed(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'conflicts with existing child assignments' );

		ComponentBlock::for_key( 'FeatureCard' )
			->class_prop( 'items[0].itemClass', $this->classes )
			->prop_value( 'items', ComponentInstanceValue::empty_repeater() );
	}

	public function test_class_only_repeater_assignments_still_require_contiguous_rows(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'contiguous rows starting at index 0' );

		ComponentBlock::for_key( 'FeatureCard' )
			->class_prop( 'items[1].itemClass', $this->classes )
			->to_block();
	}

	public function test_schema_class_root_conflicts_with_legacy_or_raw_attribute(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'conflicts with an existing raw or legacy component attribute' );

		ComponentBlock::for_key( 'FeatureCard' )
			->prop_raw( 'styling', 'raw' )
			->class_prop( 'styling.cardClass', $this->classes )
			->to_block();
	}

	public function test_non_keyed_class_prop_retains_legacy_top_level_source_compatibility(): void {
		$attributes = $this->extract_attributes(
			ComponentBlock::new()
				->ref( 77 )
				->class_prop( 'legacyKey', $this->classes )
				->to_block()
				->to_string()
		);

		self::assertSame( 'second-opaque-id first-opaque-id', $attributes['legacyKey'] );
	}

	public function test_final_schema_serialization_rejects_late_class_style_identity_replacement(): void {
		$component = ComponentBlock::for_key( 'FeatureCard' )
			->class_prop( 'rootClass', $this->classes );

		$this->storage->replace_styles(
			array(
				'first-opaque-id'  => array( 'selector' => '.replaced-class', 'type' => 'class' ),
				'second-opaque-id' => array( 'selector' => '.second-visual-class', 'type' => 'class' ),
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'changed selector identity from ".first-visual-class" to ".replaced-class"' );

		$component->to_block();
	}

	private function configure( ComponentContractCatalogProviderInterface $provider ): void {
		Environment::configure(
			$this->storage,
			new NullMode(),
			new NullAssetRegistry(),
			new SchemaClassPathRefResolver(),
			$provider
		);
	}

	private function provider( string $kind ): ComponentContractCatalogProviderInterface {
		$catalog = ComponentContractCatalog::from_contracts(
			ComponentContract::from_schema(
				'FeatureCard',
				array(
					$this->property( 'Title', 'title', 'string' ),
					$this->property( 'Root Class', 'rootClass', 'array', 'class' ),
					$this->property(
						'Visibility',
						'visibility',
						'string',
						'condition',
						array( $this->property( 'Conditional Class', 'conditionalClass', 'array', 'class' ) )
					),
					$this->property(
						'Styling',
						'styling',
						'object',
						'group',
						array(
							$this->property( 'Label', 'label', 'string' ),
							$this->property( 'Card Class', 'cardClass', 'array', 'class' ),
							$this->property(
								'Advanced',
								'advanced',
								'object',
								'group',
								array( $this->property( 'Label Class', 'labelClass', 'array', 'class' ) )
							),
						)
					),
					$this->property(
						'Items',
						'items',
						'array',
						'repeater',
						array(
							$this->property( 'Item Class', 'itemClass', 'array', 'class' ),
							$this->property(
								'Styling',
								'styling',
								'object',
								'group',
								array( $this->property( 'Inner Class', 'innerClass', 'array', 'class' ) )
							),
							$this->property(
								'Children',
								'children',
								'array',
								'repeater',
								array( $this->property( 'Child Class', 'childClass', 'array', 'class' ) )
							),
						)
					),
				),
				array()
			)
		);

		return 'accepted' === $kind
			? AcceptedComponentContractCatalogProvider::from_array( $catalog->to_array() )
			: InMemoryComponentContractCatalogProvider::from_catalog( $catalog );
	}

	/**
	 * @param array<int, array<string, mixed>>|null $children Child definitions.
	 * @return array<string, mixed>
	 */
	private function property(
		string $name,
		string $key,
		string $primitive,
		?string $specialized = null,
		?array $children = null
	): array {
		$type = array( 'primitive' => $primitive );
		if ( null !== $specialized ) {
			$type['specialized'] = $specialized;
		}

		$property = array( 'name' => $name, 'key' => $key, 'type' => $type );
		if ( null !== $children ) {
			$property['properties'] = $children;
		}

		return $property;
	}

	/**
	 * @return array<string, string>
	 */
	private function extract_attributes( string $markup ): array {
		preg_match( '/<!-- wp:etch\/component (\{.*?\}) -->/s', $markup, $matches );
		self::assertNotEmpty( $matches, 'Failed to find component block attrs in: ' . $markup );

		$block_attributes = json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );

		return $block_attributes['attributes'];
	}
}

final class SchemaClassPathRefResolver implements ComponentRefResolverInterface {

	public function ref_by_key( string $component_key ): int {
		return 'FeatureCard' === $component_key ? 77 : 0;
	}

	public function key_by_ref( int $ref ): ?string {
		return 77 === $ref ? 'FeatureCard' : null;
	}
}

final class SchemaClassPathStorage implements StorageInterface {

	public int $set_calls = 0;

	public int $delete_calls = 0;

	/**
	 * @param array<string, mixed> $values Initial values.
	 */
	public function __construct( private array $values ) {
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

	/**
	 * @param array<string, mixed> $styles Replacement persisted styles.
	 */
	public function replace_styles( array $styles ): void {
		$this->values['etch_styles'] = $styles;
	}
}
