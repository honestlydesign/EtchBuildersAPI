<?php
/**
 * Recursive class-property default tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\ClassStyleSet;
use HonestlyDesign\EtchBuilders\Component;
use HonestlyDesign\EtchBuilders\ComponentProperties\Contracts\ComponentPropertyInterface;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\NumberProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\StringProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ClassProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ConditionProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\GroupProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\RepeaterGroupProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\WpMediaIdProperty;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies exact and recursive defaults for Etch class properties.
 */
final class ComponentPropertyClassDefaultTest extends TestCase {

	/**
	 * @var array{
	 *     registry: array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>,
 *     claimed_identities: array<array-key, array{selector: string, type: string, collection: string}>,
 *     retained_persisted_identities: array<array-key, array{selector: string, type: string, collection: string}>
	 * }
	 */
	private array $style_state;

	private ComponentPropertyDefaultStorage $storage;

	protected function setUp(): void {
		parent::setUp();

		$this->style_state = Style::snapshot_state();
		$this->storage     = new ComponentPropertyDefaultStorage();

		Style::reset();
		Environment::configure( $this->storage, new NullMode(), new NullAssetRegistry() );
		ClassStyleRegistry::reset_cache();
	}

	protected function tearDown(): void {
		ClassStyleRegistry::reset_cache();
		Environment::reset();
		Style::restore_state( $this->style_state );

		parent::tearDown();
	}

	public function test_typed_default_preserves_opaque_id_order_and_explicit_empty_value(): void {
		$this->storage->replace_styles(
			array(
				'first-opaque-id'  => $this->class_style( '.first-visual-class' ),
				'second-opaque-id' => $this->class_style( '.second-visual-class' ),
			)
		);

		$first  = ClassStyleReference::registered( 'first-opaque-id' );
		$second = ClassStyleReference::registered( 'second-opaque-id' );

		$ordered = ClassProperty::new( 'Classes' )
			->default_classes( ClassStyleSet::of( $second, $first ) )
			->to_array();
		$empty   = ClassProperty::new( 'Classes' )
			->default_classes( ClassStyleSet::none() )
			->to_array();

		self::assertSame( array( 'second-opaque-id', 'first-opaque-id' ), $ordered['default'] );
		self::assertSame( array(), $empty['default'] );
		self::assertSame( 0, $this->storage->set_calls );
		self::assertSame( 0, $this->storage->delete_calls );
	}

	public function test_legacy_array_default_remains_wire_compatible_for_exact_opaque_ids(): void {
		$this->storage->replace_styles(
			array(
				'first-opaque-id'  => $this->class_style( '.first-visual-class' ),
				'second-opaque-id' => $this->class_style( '.second-visual-class' ),
			)
		);

		$payload = ClassProperty::new( 'Classes' )
			->default( array( 'second-opaque-id', 'first-opaque-id' ) )
			->to_array();

		self::assertSame( array( 'second-opaque-id', 'first-opaque-id' ), $payload['default'] );
	}

	/**
	 * @dataProvider invalid_raw_default_provider
	 *
	 * @param array<string, mixed> $style Style record.
	 */
	public function test_raw_default_rejects_values_that_are_not_exact_class_style_ids(
		string $input,
		array $style,
		string $message
	): void {
		$this->storage->replace_styles( array( 'opaque-style-id' => $style ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		ClassProperty::new( 'Classes' )->default( array( $input ) );
	}

	/**
	 * @return array<string, array{string, array<string, mixed>, string}>
	 */
	public function invalid_raw_default_provider(): array {
		return array(
			'selector token instead of ID' => array(
				'visual-class',
				$this->class_style( '.visual-class' ),
				'visual-class',
			),
			'non-class style'              => array(
				'opaque-style-id',
				array( 'selector' => '#target', 'type' => 'id' ),
				'type=class',
			),
			'compound class selector'      => array(
				'opaque-style-id',
				$this->class_style( '.card:hover' ),
				'exactly one simple class selector',
			),
		);
	}

	public function test_final_serialization_rejects_late_replacement_of_validated_style_identity(): void {
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.original-class' ) )
		);

		$property = ClassProperty::new( 'Classes' )->default( array( 'opaque-style-id' ) );

		self::assertSame( array( 'opaque-style-id' ), $property->to_array()['default'] );

		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.replacement-class' ) )
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'changed selector identity from ".original-class" to ".replacement-class"' );

		$property->to_array();
	}

	public function test_final_serialization_rejects_late_invalid_type_or_selector(): void {
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.original-class' ) )
		);

		$non_class = ClassProperty::new( 'Classes' )->default( array( 'opaque-style-id' ) );
		$this->storage->replace_styles(
			array( 'opaque-style-id' => array( 'selector' => '#target', 'type' => 'id' ) )
		);

		try {
			$non_class->to_array();
			self::fail( 'A late non-class replacement must fail.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'type=class', $exception->getMessage() );
		}

		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.original-class' ) )
		);
		$compound = ClassProperty::new( 'Classes' )->default( array( 'opaque-style-id' ) );
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.original-class:hover' ) )
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'exactly one simple class selector' );

		$compound->to_array();
	}

	public function test_group_default_applies_child_rules_recursively_without_mutating_schema_children(): void {
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.visual-class' ) )
		);

		$class_child = ClassProperty::new( 'Root Classes' )->key( 'rootClasses' );
		$group       = GroupProperty::new( 'Styling' )
			->key( 'styling' )
			->default(
				array(
					'rootClasses' => ClassStyleSet::of(
						ClassStyleReference::registered( 'opaque-style-id' )
					),
					'label'       => 'Ready',
				)
			)
			->prop( $class_child )
			->prop( StringProperty::new( 'Label' )->key( 'label' ) );

		$payload = $group->to_array();

		self::assertSame(
			array( 'rootClasses' => array( 'opaque-style-id' ), 'label' => 'Ready' ),
			$payload['default']
		);
		self::assertArrayNotHasKey( 'default', $class_child->to_array() );
		self::assertSame( 0, $this->storage->set_calls );
		self::assertSame( 0, $this->storage->delete_calls );
	}

	public function test_group_default_can_be_declared_before_children_and_rejects_unknown_keys_at_serialization(): void {
		$group = GroupProperty::new( 'Styling' )
			->default( array( 'guessedClassPath' => array() ) )
			->prop( ClassProperty::new( 'Root Classes' )->key( 'rootClasses' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unknown child property key "guessedClassPath"' );

		$group->to_array();
	}

	public function test_failed_group_default_validation_does_not_cache_a_partial_success(): void {
		$group = GroupProperty::new( 'Styling' )
			->default(
				array(
					'label'       => 'Ready',
					'guessedPath' => 'invalid',
				)
			)
			->prop( StringProperty::new( 'Label' )->key( 'label' ) );

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			try {
				$group->to_array();
				self::fail( 'Every serialization attempt must reject the unknown child key.' );
			} catch ( InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'unknown child property key "guessedPath"', $exception->getMessage() );
			}
		}
	}

	public function test_adding_an_unrelated_child_does_not_discard_existing_class_identity_proof(): void {
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.original-class' ) )
		);

		$group = GroupProperty::new( 'Styling' )
			->default( array( 'rootClasses' => array( 'opaque-style-id' ) ) )
			->prop( ClassProperty::new( 'Root Classes' )->key( 'rootClasses' ) );

		$group->to_array();
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.replacement-class' ) )
		);
		$group->prop( StringProperty::new( 'Label' )->key( 'label' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'changed selector identity from ".original-class" to ".replacement-class"' );

		$group->to_array();
	}

	public function test_group_default_validation_uses_the_current_nested_child_schema(): void {
		$inner = GroupProperty::new( 'Inner' )
			->key( 'inner' )
			->prop( StringProperty::new( 'Value' )->key( 'value' ) );
		$outer = GroupProperty::new( 'Outer' )
			->default( array( 'inner' => array( 'value' => 'string-value' ) ) )
			->prop( $inner );

		$outer->to_array();
		$inner->prop( NumberProperty::new( 'Value' )->key( 'value' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Number property default must be numeric.' );

		$outer->to_array();
	}

	public function test_group_default_validation_uses_each_childs_live_key(): void {
		$child = StringProperty::new( 'Value' )->key( 'oldKey' );
		$group = GroupProperty::new( 'Mutable Schema' )->prop( $child );

		$child->key( 'currentKey' );
		$group->default( array( 'currentKey' => 'value' ) );

		$payload = $group->to_array();

		self::assertSame( array( 'currentKey' => 'value' ), $payload['default'] );
		self::assertSame( 'currentKey', $payload['properties'][0]['key'] );
	}

	public function test_group_schema_rejects_duplicate_live_child_keys(): void {
		$first  = StringProperty::new( 'First' )->key( 'first' );
		$second = StringProperty::new( 'Second' )->key( 'second' );
		$group  = GroupProperty::new( 'Mutable Schema' )->prop( $first )->prop( $second );

		$second->key( 'first' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate current definition key "first"' );

		$group->to_array();
	}

	public function test_rekeyed_child_is_not_lost_when_its_old_key_is_reused(): void {
		$first = StringProperty::new( 'First' )->key( 'oldKey' );
		$group = GroupProperty::new( 'Mutable Schema' )->prop( $first );

		$first->key( 'currentKey' );
		$group
			->prop( StringProperty::new( 'Second' )->key( 'oldKey' ) )
			->default( array( 'currentKey' => 'first', 'oldKey' => 'second' ) );

		$payload = $group->to_array();

		self::assertSame( array( 'currentKey' => 'first', 'oldKey' => 'second' ), $payload['default'] );
		self::assertSame( array( 'currentKey', 'oldKey' ), array_column( $payload['properties'], 'key' ) );
	}

	public function test_failed_group_serialization_does_not_commit_new_class_identity_proofs(): void {
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.first-class' ) )
		);
		$external = new NonCloneableExternalProperty( 'externalValue', true );
		$group    = GroupProperty::new( 'Transactional' )
			->default(
				array(
					'rootClasses'  => array( 'opaque-style-id' ),
					'externalValue' => 'legacy-wire-value',
				)
			)
			->prop( ClassProperty::new( 'Root Classes' )->key( 'rootClasses' ) )
			->prop( $external );

		try {
			$group->to_array();
			self::fail( 'The first child serialization must fail.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'External property serialization failed.', $exception->getMessage() );
		}

		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.second-class' ) )
		);
		$external->allow_serialization();

		$payload = $group->to_array();
		self::assertSame( array( 'opaque-style-id' ), $payload['default']['rootClasses'] );
	}

	public function test_group_default_accepts_canonical_wp_media_id_wire_value_idempotently(): void {
		$group = GroupProperty::new( 'Media' )
			->default( array( 'imageId' => '42' ) )
			->prop( WpMediaIdProperty::new( 'Image ID' )->key( 'imageId' ) );

		$first  = $group->to_array();
		$second = $group->to_array();

		self::assertSame( array( 'imageId' => '42' ), $first['default'] );
		self::assertSame( $first, $second );
	}

	public function test_group_default_does_not_clone_or_mutate_an_external_property_implementation(): void {
		$external = new NonCloneableExternalProperty( 'externalValue' );
		$group    = GroupProperty::new( 'Compatibility' )
			->default( array( 'externalValue' => 'legacy-wire-value' ) )
			->prop( $external );

		$payload = $group->to_array();

		self::assertSame( array( 'externalValue' => 'legacy-wire-value' ), $payload['default'] );
		self::assertSame( 0, $external->default_calls );
	}

	public function test_condition_children_are_transparent_and_serialize_shared_nested_children_once(): void {
		$external  = new NonCloneableExternalProperty( 'nestedValue' );
		$condition = ConditionProperty::new( 'Visibility' )
			->key( 'visibility' )
			->prop( $external );
		$group     = GroupProperty::new( 'Behavior' )
			->default( array( 'nestedValue' => 'legacy-wire-value' ) )
			->prop( $condition );

		$payload = $group->to_array();

		self::assertSame( array( 'nestedValue' => 'legacy-wire-value' ), $payload['default'] );
		self::assertSame( 1, $external->to_array_calls );
	}

	public function test_condition_transparently_validates_nested_typed_class_default(): void {
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.visual-class' ) )
		);
		$condition = ConditionProperty::new( 'Visibility' )
			->key( 'visibility' )
			->prop( ClassProperty::new( 'Root Classes' )->key( 'rootClasses' ) );
		$group     = GroupProperty::new( 'Behavior' )
			->default(
				array(
					'rootClasses' => ClassStyleSet::of(
						ClassStyleReference::registered( 'opaque-style-id' )
					),
				)
			)
			->prop( $condition );

		$payload = $group->to_array();

		self::assertSame( array( 'rootClasses' => array( 'opaque-style-id' ) ), $payload['default'] );
	}

	public function test_rekeyed_condition_child_is_not_lost_when_its_old_key_is_reused(): void {
		$first     = StringProperty::new( 'First' )->key( 'oldKey' );
		$condition = ConditionProperty::new( 'Visibility' )
			->key( 'visibility' )
			->prop( $first );

		$first->key( 'currentKey' );
		$condition->prop( StringProperty::new( 'Second' )->key( 'oldKey' ) );
		$group = GroupProperty::new( 'Behavior' )
			->default( array( 'currentKey' => 'first', 'oldKey' => 'second' ) )
			->prop( $condition );

		$payload = $group->to_array();

		self::assertSame( array( 'currentKey' => 'first', 'oldKey' => 'second' ), $payload['default'] );
		self::assertSame(
			array( 'currentKey', 'oldKey' ),
			array_column( $payload['properties'][0]['properties'], 'key' )
		);
	}

	public function test_group_rejects_condition_definition_key_collision_with_sibling(): void {
		$condition = ConditionProperty::new( 'Visibility' )->key( 'visibility' );
		$group     = GroupProperty::new( 'Behavior' )
			->prop( $condition )
			->prop( StringProperty::new( 'Label' )->key( 'label' ) );

		$condition->key( 'label' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate current definition key "label"' );

		$group->to_array();
	}

	public function test_condition_rejects_duplicate_nested_definition_keys_recursively(): void {
		$nested_condition = ConditionProperty::new( 'Nested Visibility' )->key( 'nestedVisibility' );
		$sibling          = StringProperty::new( 'Nested Label' )->key( 'nestedLabel' );
		$condition        = ConditionProperty::new( 'Visibility' )
			->key( 'visibility' )
			->prop( $nested_condition )
			->prop( $sibling );

		$nested_condition->key( 'nestedLabel' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate current child property key "nestedLabel"' );

		$condition->to_array();
	}

	public function test_failed_root_serialization_does_not_commit_nested_group_class_proofs(): void {
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.first-class' ) )
		);
		$nested = GroupProperty::new( 'Nested' )
			->key( 'nested' )
			->default( array( 'rootClasses' => array( 'opaque-style-id' ) ) )
			->prop( ClassProperty::new( 'Root Classes' )->key( 'rootClasses' ) );
		$external = new NonCloneableExternalProperty( 'laterSibling', true );
		$root     = GroupProperty::new( 'Root' )
			->prop( $nested )
			->prop( $external );

		try {
			$root->to_array();
			self::fail( 'The root serialization must fail after the nested group was visited.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'External property serialization failed.', $exception->getMessage() );
		}

		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.second-class' ) )
		);
		$external->allow_serialization();

		$payload = $root->to_array();
		self::assertSame( array( 'opaque-style-id' ), $payload['properties'][0]['default']['rootClasses'] );
	}

	public function test_failed_component_property_serialization_rolls_back_all_group_proofs(): void {
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.first-class' ) )
		);
		$group = GroupProperty::new( 'Styling' )
			->key( 'styling' )
			->default( array( 'rootClasses' => array( 'opaque-style-id' ) ) )
			->prop( ClassProperty::new( 'Root Classes' )->key( 'rootClasses' ) );
		$external  = new NonCloneableExternalProperty( 'laterSibling', true );
		$component = Component::new( 'Transactional Component', 'Transaction coverage.' )
			->prop( $group )
			->prop( $external );

		try {
			$component->get_properties();
			self::fail( 'The component serialization must fail after the group was visited.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'External property serialization failed.', $exception->getMessage() );
		}

		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.second-class' ) )
		);
		$external->allow_serialization();

		$properties = $component->get_properties();
		self::assertSame( array( 'opaque-style-id' ), $properties[0]['default']['rootClasses'] );
	}

	public function test_group_default_rejects_numeric_keys(): void {
		$group = GroupProperty::new( 'Styling' )
			->default( array( array( 'opaque-style-id' ) ) )
			->prop( ClassProperty::new( 'Root Classes' )->key( 'rootClasses' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'child property keys must be strings' );

		$group->to_array();
	}

	public function test_nested_group_default_validates_class_child(): void {
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.visual-class:hover' ) )
		);

		$group = GroupProperty::new( 'Outer' )
			->default(
				array(
					'inner' => array(
						'rootClasses' => array( 'opaque-style-id' ),
					),
				)
			)
			->prop(
				GroupProperty::new( 'Inner' )
					->key( 'inner' )
					->prop( ClassProperty::new( 'Root Classes' )->key( 'rootClasses' ) )
			);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'exactly one simple class selector' );

		$group->to_array();
	}

	public function test_typed_group_default_rejects_late_style_identity_replacement(): void {
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.original-class' ) )
		);

		$group = GroupProperty::new( 'Styling' )
			->default(
				array(
					'rootClasses' => ClassStyleSet::of(
						ClassStyleReference::registered( 'opaque-style-id' )
					),
				)
			)
			->prop( ClassProperty::new( 'Root Classes' )->key( 'rootClasses' ) );

		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.replacement-class' ) )
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'changed selector identity from ".original-class" to ".replacement-class"' );

		$group->to_array();
	}

	public function test_repeater_child_class_default_is_revalidated_at_final_serialization(): void {
		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.original-class' ) )
		);

		$repeater = RepeaterGroupProperty::new( 'Items' )
			->prop(
				ClassProperty::new( 'Item Classes' )
					->key( 'itemClasses' )
					->default_classes(
						ClassStyleSet::of( ClassStyleReference::registered( 'opaque-style-id' ) )
					)
			);

		$this->storage->replace_styles(
			array( 'opaque-style-id' => $this->class_style( '.replacement-class' ) )
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'changed selector identity from ".original-class" to ".replacement-class"' );

		$repeater->to_array();
	}

	public function test_group_default_rejects_a_value_for_repeater_child(): void {
		$group = GroupProperty::new( 'Content' )
			->default( array( 'items' => array() ) )
			->prop( RepeaterGroupProperty::new( 'Items' )->key( 'items' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Repeater property does not support a default.' );

		$group->to_array();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function class_style( string $selector ): array {
		return array(
			'selector'   => $selector,
			'type'       => 'class',
			'collection' => 'User styles',
			'css'        => 'color:red',
		);
	}
}

/**
 * Mutable storage spy for class-property default tests.
 */
final class ComponentPropertyDefaultStorage implements StorageInterface {

	public int $set_calls = 0;

	public int $delete_calls = 0;

	/**
	 * @var array<string, mixed>
	 */
	private array $values = array();

	/**
	 * Replace the simulated persisted Etch style registry without counting a Builder API write.
	 *
	 * @param array<string, mixed> $styles Styles keyed by opaque ID.
	 */
	public function replace_styles( array $styles ): void {
		$this->values['etch_styles'] = $styles;
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

/**
 * Compatibility fixture whose clone and default mutation paths must not be used.
 */
final class NonCloneableExternalProperty implements ComponentPropertyInterface {

	public int $default_calls = 0;

	public int $to_array_calls = 0;

	public function __construct( private string $key, private bool $throw_on_to_array = false ) {
	}

	private function __clone() {
	}

	public function get_name(): string {
		return 'External';
	}

	public function get_key(): string {
		return $this->key;
	}

	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::STRING;
	}

	public function name( string $name ): self {
		unset( $name );
		return $this;
	}

	public function key( string $key ): self {
		$this->key = $key;
		return $this;
	}

	public function key_touched( bool $touched ): self {
		unset( $touched );
		return $this;
	}

	public function prop_source_id( string $id ): self {
		unset( $id );
		return $this;
	}

	public function description( string $description ): self {
		unset( $description );
		return $this;
	}

	public function default( mixed $value ): self {
		unset( $value );
		++$this->default_calls;
		throw new InvalidArgumentException( 'External property default must not be called.' );
	}

	public function allow_serialization(): void {
		$this->throw_on_to_array = false;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		++$this->to_array_calls;

		if ( $this->throw_on_to_array ) {
			throw new InvalidArgumentException( 'External property serialization failed.' );
		}

		return array(
			'name' => 'External',
			'key'  => $this->key,
			'type' => array( 'primitive' => 'string' ),
		);
	}
}
