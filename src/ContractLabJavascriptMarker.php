<?php
/**
 * Declarative passive JavaScript marker for an existing Contract Lab flow.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Describes one exact dataset property written by a supported file-based
 * fixture script. It does not execute JavaScript or define browser policy.
 */
final class ContractLabJavascriptMarker {

	public const MARKER_VERSION = '1';

	public const SOURCE_KIND = 'file';

	private function __construct(
		private readonly string $logical_id,
		private readonly string $fixture_id,
		private readonly string $script_id,
		private readonly string $property,
		private readonly string $expected_value
	) {
	}

	public static function new(
		string $logical_id,
		string $fixture_id,
		string $script_id,
		string $property,
		string $expected_value
	): self {
		ContractLabManifestSafety::assert_stable_id( $logical_id, 'Contract Lab JavaScript marker logical identity' );
		ContractLabManifestSafety::assert_stable_id( $fixture_id, 'Contract Lab JavaScript marker fixture identity' );
		ContractLabManifestSafety::assert_stable_id( $script_id, 'Contract Lab JavaScript marker script identity' );
		if ( '' === $property || trim( $property ) !== $property || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9]*$/D', $property ) ) {
			throw new InvalidArgumentException( 'Contract Lab JavaScript marker property must be a stable dataset property.' );
		}
		if ( '' === $expected_value || trim( $expected_value ) !== $expected_value || preg_match( '/[[:cntrl:]]/', $expected_value ) ) {
			throw new InvalidArgumentException( 'Contract Lab JavaScript marker expected value must be a safe non-empty string.' );
		}

		return new self( $logical_id, $fixture_id, $script_id, $property, $expected_value );
	}

	public static function marketing_reference(): self {
		return self::new( 'marketing-ready', 'marketing-home', 'marketing-hero', 'etchMarketingReady', 'true' );
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		$expected = array( 'expected_value', 'fixture_id', 'logical_id', 'marker_version', 'property', 'script_id', 'source_kind' );
		sort( $expected );
		if ( $keys !== $expected || self::MARKER_VERSION !== ( $record['marker_version'] ?? null ) || self::SOURCE_KIND !== ( $record['source_kind'] ?? null ) || ! is_string( $record['logical_id'] ?? null ) || ! is_string( $record['fixture_id'] ?? null ) || ! is_string( $record['script_id'] ?? null ) || ! is_string( $record['property'] ?? null ) || ! is_string( $record['expected_value'] ?? null ) ) {
			throw new InvalidArgumentException( 'Contract Lab JavaScript marker has an unknown version, source kind, or field set.' );
		}
		$marker = self::new( $record['logical_id'], $record['fixture_id'], $record['script_id'], $record['property'], $record['expected_value'] );
		if ( $marker->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab JavaScript marker must be canonical.' );
		}

		return $marker;
	}

	public function logical_id(): string {
		return $this->logical_id;
	}

	public function fixture_id(): string {
		return $this->fixture_id;
	}

	public function script_id(): string {
		return $this->script_id;
	}

	public function property(): string {
		return $this->property;
	}

	public function expected_value(): string {
		return $this->expected_value;
	}

	/**
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'marker_version' => self::MARKER_VERSION,
			'source_kind'    => self::SOURCE_KIND,
			'logical_id'     => $this->logical_id,
			'fixture_id'     => $this->fixture_id,
			'script_id'      => $this->script_id,
			'property'       => $this->property,
			'expected_value' => $this->expected_value,
		);
	}
}
