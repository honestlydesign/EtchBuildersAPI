<?php
/**
 * Immutable contract projection for one Etch component.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentContracts;

use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContractMatrix;
use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Contains observable schema facts plus curated authoring admission metadata,
 * never component rendering code.
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

	private readonly ComponentContractMetadata $metadata;

	/**
	 * Build a contract projection from Etch's observable component schema.
	 *
	 * @param array<int, array<string, mixed>> $property_definitions etch_component_properties value.
	 * @param array<int, mixed>                $slot_names Slot placeholder names from component blocks.
	 */
	public static function from_schema(
		string $component_key,
		array $property_definitions,
		array $slot_names,
		?ComponentContractMetadata $metadata = null
	): self {
		if ( ! array_is_list( $property_definitions ) ) {
			throw new InvalidArgumentException( 'Component property schema must be a list.' );
		}

		$properties = array();
		self::project_definitions( $property_definitions, '', '', $properties );

		return new self( $component_key, $properties, $slot_names, $metadata ?? ComponentContractMetadata::pending() );
	}

	/**
	 * Build from already validated path records, as used by accepted projections.
	 *
	 * @param array<int, ComponentPropertyPathContract> $properties Ordered path records.
	 * @param array<int, mixed>                         $slot_names Slot names.
	 */
	private static function from_property_contracts(
		string $component_key,
		array $properties,
		array $slot_names,
		ComponentContractMetadata $metadata
	): self {
		return new self( $component_key, $properties, $slot_names, $metadata );
	}

	/**
	 * Rehydrate one accepted machine-readable component contract record.
	 *
	 * @param array<string, mixed> $record Accepted component record.
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );

		self::assert_exact_keys(
			$record,
			array( 'component_key', 'properties', 'slots', 'class_property_paths', 'status', 'recipe_ids' ),
			'Accepted component record'
		);

		$component_key        = $record['component_key'];
		$property_records     = $record['properties'];
		$slots                = $record['slots'];
		$accepted_class_paths = $record['class_property_paths'];
		if ( ! is_string( $component_key ) || ! is_array( $property_records ) || ! array_is_list( $property_records )
			|| ! is_array( $slots ) || ! is_array( $accepted_class_paths )
		) {
			throw new InvalidArgumentException( 'Accepted component record fields have invalid shapes.' );
		}

		$properties = array();
		foreach ( $property_records as $property_record ) {
			if ( ! is_array( $property_record ) ) {
				throw new InvalidArgumentException( 'Accepted component properties must be object records.' );
			}
			$properties[] = ComponentPropertyPathContract::from_array( $property_record );
		}

		$metadata = ComponentContractMetadata::from_array(
			array(
				'status'     => $record['status'],
				'recipe_ids' => $record['recipe_ids'],
			)
		);
		$contract = self::from_property_contracts( $component_key, $properties, $slots, $metadata );
		if ( $accepted_class_paths !== $contract->class_property_paths() ) {
			throw new InvalidArgumentException( 'Accepted component class_property_paths must match paths derived from exact array/class records.' );
		}

		if ( self::canonicalize_object( $record ) !== self::canonicalize_object( $contract->to_array() ) ) {
			throw new InvalidArgumentException( 'Accepted component record must be a canonical model projection.' );
		}

		return $contract;
	}

	/**
	 * @param array<int, ComponentPropertyPathContract> $properties Ordered path records.
	 * @param array<int, mixed>                         $slot_names Slot names.
	 */
	private function __construct(
		string $component_key,
		array $properties,
		array $slot_names,
		ComponentContractMetadata $metadata
	) {
		$this->component_key = self::validate_component_key( $component_key );
		$this->slots         = self::validate_slots( $slot_names );
		$this->metadata      = $metadata;

		if ( ! array_is_list( $properties ) ) {
			throw new InvalidArgumentException( 'Component property path contracts must be a list.' );
		}

		$properties_by_declaration = array();
		$properties_by_value       = array();
		$class_property_paths      = array();
		$declaration_indexes       = array();

		foreach ( $properties as $index => $property ) {
			if ( ! $property instanceof ComponentPropertyPathContract ) {
				throw new InvalidArgumentException( 'Component property path contracts must use ComponentPropertyPathContract.' );
			}

			$declaration_path = $property->declaration_path();
			if ( isset( $properties_by_declaration[ $declaration_path ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Component property schema has duplicate declaration path "%s".', $declaration_path ) );
			}
			$properties_by_declaration[ $declaration_path ] = $property;
			$declaration_indexes[ $declaration_path ]       = $index;

			$value_path = $property->value_path();
			if ( null === $value_path ) {
				continue;
			}

			if ( isset( $properties_by_value[ $value_path ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Component property schema has ambiguous value path "%s".', $value_path ) );
			}
			$properties_by_value[ $value_path ] = $property;

			if ( $property->is_class_property() ) {
				$class_property_paths[] = $value_path;
			}
		}

		$previous_declaration_path = null;
		foreach ( $properties as $index => $property ) {
			self::assert_structural_path(
				$property,
				$index,
				$properties_by_declaration,
				$declaration_indexes
			);
			self::assert_canonical_preorder( $property->declaration_path(), $previous_declaration_path );
			$previous_declaration_path = $property->declaration_path();
		}

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
	 * Require one exact slot name declared by this component.
	 *
	 * @throws InvalidArgumentException When the slot name is absent.
	 */
	public function require_slot( string $slot_name ): string {
		if ( ! in_array( $slot_name, $this->slots, true ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Component "%s" has no exact slot named "%s".', $this->component_key, $slot_name )
			);
		}

		return $slot_name;
	}

	/**
	 * Return exact effective paths whose exact matrix pair is array/class.
	 *
	 * @return array<int, string>
	 */
	public function class_property_paths(): array {
		return $this->class_property_paths;
	}

	public function status(): ComponentContractStatus {
		return $this->metadata->status();
	}

	/**
	 * @return array<int, string>
	 */
	public function recipe_ids(): array {
		return $this->metadata->recipe_ids();
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
			'status'               => $this->metadata->status()->value,
			'recipe_ids'           => $this->metadata->recipe_ids(),
		);
	}

	/**
	 * Recursively project exact schema types and both path domains.
	 *
	 * @param array<int, array<string, mixed>>                  $definitions Property definitions at this level.
	 * @param array<int, ComponentPropertyPathContract>         $properties Ordered output records.
	 */
	private static function project_definitions(
		array $definitions,
		string $declaration_prefix,
		string $value_prefix,
		array &$properties
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

			$properties[] = $property;

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
				$properties
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

	/**
	 * @param array<string, mixed> $record Record to inspect.
	 * @param array<int, string>   $expected_keys Exact allowed keys.
	 */
	private static function assert_exact_keys( array $record, array $expected_keys, string $context ): void {
		$actual_keys = array_keys( $record );
		sort( $actual_keys );
		sort( $expected_keys );
		if ( $actual_keys !== $expected_keys ) {
			throw new InvalidArgumentException(
				sprintf( '%s must contain exactly the keys: %s.', $context, implode( ', ', $expected_keys ) )
			);
		}
	}

	/**
	 * Require every flattened record to belong to one coherent schema tree.
	 *
	 * @param array<string, ComponentPropertyPathContract> $by_declaration Complete declaration lookup.
	 * @param array<string, int>                           $declaration_indexes Declaration order lookup.
	 */
	private static function assert_structural_path(
		ComponentPropertyPathContract $property,
		int $index,
		array $by_declaration,
		array $declaration_indexes
	): void {
		$declaration_path = $property->declaration_path();
		$separator        = strrpos( $declaration_path, '.' );
		$key              = false === $separator ? $declaration_path : substr( $declaration_path, $separator + 1 );
		$value_prefix     = '';

		if ( false !== $separator ) {
			$parent_path = substr( $declaration_path, 0, $separator );
			if ( ! isset( $by_declaration[ $parent_path ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Component property "%s" has missing parent declaration path "%s".', $declaration_path, $parent_path )
				);
			}

			if ( $declaration_indexes[ $parent_path ] >= $index ) {
				throw new InvalidArgumentException(
					sprintf( 'Component property parent "%s" must precede child "%s".', $parent_path, $declaration_path )
				);
			}

			$value_prefix = self::child_value_prefix( $by_declaration[ $parent_path ], $by_declaration );
		}

		$expected_value_path = 'string/condition' === $property->property_contract()->type_key()
			? null
			: self::join_path( $value_prefix, $key );

		if ( $expected_value_path !== $property->value_path() ) {
			throw new InvalidArgumentException(
				sprintf( 'Component property "%s" value path does not match its structural parent.', $declaration_path )
			);
		}
	}

	/**
	 * Prevent a flattened record list from returning to an already closed subtree.
	 */
	private static function assert_canonical_preorder( string $declaration_path, ?string $previous_path ): void {
		$separator = strrpos( $declaration_path, '.' );
		if ( false === $separator ) {
			return;
		}

		$parent_path = substr( $declaration_path, 0, $separator );
		if ( null === $previous_path
			|| ( $previous_path !== $parent_path && ! str_starts_with( $previous_path, $parent_path . '.' ) )
		) {
			throw new InvalidArgumentException(
				sprintf( 'Component property "%s" re-enters a completed declaration subtree.', $declaration_path )
			);
		}
	}

	/**
	 * Compute the effective prefix inherited by direct children of one parent.
	 *
	 * @param array<string, ComponentPropertyPathContract> $by_declaration Complete declaration lookup.
	 */
	private static function child_value_prefix( ComponentPropertyPathContract $parent, array $by_declaration ): string {
		$type_key   = $parent->property_contract()->type_key();
		$value_path = $parent->value_path();
		if ( 'object/group' === $type_key && null !== $value_path ) {
			return $value_path;
		}

		if ( 'array/repeater' === $type_key && null !== $value_path ) {
			return $value_path . '[]';
		}

		if ( 'string/condition' === $type_key ) {
			$declaration_path = $parent->declaration_path();
			$separator        = strrpos( $declaration_path, '.' );
			if ( false === $separator ) {
				return '';
			}

			$grandparent_path = substr( $declaration_path, 0, $separator );
			if ( ! isset( $by_declaration[ $grandparent_path ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Component condition "%s" has missing parent declaration path "%s".', $declaration_path, $grandparent_path )
				);
			}

			return self::child_value_prefix( $by_declaration[ $grandparent_path ], $by_declaration );
		}

		throw new InvalidArgumentException(
			sprintf( 'Component property "%s" cannot contain child property definitions.', $parent->declaration_path() )
		);
	}

	/**
	 * Sort associative object keys recursively while preserving list order.
	 *
	 * @return mixed
	 */
	private static function canonicalize_object( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_is_list( $value ) ) {
			return array_map( static fn ( mixed $item ): mixed => self::canonicalize_object( $item ), $value );
		}

		$keys = array_keys( $value );
		sort( $keys );

		$result = array();
		foreach ( $keys as $key ) {
			$result[ $key ] = self::canonicalize_object( $value[ $key ] );
		}

		return $result;
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
