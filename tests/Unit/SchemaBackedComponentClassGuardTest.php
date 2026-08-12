<?php
/**
 * Schema-backed component class guard tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\BuilderPreviewStyleGuard;
use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\ClassStyleSet;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ComponentProperties\ComponentExpression;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyInstanceValueKind;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;
use HonestlyDesign\EtchBuilders\Contracts\ComponentRefResolverInterface;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropValueEncoder;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\AcceptedComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\InMemoryComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use PHPUnit\Framework\TestCase;

/**
 * Proves Rule G follows exact class-property schemas instead of key suffixes.
 */
final class SchemaBackedComponentClassGuardTest extends TestCase {

	/**
	 * @var array{
	 *     registry: array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>,
	 *     claimed_identities: array<array-key, array{selector: string, type: string}>,
	 *     retained_persisted_identities: array<array-key, array{selector: string, type: string}>
	 * }
	 */
	private array $style_state;

	private SchemaClassGuardStorage $storage;

	protected function setUp(): void {
		parent::setUp();

		$this->style_state = Style::snapshot_state();
		$this->storage     = new SchemaClassGuardStorage(
			array(
				'etch_styles' => array(
					'opaque-style-id' => array( 'selector' => '.visual-class', 'type' => 'class' ),
					'second-style-id' => array( 'selector' => '.second-class', 'type' => 'class' ),
						'non-class-id'    => array( 'selector' => '#visual-id', 'type' => 'id' ),
						'compound-id'     => array( 'selector' => '.visual-class:hover', 'type' => 'class' ),
						'legacy-no-type'  => array( 'selector' => '.legacy-class' ),
				),
			)
		);

		Style::reset();
		ClassStyleRegistry::reset_cache();
		$this->configure( $this->provider( 'memory' ) );
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
	public function test_exact_schema_paths_compile_and_validate_through_both_catalog_providers( string $provider_kind ): void {
		$this->configure( $this->provider( $provider_kind ) );
		$classes = ClassStyleSet::of( ClassStyleReference::registered( 'opaque-style-id' ) );

		$parsed = parse_blocks(
			ComponentBlock::for_key( 'FeatureCard' )
				->class_prop( 'classes', $classes )
				->class_prop( 'root_classes', ClassStyleSet::none() )
				->class_prop( 'conditional_classes', $classes )
				->class_prop( 'styling.cardClass', $classes )
				->class_prop( 'styling.advanced.label_classes', $classes )
				->class_prop( 'items[0].item_classes', $classes )
				->class_prop( 'items[0].styling.innerClass', $classes )
				->class_prop( 'items[0].children[0].child_classes', $classes )
				->to_block()
				->to_string()
		);

		self::assertSame( array(), BuilderPreviewStyleGuard::validate_component_class_props( $parsed ) );
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

	public function test_plural_snake_grouped_and_repeater_class_paths_are_all_guarded_recursively(): void {
		$attributes = array(
			'classes'             => 'missing-top',
			'root_classes'        => 'missing-snake',
			'conditional_classes' => 'missing-condition',
			'styling'             => ComponentPropValueEncoder::group(
				array(
					'cardClass' => 'missing-group',
					'advanced'  => array( 'label_classes' => 'missing-nested' ),
				)
			),
			'items'               => ComponentPropValueEncoder::repeater(
				array(
					array(
						'item_classes' => 'missing-row',
						'styling'      => ComponentPropValueEncoder::group( array( 'innerClass' => 'missing-row-group' ) ),
						'children'     => ComponentPropValueEncoder::repeater(
							array( array( 'child_classes' => 'missing-child-row' ) )
						),
					),
				)
			),
		);

		$errors = BuilderPreviewStyleGuard::validate_component_class_props( $this->component_blocks( $attributes ) );

		self::assertCount( 8, $errors );
		foreach (
			array(
				'classes'                           => 'missing-top',
				'root_classes'                      => 'missing-snake',
				'conditional_classes'               => 'missing-condition',
				'styling.cardClass'                 => 'missing-group',
				'styling.advanced.label_classes'    => 'missing-nested',
				'items[0].item_classes'             => 'missing-row',
				'items[0].styling.innerClass'       => 'missing-row-group',
				'items[0].children[0].child_classes' => 'missing-child-row',
			) as $path => $token
		) {
			self::assertTrue( $this->has_error( $errors, $path, $token ), 'Missing Rule G error for ' . $path );
		}
	}

	public function test_suffix_shaped_non_class_props_are_ignored_by_the_schema_guard(): void {
		$errors = BuilderPreviewStyleGuard::validate_component_class_props(
			$this->component_blocks(
				array(
					'fakeClass'     => 'not-a-style-id',
					'other_classes' => 'also-not-a-style-id',
				)
			)
		);

		self::assertSame( array(), $errors );
	}

	/**
	 * @dataProvider invalid_class_value
	 */
	public function test_static_class_values_are_validated_as_direct_opaque_ids( string $value, string $expected ): void {
		$before = Style::snapshot_state();
		$errors = BuilderPreviewStyleGuard::validate_component_class_props(
			$this->component_blocks( array( 'classes' => $value ) )
		);

		self::assertCount( 1, $errors );
		self::assertStringContainsString( 'Rule G', $errors[0] );
		self::assertStringContainsString( 'classes', $errors[0] );
		self::assertStringContainsString( $expected, $errors[0] );
		self::assertSame( $before, Style::snapshot_state() );
		self::assertSame( 0, $this->storage->set_calls );
		self::assertSame( 0, $this->storage->delete_calls );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function invalid_class_value(): array {
		return array(
			'unknown opaque ID'  => array( 'unknown-style-id', 'ETCH_CLASS_UNKNOWN_ID' ),
			'class name input'   => array( 'visual-class', 'ETCH_CLASS_NAME_INSTEAD_OF_ID' ),
			'non-class style'    => array( 'non-class-id', 'ETCH_CLASS_NON_CLASS_STYLE' ),
			'compound selector'  => array( 'compound-id', 'ETCH_CLASS_COMPOUND_SELECTOR' ),
			'missing class type' => array( 'legacy-no-type', 'explicit type=class' ),
			'runtime token'      => array( 'rt-active', 'ETCH_CLASS_RUNTIME_TOKEN' ),
			'unchecked function' => array( '{this.ids.applyData()}', 'unchecked dynamic expression' ),
			'mixed template'     => array( 'opaque-style-id {this.moreIds}', 'unchecked dynamic expression' ),
			'tab delimiter'      => array( "opaque-style-id\tsecond-style-id", 'ETCH_CLASS_UNKNOWN_ID' ),
		);
	}

	public function test_opaque_ids_empty_values_and_checked_source_expressions_pass_without_writes(): void {
		$before = Style::snapshot_state();
		$blocks = array_merge(
			$this->component_blocks( array( 'classes' => 'opaque-style-id second-style-id' ) ),
			$this->component_blocks( array( 'classes' => array( 'opaque-style-id', 'second-style-id' ) ) ),
			$this->component_blocks( array( 'classes' => '' ) ),
			$this->component_blocks( array( 'classes' => array() ) ),
			$this->component_blocks(
				array(
					'styling' => array(
						'cardClass' => array( 'opaque-style-id' ),
					),
				)
			),
			$this->component_blocks(
				array(
					'styling' => '{"cardClass":"opaque-style-id"}',
				)
			),
			$this->component_blocks(
				array(
					'styling' => '  {"cardClass":"opaque-style-id"}  ',
				)
			),
			$this->component_blocks(
				array(
					'styling' => '  {{"cardClass":"opaque-style-id"}}  ',
				)
			),
			$this->component_blocks(
				array(
					'items' => array( array( 'item_classes' => array( 'opaque-style-id' ) ) ),
				)
			),
			$this->component_blocks(
				array(
					'items' => '[{"item_classes":"opaque-style-id"}]',
				)
			),
			$this->component_blocks(
				array(
					'items' => '  [{"item_classes":"opaque-style-id"}]  ',
				)
			),
			$this->component_blocks(
				array(
					'items' => '  {[{"item_classes":"opaque-style-id"}]}  ',
				)
			),
			$this->component_blocks(
				array(
					'classes' => ComponentExpression::source(
						'this.classStyleIds',
						PropertyInstanceValueKind::CLASS_STYLE_SET
					)->encode(),
				)
			),
			$this->component_blocks(
				array(
					'styling' => ComponentExpression::source(
						'this.styling',
						PropertyInstanceValueKind::GROUP
					)->encode(),
				)
			),
			$this->component_blocks(
				array(
					'items' => ComponentExpression::source(
						'this.items',
						PropertyInstanceValueKind::REPEATER
					)->encode(),
				)
			)
		);

		self::assertSame( array(), BuilderPreviewStyleGuard::validate_component_class_props( $blocks ) );
		self::assertSame( $before, Style::snapshot_state() );
		self::assertSame( 0, $this->storage->set_calls );
		self::assertSame( 0, $this->storage->delete_calls );
	}

	/**
	 * @dataProvider malformed_component_wire
	 */
	public function test_malformed_class_group_and_repeater_wire_fails_closed( array $attributes, string $path ): void {
		$errors = BuilderPreviewStyleGuard::validate_component_class_props( $this->component_blocks( $attributes ) );

		self::assertNotEmpty( $errors );
		self::assertStringContainsString( 'Rule G', $errors[0] );
		self::assertStringContainsString( $path, $errors[0] );
		self::assertStringContainsString( 'malformed', strtolower( $errors[0] ) );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public function malformed_component_wire(): array {
		return array(
			'class associative array'       => array( array( 'classes' => array( 'id' => 'opaque-style-id' ) ), 'classes' ),
			'class list with non-string'    => array( array( 'classes' => array( 42 ) ), 'classes' ),
			'whitespace-only class value'   => array( array( 'classes' => '   ' ), 'classes' ),
			'broken group JSON'             => array( array( 'styling' => '{{broken}}' ), 'styling' ),
			'group encoded as list'         => array( array( 'styling' => '{[]}' ), 'styling' ),
			'repeater encoded as object'    => array( array( 'items' => '{{}}' ), 'items' ),
			'repeater row encoded as list'  => array( array( 'items' => '{[["opaque-style-id"]]}' ), 'items[0]' ),
			'nested group malformed'        => array(
				array(
					'items' => ComponentPropValueEncoder::repeater(
						array( array( 'styling' => '{{broken}}' ) )
					),
				),
				'items[0].styling',
			),
		);
	}

	public function test_unknown_component_refs_and_missing_catalog_contracts_fail_closed(): void {
		$unknown_ref = BuilderPreviewStyleGuard::validate_component_class_props(
			$this->component_blocks( array(), 999 )
		);
		self::assertCount( 1, $unknown_ref );
		self::assertStringContainsString( 'ref "999"', $unknown_ref[0] );

		$missing_contract = BuilderPreviewStyleGuard::validate_component_class_props(
			$this->component_blocks( array(), 88 )
		);
		self::assertCount( 1, $missing_contract );
		self::assertStringContainsString( 'KnownWithoutContract', $missing_contract[0] );
		self::assertStringContainsString( 'no component key', $missing_contract[0] );
	}

	public function test_missing_or_malformed_component_identity_and_attributes_fail_closed(): void {
		$missing_ref = BuilderPreviewStyleGuard::validate_component_class_props(
			parse_blocks( Block::new( 'component', array( 'attributes' => array() ) )->to_string() )
		);
		self::assertCount( 1, $missing_ref );
		self::assertStringContainsString( 'missing or malformed positive integer ref', $missing_ref[0] );

		$malformed_ref = BuilderPreviewStyleGuard::validate_component_class_props(
			parse_blocks( Block::new( 'component', array( 'ref' => '77', 'attributes' => array() ) )->to_string() )
		);
		self::assertCount( 1, $malformed_ref );
		self::assertStringContainsString( 'missing or malformed positive integer ref', $malformed_ref[0] );

		$malformed_attributes = BuilderPreviewStyleGuard::validate_component_class_props(
			parse_blocks( Block::new( 'component', array( 'ref' => 77, 'attributes' => 'invalid' ) )->to_string() )
		);
		self::assertCount( 1, $malformed_attributes );
		self::assertStringContainsString( 'attributes payload is malformed', $malformed_attributes[0] );

		$list_attributes = BuilderPreviewStyleGuard::validate_component_class_props(
			parse_blocks( Block::new( 'component', array( 'ref' => 77, 'attributes' => array( 'invalid' ) ) )->to_string() )
		);
		self::assertCount( 1, $list_attributes );
		self::assertStringContainsString( 'attributes payload is malformed', $list_attributes[0] );
	}

	public function test_nested_component_blocks_are_visited(): void {
		$inner = Block::new(
			'component',
			array( 'ref' => 77, 'attributes' => array( 'classes' => 'nested-missing' ) )
		);
		$outer = Block::new( 'element', array( 'tag' => 'div' ) )->add_child( $inner );

		$errors = BuilderPreviewStyleGuard::validate_component_class_props(
			parse_blocks( $outer->to_string() )
		);

		self::assertCount( 1, $errors );
		self::assertStringContainsString( 'nested-missing', $errors[0] );
	}

	private function configure( ComponentContractCatalogProviderInterface $provider ): void {
		Environment::configure(
			$this->storage,
			new NullMode(),
			new NullAssetRegistry(),
			new SchemaClassGuardRefResolver(),
			$provider
		);
	}

	private function provider( string $kind ): ComponentContractCatalogProviderInterface {
		$catalog = ComponentContractCatalog::from_contracts(
			ComponentContract::from_schema(
				'FeatureCard',
				array(
					$this->property( 'Classes', 'classes', 'array', 'class' ),
					$this->property( 'Root Classes', 'root_classes', 'array', 'class' ),
					$this->property( 'Class-like text', 'fakeClass', 'string' ),
					$this->property( 'Other class-like text', 'other_classes', 'string' ),
					$this->property(
						'Visibility',
						'visibility',
						'string',
						'condition',
						array( $this->property( 'Conditional classes', 'conditional_classes', 'array', 'class' ) )
					),
					$this->property(
						'Styling',
						'styling',
						'object',
						'group',
						array(
							$this->property( 'Card Class', 'cardClass', 'array', 'class' ),
							$this->property(
								'Advanced',
								'advanced',
								'object',
								'group',
								array( $this->property( 'Label classes', 'label_classes', 'array', 'class' ) )
							),
						)
					),
					$this->property(
						'Items',
						'items',
						'array',
						'repeater',
						array(
							$this->property( 'Item classes', 'item_classes', 'array', 'class' ),
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
								array( $this->property( 'Child classes', 'child_classes', 'array', 'class' ) )
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
	 * @param array<string, mixed> $attributes Component instance attributes.
	 * @return array<int|string, array<string, mixed>>
	 */
	private function component_blocks( array $attributes, int $ref = 77 ): array {
		return parse_blocks(
			Block::new( 'component', array( 'ref' => $ref, 'attributes' => $attributes ) )->to_string()
		);
	}

	/**
	 * @param array<int, string> $errors Guard errors.
	 */
	private function has_error( array $errors, string $path, string $token ): bool {
		foreach ( $errors as $error ) {
			if ( str_contains( $error, $path ) && str_contains( $error, $token ) ) {
				return true;
			}
		}

		return false;
	}
}

final class SchemaClassGuardRefResolver implements ComponentRefResolverInterface {

	public function ref_by_key( string $component_key ): int {
		return array_search( $component_key, array( 77 => 'FeatureCard', 88 => 'KnownWithoutContract' ), true ) ?: 0;
	}

	public function key_by_ref( int $ref ): ?string {
		return array( 77 => 'FeatureCard', 88 => 'KnownWithoutContract' )[ $ref ] ?? null;
	}
}

final class SchemaClassGuardStorage implements StorageInterface {

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
}
