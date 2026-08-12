<?php
/**
 * Normalized Builder persistence handoff for Contract Lab probes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;

/**
 * Keeps only the Builder-owned persistence facts consumed by the Etch seam.
 *
 * Runtime IDs, URLs, post metadata, CSS bodies, and storage bookkeeping do
 * not belong in this observation. The style ID and selector are intentionally
 * retained as separate fields so a probe cannot silently treat one as the
 * other.
 */
final class ContractLabPersistenceHandoffObservation {

	public const OBSERVATION_VERSION = '1';

	private const SOURCE = 'builder_handoff';

	/**
	 * @param array<int, array{opaque_id: string, type: string, selector: string}> $styles
	 * @param array<int, array{component_key: string, properties: array<int, array<string, mixed>>, slots: array<int, string>, instances: array<int, array{attributes: array<string, mixed>, slots: array<int, array{name: string, blocks: array<int, string>}>}>}> $components
	 */
	private function __construct(
		private readonly array $styles,
		private readonly array $components
	) {
	}

	/**
	 * Build a normalized handoff directly from public persistence projections.
	 *
	 * @param array<int, array<string, mixed>> $styles
	 * @param array<int, array<string, mixed>> $components
	 */
	public static function from_public_surfaces( array $styles, array $components ): self {
		if ( ! array_is_list( $styles ) || ! array_is_list( $components ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab persistence handoff collections must be ordered lists.' );
		}

		$normalized_styles = array();
		$seen_style_ids    = array();
		foreach ( $styles as $style ) {
			$normalized = self::normalize_style( $style );
			if ( isset( $seen_style_ids[ $normalized['opaque_id'] ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Persistence handoff contains duplicate style ID "%s".', $normalized['opaque_id'] ) );
			}
			$seen_style_ids[ $normalized['opaque_id'] ] = true;
			$normalized_styles[] = $normalized;
		}

		$normalized_components = array();
		$seen_component_keys    = array();
		foreach ( $components as $component ) {
			$normalized = self::normalize_component( $component );
			if ( isset( $seen_component_keys[ $normalized['component_key'] ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Persistence handoff contains duplicate component key "%s".', $normalized['component_key'] ) );
			}
			$seen_component_keys[ $normalized['component_key'] ] = true;
			$normalized_components[] = $normalized;
		}

		return new self( $normalized_styles, $normalized_components );
	}

	/**
	 * Project the minimal facts from compiled persistence records.
	 *
	 * Only component entities and class/style resources participate in this
	 * probe. Other Site entities remain the responsibility of their later
	 * evidence layers and are deliberately ignored here.
	 *
	 * @param array<int, SitePersistenceRecord>                 $records
	 * @param array<string, array<int, string>>                 $component_slots
	 * @param array<string, array<int, array<string, mixed>>>  $component_instances
	 */
	public static function from_persistence_records(
		array $records,
		array $component_slots = array(),
		array $component_instances = array()
	): self {
		if ( ! array_is_list( $records ) ) {
			throw new ContractLabObservationException( 'malformed', 'Persistence records must be an ordered list.' );
		}

		$styles     = array();
		$components = array();
		foreach ( $records as $record ) {
			if ( ! $record instanceof SitePersistenceRecord ) {
				throw new ContractLabObservationException( 'malformed', 'Persistence handoff records must use SitePersistenceRecord.' );
			}

			if ( 'style' === $record->kind() ) {
				$style_id = self::identity_suffix( $record->identity(), 'style:' );
				$payload  = $record->payload();
				$styles[] = array(
					'opaque_id' => $style_id,
					'type'      => $payload['type'] ?? null,
					'selector'  => $payload['selector'] ?? null,
				);
				continue;
			}

			if ( 'component' !== $record->kind() ) {
				continue;
			}

			$component_key = self::identity_suffix( $record->identity(), 'component:' );
			$components[]   = array(
				'component_key' => $component_key,
				'properties'    => $record->payload()['properties'] ?? array(),
				'slots'         => $component_slots[ $component_key ] ?? array(),
				'instances'     => $component_instances[ $component_key ] ?? array(),
			);
		}

		return self::from_public_surfaces( $styles, $components );
	}

	/**
	 * Rehydrate and validate one canonical handoff observation.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'components', 'observation_version', 'source', 'styles' ) !== $keys
			|| self::OBSERVATION_VERSION !== ( $record['observation_version'] ?? null )
			|| self::SOURCE !== ( $record['source'] ?? null )
			|| ! is_array( $record['styles'] ?? null )
			|| ! is_array( $record['components'] ?? null )
		) {
			throw new ContractLabObservationException( 'malformed', 'Persistence handoff has an unknown version, source, or field set.' );
		}

		$observation = self::from_public_surfaces( $record['styles'], $record['components'] );
		if ( $observation->to_array() !== $record ) {
			throw new ContractLabObservationException( 'malformed', 'Persistence handoff is not canonical.' );
		}

		return $observation;
	}

	/**
	 * @return array<int, array{opaque_id: string, type: string, selector: string}>
	 */
	public function styles(): array {
		return $this->styles;
	}

	/**
	 * @return array<int, array{component_key: string, properties: array<int, array<string, mixed>>, slots: array<int, string>, instances: array<int, array{attributes: array<string, mixed>, slots: array<int, array{name: string, blocks: array<int, string>}>}>}>
	 */
	public function components(): array {
		return $this->components;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'observation_version' => self::OBSERVATION_VERSION,
			'source'             => self::SOURCE,
			'styles'             => $this->styles,
			'components'        => $this->components,
		);
	}

	/**
	 * @param array<string, mixed> $style
	 * @return array{opaque_id: string, type: string, selector: string}
	 */
	private static function normalize_style( array $style ): array {
		self::assert_exact_keys( $style, array( 'opaque_id', 'selector', 'type' ), 'Persistence handoff style' );
		$opaque_id = $style['opaque_id'];
		$type      = $style['type'];
		$selector  = $style['selector'];
		if ( ! is_string( $opaque_id ) || ! is_string( $type ) || ! is_string( $selector ) ) {
			throw new ContractLabObservationException( 'malformed', 'Persistence handoff style fields must be strings.' );
		}
		if ( '' === $opaque_id || trim( $opaque_id ) !== $opaque_id || 1 === preg_match( '/[\x00-\x1F\x7F\s]/', $opaque_id ) ) {
			throw new ContractLabObservationException( 'malformed', 'Persistence handoff style opaque ID must be a stable non-selector identity.' );
		}
		if ( '' === $type || trim( $type ) !== $type || 1 !== preg_match( '/^[a-z][a-z0-9_-]*$/D', $type ) ) {
			throw new ContractLabObservationException( 'malformed', 'Persistence handoff style type must be a stable token.' );
		}
		if ( '' === $selector || trim( $selector ) !== $selector || 1 === preg_match( '/[\x00-\x1F\x7F]/', $selector ) ) {
			throw new ContractLabObservationException( 'malformed', 'Persistence handoff style selector must be a safe exact string.' );
		}
		if ( 'class' === $type ) {
			if ( 1 !== preg_match( '/^\.([A-Za-z_][A-Za-z0-9_-]*)$/D', $selector ) ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Persistence handoff class style selector "%s" is not one simple class selector.', $selector ) );
			}
			if ( $opaque_id === $selector || $opaque_id === substr( $selector, 1 ) ) {
				throw new ContractLabObservationException( 'malformed', 'Persistence handoff must keep the opaque style ID distinct from the selector and class token.' );
			}
		}

		return array(
			'opaque_id' => $opaque_id,
			'type'      => $type,
			'selector'  => $selector,
		);
	}

	/**
	 * @param array<string, mixed> $component
	 * @return array{component_key: string, properties: array<int, array<string, mixed>>, slots: array<int, string>, instances: array<int, array{attributes: array<string, mixed>, slots: array<int, array{name: string, blocks: array<int, string>}>}>}
	 */
	private static function normalize_component( array $component ): array {
		self::assert_exact_keys( $component, array( 'component_key', 'instances', 'properties', 'slots' ), 'Persistence handoff component' );
		$component_key = $component['component_key'];
		$properties    = $component['properties'];
		$slots         = $component['slots'];
		$instances     = $component['instances'];
		if ( ! is_string( $component_key ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/D', $component_key ) || ! is_array( $properties ) || ! array_is_list( $properties ) || ! is_array( $slots ) || ! array_is_list( $slots ) || ! is_array( $instances ) || ! array_is_list( $instances ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Persistence handoff component "%s" has invalid field shapes.', is_string( $component_key ) ? $component_key : 'unknown' ) );
		}

		$normalized_properties = array();
		foreach ( $properties as $property ) {
			if ( ! is_array( $property ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Persistence handoff component "%s" properties must be object records.', $component_key ) );
			}
			AcyclicArrayGuard::assert_acyclic( $property );
			$normalized_properties[] = ImmutableArray::copy( $property, 'Persistence handoff component properties must contain persisted data.' );
		}

		$normalized_slots = self::normalize_slot_names( $slots, $component_key . ' declared slots' );
		$normalized_instances = array();
		foreach ( $instances as $instance ) {
			$normalized_instances[] = self::normalize_instance( $instance, $component_key );
		}

		return array(
			'component_key' => $component_key,
			'properties'    => $normalized_properties,
			'slots'         => $normalized_slots,
			'instances'     => $normalized_instances,
		);
	}

	/**
	 * @param array<string, mixed> $instance
	 * @return array{attributes: array<string, mixed>, slots: array<int, array{name: string, blocks: array<int, string>}>}
	 */
	private static function normalize_instance( array $instance, string $component_key ): array {
		self::assert_exact_keys( $instance, array( 'attributes', 'slots' ), sprintf( 'Persistence handoff component "%s" instance', $component_key ) );
		$attributes = $instance['attributes'];
		$slots      = $instance['slots'];
		if ( ! is_array( $attributes ) || ( array() !== $attributes && array_is_list( $attributes ) ) || ! is_array( $slots ) || ! array_is_list( $slots ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Persistence handoff component "%s" instance has invalid attributes or slots.', $component_key ) );
		}
		foreach ( array_keys( $attributes ) as $key ) {
			if ( ! is_string( $key ) || '' === $key || trim( $key ) !== $key ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Persistence handoff component "%s" instance attributes must use exact string keys.', $component_key ) );
			}
		}
		AcyclicArrayGuard::assert_acyclic( $attributes );
		$attributes = ImmutableArray::copy( $attributes, 'Persistence handoff component instance attributes must contain persisted data.' );

		$normalized_slots = array();
		$seen_slots       = array();
		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Persistence handoff component "%s" slot assignments must be object records.', $component_key ) );
			}
			self::assert_exact_keys( $slot, array( 'blocks', 'name' ), sprintf( 'Persistence handoff component "%s" slot assignment', $component_key ) );
			$name   = $slot['name'];
			$blocks = $slot['blocks'];
			if ( ! is_string( $name ) || '' === $name || trim( $name ) !== $name || ! is_array( $blocks ) || ! array_is_list( $blocks ) || isset( $seen_slots[ $name ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Persistence handoff component "%s" has a duplicate or malformed exact slot assignment.', $component_key ) );
			}
			$seen_slots[ $name ] = true;
			$normalized_blocks = array();
			foreach ( $blocks as $block_name ) {
				if ( ! is_string( $block_name ) || 1 !== preg_match( '/^[a-z][a-z0-9-]*(?:\/[a-z][a-z0-9-]*)?$/D', $block_name ) ) {
					throw new ContractLabObservationException( 'malformed', sprintf( 'Persistence handoff component "%s" has an invalid slot child block.', $component_key ) );
				}
				$normalized_blocks[] = $block_name;
			}
			$normalized_slots[] = array( 'name' => $name, 'blocks' => $normalized_blocks );
		}

		return array( 'attributes' => $attributes, 'slots' => $normalized_slots );
	}

	/**
	 * @param array<int, mixed> $slot_names
	 * @return array<int, string>
	 */
	private static function normalize_slot_names( array $slot_names, string $label ): array {
		$normalized = array();
		$seen       = array();
		foreach ( $slot_names as $slot_name ) {
			if ( ! is_string( $slot_name ) || '' === $slot_name || trim( $slot_name ) !== $slot_name || isset( $seen[ $slot_name ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( '%s must contain unique exact strings.', $label ) );
			}
			$seen[ $slot_name ] = true;
			$normalized[]       = $slot_name;
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $record
	 * @param array<int, string>   $expected
	 */
	private static function assert_exact_keys( array $record, array $expected, string $label ): void {
		$actual = array_keys( $record );
		sort( $actual );
		$expected = array_values( $expected );
		sort( $expected );
		if ( $actual !== $expected ) {
			throw new ContractLabObservationException( 'malformed', $label . ' has an unknown field set.' );
		}
	}

	private static function identity_suffix( string $identity, string $prefix ): string {
		if ( ! str_starts_with( $identity, $prefix ) || '' === substr( $identity, strlen( $prefix ) ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Persistence handoff identity must use the "%s" namespace.', $prefix ) );
		}

		return substr( $identity, strlen( $prefix ) );
	}
}
