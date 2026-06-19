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
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
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
	 * Set the component reference ID (required).
	 *
	 * @param int $ref The component reference ID.
	 */
	public function ref( int $ref ): self {
		$this->ref = $ref;
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
		$ref = Environment::ref_resolver()->ref_by_key( $component_key );

		if ( 0 === $ref ) {
			throw new InvalidArgumentException(
				'Component not found for key: ' . $component_key
			);
		}

		$this->ref = $ref;
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
	 * Set a class component prop.
	 *
	 * Tokens are resolved to canonical Etch style IDs before encoding,
	 * matching what Etch's ClassProperty::resolve_value expects at render.
	 * Dynamic tokens ({...}) and runtime tokens (rt-*) pass through untouched
	 * in their original position; non-skipped tokens are validated and
	 * auto-registered as type=class styles when missing.
	 *
	 * @param string             $key Prop key.
	 * @param array<int, string> $class_names Class tokens to resolve to style IDs.
	 * @return self
	 * @throws InvalidArgumentException When a non-dynamic, non-runtime token cannot resolve.
	 */
	public function prop_class( string $key, array $class_names ): self {
		$resolved = array();

		foreach ( $class_names as $class_name ) {
			if ( ! is_string( $class_name ) || '' === trim( $class_name ) ) {
				throw new InvalidArgumentException( 'Class tokens must be non-empty strings.' );
			}

			if ( ClassStyleRegistry::should_skip_class_token( $class_name ) ) {
				$resolved[] = $class_name;
				continue;
			}

			// ensure_registered_for_class throws InvalidArgumentException for tokens that
			// cannot be used as style IDs (invalid chars) and auto-registers valid ones.
			$resolved[] = ClassStyleRegistry::ensure_registered_for_class( $class_name );
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
	 * Add an empty slot-content block for the default slot.
	 *
	 * Use this when a component has a default slot but you're not providing
	 * any content. This ensures the Etch runtime correctly evaluates
	 * `slots.default.empty = true` for conditional fallbacks.
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

		$block_attrs = array_merge(
			array(
				'ref'        => $this->ref,
				'attributes' => $this->attributes->to_array(),
			),
			$this->base->to_array()
		);

		$block = Block::new( 'component', $block_attrs );

		foreach ( $this->children as $child ) {
			$block->add_child( $child );
		}

		return $block;
	}
}
