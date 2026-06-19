<?php
/**
 * Group property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized;

use HonestlyDesign\EtchBuilders\ComponentProperties\Contracts\ComponentPropertyInterface;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\ObjectDefaultNormalizer;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

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
	 * Nested child properties keyed by prop key.
	 *
	 * @var array<string, ComponentPropertyInterface>
	 */
	private array $properties = array();

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
		$this->default_value = ObjectDefaultNormalizer::normalize( $value, 'Group property' );
		$this->has_default   = true;
		return $this;
	}

	/**
	 * Add a nested property to the group.
	 *
	 * @param ComponentPropertyInterface $property Nested property builder.
	 */
	public function prop( ComponentPropertyInterface $property ): self {
		$this->properties[ $property->get_key() ] = $property;
		return $this;
	}

	/**
	 * Returns the object primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::OBJECT;
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
}
