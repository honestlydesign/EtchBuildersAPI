<?php
/**
 * Contract Lab persistence/component-property probe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentPropertyPathContract;
use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;
use JsonException;

/**
 * Joins minimal Builder handoff facts to separately observed public runtime
 * resolution facts. It never infers runtime success from persistence alone.
 */
final class ContractLabComponentPropertyProbe {

	public const PROBE_VERSION = '1';

	/**
	 * @param array<int, array{opaque_id: string, type: string, selector: string}> $styles
	 * @param array<int, array{component_key: string, schema: array<string, mixed>, instances: array<int, array<string, mixed>>}> $components
	 */
	private function __construct(
		private readonly array $styles,
		private readonly array $components,
		private readonly ContractLabPersistenceHandoffObservation $handoff,
		private readonly ContractLabEtchRuntimeResolutionObservation $runtime
	) {
	}

	/**
	 * Observe exact component properties, nested values, slots, and style
	 * identity resolution across the two explicitly named evidence sources.
	 */
	public static function observe(
		ContractLabPersistenceHandoffObservation $handoff,
		ComponentContractCatalog $catalog,
		ContractLabEtchRuntimeResolutionObservation $runtime
	): self {
		if ( ! $runtime->is_observed() ) {
			throw new ContractLabObservationException( 'unavailable', 'Component-property probe cannot claim runtime resolution while Etch runtime evidence is inconclusive.' );
		}

		$runtime_styles = array();
		foreach ( $runtime->styles() as $style ) {
			$runtime_styles[ $style['opaque_id'] ] = $style;
		}

		$styles       = array();
		$styles_by_id = array();
		foreach ( $handoff->styles() as $style ) {
			$resolved = $runtime_styles[ $style['opaque_id'] ] ?? null;
			if ( null === $resolved || $resolved['selector'] !== $style['selector'] ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Etch runtime did not resolve Builder style ID "%s" to the handed-off selector.', $style['opaque_id'] ) );
			}
			$styles[] = $style;
			$styles_by_id[ $style['opaque_id'] ] = $style;
		}

		$runtime_components = array();
		foreach ( $runtime->components() as $component ) {
			$runtime_components[ $component['component_key'] ] = $component;
		}

		$components = array();
		foreach ( $handoff->components() as $component ) {
			$component_key = $component['component_key'];
			if ( ! $catalog->has( $component_key ) ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Component-property probe has no admitted component contract for "%s".', $component_key ) );
			}

			$expected = $catalog->contract( $component_key );
			$observed = self::schema_from_handoff( $component_key, $component['properties'], $component['slots'] );
			if ( self::schema_projection( $expected ) !== self::schema_projection( $observed ) ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Persisted component schema for "%s" does not match the admitted public contract.', $component_key ) );
			}

			$runtime_component = $runtime_components[ $component_key ] ?? null;
			if ( null === $runtime_component ) {
				throw new ContractLabObservationException( 'unavailable', sprintf( 'Etch runtime resolution has no component evidence for "%s"; persistence facts alone cannot prove runtime behavior.', $component_key ) );
			}
			if ( $runtime_component['slots'] !== $observed->slots() ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Etch runtime did not resolve the exact slots for component "%s".', $component_key ) );
			}

			$instances = array();
			foreach ( $component['instances'] as $instance ) {
				$values      = array();
				$class_paths = array();
				self::flatten_attributes( $observed, $instance['attributes'], $styles_by_id, $runtime_styles, $values, $class_paths );

				$instance_slot_names = array_map(
					static fn ( array $slot ): string => $slot['name'],
					$instance['slots']
				);
				foreach ( $instance_slot_names as $slot_name ) {
					if ( ! in_array( $slot_name, $observed->slots(), true ) ) {
						throw new ContractLabObservationException( 'unsupported', sprintf( 'Component "%s" instance uses an unknown exact slot "%s".', $component_key, $slot_name ) );
					}
				}

				$runtime_paths = $runtime_component['property_paths'];
				foreach ( $values as $value ) {
					if ( ! in_array( $value['path'], $runtime_paths, true ) ) {
						throw new ContractLabObservationException( 'unsupported', sprintf( 'Etch runtime did not resolve component "%s" property path "%s".', $component_key, $value['path'] ) );
					}
				}

				$instances[] = array(
					'values'              => $values,
					'slots'               => $instance_slot_names,
					'class_property_paths' => $class_paths,
				);
			}

			$components[] = array(
				'component_key' => $component_key,
				'schema'        => self::schema_projection( $observed ),
				'instances'     => $instances,
			);
		}

		return new self( $styles, $components, $handoff, $runtime );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'probe_version'            => self::PROBE_VERSION,
			'builder_handoff'          => $this->handoff->to_array(),
			'etch_runtime_resolution'  => $this->runtime->to_array(),
			'styles'                   => $this->styles,
			'components'               => $this->components,
		);
	}

	/**
	 * @return array<int, array{opaque_id: string, type: string, selector: string}>
	 */
	public function styles(): array {
		return $this->styles;
	}

	/**
	 * @return array<int, array{component_key: string, schema: array<string, mixed>, instances: array<int, array<string, mixed>>}>
	 */
	public function components(): array {
		return $this->components;
	}

	/**
	 * @param array<int, array<string, mixed>> $properties
	 */
	private static function schema_from_handoff( string $component_key, array $properties, array $slots ): ComponentContract {
		try {
			return ComponentContract::from_schema( $component_key, $properties, $slots );
		} catch ( InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Persisted component schema for "%s" is malformed: %s', $component_key, $error->getMessage() ) );
		}
	}

	/**
	 * @return array{properties: array<int, array<string, mixed>>, slots: array<int, string>, class_property_paths: array<int, string>}
	 */
	private static function schema_projection( ComponentContract $contract ): array {
		$properties = array();
		foreach ( $contract->properties() as $property ) {
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
			$properties[] = $record;
		}

		return array(
			'properties'           => $properties,
			'slots'                => $contract->slots(),
			'class_property_paths' => $contract->class_property_paths(),
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 * @param array<string, array{opaque_id: string, type: string, selector: string}> $styles
	 * @param array<string, array{opaque_id: string, selector: string, status: string}> $runtime_styles
	 * @param array<int, array{path: string, wire: mixed}> $values
	 * @param array<int, string> $class_paths
	 */
	private static function flatten_attributes(
		ComponentContract $contract,
		array $attributes,
		array $styles,
		array $runtime_styles,
		array &$values,
		array &$class_paths
	): void {
		$known_roots = array();
		foreach ( $contract->properties() as $property ) {
			$value_path = $property->value_path();
			if ( null === $value_path || str_contains( $value_path, '.' ) || str_contains( $value_path, '[]' ) ) {
				continue;
			}
			$known_roots[ $value_path ] = $property;
		}
		foreach ( array_keys( $attributes ) as $key ) {
			if ( ! is_string( $key ) || ! isset( $known_roots[ $key ] ) ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Component "%s" instance contains an unknown property path "%s".', $contract->component_key(), (string) $key ) );
			}
		}

		foreach ( $contract->properties() as $property ) {
			$value_path = $property->value_path();
			if ( null === $value_path || str_contains( $value_path, '.' ) || str_contains( $value_path, '[]' ) || ! array_key_exists( $value_path, $attributes ) ) {
				continue;
			}
			self::flatten_value( $contract, $property, $value_path, $attributes[ $value_path ], $styles, $runtime_styles, $values, $class_paths );
		}
	}

	/**
	 * @param array<string, array{opaque_id: string, type: string, selector: string}> $styles
	 * @param array<string, array{opaque_id: string, selector: string, status: string}> $runtime_styles
	 * @param array<int, array{path: string, wire: mixed}> $values
	 * @param array<int, string> $class_paths
	 */
	private static function flatten_value(
		ComponentContract $contract,
		ComponentPropertyPathContract $property,
		string $path,
		mixed $wire,
		array $styles,
		array $runtime_styles,
		array &$values,
		array &$class_paths
	): void {
		$type_key = $property->property_contract()->type_key();
		if ( 'object/group' === $type_key ) {
			$decoded = self::decode_structural_wire( $wire, $path, 'object' );
			if ( ! is_array( $decoded ) || ( array() !== $decoded && array_is_list( $decoded ) ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Component group property "%s" did not resolve to an object.', $path ) );
			}
			self::flatten_group_children( $contract, $path, $decoded, $styles, $runtime_styles, $values, $class_paths );
			return;
		}

		if ( 'array/repeater' === $type_key ) {
			$decoded = self::decode_structural_wire( $wire, $path, 'array' );
			if ( ! is_array( $decoded ) || ! array_is_list( $decoded ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Component repeater property "%s" did not resolve to an ordered list.', $path ) );
			}
			foreach ( $decoded as $index => $row ) {
				if ( ! is_array( $row ) || ( array() !== $row && array_is_list( $row ) ) ) {
					throw new ContractLabObservationException( 'malformed', sprintf( 'Component repeater property "%s" row %d did not resolve to an object.', $path, $index ) );
				}
				self::flatten_group_children( $contract, $path . '[' . $index . ']', $row, $styles, $runtime_styles, $values, $class_paths );
			}
			return;
		}

		if ( 'array/class' === $type_key ) {
			if ( ! is_string( $wire ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Component class property "%s" must use its string wire value.', $path ) );
			}
			$ids = '' === trim( $wire ) ? array() : preg_split( '/\s+/', trim( $wire ) );
			if ( false === $ids ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Component class property "%s" has an invalid wire value.', $path ) );
			}
			foreach ( $ids as $id ) {
				if ( ! isset( $styles[ $id ] ) || ! isset( $runtime_styles[ $id ] ) ) {
					throw new ContractLabObservationException( 'unsupported', sprintf( 'Component class property "%s" references an unresolved opaque style ID; selector or class-name input is not accepted.', $path ) );
				}
				if ( $styles[ $id ]['selector'] !== $runtime_styles[ $id ]['selector'] || $id === ltrim( $styles[ $id ]['selector'], '.' ) ) {
					throw new ContractLabObservationException( 'unsupported', sprintf( 'Component class property "%s" did not preserve opaque style identity through runtime resolution.', $path ) );
				}
			}
			$values[]      = array( 'path' => $path, 'wire' => $wire );
			$class_paths[] = $path;
			return;
		}

		$values[] = array( 'path' => $path, 'wire' => self::normalize_leaf_wire( $wire, $path ) );
	}

	/**
	 * @param array<string, mixed> $decoded
	 * @param array<string, array{opaque_id: string, type: string, selector: string}> $styles
	 * @param array<string, array{opaque_id: string, selector: string, status: string}> $runtime_styles
	 * @param array<int, array{path: string, wire: mixed}> $values
	 * @param array<int, string> $class_paths
	 */
	private static function flatten_group_children(
		ComponentContract $contract,
		string $parent_path,
		array $decoded,
		array $styles,
		array $runtime_styles,
		array &$values,
		array &$class_paths
	): void {
		$canonical_parent = preg_replace( '/\[[0-9]+\]/', '[]', $parent_path );
		if ( ! is_string( $canonical_parent ) ) {
			throw new ContractLabObservationException( 'malformed', 'Component nested property path could not be normalized.' );
		}
		$children = self::direct_children( $contract, $canonical_parent );
		foreach ( array_keys( $decoded ) as $key ) {
			if ( ! is_string( $key ) || ! isset( $children[ $key ] ) ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Component nested property path "%s.%s" is not in the admitted schema.', $parent_path, (string) $key ) );
			}
		}
		foreach ( $children as $key => $property ) {
			if ( ! array_key_exists( $key, $decoded ) ) {
				continue;
			}
			$child_path = $parent_path . '.' . $key;
			self::flatten_value( $contract, $property, $child_path, $decoded[ $key ], $styles, $runtime_styles, $values, $class_paths );
		}
	}

	/**
	 * @return array<string, ComponentPropertyPathContract>
	 */
	private static function direct_children( ComponentContract $contract, string $parent_path ): array {
		$children = array();
		$prefix   = $parent_path . '.';
		foreach ( $contract->properties() as $property ) {
			$value_path = $property->value_path();
			if ( null === $value_path || ! str_starts_with( $value_path, $prefix ) ) {
				continue;
			}
			$remainder = substr( $value_path, strlen( $prefix ) );
			if ( false === strpos( $remainder, '.' ) ) {
				$children[ $remainder ] = $property;
			}
		}

		return $children;
	}

	private static function normalize_leaf_wire( mixed $wire, string $path ): mixed {
		if ( is_string( $wire ) || is_int( $wire ) || is_float( $wire ) || is_bool( $wire ) || null === $wire || is_array( $wire ) ) {
			if ( is_array( $wire ) ) {
				AcyclicArrayGuard::assert_acyclic( $wire );
				return ImmutableArray::copy( $wire, sprintf( 'Component property "%s" contains unsupported persisted data.', $path ) );
			}
			return $wire;
		}

		throw new ContractLabObservationException( 'malformed', sprintf( 'Component property "%s" has an unsupported wire value.', $path ) );
	}

	/**
	 * @return mixed
	 */
	private static function decode_structural_wire( mixed $wire, string $path, string $expected ): mixed {
		if ( is_array( $wire ) ) {
			AcyclicArrayGuard::assert_acyclic( $wire );
			return $wire;
		}
		if ( ! is_string( $wire ) || strlen( $wire ) < 3 || ! str_starts_with( $wire, '{' ) || ! str_ends_with( $wire, '}' ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Component %s property "%s" does not use the observed Etch JSON wire wrapper.', $expected, $path ) );
		}

		$json = substr( $wire, 1, -1 );
		try {
			return json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Component property "%s" contains malformed JSON wire data.', $path ) );
		}
	}
}
