<?php
/**
 * Normalized public Runtime Capability Shape observation.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentPropertyPathContract;
use HonestlyDesign\EtchBuilders\ComponentProperties\PropertyContractMatrix;
use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;

/**
 * Projects only required block schema and component property/slot facts.
 */
final class ContractLabRuntimeShapeObservation {

	public const OBSERVATION_VERSION = '1';

	/**
	 * @param array<int, string>                $required_blocks
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, array<string, mixed>> $components
	 */
	private function __construct(
		private readonly array $required_blocks,
		private readonly array $blocks,
		private readonly array $components
	) {
	}

	/**
	 * Adapt public registry records and accepted component contracts into the
	 * minimal shape needed by the Compatibility Contract.
	 *
	 * @param array<int, string>                $required_block_names
	 * @param array<int, array<string, mixed>> $registry_records
	 * @param array<int, string>                $required_component_keys
	 */
	public static function from_public_surfaces(
		array $required_block_names,
		array $registry_records,
		ComponentContractCatalog $component_catalog,
		array $required_component_keys = array()
	): self {
		self::assert_namespaced_list( $required_block_names, 'required Runtime Shape block names' );
		self::assert_component_keys( $required_component_keys );
		if ( ! array_is_list( $registry_records ) ) {
			throw new ContractLabObservationException( 'malformed', 'Public block registry records must be an ordered list.' );
		}

		$registry = array();
		foreach ( $registry_records as $record ) {
			if ( ! is_array( $record ) ) {
				throw new ContractLabObservationException( 'malformed', 'Public block registry records must be objects.' );
			}
			$shape = ContractLabBlockShape::from_registry( $record );
			if ( isset( $registry[ $shape->name() ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Public block registry contains duplicate block "%s".', $shape->name() ) );
			}
			$registry[ $shape->name() ] = $shape;
		}

		$blocks = array();
		foreach ( $required_block_names as $required_block_name ) {
			if ( ! isset( $registry[ $required_block_name ] ) ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Required block "%s" is not registered.', $required_block_name ) );
			}
			$blocks[] = $registry[ $required_block_name ]->to_array();
		}

		$components = array();
		foreach ( $required_component_keys as $component_key ) {
			if ( ! $component_catalog->has( $component_key ) ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Required component "%s" is not present in the public component catalog.', $component_key ) );
			}
			$components[] = self::component_shape( $component_catalog->contract( $component_key ) );
		}

		return self::from_array(
			array(
				'observation_version' => self::OBSERVATION_VERSION,
				'required_blocks'     => $required_block_names,
				'blocks'              => $blocks,
				'components'          => $components,
			)
		);
	}

	/**
	 * Rehydrate an observation and reject unsupported or non-canonical shapes.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'blocks', 'components', 'observation_version', 'required_blocks' ) !== $keys || self::OBSERVATION_VERSION !== ( $record['observation_version'] ?? null ) ) {
			throw new ContractLabObservationException( 'malformed', 'Runtime Shape observation has an unknown version or field set.' );
		}
		$required_blocks = $record['required_blocks'];
		$blocks          = $record['blocks'];
		$components      = $record['components'];
		if ( ! is_array( $required_blocks ) || ! is_array( $blocks ) || ! is_array( $components ) || ! array_is_list( $required_blocks ) || ! array_is_list( $blocks ) || ! array_is_list( $components ) ) {
			throw new ContractLabObservationException( 'malformed', 'Runtime Shape observation lists have invalid shapes.' );
		}
		/** @var array<int, string> $required_blocks */
		self::assert_namespaced_list( $required_blocks, 'required Runtime Shape block names' );
		if ( count( $required_blocks ) !== count( $blocks ) ) {
			throw new ContractLabObservationException( 'malformed', 'Runtime Shape observation block projection does not match required blocks.' );
		}

		$normalized_blocks = array();
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				throw new ContractLabObservationException( 'malformed', 'Runtime Shape observation blocks must be records.' );
			}
			$normalized = ContractLabBlockShape::from_array( $block )->to_array();
			if ( $normalized['name'] !== $required_blocks[ $index ] ) {
				throw new ContractLabObservationException( 'malformed', 'Runtime Shape observation blocks must retain required order.' );
			}
			$normalized_blocks[] = $normalized;
		}

		$normalized_components = array();
		$seen_components       = array();
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				throw new ContractLabObservationException( 'malformed', 'Runtime Shape observation components must be records.' );
			}
			$normalized_component = self::normalize_component_shape( $component );
			if ( isset( $seen_components[ $normalized_component['component_key'] ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Runtime Shape observation contains duplicate component "%s".', $normalized_component['component_key'] ) );
			}
			$seen_components[ $normalized_component['component_key'] ] = true;
			$normalized_components[] = $normalized_component;
		}

		return new self( array_values( $required_blocks ), $normalized_blocks, $normalized_components );
	}

	/**
	 * @return array{observation_version: string, required_blocks: array<int, string>, blocks: array<int, array<string, mixed>>, components: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'observation_version' => self::OBSERVATION_VERSION,
			'required_blocks'     => $this->required_blocks,
			'blocks'              => $this->blocks,
			'components'          => $this->components,
		);
	}

	/**
	 * @return array<int, string>
	 */
	public function required_blocks(): array {
		return $this->required_blocks;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function blocks(): array {
		return $this->blocks;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function components(): array {
		return $this->components;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function component_shape( ComponentContract $contract ): array {
		$properties = array();
		foreach ( $contract->properties() as $property ) {
			$properties[] = self::property_shape( $property );
		}

		return array(
			'component_key'        => $contract->component_key(),
			'properties'           => $properties,
			'slots'                => $contract->slots(),
			'class_property_paths' => $contract->class_property_paths(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function property_shape( ComponentPropertyPathContract $property ): array {
		$type = array( 'primitive' => $property->property_contract()->primitive()->value );
		if ( null !== $property->property_contract()->specialized() ) {
			$type['specialized'] = $property->property_contract()->specialized();
		}
		$record = array(
			'declaration_path' => $property->declaration_path(),
			'value_path'       => $property->value_path(),
			'type'              => $type,
			'has_default'       => $property->has_default(),
		);
		if ( $property->has_default() ) {
			$record['default'] = $property->default_value();
		}

		return $record;
	}

	/**
	 * @param array<int, string> $names
	 */
	private static function assert_namespaced_list( array $names, string $label ): void {
		if ( array() === $names || ! array_is_list( $names ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( '%s must be a non-empty ordered list.', $label ) );
		}
		$seen = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/D', $name ) || isset( $seen[ $name ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( '%s must contain unique namespaced block names.', $label ) );
			}
			$seen[ $name ] = true;
		}
	}

	/**
	 * @param array<int, string> $keys
	 */
	private static function assert_component_keys( array $keys ): void {
		if ( ! array_is_list( $keys ) ) {
			throw new ContractLabObservationException( 'malformed', 'Required Runtime Shape component keys must be an ordered list.' );
		}
		$seen = array();
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_.-]*$/D', $key ) || isset( $seen[ $key ] ) ) {
				throw new ContractLabObservationException( 'malformed', 'Required Runtime Shape component keys must be unique stable identifiers.' );
			}
			$seen[ $key ] = true;
		}
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private static function normalize_component_shape( array $record ): array {
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'class_property_paths', 'component_key', 'properties', 'slots' ) !== $keys || ! is_string( $record['component_key'] ?? null ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_.-]*$/D', $record['component_key'] ) || ! is_array( $record['properties'] ?? null ) || ! array_is_list( $record['properties'] ) || ! is_array( $record['slots'] ?? null ) || ! array_is_list( $record['slots'] ) || ! is_array( $record['class_property_paths'] ?? null ) || ! array_is_list( $record['class_property_paths'] ) ) {
			throw new ContractLabObservationException( 'malformed', 'Runtime Shape component has an invalid field set.' );
		}

		$properties = array();
		$class_paths = array();
		foreach ( $record['properties'] as $property ) {
			if ( ! is_array( $property ) ) {
				throw new ContractLabObservationException( 'malformed', 'Runtime Shape component properties must be records.' );
			}
			$properties[] = self::normalize_property_shape( $property );
		}
		foreach ( $record['slots'] as $slot ) {
			if ( ! is_string( $slot ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_.-]*$/D', $slot ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Runtime Shape component "%s" has an invalid slot.', $record['component_key'] ) );
			}
		}
		foreach ( $record['class_property_paths'] as $path ) {
			if ( ! is_string( $path ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_.\[\]-]*$/D', $path ) || isset( $class_paths[ $path ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Runtime Shape component "%s" has invalid class-property paths.', $record['component_key'] ) );
			}
			$class_paths[ $path ] = true;
		}

		$derived_class_paths = array();
		foreach ( $properties as $property ) {
			if ( 'array/class' === self::type_key( $property['type'] ) && null !== $property['value_path'] ) {
				$derived_class_paths[] = $property['value_path'];
			}
		}
		if ( $derived_class_paths !== array_values( $record['class_property_paths'] ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Runtime Shape component "%s" class-property paths are not derived from its properties.', $record['component_key'] ) );
		}

		return array(
			'component_key'        => $record['component_key'],
			'properties'           => $properties,
			'slots'                => array_values( $record['slots'] ),
			'class_property_paths' => array_values( $record['class_property_paths'] ),
		);
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private static function normalize_property_shape( array $record ): array {
		$has_default = $record['has_default'] ?? null;
		$keys        = array_keys( $record );
		sort( $keys );
		$expected = array( 'declaration_path', 'has_default', 'type', 'value_path' );
		if ( true === $has_default ) {
			$expected[] = 'default';
		}
		sort( $expected );
		if ( $keys !== $expected || ! is_string( $record['declaration_path'] ?? null ) || ( null !== ( $record['value_path'] ?? null ) && ! is_string( $record['value_path'] ) ) || ! is_bool( $has_default ) || ! is_array( $record['type'] ?? null ) ) {
			throw new ContractLabObservationException( 'malformed', 'Runtime Shape component property has an invalid field set.' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_.\[\]-]*$/D', $record['declaration_path'] ) || ( null !== $record['value_path'] && 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_.\[\]-]*$/D', $record['value_path'] ) ) ) {
			throw new ContractLabObservationException( 'malformed', 'Runtime Shape component property paths are invalid.' );
		}
		$type = $record['type'];
		$type_keys = array_keys( $type );
		sort( $type_keys );
		$expected_type_keys = array( 'primitive' );
		if ( array_key_exists( 'specialized', $type ) ) {
			$expected_type_keys[] = 'specialized';
		}
		sort( $expected_type_keys );
		if ( $type_keys !== $expected_type_keys || ! is_string( $type['primitive'] ?? null ) || ( array_key_exists( 'specialized', $type ) && ! is_string( $type['specialized'] ) ) ) {
			throw new ContractLabObservationException( 'malformed', 'Runtime Shape component property type is invalid.' );
		}
		try {
			PropertyContractMatrix::contract_for_type( $type['primitive'], $type['specialized'] ?? null );
		} catch ( \InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'unsupported', 'Runtime Shape component property type pair is unsupported.' );
		}

		$normalized_type = array( 'primitive' => $type['primitive'] );
		if ( array_key_exists( 'specialized', $type ) ) {
			$normalized_type['specialized'] = $type['specialized'];
		}
		$normalized = array(
			'declaration_path' => $record['declaration_path'],
			'value_path'       => $record['value_path'],
			'type'              => $normalized_type,
			'has_default'       => $has_default,
		);
		if ( $has_default ) {
			$default = $record['default'];
			if ( is_array( $default ) ) {
				AcyclicArrayGuard::assert_acyclic( $default );
				$normalized['default'] = ImmutableArray::copy( $default, 'Runtime Shape component defaults must contain persisted data.' );
			} elseif ( is_string( $default ) || is_int( $default ) || is_float( $default ) || is_bool( $default ) || null === $default ) {
				$normalized['default'] = $default;
			} else {
				throw new ContractLabObservationException( 'malformed', 'Runtime Shape component defaults must contain persisted data.' );
			}
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $type
	 */
	private static function type_key( array $type ): string {
		return isset( $type['specialized'] ) ? $type['primitive'] . '/' . $type['specialized'] : $type['primitive'];
	}
}
