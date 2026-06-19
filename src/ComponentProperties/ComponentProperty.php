<?php
/**
 * Component property builder facade for Etch schemas.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Contracts\ComponentPropertyInterface;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\ArrayProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\BooleanProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\NumberProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\ObjectProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\StringProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ColorProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ClassProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ConditionProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\GroupProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\ImageProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\LoopProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\RepeaterGroupProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\SelectProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\UrlProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized\WpMediaIdProperty;

/**
 * Generic Etch component property builder facade.
 *
 * Provides backward compatibility with legacy array config while enabling
 * the new fluent builder pattern for component properties.
 *
 * Legacy array usage:
 *   new ComponentProperty([
 *     'name' => 'Type',
 *     'key' => 'type',
 *     'type' => ['primitive' => 'string', 'specialized' => 'select'],
 *     'default' => 'single',
 *     'options' => ['single', 'multiple'],
 *     'selectOptionsString' => "Single : single\nMultiple : multiple",
 *   ])
 *
 * New fluent API usage:
 *   ComponentProperty::from(
 *     SelectProperty::new('Type')
 *       ->key('type')
 *       ->option_with_label('Single', 'single')
 *       ->option_with_label('Multiple', 'multiple')
 *       ->default('single')
 *   )
 */
final class ComponentProperty {

	/**
	 * Backing typed property instance.
	 *
	 * @var ComponentPropertyInterface
	 */
	private ?ComponentPropertyInterface $property = null;

	/**
	 * Private constructor - use factory methods instead.
	 */
	private function __construct() {}

	/**
	 * Creates a property builder from schema-shaped config (legacy support).
	 *
	 * @param array<string, mixed> $config Property configuration array.
	 * @throws InvalidArgumentException When config is invalid.
	 */
	public static function from_config( array $config ): self {
		$instance           = new self();
		$instance->property = $instance->create_from_config( $config );
		return $instance;
	}

	/**
	 * Creates a ComponentProperty wrapper from a typed property builder.
	 *
	 * @param ComponentPropertyInterface $property Typed property builder instance.
	 */
	public static function from( ComponentPropertyInterface $property ): self {
		$instance           = new self();
		$instance->property = $property;
		return $instance;
	}

	/**
	 * Gets the property display name.
	 */
	public function get_name(): string {
		$this->ensure_property();

		return $this->property->get_name();
	}

	/**
	 * Gets the property key.
	 */
	public function get_key(): string {
		$this->ensure_property();

		return $this->property->get_key();
	}

	/**
	 * Gets the property primitive.
	 */
	public function get_primitive(): PropertyPrimitive {
		$this->ensure_property();

		return $this->property->get_primitive();
	}

	/**
	 * Serializes the property to Etch schema format.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$this->ensure_property();

		return $this->property->to_array();
	}

	/**
	 * Assert that the facade has been initialized through a factory method.
	 *
	 * @phpstan-assert ComponentPropertyInterface $this->property
	 * @throws InvalidArgumentException When the facade is not initialized.
	 */
	private function ensure_property(): void {
		if ( null === $this->property ) {
			throw new InvalidArgumentException( 'ComponentProperty must be created through a factory method.' );
		}
	}

	/**
	 * Creates the appropriate typed property from legacy config array.
	 *
	 * @param array<string, mixed> $config Property configuration array.
	 * @throws InvalidArgumentException When config is invalid.
	 */
	private function create_from_config( array $config ): ComponentPropertyInterface {
		$primitive   = $this->extract_primitive( $config );
		$specialized = $this->extract_specialized( $config );
		$name        = $this->extract_name( $config );

		// Route to appropriate specialized or primitive type.
		if ( null !== $specialized ) {
			if ( PropertyPrimitive::STRING === $primitive ) {
				return $this->create_specialized_string( $name, $specialized, $config );
			}

			if ( PropertyPrimitive::ARRAY === $primitive ) {
				return $this->create_specialized_array( $name, $specialized, $config );
			}

			if ( PropertyPrimitive::OBJECT === $primitive ) {
				return $this->create_specialized_object( $name, $specialized, $config );
			}
		}

		return $this->create_primitive( $name, $primitive, $config );
	}

	/**
	 * Extracts primitive type from config.
	 *
	 * @param array<string, mixed> $config Property configuration array.
	 * @throws InvalidArgumentException When primitive is invalid.
	 */
	private function extract_primitive( array $config ): PropertyPrimitive {
		$raw_primitive = 'string';

		if ( array_key_exists( 'type', $config ) ) {
			if ( is_array( $config['type'] ) && isset( $config['type']['primitive'] ) && is_string( $config['type']['primitive'] ) ) {
				$raw_primitive = $config['type']['primitive'];
			} elseif ( is_string( $config['type'] ) ) {
				$raw_primitive = $config['type'];
			} else {
				throw new InvalidArgumentException( 'Property "type" must be a string or array with "primitive".' );
			}
		} elseif ( isset( $config['primitive'] ) && is_string( $config['primitive'] ) ) {
			$raw_primitive = $config['primitive'];
		}

		$primitive = PropertyPrimitive::tryFrom( $raw_primitive );
		if ( null === $primitive ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( 'Invalid property primitive value: ' . $raw_primitive );
		}

		return $primitive;
	}

	/**
	 * Extracts specialized type from config.
	 *
	 * @param array<string, mixed> $config Property configuration array.
	 * @return string|null
	 */
	private function extract_specialized( array $config ): ?string {
		if ( array_key_exists( 'type', $config ) && is_array( $config['type'] ) ) {
			if ( isset( $config['type']['specialized'] ) && is_string( $config['type']['specialized'] ) ) {
				return $config['type']['specialized'];
			}
		}

		if ( array_key_exists( 'specialized', $config ) && is_string( $config['specialized'] ) ) {
			return $config['specialized'];
		}

		return null;
	}

	/**
	 * Extracts name from config.
	 *
	 * @param array<string, mixed> $config Property configuration array.
	 * @throws InvalidArgumentException When name is missing or invalid.
	 */
	private function extract_name( array $config ): string {
		if ( ! isset( $config['name'] ) || ! is_string( $config['name'] ) ) {
			throw new InvalidArgumentException( 'Property requires "name" field.' );
		}

		$name = trim( $config['name'] );
		if ( '' === $name ) {
			throw new InvalidArgumentException( 'Property "name" cannot be empty.' );
		}

		return $name;
	}

	/**
	 * Creates a specialized string property.
	 *
	 * @param string               $name        Property name.
	 * @param string               $specialized Specialized type.
	 * @param array<string, mixed> $config      Property configuration.
	 * @throws InvalidArgumentException When specialized type is unknown.
	 */
	private function create_specialized_string( string $name, string $specialized, array $config ): ComponentPropertyInterface {
		$property = match ( $specialized ) {
			'color'     => ColorProperty::new( $name ),
			'condition' => $this->create_condition_property( $name, $config ),
			'array'     => LoopProperty::new( $name ),
			'url'       => UrlProperty::new( $name ),
			'image'     => ImageProperty::new( $name ),
			'select'    => $this->create_select_property( $name, $config ),
			'wpMediaId' => WpMediaIdProperty::new( $name ),
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			default     => throw new InvalidArgumentException( 'Unknown specialized type: ' . $specialized ),
		};

		return $this->apply_common_fields( $property, $config );
	}

	/**
	 * Creates a select property with options from config.
	 *
	 * @param string               $name   Property name.
	 * @param array<string, mixed> $config Property configuration.
	 */
	private function create_select_property( string $name, array $config ): SelectProperty {
		$property = SelectProperty::new( $name );

		// Handle selectOptionsString format for options.
		if ( array_key_exists( 'selectOptionsString', $config ) && is_string( $config['selectOptionsString'] ) ) {
			$lines = explode( "\n", $config['selectOptionsString'] );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}

				// Parse "label : value" or just "value".
				if ( 1 === preg_match( '/^(.+) : (.+)$/', $line, $parts ) ) {
					$property->option_with_label( trim( $parts[1] ), trim( $parts[2] ) );
				} else {
					$property->option( $line );
				}
			}
		}

		// Also handle simple options array.
		if ( array_key_exists( 'options', $config ) && is_array( $config['options'] ) ) {
			foreach ( $config['options'] as $option ) {
				if ( is_string( $option ) ) {
					$property->option( $option );
				}
			}
		}

		return $property;
	}

	/**
	 * Creates a specialized array property.
	 *
	 * @param string               $name        Property name.
	 * @param string               $specialized Specialized type.
	 * @param array<string, mixed> $config      Property configuration.
	 * @throws InvalidArgumentException When specialized type is unknown.
	 */
	private function create_specialized_array( string $name, string $specialized, array $config ): ComponentPropertyInterface {
		$property = match ( $specialized ) {
			'class'    => ClassProperty::new( $name ),
			'repeater' => $this->create_repeater_property( $name, $config ),
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			default    => throw new InvalidArgumentException( 'Unknown specialized type: ' . $specialized ),
		};

		return $this->apply_common_fields( $property, $config );
	}

	/**
	 * Creates a specialized object property.
	 *
	 * @param string               $name        Property name.
	 * @param string               $specialized Specialized type.
	 * @param array<string, mixed> $config      Property configuration.
	 * @throws InvalidArgumentException When specialized type is unknown.
	 */
	private function create_specialized_object( string $name, string $specialized, array $config ): ComponentPropertyInterface {
		$property = match ( $specialized ) {
			'group' => $this->create_group_property( $name, $config ),
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			default => throw new InvalidArgumentException( 'Unknown specialized type: ' . $specialized ),
		};

		return $this->apply_common_fields( $property, $config );
	}

	/**
	 * Creates a primitive property.
	 *
	 * @param string               $name      Property name.
	 * @param PropertyPrimitive    $primitive Primitive type.
	 * @param array<string, mixed> $config    Property configuration.
	 */
	private function create_primitive( string $name, PropertyPrimitive $primitive, array $config ): ComponentPropertyInterface {
		$property = match ( $primitive ) {
			PropertyPrimitive::STRING  => StringProperty::new( $name ),
			PropertyPrimitive::NUMBER  => NumberProperty::new( $name ),
			PropertyPrimitive::BOOLEAN => BooleanProperty::new( $name ),
			PropertyPrimitive::OBJECT  => ObjectProperty::new( $name ),
			PropertyPrimitive::ARRAY   => ArrayProperty::new( $name ),
		};

		// Apply number options if present.
		if ( PropertyPrimitive::NUMBER === $primitive && array_key_exists( 'options', $config ) && is_array( $config['options'] ) ) {
			$options = array();
			foreach ( $config['options'] as $option ) {
				if ( is_int( $option ) || is_float( $option ) ) {
					$options[] = $option;
				}
			}
			if ( array() !== $options && $property instanceof NumberProperty ) {
				$property->options( $options );
			}
		}

		return $this->apply_common_fields( $property, $config );
	}

	/**
	 * Creates a group property with recursively hydrated nested properties.
	 *
	 * @param string               $name   Property name.
	 * @param array<string, mixed> $config Property configuration.
	 * @throws InvalidArgumentException When nested group properties are invalid.
	 */
	private function create_group_property( string $name, array $config ): GroupProperty {
		$property          = GroupProperty::new( $name );
		$nested_properties = $this->extract_nested_property_configs( $config, 'Group property' );

		foreach ( $nested_properties as $nested_property ) {
			$property->prop( $this->create_from_config( $nested_property ) );
		}

		return $property;
	}

	/**
	 * Creates a condition property with recursively hydrated nested properties.
	 *
	 * @param string               $name   Property name.
	 * @param array<string, mixed> $config Property configuration.
	 * @throws InvalidArgumentException When nested condition properties are invalid.
	 */
	private function create_condition_property( string $name, array $config ): ConditionProperty {
		$property          = ConditionProperty::new( $name );
		$nested_properties = $this->extract_nested_property_configs( $config, 'Condition property' );

		foreach ( $nested_properties as $nested_property ) {
			$property->prop( $this->create_from_config( $nested_property ) );
		}

		return $property;
	}

	/**
	 * Creates a repeater property with recursively hydrated nested properties.
	 *
	 * @param string               $name   Property name.
	 * @param array<string, mixed> $config Property configuration.
	 * @throws InvalidArgumentException When nested repeater properties are invalid.
	 */
	private function create_repeater_property( string $name, array $config ): RepeaterGroupProperty {
		$property          = RepeaterGroupProperty::new( $name );
		$nested_properties = $this->extract_nested_property_configs( $config, 'Repeater property' );

		foreach ( $nested_properties as $nested_property ) {
			$property->prop( $this->create_from_config( $nested_property ) );
		}

		return $property;
	}

	/**
	 * Extract nested property config entries for container-like properties.
	 *
	 * @param array<string, mixed> $config Property configuration.
	 * @param string               $label  Property label for error messages.
	 * @return array<int, array<string, mixed>>
	 * @throws InvalidArgumentException When nested properties are invalid.
	 */
	private function extract_nested_property_configs( array $config, string $label ): array {
		if ( ! array_key_exists( 'properties', $config ) ) {
			return array();
		}

		if ( ! is_array( $config['properties'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $label . ' requires a "properties" array.' );
		}

		$nested_properties = array();

		foreach ( $config['properties'] as $nested_property ) {
			if ( ! is_array( $nested_property ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $label . ' "properties" entries must be arrays.' );
			}

			$nested_properties[] = $nested_property;
		}

		return $nested_properties;
	}

	/**
	 * Apply common config fields to a property builder.
	 *
	 * @param ComponentPropertyInterface $property Property builder.
	 * @param array<string, mixed>       $config   Property configuration.
	 */
	private function apply_common_fields( ComponentPropertyInterface $property, array $config ): ComponentPropertyInterface {
		if ( array_key_exists( 'key', $config ) && is_string( $config['key'] ) ) {
			$property->key( $config['key'] );
		}

		if ( array_key_exists( 'keyTouched', $config ) && is_bool( $config['keyTouched'] ) ) {
			$property->key_touched( $config['keyTouched'] );
		}

		if ( array_key_exists( 'propSourceId', $config ) && is_string( $config['propSourceId'] ) ) {
			$property->prop_source_id( $config['propSourceId'] );
		}

		if ( array_key_exists( 'description', $config ) && is_string( $config['description'] ) ) {
			$property->description( $config['description'] );
		}

		if ( array_key_exists( 'default', $config ) ) {
			$property->default( $config['default'] );
		}

		return $property;
	}
}
