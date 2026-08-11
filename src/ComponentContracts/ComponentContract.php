<?php
/**
 * Immutable contract projection for one Etch component.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentContracts;

use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContractMatrix;
use InvalidArgumentException;

/**
 * Contains only observable authoring schema facts, never component rendering code.
 */
final class ComponentContract {

	private readonly string $component_key;

	/**
	 * @var array<int, ComponentPropertyPathContract>
	 */
	private readonly array $properties;

	/**
	 * @var array<string, ComponentPropertyPathContract>
	 */
	private readonly array $properties_by_declaration_path;

	/**
	 * @var array<string, ComponentPropertyPathContract>
	 */
	private readonly array $properties_by_value_path;

	/**
	 * @var array<int, string>
	 */
	private readonly array $slots;

	/**
	 * @var array<int, string>
	 */
	private readonly array $class_property_paths;

	/**
	 * Build a contract projection from Etch's observable component schema.
	 *
	 * @param array<int, array<string, mixed>> $property_definitions etch_component_properties value.
	 * @param array<int, mixed>                $slot_names Slot placeholder names from component blocks.
	 */
	public static function from_schema( string $component_key, array $property_definitions, array $slot_names ): self {
		return new self( $component_key, $property_definitions, $slot_names );
	}

	/**
	 * @param array<int, array<string, mixed>> $property_definitions Etch property definitions.
	 * @param array<int, mixed>                $slot_names Slot names.
	 */
	private function __construct( string $component_key, array $property_definitions, array $slot_names ) {
		$this->component_key = self::validate_component_key( $component_key );
		$this->slots         = self::validate_slots( $slot_names );

		if ( ! array_is_list( $property_definitions ) ) {
			throw new InvalidArgumentException( 'Component property schema must be a list.' );
		}

		$properties                = array();
		$properties_by_declaration = array();
		$properties_by_value       = array();
		$class_property_paths      = array();

		self::project_definitions(
			$property_definitions,
			'',
			'',
			$properties,
			$properties_by_declaration,
			$properties_by_value,
			$class_property_paths
		);

		$this->properties                     = $properties;
		$this->properties_by_declaration_path = $properties_by_declaration;
		$this->properties_by_value_path       = $properties_by_value;
		$this->class_property_paths           = $class_property_paths;
	}

	public function component_key(): string {
		return $this->component_key;
	}

	/**
	 * @return array<int, ComponentPropertyPathContract>
	 */
	public function properties(): array {
		return $this->properties;
	}

	/**
	 * Require one exact declaration-tree path.
	 *
	 * @throws InvalidArgumentException When the declaration path is unknown.
	 */
	public function property_by_declaration_path( string $path ): ComponentPropertyPathContract {
		if ( ! isset( $this->properties_by_declaration_path[ $path ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Component "%s" has no property declaration path "%s".', $this->component_key, $path )
			);
		}

		return $this->properties_by_declaration_path[ $path ];
	}

	/**
	 * Require one exact effective instance-value path.
	 *
	 * @throws InvalidArgumentException When the value path is unknown or transparent.
	 */
	public function property_by_value_path( string $path ): ComponentPropertyPathContract {
		if ( ! isset( $this->properties_by_value_path[ $path ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Component "%s" has no property value path "%s".', $this->component_key, $path )
			);
		}

		return $this->properties_by_value_path[ $path ];
	}

	/**
	 * @return array<int, string>
	 */
	public function slots(): array {
		return $this->slots;
	}

	/**
	 * Return exact effective paths whose exact matrix pair is array/class.
	 *
	 * @return array<int, string>
	 */
	public function class_property_paths(): array {
		return $this->class_property_paths;
	}

	/**
	 * Return deterministic machine-readable contract data.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'component_key'        => $this->component_key,
			'properties'           => array_map(
				static fn ( ComponentPropertyPathContract $property ): array => $property->to_array(),
				$this->properties
			),
			'slots'                => $this->slots,
			'class_property_paths' => $this->class_property_paths,
		);
	}

	/**
	 * Recursively project exact schema types and both path domains.
	 *
	 * @param array<int, array<string, mixed>>                  $definitions Property definitions at this level.
	 * @param array<int, ComponentPropertyPathContract>         $properties Ordered output records.
	 * @param array<string, ComponentPropertyPathContract>      $by_declaration Declaration lookup.
	 * @param array<string, ComponentPropertyPathContract>      $by_value Effective value lookup.
	 * @param array<int, string>                                $class_paths Exact array/class value paths.
	 */
	private static function project_definitions(
		array $definitions,
		string $declaration_prefix,
		string $value_prefix,
		array &$properties,
		array &$by_declaration,
		array &$by_value,
		array &$class_paths
	): void {
		if ( ! array_is_list( $definitions ) ) {
			throw new InvalidArgumentException( 'Component child property schema must be a list.' );
		}

		foreach ( $definitions as $definition ) {
			if ( ! is_array( $definition ) ) {
				throw new InvalidArgumentException( 'Each component property definition must be an object-shaped array.' );
			}

			$key              = self::definition_key( $definition );
			$declaration_path = self::join_path( $declaration_prefix, $key );
			if ( isset( $by_declaration[ $declaration_path ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Component property schema has duplicate declaration path "%s".', $declaration_path ) );
			}

			$type              = self::definition_type( $definition, $declaration_path );
			$primitive         = $type['primitive'];
			$specialized       = $type['specialized'];
			$property_contract = PropertyContractMatrix::contract_for_type( $primitive, $specialized );
			$is_condition      = 'string/condition' === $property_contract->type_key();
			$is_group          = 'object/group' === $property_contract->type_key();
			$is_repeater       = 'array/repeater' === $property_contract->type_key();
			$is_structural     = $is_condition || $is_group || $is_repeater;

			$children = $definition['properties'] ?? null;
			if ( $is_structural ) {
				if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Structural component property "%s" must declare a list of child properties.', $declaration_path )
					);
				}
			} elseif ( array_key_exists( 'properties', $definition ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Leaf component property "%s" cannot declare child properties.', $declaration_path )
				);
			}

			$value_path = $is_condition ? null : self::join_path( $value_prefix, $key );
			$property   = new ComponentPropertyPathContract(
				$declaration_path,
				$value_path,
				$property_contract,
				array_key_exists( 'default', $definition ),
				$definition['default'] ?? null
			);

			$properties[]                       = $property;
			$by_declaration[ $declaration_path ] = $property;

			if ( null !== $value_path ) {
				if ( isset( $by_value[ $value_path ] ) ) {
					throw new InvalidArgumentException( sprintf( 'Component property schema has ambiguous value path "%s".', $value_path ) );
				}

				$by_value[ $value_path ] = $property;
				if ( $property->is_class_property() ) {
					$class_paths[] = $value_path;
				}
			}

			if ( ! $is_structural ) {
				continue;
			}

			$child_value_prefix = $value_prefix;
			if ( $is_group && null !== $value_path ) {
				$child_value_prefix = $value_path;
			} elseif ( $is_repeater && null !== $value_path ) {
				$child_value_prefix = $value_path . '[]';
			}

			/** @var array<int, array<string, mixed>> $children */
			self::project_definitions(
				$children,
				$declaration_path,
				$child_value_prefix,
				$properties,
				$by_declaration,
				$by_value,
				$class_paths
			);
		}
	}

	/**
	 * @param array<string, mixed> $definition Property definition.
	 */
	private static function definition_key( array $definition ): string {
		$key = $definition['key'] ?? null;
		if ( ! is_string( $key ) || '' === $key || trim( $key ) !== $key ) {
			throw new InvalidArgumentException( 'Each component property definition must declare a non-empty string key.' );
		}

		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $key ) ) {
			throw new InvalidArgumentException( sprintf( 'Component property key "%s" is invalid.', $key ) );
		}

		return $key;
	}

	/**
	 * @param array<string, mixed> $definition Property definition.
	 * @return array{primitive: string, specialized: string|null}
	 */
	private static function definition_type( array $definition, string $path ): array {
		$type = $definition['type'] ?? null;
		if ( ! is_array( $type ) || array_is_list( $type ) ) {
			throw new InvalidArgumentException( sprintf( 'Component property "%s" must declare a type object.', $path ) );
		}

		$primitive = $type['primitive'] ?? null;
		if ( ! is_string( $primitive ) || '' === $primitive ) {
			throw new InvalidArgumentException( sprintf( 'Component property "%s" must declare a non-empty primitive type.', $path ) );
		}

		$specialized = $type['specialized'] ?? null;
		if ( null !== $specialized && ! is_string( $specialized ) ) {
			throw new InvalidArgumentException( sprintf( 'Component property "%s" specialized type must be a string.', $path ) );
		}

		return array(
			'primitive'   => $primitive,
			'specialized' => $specialized,
		);
	}

	private static function join_path( string $prefix, string $key ): string {
		return '' === $prefix ? $key : $prefix . '.' . $key;
	}

	private static function validate_component_key( string $component_key ): string {
		if ( '' === $component_key || trim( $component_key ) !== $component_key
			|| 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $component_key )
		) {
			throw new InvalidArgumentException( 'Component contract component key must match /^[A-Za-z][A-Za-z0-9_-]*$/.' );
		}

		return $component_key;
	}

	/**
	 * @param array<int, mixed> $slot_names Raw slot names.
	 * @return array<int, string>
	 */
	private static function validate_slots( array $slot_names ): array {
		if ( ! array_is_list( $slot_names ) ) {
			throw new InvalidArgumentException( 'Component contract slots must be a list.' );
		}

		$slots = array();
		$seen  = array();
		foreach ( $slot_names as $slot_name ) {
			if ( ! is_string( $slot_name ) || '' === $slot_name ) {
				throw new InvalidArgumentException( 'Component contract slot names must be non-empty strings.' );
			}

			if ( trim( $slot_name ) !== $slot_name ) {
				throw new InvalidArgumentException( 'Component contract slot names must be non-empty exact strings.' );
			}

			if ( isset( $seen[ $slot_name ] ) ) {
				continue;
			}

			$seen[ $slot_name ] = true;
			$slots[]            = $slot_name;
		}

		return $slots;
	}
}
