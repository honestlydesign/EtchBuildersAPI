<?php
/**
 * Versioned probe or observation schema declaration.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Declares the ordered fields a Contract Lab probe or observation emits.
 */
final class ContractLabSchema {

	public const PROBE_VERSION = '1.0';

	public const OBSERVATION_VERSION = '1.0';

	public const PROBE_SCHEMA_VERSION = self::PROBE_VERSION;

	public const OBSERVATION_SCHEMA_VERSION = self::OBSERVATION_VERSION;

	/**
	 * @param array<int, string> $required_fields
	 */
	private function __construct(
		private readonly string $kind,
		private readonly string $version,
		private readonly array $required_fields
	) {
	}

	/**
	 * Create a versioned schema declaration.
	 *
	 * @param array<int, string> $required_fields
	 */
	public static function new( string $kind, string $version, array $required_fields ): self {
		self::assert_version( $kind, $version );
		if ( array() === $required_fields || ! array_is_list( $required_fields ) ) {
			throw new InvalidArgumentException( sprintf( 'Contract Lab %s schema required fields must be a non-empty ordered list.', $kind ) );
		}

		$seen = array();
		foreach ( $required_fields as $required_field ) {
			if ( ! is_string( $required_field ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab %s schema required fields must contain stable identifiers.', $kind ) );
			}
			ContractLabManifestSafety::assert_stable_id( $required_field, sprintf( 'Contract Lab %s schema field', $kind ) );
			if ( isset( $seen[ $required_field ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab %s schema has duplicate required field "%s".', $kind, $required_field ) );
			}
			$seen[ $required_field ] = true;
		}

		return new self( $kind, $version, array_values( $required_fields ) );
	}

	/**
	 * Create the currently supported probe schema.
	 *
	 * @param array<int, string> $required_fields
	 */
	public static function probe( string $version, array $required_fields ): self {
		return self::new( 'probe', $version, $required_fields );
	}

	/**
	 * Create the currently supported observation schema.
	 *
	 * @param array<int, string> $required_fields
	 */
	public static function observation( string $version, array $required_fields ): self {
		return self::new( 'observation', $version, $required_fields );
	}

	/**
	 * Rehydrate one canonical schema declaration.
	 *
	 * Version compatibility is checked before required fields are interpreted.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		ContractLabManifestSafety::assert_exact_keys( $record, array( 'kind', 'version', 'required_fields' ), 'Contract Lab schema' );
		if ( ! is_string( $record['kind'] ) || ! is_string( $record['version'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab schema has invalid version fields.' );
		}

		self::assert_version( $record['kind'], $record['version'] );
		if ( ! is_array( $record['required_fields'] ) ) {
			throw new InvalidArgumentException( 'Contract Lab schema has invalid required_fields shape.' );
		}

		/** @var array<int, string> $required_fields */
		$required_fields = $record['required_fields'];
		$schema           = self::new( $record['kind'], $record['version'], $required_fields );
		if ( $schema->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab schema must be canonical.' );
		}

		return $schema;
	}

	public function kind(): string {
		return $this->kind;
	}

	public function version(): string {
		return $this->version;
	}

	/**
	 * @return array<int, string>
	 */
	public function required_fields(): array {
		return $this->required_fields;
	}

	/**
	 * @return array{kind: string, version: string, required_fields: array<int, string>}
	 */
	public function to_array(): array {
		return array(
			'kind'            => $this->kind,
			'version'         => $this->version,
			'required_fields' => $this->required_fields,
		);
	}

	private static function assert_version( string $kind, string $version ): void {
		$known_version = match ( $kind ) {
			'probe'       => self::PROBE_VERSION,
			'observation' => self::OBSERVATION_VERSION,
			default       => null,
		};
		if ( null === $known_version ) {
			throw new InvalidArgumentException( sprintf( 'Unknown Contract Lab schema kind "%s".', $kind ) );
		}
		if ( $version !== $known_version ) {
			throw new InvalidArgumentException( sprintf( 'Unknown Contract Lab %s schema version "%s".', $kind, $version ) );
		}
	}
}
