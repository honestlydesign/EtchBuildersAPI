<?php
/**
 * Component Contract Catalog model tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Component;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentPropertyPathContract;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContractMatrix;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContract;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContractStatus;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyInstanceValueKind;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyWireShape;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\BooleanProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\StringProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ClassProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ConditionProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\GroupProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\RepeaterGroupProperty;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the pure schema-derived component contract model.
 */
final class ComponentContractCatalogTest extends TestCase {

	public function test_contract_projects_nested_schema_without_component_implementation(): void {
		$contract = ComponentContract::from_schema(
			'FeatureCard',
			array(
				$this->property( 'Title', 'title', 'string', null, 'Hello' ),
				$this->property(
					'Visibility',
					'visibility',
					'string',
					'condition',
					null,
					array(
						$this->property( 'Enabled', 'enabled', 'boolean', null, true ),
						$this->property( 'Conditional Class', 'conditionalClass', 'array', 'class' ),
					)
				),
				$this->property(
					'Styling',
					'styling',
					'object',
					'group',
					array( 'rootClass' => array( 'opaque-root' ) ),
					array(
						$this->property( 'Root Class', 'rootClass', 'array', 'class', array( 'opaque-root' ) ),
						$this->property(
							'Advanced',
							'advanced',
							'object',
							'group',
							null,
							array( $this->property( 'Tag', 'tag', 'string' ) )
						),
					)
				),
				$this->property(
					'Items',
					'items',
					'array',
					'repeater',
					null,
					array(
						$this->property( 'Label', 'label', 'string' ),
						$this->property( 'Item Class', 'itemClass', 'array', 'class' ),
					)
				),
				$this->property( 'Explicit Null', 'explicitNull', 'string', null, null ),
			),
			array( 'default', 'actions' )
		);

		self::assertSame( 'FeatureCard', $contract->component_key() );
		self::assertSame(
			array(
				'conditionalClass',
				'styling.rootClass',
				'items[].itemClass',
			),
			$contract->class_property_paths()
		);
		self::assertSame( array( 'default', 'actions' ), $contract->slots() );

		$condition = $contract->property_by_declaration_path( 'visibility' );
		self::assertSame( 'string/condition', $condition->property_contract()->type_key() );
		self::assertNull( $condition->value_path() );

		$conditional_class = $contract->property_by_declaration_path( 'visibility.conditionalClass' );
		self::assertSame( 'conditionalClass', $conditional_class->value_path() );
		self::assertSame( 'array/class', $conditional_class->property_contract()->type_key() );
		self::assertSame( $conditional_class, $contract->property_by_value_path( 'conditionalClass' ) );

		$repeater_class = $contract->property_by_value_path( 'items[].itemClass' );
		self::assertSame( 'items.itemClass', $repeater_class->declaration_path() );
		self::assertFalse( $repeater_class->has_default() );

		$explicit_null = $contract->property_by_value_path( 'explicitNull' );
		self::assertTrue( $explicit_null->has_default() );
		self::assertNull( $explicit_null->default_value() );

		$tag = $contract->property_by_value_path( 'styling.advanced.tag' );
		self::assertSame( 'string', $tag->property_contract()->type_key() );
		self::assertFalse( $tag->has_default() );

		self::assertSame(
			array(
				'component_key'        => 'FeatureCard',
				'properties'           => array_map(
					static fn ( $property ): array => $property->to_array(),
					$contract->properties()
				),
				'slots'                => array( 'default', 'actions' ),
				'class_property_paths' => array(
					'conditionalClass',
					'styling.rootClass',
					'items[].itemClass',
				),
				'status'               => 'pending',
				'recipe_ids'           => array(),
			),
			$contract->to_array()
		);
	}

	public function test_catalog_is_deterministic_and_rejects_duplicate_component_keys(): void {
		$alpha   = ComponentContract::from_schema( 'Alpha', array(), array() );
		$beta    = ComponentContract::from_schema( 'Beta', array(), array( 'default' ) );
		$catalog = ComponentContractCatalog::from_contracts( $alpha, $beta );

		self::assertTrue( $catalog->has( 'Alpha' ) );
		self::assertFalse( $catalog->has( 'Missing' ) );
		self::assertSame( $beta, $catalog->contract( 'Beta' ) );
		self::assertSame( array( $alpha, $beta ), $catalog->all() );
		self::assertSame(
			array(
				'components' => array( $alpha->to_array(), $beta->to_array() ),
			),
			$catalog->to_array()
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate component key "Alpha"' );

		ComponentContractCatalog::from_contracts( $alpha, $alpha );
	}

	public function test_contract_projects_the_actual_typed_builder_schema(): void {
		$component = Component::new( 'Typed Contract', 'Produces the canonical Etch property schema.' )
			->key( 'TypedContract' )
			->prop(
				GroupProperty::new( 'Styling' )
					->key( 'styling' )
					->prop( ClassProperty::new( 'Root Class' )->key( 'rootClass' ) )
			)
			->prop(
				ConditionProperty::new( 'Visibility' )
					->key( 'visibility' )
					->prop( BooleanProperty::new( 'Enabled' )->key( 'enabled' )->default( true ) )
			)
			->prop(
				RepeaterGroupProperty::new( 'Items' )
					->key( 'items' )
					->prop( StringProperty::new( 'Label' )->key( 'label' ) )
					->prop( ClassProperty::new( 'Item Class' )->key( 'itemClass' ) )
			);

		$contract = ComponentContract::from_schema(
			$component->get_key(),
			$component->get_properties(),
			array( 'default', 'aside', 'default' )
		);

		self::assertSame( array( 'default', 'aside' ), $contract->slots() );
		self::assertSame( array( 'styling.rootClass', 'items[].itemClass' ), $contract->class_property_paths() );
		self::assertSame( 'boolean', $contract->property_by_value_path( 'enabled' )->property_contract()->type_key() );
		self::assertTrue( $contract->property_by_value_path( 'enabled' )->default_value() );
		self::assertSame( 'array/repeater', $contract->property_by_value_path( 'items' )->property_contract()->type_key() );
	}

	/**
	 * @dataProvider invalid_schema_provider
	 * @param array<int, array<string, mixed>> $properties Property schema.
	 */
	public function test_malformed_or_unsupported_schema_fails_closed( array $properties, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		ComponentContract::from_schema( 'Broken', $properties, array() );
	}

	/**
	 * @return array<string, array{array<int, array<string, mixed>>, string}>
	 */
	public function invalid_schema_provider(): array {
		return array(
			'invented primitive' => array(
				array( $this->property( 'URL', 'url', 'url' ) ),
				'Unsupported Etch property type pair "url"',
			),
			'invented specialization' => array(
				array( $this->property( 'Number', 'number', 'string', 'number' ) ),
				'Unsupported Etch property type pair "string/number"',
			),
			'missing key' => array(
				array( array( 'name' => 'Missing', 'type' => array( 'primitive' => 'string' ) ) ),
				'must declare a non-empty string key',
			),
			'missing type' => array(
				array( array( 'name' => 'Missing', 'key' => 'missing' ) ),
				'must declare a type object',
			),
			'structural type missing properties' => array(
				array( $this->property( 'Group', 'group', 'object', 'group' ) ),
				'must declare a list of child properties',
			),
			'leaf with child properties' => array(
				array( $this->property( 'Title', 'title', 'string', null, null, array() ) ),
				'cannot declare child properties',
			),
			'duplicate direct key' => array(
				array(
					$this->property( 'First', 'same', 'string' ),
					$this->property( 'Second', 'same', 'boolean' ),
				),
				'duplicate declaration path "same"',
			),
			'ambiguous transparent condition child' => array(
				array(
					$this->property( 'Direct', 'enabled', 'boolean' ),
					$this->property(
						'Condition',
						'visibility',
						'string',
						'condition',
						null,
						array( $this->property( 'Nested', 'enabled', 'string' ) )
					),
				),
				'ambiguous value path "enabled"',
			),
			'mutable object default' => array(
				array( $this->property( 'Object', 'object', 'object', null, new \stdClass() ) ),
				'default must contain only persisted scalar, null, or array data',
			),
		);
	}

	/**
	 * @dataProvider invalid_component_or_slots_provider
	 * @param array<int, mixed> $slots Slot names.
	 */
	public function test_invalid_component_keys_and_slots_fail_closed( string $component_key, array $slots, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		ComponentContract::from_schema( $component_key, array(), $slots );
	}

	/**
	 * @return array<string, array{string, array<int, mixed>, string}>
	 */
	public function invalid_component_or_slots_provider(): array {
		return array(
			'empty component key' => array( '', array(), 'component key' ),
			'invalid component key' => array( 'Not A Key', array(), 'component key' ),
			'empty slot' => array( 'Valid', array( '' ), 'non-empty string' ),
			'trimmed slot' => array( 'Valid', array( ' default ' ), 'non-empty exact string' ),
			'non-string slot' => array( 'Valid', array( 7 ), 'non-empty string' ),
		);
	}

	public function test_returned_arrays_do_not_mutate_contract_state(): void {
		$default  = array( 'nested' => array( 'value' => 'original' ) );
		$contract = ComponentContract::from_schema(
			'Immutable',
			array( $this->property( 'Settings', 'settings', 'object', null, $default ) ),
			array( 'default' )
		);

		$properties = $contract->properties();
		$slots      = $contract->slots();
		$payload    = $contract->to_array();

		$properties = array();
		$slots[0]   = 'changed';
		$payload['properties'][0]['default']['nested']['value'] = 'changed';

		self::assertCount( 1, $contract->properties() );
		self::assertSame( array( 'default' ), $contract->slots() );
		self::assertSame(
			array( 'nested' => array( 'value' => 'original' ) ),
			$contract->property_by_value_path( 'settings' )->default_value()
		);
	}

	public function test_referenced_default_arrays_are_detached_from_external_state(): void {
		$external = 'original';
		$default  = array( 'nested' => array( 'value' => &$external ) );
		$contract = ComponentContract::from_schema(
			'Detached',
			array( $this->property( 'Settings', 'settings', 'object', null, $default ) ),
			array()
		);

		$external = new \stdClass();
		$returned = $contract->property_by_value_path( 'settings' )->default_value();
		$returned['nested']['value'] = 'changed';

		self::assertSame(
			array( 'nested' => array( 'value' => 'original' ) ),
			$contract->property_by_value_path( 'settings' )->default_value()
		);
	}

	public function test_invalid_json_serializable_defaults_are_rejected_without_invoking_hooks(): void {
		$object = new ExplosiveJsonDefault();

		try {
			ComponentContract::from_schema(
				'NoHooks',
				array( $this->property( 'Settings', 'settings', 'object', null, array( 'object' => $object ) ) ),
				array()
			);
			self::fail( 'Expected an invalid persisted default to fail closed.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'persisted scalar, null, or array data', $exception->getMessage() );
		}

		self::assertSame( 0, $object->calls );
	}

	public function test_recursive_default_arrays_fail_closed(): void {
		$recursive         = array();
		$recursive['self'] = &$recursive;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'non-recursive persisted data' );

		ComponentContract::from_schema(
			'NoCycles',
			array( $this->property( 'Settings', 'settings', 'object', null, $recursive ) ),
			array()
		);
	}

	/**
	 * @dataProvider impossible_path_record_provider
	 */
	public function test_public_path_records_reject_impossible_states(
		string $declaration_path,
		?string $value_path,
		string $type,
		string $message
	): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		$type_parts = explode( '/', $type, 2 );
		new ComponentPropertyPathContract(
			$declaration_path,
			$value_path,
			PropertyContractMatrix::contract_for_type( $type_parts[0], $type_parts[1] ?? null ),
			false
		);
	}

	/**
	 * @return array<string, array{string, string|null, string, string}>
	 */
	public function impossible_path_record_provider(): array {
		return array(
			'writable condition' => array( 'visibility', 'visibility', 'string/condition', 'transparent condition' ),
			'leaf without value path' => array( 'title', null, 'string', 'must declare a value path' ),
			'repeater marker in declaration path' => array( 'items[].title', 'items[].title', 'string', 'declaration path cannot contain repeater markers' ),
			'renamed value path' => array( 'title', 'other', 'string', 'must be derived from its declaration path' ),
			'unrelated value parent' => array( 'styling.title', 'content.title', 'string', 'must be derived from its declaration path' ),
		);
	}

	public function test_public_path_records_reject_property_pairs_outside_the_matrix(): void {
		$invented = new PropertyContract(
			PropertyPrimitive::STRING,
			'invented',
			StringProperty::class,
			array( PropertyInstanceValueKind::STRING ),
			PropertyWireShape::PLAIN_STRING_ATTRIBUTE,
			PropertyContractStatus::SUPPORTED
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported Etch property type pair "string/invented"' );

		new ComponentPropertyPathContract( 'title', 'title', $invented, false );
	}

	public function test_public_path_records_replace_forged_semantics_with_the_canonical_matrix_contract(): void {
		$forged = new PropertyContract(
			PropertyPrimitive::STRING,
			'condition',
			StringProperty::class,
			array( PropertyInstanceValueKind::STRING ),
			PropertyWireShape::PLAIN_STRING_ATTRIBUTE,
			PropertyContractStatus::SUPPORTED
		);

		$record = new ComponentPropertyPathContract( 'visibility', null, $forged, false );

		self::assertSame(
			PropertyContractMatrix::contract_for_type( 'string', 'condition' ),
			$record->property_contract()
		);
	}

	/**
	 * Build one Etch component property schema record.
	 *
	 * Passing six arguments records an explicit default, including null.
	 *
	 * @param array<int, array<string, mixed>>|null $children Child properties.
	 * @return array<string, mixed>
	 */
	private function property(
		string $name,
		string $key,
		string $primitive,
		?string $specialized = null,
		mixed $default = null,
		?array $children = null
	): array {
		$type = array( 'primitive' => $primitive );
		if ( null !== $specialized ) {
			$type['specialized'] = $specialized;
		}

		$property = array(
			'name' => $name,
			'key'  => $key,
			'type' => $type,
		);

		if ( func_num_args() >= 5 ) {
			$property['default'] = $default;
		}

		if ( null !== $children ) {
			$property['properties'] = $children;
		}

		return $property;
	}
}

/**
 * Invalid default proving that catalog validation never dispatches object hooks.
 */
final class ExplosiveJsonDefault implements \JsonSerializable {

	public int $calls = 0;

	public function jsonSerialize(): mixed {
		++$this->calls;
		throw new \RuntimeException( 'This hook must never run.' );
	}
}
