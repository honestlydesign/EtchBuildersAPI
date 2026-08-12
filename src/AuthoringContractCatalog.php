<?php
/**
 * Versioned machine-readable Authoring Contract Catalog.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Stores intent declarations together with source-derived interface facts.
 *
 * A catalog parsed from an array is canonical but becomes authoritative only
 * after AuthoringContractCatalogGenerator::verify() compares it to source.
 */
final class AuthoringContractCatalog {

	/**
	 * @var array<int, AuthoringCapability>
	 */
	private readonly array $capabilities;

	/**
	 * @var array<string, array<int, AuthoringInterfaceFact>>
	 */
	private readonly array $interfaces_by_capability;

	/**
	 * @param array<int, AuthoringCapability>                         $capabilities
	 * @param array<string, array<int, AuthoringInterfaceFact>> $interfaces_by_capability
	 */
	private function __construct(
		private readonly string $schema_version,
		private readonly string $contract_version,
		private readonly string $package_version,
		private readonly string $source_digest,
		array $capabilities,
		array $interfaces_by_capability
	) {
		$capability_ids = array();
		foreach ( $capabilities as $capability ) {
			if ( isset( $capability_ids[ $capability->id() ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring Contract Catalog has duplicate capability ID "%s".', $capability->id() ) );
			}

			$capability_ids[ $capability->id() ] = true;
			if ( ! array_key_exists( $capability->id(), $interfaces_by_capability ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring Contract Catalog has no interface record for capability "%s".', $capability->id() ) );
			}
		}

		foreach ( $interfaces_by_capability as $capability_id => $interfaces ) {
			if ( ! isset( $capability_ids[ $capability_id ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring Contract Catalog has interface records for unknown capability "%s".', $capability_id ) );
			}

			if ( ! is_array( $interfaces ) || ! array_is_list( $interfaces ) ) {
				throw new InvalidArgumentException( sprintf( 'Authoring Contract Catalog interfaces for "%s" must be ordered.', $capability_id ) );
			}
		}

		$this->capabilities             = array_values( $capabilities );
		$this->interfaces_by_capability = $interfaces_by_capability;
	}

	/**
	 * Build a catalog from generated facts.
	 *
	 * @param array<int, AuthoringCapability>                         $capabilities
	 * @param array<string, array<int, AuthoringInterfaceFact>> $interfaces_by_capability
	 */
	public static function from_generated(
		string $schema_version,
		string $contract_version,
		string $package_version,
		string $source_digest,
		array $capabilities,
		array $interfaces_by_capability
	): self {
		self::assert_metadata( $schema_version, $contract_version, $package_version, $source_digest );
		return new self( $schema_version, $contract_version, $package_version, $source_digest, $capabilities, $interfaces_by_capability );
	}

	/**
	 * Rehydrate a canonical machine-readable projection.
	 *
	 * This validates shape and canonical ordering. Call Generator::verify() before
	 * treating the result as source-authoritative.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'capabilities', 'contract_version', 'package_version', 'schema_version', 'source_digest' ) !== $keys ) {
			throw new InvalidArgumentException( 'Authoring Contract Catalog must contain exactly its version, digest, and capabilities.' );
		}

		$schema_version   = $record['schema_version'];
		$contract_version = $record['contract_version'];
		$package_version  = $record['package_version'];
		$source_digest    = $record['source_digest'];
		$capability_records = $record['capabilities'];
		if ( ! is_string( $schema_version ) || ! is_string( $contract_version ) || ! is_string( $package_version ) || ! is_string( $source_digest ) || ! is_array( $capability_records ) || ! array_is_list( $capability_records ) ) {
			throw new InvalidArgumentException( 'Authoring Contract Catalog has invalid field shapes.' );
		}

		$capabilities             = array();
		$interfaces_by_capability = array();
		foreach ( $capability_records as $capability_record ) {
			if ( ! is_array( $capability_record ) ) {
				throw new InvalidArgumentException( 'Authoring Contract Catalog capabilities must be object records.' );
			}

			$keys = array_keys( $capability_record );
			sort( $keys );
			if ( array( 'diagnostic_ids', 'evidence_ids', 'id', 'interfaces', 'prerequisite_ids', 'recipe_ids', 'status', 'status_reason' ) !== $keys ) {
				throw new InvalidArgumentException( 'Authoring Contract Catalog capability must contain its declaration and interfaces.' );
			}

			$interfaces = $capability_record['interfaces'];
			if ( ! is_array( $interfaces ) || ! array_is_list( $interfaces ) ) {
				throw new InvalidArgumentException( 'Authoring Contract Catalog capability interfaces must be a list.' );
			}

			$declaration_record = $capability_record;
			unset( $declaration_record['interfaces'] );
			$capability = AuthoringCapability::from_array( $declaration_record );
			$facts      = array();
			foreach ( $interfaces as $interface_record ) {
				if ( ! is_array( $interface_record ) ) {
					throw new InvalidArgumentException( 'Authoring Contract Catalog interface entries must be object records.' );
				}

				$facts[] = AuthoringInterfaceFact::from_array( $interface_record );
			}

			$capabilities[]                            = $capability;
			$interfaces_by_capability[ $capability->id() ] = $facts;
		}

		$catalog = self::from_generated( $schema_version, $contract_version, $package_version, $source_digest, $capabilities, $interfaces_by_capability );
		if ( $catalog->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Authoring Contract Catalog must be a canonical generated projection.' );
		}

		return $catalog;
	}

	public function schema_version(): string {
		return $this->schema_version;
	}

	public function contract_version(): string {
		return $this->contract_version;
	}

	public function package_version(): string {
		return $this->package_version;
	}

	public function source_digest(): string {
		return $this->source_digest;
	}

	/**
	 * @return array<int, AuthoringCapability>
	 */
	public function capabilities(): array {
		return $this->capabilities;
	}

	public function capability( string $id ): AuthoringCapability {
		foreach ( $this->capabilities as $capability ) {
			if ( $capability->id() === $id ) {
				return $capability;
			}
		}

		throw new InvalidArgumentException( sprintf( 'Authoring Contract Catalog has no capability ID "%s".', $id ) );
	}

	/**
	 * @return array<int, AuthoringInterfaceFact>
	 */
	public function interfaces_for( string $capability_id ): array {
		if ( ! array_key_exists( $capability_id, $this->interfaces_by_capability ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring Contract Catalog has no capability ID "%s".', $capability_id ) );
		}

		return $this->interfaces_by_capability[ $capability_id ];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$capability_records = array();
		foreach ( $this->capabilities as $capability ) {
			$record               = $capability->to_array();
			$record['interfaces'] = array_map(
				static fn ( AuthoringInterfaceFact $fact ): array => $fact->to_array(),
				$this->interfaces_by_capability[ $capability->id() ]
			);
			$capability_records[] = $record;
		}

		return array(
			'schema_version'   => $this->schema_version,
			'contract_version' => $this->contract_version,
			'package_version'  => $this->package_version,
			'source_digest'    => $this->source_digest,
			'capabilities'     => $capability_records,
		);
	}

	private static function assert_metadata( string $schema_version, string $contract_version, string $package_version, string $source_digest ): void {
		if ( '' === trim( $schema_version ) || '' === trim( $contract_version ) || '' === trim( $package_version ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_digest ) ) {
			throw new InvalidArgumentException( 'Authoring Contract Catalog metadata must contain non-empty versions and a SHA-256 source digest.' );
		}
	}
}
