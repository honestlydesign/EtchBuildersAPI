<?php
/**
 * Normalized public Etch runtime resolution evidence for Contract Lab.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;

/**
 * Records runtime-facing resolution facts without exposing Etch internals.
 *
 * This is deliberately a separate value from
 * ContractLabPersistenceHandoffObservation: matching storage facts do not
 * prove that the installed runtime resolved them.
 */
final class ContractLabEtchRuntimeResolutionObservation {

	public const OBSERVATION_VERSION = '1';

	private const SOURCE = 'etch_runtime_resolution';

	/**
	 * @param array<int, array{opaque_id: string, selector: string, status: string}> $styles
	 * @param array<int, array{component_key: string, property_paths: array<int, string>, slots: array<int, string>, status: string}> $components
	 */
	private function __construct(
		private readonly string $status,
		private readonly array $styles,
		private readonly array $components,
		private readonly ?string $reason
	) {
	}

	/**
	 * Create positive evidence from public Etch runtime surfaces.
	 *
	 * @param array<int, array<string, mixed>> $styles
	 * @param array<int, array<string, mixed>> $components
	 */
	public static function observed( array $styles, array $components ): self {
		if ( ! array_is_list( $styles ) || ! array_is_list( $components ) ) {
			throw new ContractLabObservationException( 'malformed', 'Etch runtime resolution collections must be ordered lists.' );
		}

		$normalized_styles = array();
		$seen_style_ids    = array();
		foreach ( $styles as $style ) {
			$normalized = self::normalize_style( $style );
			if ( isset( $seen_style_ids[ $normalized['opaque_id'] ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Etch runtime resolution contains duplicate style ID "%s".', $normalized['opaque_id'] ) );
			}
			$seen_style_ids[ $normalized['opaque_id'] ] = true;
			$normalized_styles[] = $normalized;
		}

		$normalized_components = array();
		$seen_component_keys    = array();
		foreach ( $components as $component ) {
			$normalized = self::normalize_component( $component );
			if ( isset( $seen_component_keys[ $normalized['component_key'] ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Etch runtime resolution contains duplicate component key "%s".', $normalized['component_key'] ) );
			}
			$seen_component_keys[ $normalized['component_key'] ] = true;
			$normalized_components[] = $normalized;
		}

		return new self( 'observed', $normalized_styles, $normalized_components, null );
	}

	/**
	 * Represent a broken/inconclusive runtime prerequisite without turning it
	 * into a successful compatibility observation.
	 */
	public static function inconclusive( string $reason ): self {
		if ( '' === trim( $reason ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $reason ) ) {
			throw new ContractLabObservationException( 'malformed', 'Etch runtime resolution inconclusive reason must be a safe non-empty string.' );
		}

		return new self( 'inconclusive', array(), array(), $reason );
	}

	/**
	 * Rehydrate a canonical runtime resolution observation.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		$expected = array( 'components', 'observation_version', 'source', 'status', 'styles' );
		if ( 'inconclusive' === ( $record['status'] ?? null ) ) {
			$expected[] = 'reason';
		}
		sort( $expected );
		if ( $keys !== $expected
			|| self::OBSERVATION_VERSION !== ( $record['observation_version'] ?? null )
			|| self::SOURCE !== ( $record['source'] ?? null )
			|| ! is_string( $record['status'] ?? null )
			|| ! is_array( $record['styles'] ?? null )
			|| ! is_array( $record['components'] ?? null )
		) {
			throw new ContractLabObservationException( 'malformed', 'Etch runtime resolution has an unknown version, source, or field set.' );
		}

		if ( 'observed' === $record['status'] ) {
			$styles     = self::strip_item_statuses( $record['styles'], 'style' );
			$components = self::strip_item_statuses( $record['components'], 'component' );
			$observation = self::observed( $styles, $components );
			$canonical   = $observation->to_array();
			$raw         = $record;
			$raw['styles']     = $styles;
			$raw['components'] = $components;
			if ( $canonical !== $record && $raw !== $record ) {
				throw new ContractLabObservationException( 'malformed', 'Etch runtime resolution is not canonical.' );
			}
		} else {
			$observation = self::inconclusive( is_string( $record['reason'] ?? null ) ? $record['reason'] : '' );
			if ( $observation->to_array() !== $record ) {
				throw new ContractLabObservationException( 'malformed', 'Etch runtime resolution is not canonical.' );
			}
		}

		return $observation;
	}

	public function status(): string {
		return $this->status;
	}

	public function is_observed(): bool {
		return 'observed' === $this->status;
	}

	/**
	 * @return array<int, array{opaque_id: string, selector: string, status: string}>
	 */
	public function styles(): array {
		return $this->styles;
	}

	/**
	 * @return array<int, array{component_key: string, property_paths: array<int, string>, slots: array<int, string>, status: string}>
	 */
	public function components(): array {
		return $this->components;
	}

	/**
	 * @return array{observation_version: string, source: string, status: string, styles: array<int, array{opaque_id: string, selector: string, status: string}>, components: array<int, array{component_key: string, property_paths: array<int, string>, slots: array<int, string>, status: string}>}|array{observation_version: string, source: string, status: string, styles: array<int, mixed>, components: array<int, mixed>, reason: string}
	 */
	public function to_array(): array {
		$record = array(
			'observation_version' => self::OBSERVATION_VERSION,
			'source'             => self::SOURCE,
			'status'             => $this->status,
			'styles'             => $this->styles,
			'components'         => $this->components,
		);
		if ( null !== $this->reason ) {
			$record['reason'] = $this->reason;
		}

		return $record;
	}

	/**
	 * @param array<string, mixed> $style
	 * @return array{opaque_id: string, selector: string, status: string}
	 */
	private static function normalize_style( array $style ): array {
		self::assert_exact_keys( $style, array( 'opaque_id', 'selector' ), 'Etch runtime style resolution' );
		$opaque_id = $style['opaque_id'];
		$selector  = $style['selector'];
		if ( ! is_string( $opaque_id ) || ! is_string( $selector ) || '' === $opaque_id || trim( $opaque_id ) !== $opaque_id || 1 === preg_match( '/[\x00-\x1F\x7F\s]/', $opaque_id ) || '' === $selector || trim( $selector ) !== $selector || 1 !== preg_match( '/^\.([A-Za-z_][A-Za-z0-9_-]*)$/D', $selector ) ) {
			throw new ContractLabObservationException( 'malformed', 'Etch runtime style resolution must contain one opaque ID and one simple selector.' );
		}
		if ( $opaque_id === $selector || $opaque_id === substr( $selector, 1 ) ) {
			throw new ContractLabObservationException( 'malformed', 'Etch runtime style resolution must keep the opaque style ID distinct from the selector and class token.' );
		}

		return array( 'opaque_id' => $opaque_id, 'selector' => $selector, 'status' => 'resolved' );
	}

	/**
	 * @param array<string, mixed> $component
	 * @return array{component_key: string, property_paths: array<int, string>, slots: array<int, string>, status: string}
	 */
	private static function normalize_component( array $component ): array {
		self::assert_exact_keys( $component, array( 'component_key', 'property_paths', 'slots' ), 'Etch runtime component resolution' );
		$component_key  = $component['component_key'];
		$property_paths = $component['property_paths'];
		$slots          = $component['slots'];
		if ( ! is_string( $component_key ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/D', $component_key ) || ! is_array( $property_paths ) || ! array_is_list( $property_paths ) || ! is_array( $slots ) || ! array_is_list( $slots ) ) {
			throw new ContractLabObservationException( 'malformed', 'Etch runtime component resolution has invalid field shapes.' );
		}

		$normalized_paths = self::normalize_strings( $property_paths, 'Etch runtime component property paths', '/^[A-Za-z_][A-Za-z0-9_.\[\]-]*$/D' );
		$normalized_slots = self::normalize_strings( $slots, 'Etch runtime component slots', null );

		return array(
			'component_key'  => $component_key,
			'property_paths' => $normalized_paths,
			'slots'          => $normalized_slots,
			'status'         => 'resolved',
		);
	}

	/**
	 * Accept the raw public probe shape (no item status) and the canonical
	 * rehydration shape (resolved item status), while rejecting any other
	 * status value or field set.
	 *
	 * @param array<int, mixed> $items
	 * @return array<int, array<string, mixed>>
	 */
	private static function strip_item_statuses( array $items, string $kind ): array {
		$normalized = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Etch runtime %s records must be objects.', $kind ) );
			}
			if ( array_key_exists( 'status', $item ) ) {
				if ( 'resolved' !== $item['status'] ) {
					throw new ContractLabObservationException( 'malformed', sprintf( 'Etch runtime %s status must be resolved.', $kind ) );
				}
				unset( $item['status'] );
			}
			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * @param array<int, mixed> $values
	 * @return array<int, string>
	 */
	private static function normalize_strings( array $values, string $label, ?string $pattern ): array {
		$normalized = array();
		$seen       = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value || trim( $value ) !== $value || ( null !== $pattern && 1 !== preg_match( $pattern, $value ) ) || isset( $seen[ $value ] ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( '%s must contain unique exact strings.', $label ) );
			}
			$seen[ $value ] = true;
			$normalized[]   = $value;
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
		sort( $expected );
		if ( $actual !== $expected ) {
			throw new ContractLabObservationException( 'malformed', $label . ' has an unknown field set.' );
		}
	}
}
