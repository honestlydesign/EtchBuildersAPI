<?php
/**
 * Schema authority for canonical Contract Lab candidate observations.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;

/**
 * Declares the exact candidate envelope, volatile fields, and semantic fields.
 */
final class ContractLabCandidateObservationSchema {

	public const SCHEMA_VERSION = '1';

	public const CANDIDATE_VERSION = '1';

	/** @var array<int, string> */
	private const TOP_LEVEL_FIELDS = array( 'candidate_version', 'contract_version', 'integration_outcomes', 'metadata', 'runtime_shape', 'schema_version' );

	/** @var array<int, string> */
	private const METADATA_FIELDS = array( 'artifact_fingerprint', 'etch_release', 'observed_at', 'request_id' );

	/** @var array<int, string> */
	private const PERSISTED_METADATA_FIELDS = array( 'artifact_fingerprint', 'etch_release' );

	/** @var array<int, string> */
	private const VOLATILE_METADATA_FIELDS = array( 'observed_at', 'request_id' );

	private function __construct() {
	}

	public static function current(): self {
		return new self();
	}

	/**
	 * Validate the candidate envelope and normalize its metadata.
	 *
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	public function normalize( array $record ): array {
		AcyclicArrayGuard::assert_acyclic( $record );
		self::assert_exact_keys( $record, self::TOP_LEVEL_FIELDS, 'Contract Lab candidate observation' );
		if ( self::CANDIDATE_VERSION !== ( $record['candidate_version'] ?? null ) || self::SCHEMA_VERSION !== ( $record['schema_version'] ?? null ) ) {
			throw new ContractLabObservationException( 'unsupported', 'Contract Lab candidate observation schema version is unsupported.' );
		}
		$contract_version = $record['contract_version'] ?? null;
		if ( ! is_string( $contract_version ) || '' === $contract_version || trim( $contract_version ) !== $contract_version || preg_match( '/[[:cntrl:]]/', $contract_version ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab candidate contract version must be a safe non-empty string.' );
		}
		$metadata = $record['metadata'] ?? null;
		if ( ! is_array( $metadata ) || array_is_list( $metadata ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab candidate metadata must be an associative map.' );
		}
		$normalized_metadata = $this->normalize_metadata( $metadata );
		if ( ! is_array( $record['runtime_shape'] ?? null ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab candidate runtime_shape must be an object.' );
		}
		if ( ! is_array( $record['integration_outcomes'] ?? null ) || ! array_is_list( $record['integration_outcomes'] ) || array() === $record['integration_outcomes'] ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab candidate integration_outcomes must be a non-empty ordered list.' );
		}

		return array(
			'candidate_version'     => self::CANDIDATE_VERSION,
			'schema_version'        => self::SCHEMA_VERSION,
			'contract_version'      => $contract_version,
			'metadata'              => $normalized_metadata,
			'runtime_shape'         => $record['runtime_shape'],
			'integration_outcomes'  => $record['integration_outcomes'],
		);
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array{etch_release: string, artifact_fingerprint: string}
	 */
	public function normalize_metadata( array $metadata ): array {
		$keys = array_keys( $metadata );
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, self::METADATA_FIELDS, true ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab candidate metadata contains an unknown field.' );
			}
		}
		foreach ( self::PERSISTED_METADATA_FIELDS as $required ) {
			if ( ! array_key_exists( $required, $metadata ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab candidate metadata is missing required field "%s".', $required ) );
			}
		}
		foreach ( self::VOLATILE_METADATA_FIELDS as $volatile ) {
			if ( ! array_key_exists( $volatile, $metadata ) ) {
				continue;
			}
			$value = $metadata[ $volatile ];
			if ( ! is_string( $value ) || '' === $value || trim( $value ) !== $value || preg_match( '/[[:cntrl:]]/', $value ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab candidate volatile field "%s" must be a safe string.', $volatile ) );
			}
		}
		$etch_release = $metadata['etch_release'];
		if ( ! is_string( $etch_release ) || '' === $etch_release || trim( $etch_release ) !== $etch_release || preg_match( '/[[:cntrl:]]/', $etch_release ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab candidate Etch release identity must be a safe string.' );
		}
		$artifact_fingerprint = $metadata['artifact_fingerprint'];
		if ( ! is_string( $artifact_fingerprint ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab candidate artifact fingerprint must be a string.' );
		}
		try {
			ContractLabManifestSafety::assert_digest( $artifact_fingerprint, 'Contract Lab candidate artifact fingerprint' );
		} catch ( \InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}

		return array(
			'etch_release'         => $etch_release,
			'artifact_fingerprint' => $artifact_fingerprint,
		);
	}

	/**
	 * Select only the schema-declared semantic fields for snapshot/diff work.
	 * Release identity and artifact fingerprint remain candidate envelope data.
	 *
	 * @param array<string, mixed> $normalized
	 * @return array<string, mixed>
	 */
	public function semantic_projection( array $normalized ): array {
		return array(
			'schema_version'       => $normalized['schema_version'],
			'candidate_version'    => $normalized['candidate_version'],
			'contract_version'     => $normalized['contract_version'],
			'runtime_shape'        => $normalized['runtime_shape'],
			'integration_outcomes' => $normalized['integration_outcomes'],
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version'          => self::SCHEMA_VERSION,
			'candidate_version'       => self::CANDIDATE_VERSION,
			'top_level_fields'        => self::TOP_LEVEL_FIELDS,
			'persistent_metadata'     => self::PERSISTED_METADATA_FIELDS,
			'volatile_metadata'       => self::VOLATILE_METADATA_FIELDS,
			'semantic_fields'         => array( 'schema_version', 'candidate_version', 'contract_version', 'runtime_shape', 'integration_outcomes' ),
			'order_sensitive_lists'   => array( 'runtime_shape.required_blocks', 'runtime_shape.blocks', 'runtime_shape.components', 'integration_outcomes' ),
		);
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
			throw new ContractLabObservationException( 'malformed', $label . ' has an unknown or missing field.' );
		}
	}
}
