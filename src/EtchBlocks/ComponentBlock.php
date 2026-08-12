<?php
/**
 * Type-safe builder for etch/component block.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\ClassStyleDiagnostic;
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\ClassStyleSet;
use HonestlyDesign\EtchBuilders\ComponentProperties\ComponentInstanceValue;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasBlockBase;
use HonestlyDesign\EtchBuilders\EtchBlocks\Concerns\HasChildren;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\EtchJsonAttribute;
use HonestlyDesign\EtchBuilders\Types\Attributes;
use HonestlyDesign\EtchBuilders\Types\BlockBase;

/**
 * Builds etch/component block with consistent fluent API.
 *
 * Pattern:
 *   ComponentBlock::new()
 *     ->ref(123)
 *     ->refByKey('Accordion')
 *     ->attribute('class', 'my-class')
 *     ->child($slotContent)
 *     ->to_block();
 */
final class ComponentBlock implements EtchBlockBuilderInterface {
	use HasBlockBase;
	use HasChildren;

	/**
	 * Component reference ID.
	 *
	 * @var int
	 */
	private int $ref = 0;

	/**
	 * Component key retained by the schema-backed authoring lane.
	 */
	private ?string $component_key = null;

	/**
	 * Schema-backed instance assignments, initialized only for keyed blocks.
	 */
	private ?ComponentInstanceValues $instance_values = null;

	/**
	 * Schema-backed instance slot assignments, initialized only for keyed blocks.
	 */
	private ?ComponentInstanceSlots $instance_slots = null;

	/**
	 * HTML attributes.
	 *
	 * @var Attributes
	 */
	private Attributes $attributes;

	/**
	 * Base block attributes.
	 *
	 * @var BlockBase
	 */
	private BlockBase $base;

	/**
	 * Child blocks.
	 *
	 * @var array<int, Block>
	 */
	private array $children = array();

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attributes = Attributes::new();
		$this->base       = BlockBase::new();
	}

	/**
	 * Create a new ComponentBlock builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Create the Golden Path block for one exact catalog component key.
	 */
	public static function for_key( string $component_key ): self {
		$block = self::new()->ref_by_key( $component_key );
		$block->instance_values = ComponentInstanceValues::for_component(
			$component_key,
			Environment::component_contracts()
		);
		$block->instance_slots = ComponentInstanceSlots::for_component(
			$component_key,
			Environment::component_contracts()
		);

		return $block;
	}

	/**
	 * Set the component reference ID (required).
	 *
	 * @param int $ref The component reference ID.
	 */
	public function ref( int $ref ): self {
		if ( null !== $this->instance_values || null !== $this->instance_slots ) {
			throw new InvalidArgumentException( 'A schema-backed ComponentBlock cannot replace its key-derived component ref.' );
		}

		$this->ref = $ref;
		$this->component_key = null;
		return $this;
	}

	/**
	 * Set the component reference by component key.
	 *
	 * Looks up the ref automatically via Environment::ref_resolver().
	 *
	 * @param string $component_key Component key (e.g., 'Accordion').
	 * @throws InvalidArgumentException When component is not found.
	 */
	public function ref_by_key( string $component_key ): self {
		if ( ( null !== $this->instance_values && $component_key !== $this->instance_values->component_key() )
			|| ( null !== $this->instance_slots && $component_key !== $this->instance_slots->component_key() )
		) {
			throw new InvalidArgumentException( 'A schema-backed ComponentBlock cannot switch to a different component key.' );
		}

		$ref = Environment::ref_resolver()->ref_by_key( $component_key );

		if ( 0 === $ref ) {
			throw new InvalidArgumentException(
				'Component not found for key: ' . $component_key
			);
		}

		$this->ref = $ref;
		$this->component_key = $component_key;
		return $this;
	}

	/**
	 * Set one exact schema-backed component instance value path.
	 */
	public function prop_value( string $path, ComponentInstanceValue $value ): self {
		if ( null === $this->component_key ) {
			throw new InvalidArgumentException(
				'Schema-backed component prop authoring requires for_key() or ref_by_key() so an exact Component Contract can be resolved.'
			);
		}

		$this->instance_values ??= ComponentInstanceValues::for_component(
			$this->component_key,
			Environment::component_contracts()
		);
		$this->instance_values->set( $path, $value );

		return $this;
	}

	/**
	 * Add a single HTML attribute.
	 *
	 * @param string      $name  Attribute name.
	 * @param string|null $value Attribute value.
	 */
	public function attribute( string $name, ?string $value ): self {
		if ( null !== $value ) {
			$this->attributes->add( $name, $value );
		}
		return $this;
	}

	/**
	 * Add a JSON-encoded attribute with Etch double-brace escaping.
	 *
	 * @param string                         $name  Attribute name.
	 * @param array<int|string, mixed>|string $value PHP array or pre-encoded JSON string.
	 */
	public function json_attribute( string $name, array|string $value ): self {
		$this->attributes->add( $name, EtchJsonAttribute::encode_value( $value ) );
		return $this;
	}

	/**
	 * Set a plain string component prop.
	 *
	 * @param string $key Prop key.
	 * @param string $value Prop value.
	 */
	public function prop_string( string $key, string $value ): self {
		return $this->set_prop_value( $key, ComponentPropValueEncoder::string( $value ) );
	}

	/**
	 * Set a boolean component prop.
	 *
	 * @param string $key Prop key.
	 * @param bool   $value Prop value.
	 */
	public function prop_boolean( string $key, bool $value ): self {
		return $this->set_prop_value( $key, ComponentPropValueEncoder::boolean( $value ) );
	}

	/**
	 * Set an expression component prop.
	 *
	 * @param string $key Prop key.
	 * @param string $expression Expression without surrounding braces.
	 */
	public function prop_expression( string $key, string $expression ): self {
		return $this->set_prop_value( $key, ComponentPropValueEncoder::expression( $expression ) );
	}

	/**
	 * Set a raw component prop string.
	 *
	 * @param string $key Prop key.
	 * @param string $value Stored prop string.
	 */
	public function prop_raw( string $key, string $value ): self {
		return $this->set_prop_value( $key, $value );
	}

	/**
	 * Set an object component prop.
	 *
	 * Primitive object props use the wrapped Etch object format so authored
	 * JSON values follow the same hydration path as grouped props.
	 *
	 * @param string                                           $key Prop key.
	 * @param array<string, mixed>|array<int, mixed>|\stdClass $value Object-like value.
	 */
	public function prop_object( string $key, array|\stdClass $value ): self {
		return $this->set_prop_value( $key, ComponentPropValueEncoder::group( (array) $value ) );
	}

	/**
	 * Set an array component prop.
	 *
	 * @param string             $key Prop key.
	 * @param ComponentPropArray $prop_array Array value.
	 */
	public function prop_array( string $key, ComponentPropArray $prop_array ): self {
		return $this->set_prop_value( $key, $prop_array->encode() );
	}

	/**
	 * Set a typed class component prop from validated ordered references.
	 *
	 * This is the Golden Path for top-level class properties. ClassStyleSet::none()
	 * writes an explicit empty override rather than omitting the property.
	 *
	 * @param string        $key Prop key.
	 * @param ClassStyleSet $classes Validated ordered class-style value.
	 */
	public function class_prop( string $key, ClassStyleSet $classes ): self {
		return $this->set_prop_value( $key, ComponentPropValueEncoder::class( $classes->ids() ) );
	}

	/**
	 * Set a class component prop.
	 *
	 * Static values are opaque Etch style IDs and are looked up directly without
	 * selector resolution, registration, or any other style mutation. Dynamic
	 * values ({...}) and legacy runtime tokens (rt-*) retain their current
	 * pass-through behavior for backwards compatibility.
	 *
	 * @deprecated Use class_prop() with ClassStyleSet for validated typed values.
	 *
	 * @param string             $key Prop key.
	 * @param array<int, string> $class_names Style IDs or pass-through dynamic values.
	 * @return self
	 * @throws InvalidArgumentException When a static value is not a registered class-style ID.
	 */
	public function prop_class( string $key, array $class_names ): self {
		ClassStyleDiagnostic::emit_deprecation(
			ClassStyleDiagnostic::DESTRUCTIVE_LEGACY_CALL,
			'ComponentBlock::prop_class() uses the deprecated legacy raw class-property lane.',
			'Build ClassStyleReference values, combine them with ClassStyleSet, and call ComponentBlock::class_prop().'
		);

		$resolved = array();

		foreach ( $class_names as $class_name ) {
			if ( ! is_string( $class_name ) || '' === trim( $class_name ) ) {
				throw new InvalidArgumentException( 'Class tokens must be non-empty strings.' );
			}

			if ( 1 === preg_match( '/^rt-/', $class_name ) ) {
				ClassStyleDiagnostic::emit_deprecation(
					ClassStyleDiagnostic::RUNTIME_TOKEN,
					sprintf( 'Runtime token "%s" is owned by Etch and is not a component Class Style ID.', $class_name ),
					'Put the token on an element HTML class through ElementBlock::class(); do not use it as a component class-property value.'
				);
				$resolved[] = $class_name;
				continue;
			}

			if ( ClassStyleRegistry::should_skip_class_token( $class_name ) ) {
				$resolved[] = $class_name;
				continue;
			}

			$resolved[] = ClassStyleRegistry::require_registered_class_style_id( $class_name );
		}

		return $this->set_prop_value( $key, ComponentPropValueEncoder::class( $resolved ) );
	}

	/**
	 * Set a grouped component prop.
	 *
	 * @param string             $key Prop key.
	 * @param ComponentPropGroup $group Group value.
	 */
	public function prop_group( string $key, ComponentPropGroup $group ): self {
		return $this->set_prop_value( $key, $group->encode() );
	}

	/**
	 * Set a repeater component prop.
	 *
	 * @param string                $key Prop key.
	 * @param ComponentPropRepeater $repeater Repeater value.
	 */
	public function prop_repeater( string $key, ComponentPropRepeater $repeater ): self {
		return $this->set_prop_value( $key, $repeater->encode() );
	}

	/**
	 * Set all HTML attributes at once.
	 *
	 * @param Attributes $attrs Attributes to set.
	 */
	public function attributes( Attributes $attrs ): self {
		$this->attributes = $attrs;
		return $this;
	}

	/**
	 * Register a single style and return its style ID.
	 *
	 * @param Style $style Style definition.
	 * @return string
	 */
	public function register_style( Style $style ): string {
		return $style->add();
	}

	/**
	 * Register multiple styles and return their style IDs.
	 *
	 * @param array<int, Style> $styles Style definitions.
	 * @return array<int, string>
	 * @throws InvalidArgumentException When a non-Style entry is provided.
	 */
	public function register_styles( array $styles ): array {
		$style_ids = array();

		foreach ( $styles as $style ) {
			if ( ! ( $style instanceof Style ) ) {
				throw new InvalidArgumentException( 'ComponentBlock::register_styles expects an array of Style instances.' );
			}

			$style_ids[] = $this->register_style( $style );
		}

		return $style_ids;
	}

	/**
	 * Assign one exact schema-backed filled component slot.
	 */
	public function slot( string $name, Block $first_child, Block ...$additional_children ): self {
		$this->schema_backed_slots()->set( $name, $first_child, ...$additional_children );

		return $this;
	}

	/**
	 * Assign the exact default component slot with content.
	 */
	public function default_slot( Block $first_child, Block ...$additional_children ): self {
		return $this->slot( 'default', $first_child, ...$additional_children );
	}

	/**
	 * Assign one exact schema-backed slot as explicitly empty.
	 */
	public function empty_slot( string $name ): self {
		$this->schema_backed_slots()->set_empty( $name );

		return $this;
	}

	/**
	 * Assign the exact default component slot as explicitly empty.
	 */
	public function empty_default_slot(): self {
		return $this->empty_slot( 'default' );
	}

	/**
	 * Add an empty slot-content block for the default slot.
	 *
	 * Use this when a component has a default slot but you're not providing
	 * any content. This ensures the Etch runtime correctly evaluates
	 * `slots.default.empty = true` for conditional fallbacks.
	 *
	 * @deprecated Use empty_default_slot() on a keyed component for schema validation.
	 *
	 * @return self
	 */
	public function with_empty_default_slot(): self {
		return $this->with_empty_slot( 'default' );
	}

	/**
	 * Add an empty slot-content block for a named slot.
	 *
	 * Use this when a component has slots but you're not providing
	 * any content. This ensures the Etch runtime correctly evaluates
	 * slot emptiness for conditional fallbacks.
	 *
	 * @deprecated Use empty_slot() on a keyed component for schema validation.
	 *
	 * @param string $name Slot name (defaults to 'default').
	 * @return self
	 */
	public function with_empty_slot( string $name = 'default' ): self {
		$this->children[] = SlotContentBlock::new()
			->name( $name )
			->to_block();
		return $this;
	}

	/**
	 * Require the schema-backed slot manager for this keyed component.
	 */
	private function schema_backed_slots(): ComponentInstanceSlots {
		if ( null === $this->component_key ) {
			throw new InvalidArgumentException(
				'Schema-backed component slot authoring requires for_key() or ref_by_key() so an exact Component Contract can be resolved.'
			);
		}

		$this->instance_slots ??= ComponentInstanceSlots::for_component(
			$this->component_key,
			Environment::component_contracts()
		);

		return $this->instance_slots;
	}

	/**
	 * Set one stored component prop value.
	 *
	 * @param string $key Stored prop key.
	 * @param string $value Stored prop value.
	 * @throws InvalidArgumentException When key is empty.
	 */
	private function set_prop_value( string $key, string $value ): self {
		$normalized_key = trim( $key );
		if ( '' === $normalized_key ) {
			throw new InvalidArgumentException( 'ComponentBlock prop keys must be non-empty.' );
		}

		$this->attributes->add( $normalized_key, $value );
		return $this;
	}

	/**
	 * Build and return the Block.
	 *
	 * @return Block
	 * @throws InvalidArgumentException When ref is not set.
	 */
	public function to_block(): Block {
		if ( 0 === $this->ref ) {
			throw new InvalidArgumentException(
				'ComponentBlock requires a ref. Use ->ref() or ->ref_by_key() before to_block().'
			);
		}

		$component_attributes = $this->attributes->to_array();
		if ( null !== $this->instance_values ) {
			foreach ( $this->instance_values->encode_attributes() as $key => $value ) {
				if ( array_key_exists( $key, $component_attributes ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Schema-backed component root "%s" conflicts with an existing raw or legacy component attribute.', $key )
					);
				}
				$component_attributes[ $key ] = $value;
			}
		}

		$block_attrs = array_merge(
			array(
				'ref'        => $this->ref,
				'attributes' => $component_attributes,
			),
			$this->base->to_array()
		);

		if ( null !== $this->instance_slots && $this->instance_slots->has_assignments() ) {
			if ( array() !== $this->children ) {
				throw new InvalidArgumentException(
					'A ComponentBlock cannot mix schema-backed slots with legacy direct component children; put every intended child inside slot() or default_slot().'
				);
			}
		}

		$block = Block::new( 'component', $block_attrs );

		foreach ( $this->children as $child ) {
			$block->add_child( $child );
		}

		if ( null !== $this->instance_slots ) {
			$block->add_children( $this->instance_slots->to_blocks() );
		}

		return $block;
	}
}
