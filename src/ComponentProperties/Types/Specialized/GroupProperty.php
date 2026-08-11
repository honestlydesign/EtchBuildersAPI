<?php
/**
 * Group property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Contracts\ComponentPropertyInterface;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\ClassPropertyDefaultValue;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\ObjectDefaultNormalizer;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertySerializationTransaction;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\ArrayProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\BooleanProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\NumberProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\ObjectProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\StringProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ColorProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ConditionProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ImageProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\LoopProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\SelectProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\UrlProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\WpMediaIdProperty;

/**
 * Fluent builder for Etch group properties (specialized object).
 *
 * Usage in a component builder:
 *   Component::new( 'Example', 'Example description' )
 *     ->prop(
 *       GroupProperty::new( 'First Group' )
 *         ->key( 'firstGroup' )
 *         ->prop(
 *           StringProperty::new( 'Text' )
 *             ->key( 'text' )
 *             ->default( 'Hello' )
 *         )
 *     );
 *
 * Nested properties are referenced in block builders with dot notation,
 * for example: {props.firstGroup.text}.
 *
 * Because nested keys are scoped to their parent group, multiple groups can
 * reuse the same child key (for example "text" in both firstGroup and
 * secondGroup), but keys must still remain unique within each individual
 * group.
 *
 * When supplying grouped component instance values, boolean child values
 * should use Etch dynamic boolean strings (`{true}` / `{false}`) instead of
 * plain `'true'` / `'false'` strings. `ComponentBlock::prop_group()`
 * normalizes PHP booleans to that format automatically.
 */
final class GroupProperty extends BaseProperty {

	/**
	 * Nested child properties in declaration order.
	 *
	 * @var array<int, ComponentPropertyInterface>
	 */
	private array $properties = array();

	/**
	 * Class-default proofs keyed by their current nested property path.
	 *
	 * @var array<string, ClassPropertyDefaultValue>
	 */
	private array $default_class_proofs = array();

	/**
	 * Create a new group property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default group value.
	 *
	 * @param mixed $value Default group value.
	 */
	public function default( mixed $value ): self {
		$this->default_value        = ObjectDefaultNormalizer::normalize( $value, 'Group property' );
		$this->has_default          = true;
		$this->default_class_proofs = array();
		return $this;
	}

	/**
	 * Add a nested property to the group.
	 *
	 * @param ComponentPropertyInterface $property Nested property builder.
	 */
	public function prop( ComponentPropertyInterface $property ): self {
		foreach ( $this->properties as $index => $existing ) {
			if ( $existing->get_key() === $property->get_key() ) {
				$this->properties[ $index ] = $property;
				return $this;
			}
		}

		$this->properties[] = $property;
		return $this;
	}

	/**
	 * Returns the object primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::OBJECT;
	}

	/**
	 * Convert the group to an Etch schema with recursively validated defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return PropertySerializationTransaction::run(
			fn (): array => $this->serialize_group()
		);
	}

	/**
	 * Build the group payload and stage proof state for root commit.
	 *
	 * @return array<string, mixed>
	 */
	private function serialize_group(): array {
		$validated_default = null;
		$class_proofs      = $this->default_class_proofs;
		$properties        = $this->current_properties();

		if ( $this->has_default ) {
			$validation        = $this->validate_default( $properties );
			$validated_default = $validation['value'];
			$class_proofs      = $validation['class_proofs'];
		}

		$property = parent::to_array();
		if ( $this->has_default ) {
			$property['default'] = $validated_default;
			PropertySerializationTransaction::stage(
				$this,
				function () use ( $class_proofs ): void {
					$this->default_class_proofs = $class_proofs;
				}
			);
		}

		return $property;
	}

	/**
	 * Returns the group specialized discriminator.
	 */
	protected function get_specialized(): string {
		return 'group';
	}

	/**
	 * Builds group-only payload fields.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		$properties = array();

		foreach ( $this->properties as $property ) {
			$properties[] = $property->to_array();
		}

		return array(
			'properties' => $properties,
		);
	}

	/**
	 * Apply each declared child property's own default rules recursively.
	 *
	 * @return array{value: array<string, mixed>, class_proofs: array<string, ClassPropertyDefaultValue>}
	 * @throws InvalidArgumentException When a key or child default is invalid.
	 */
	private function validate_default( array $properties ): array {
		if ( ! is_array( $this->default_value ) ) {
			throw new InvalidArgumentException( 'Group property default must remain an array.' );
		}

		return $this->validate_group_value(
			$this->default_value,
			$properties,
			'',
			$this->default_class_proofs
		);
	}

	/**
	 * Validate one object-shaped value against the current group schema.
	 *
	 * @param array<string, mixed>|array<int, mixed>             $value Group default value.
	 * @param array<string, ComponentPropertyInterface>          $properties Current child schema.
	 * @param string                                             $path Parent property path.
	 * @param array<string, ClassPropertyDefaultValue>            $expected_proofs Prior class identity proofs.
	 * @return array{value: array<string, mixed>, class_proofs: array<string, ClassPropertyDefaultValue>}
	 */
	private function validate_group_value(
		array $value,
		array $properties,
		string $path,
		array $expected_proofs
	): array {
		$validated    = array();
		$class_proofs = array();

		foreach ( $value as $key => $child_value ) {
			if ( ! is_string( $key ) ) {
				throw new InvalidArgumentException( 'Group property default child property keys must be strings.' );
			}

			if ( ! isset( $properties[ $key ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Group property default references unknown child property key "%s".', $key )
				);
			}

			$property      = $properties[ $key ];
			$property_path = '' === $path ? $key : $path . '.' . $key;

			if ( $property instanceof ClassProperty ) {
				$prior = $expected_proofs[ $property_path ] ?? null;
				if ( null !== $prior ) {
					$prior->assert_current();
				}

				$current = ClassPropertyDefaultValue::from( $child_value );
				$proof   = null !== $prior && $prior->ids() === $current->ids() ? $prior : $current;

				$validated[ $key ]                  = $current->ids();
				$class_proofs[ $property_path ]     = $proof;
				continue;
			}

			if ( $property instanceof GroupProperty ) {
				$nested = $this->validate_group_value(
					ObjectDefaultNormalizer::normalize( $child_value, 'Group property' ),
					$property->current_properties(),
					$property_path,
					$expected_proofs
				);
				$validated[ $key ] = $nested['value'];
				foreach ( $nested['class_proofs'] as $nested_path => $nested_proof ) {
					$class_proofs[ $nested_path ] = $nested_proof;
				}
				continue;
			}

			if ( $property instanceof RepeaterGroupProperty ) {
				$property->default( $child_value );
			}

			$validated[ $key ] = $this->validate_leaf_default( $property, $child_value );
		}

		return array(
			'value'        => $validated,
			'class_proofs' => $class_proofs,
		);
	}

	/**
	 * Build the child lookup from live keys and reject ambiguous schema state.
	 *
	 * @return array<string, ComponentPropertyInterface>
	 */
	private function current_properties(): array {
		$properties = array();
		$this->assert_unique_definition_keys();

		foreach ( $this->properties as $property ) {
			if ( $property instanceof ConditionProperty ) {
				$this->add_transparent_condition_properties( $properties, $property );
				continue;
			}

			$this->add_current_property( $properties, $property );
		}

		return $properties;
	}

	/**
	 * Validate direct definition keys before flattening condition values.
	 */
	private function assert_unique_definition_keys(): void {
		$seen = array();

		foreach ( $this->properties as $property ) {
			$key = $property->get_key();
			if ( isset( $seen[ $key ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Group property has duplicate current definition key "%s".', $key )
				);
			}

			$seen[ $key ] = true;
			if ( $property instanceof ConditionProperty ) {
				$property->assert_unique_nested_keys();
			}
		}
	}

	/**
	 * Flatten condition children into the current group-value lookup.
	 *
	 * @param array<string, ComponentPropertyInterface> $properties Current lookup.
	 */
	private function add_transparent_condition_properties( array &$properties, ConditionProperty $condition ): void {
		foreach ( $condition->nested_properties() as $property ) {
			if ( $property instanceof ConditionProperty ) {
				$this->add_transparent_condition_properties( $properties, $property );
				continue;
			}

			$this->add_current_property( $properties, $property );
		}
	}

	/**
	 * Add one live child key to a flattened lookup and reject ambiguity.
	 *
	 * @param array<string, ComponentPropertyInterface> $properties Current lookup.
	 */
	private function add_current_property( array &$properties, ComponentPropertyInterface $property ): void {
		$key = $property->get_key();
		if ( isset( $properties[ $key ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Group property has duplicate current child property key "%s".', $key )
			);
		}

		$properties[ $key ] = $property;
	}

	/**
	 * Apply package-owned leaf rules without mutating or cloning external builders.
	 *
	 * Unknown ComponentPropertyInterface implementations retain their legacy wire
	 * value because the interface does not provide a pure validation contract.
	 *
	 * @param ComponentPropertyInterface $property Declared child property.
	 * @param mixed                      $value Default value.
	 * @return mixed Validated wire value.
	 */
	private function validate_leaf_default( ComponentPropertyInterface $property, mixed $value ): mixed {
		if ( $property instanceof WpMediaIdProperty && is_string( $value ) && 1 === preg_match( '/^(?:0|-?[1-9][0-9]*)$/', $value ) ) {
			return $value;
		}

		if ( ! $this->is_package_leaf_property( $property ) ) {
			return $value;
		}

		$validator = clone $property;
		$validator->default( $value );
		$serialized = $validator->to_array();

		if ( ! array_key_exists( 'default', $serialized ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Group child property "%s" did not serialize its validated default.', $property->get_key() )
			);
		}

		return $serialized['default'];
	}

	/**
	 * Whether a final package-owned leaf is safe to clone for pure validation.
	 */
	private function is_package_leaf_property( ComponentPropertyInterface $property ): bool {
		return $property instanceof ArrayProperty
			|| $property instanceof BooleanProperty
			|| $property instanceof NumberProperty
			|| $property instanceof ObjectProperty
			|| $property instanceof StringProperty
			|| $property instanceof ColorProperty
			|| $property instanceof ImageProperty
			|| $property instanceof LoopProperty
			|| $property instanceof SelectProperty
			|| $property instanceof UrlProperty
			|| $property instanceof WpMediaIdProperty;
	}
}
