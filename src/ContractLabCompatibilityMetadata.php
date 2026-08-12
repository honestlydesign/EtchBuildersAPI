<?php
/**
 * Derived Builder release compatibility metadata.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;

/**
 * Projects the latest certified runtime from the append-only ledger.
 *
 * This value is derived at generation time. It is not an independently
 * writable "latest" record and cannot drift from reviewed ledger history.
 */
final class ContractLabCompatibilityMetadata {

	public const METADATA_VERSION = '1';

	/** @var array<int, string> */
	private const CERTIFIED_CLASSIFICATIONS = array( 'green', 'yellow' );

	/**
	 * @param array<string, mixed> $metadata
	 */
	private function __construct( private readonly array $metadata ) {
	}

	/**
	 * Derive the last certified runtime for one Builder contract version.
	 *
	 * Ledger order is the append-only history order. Red records do not advance
	 * certification, while older Builder contract versions are not candidates
	 * for metadata generated for the requested contract.
	 */
	public static function from_ledger( ContractLabCompatibilityLedger $ledger, string $builder_contract_version ): self {
		try {
			ContractLabVersionConstraint::assert_version( $builder_contract_version, 'Contract Lab compatibility metadata Builder contract version' );
		} catch ( \InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}

		$certified = array();
		$runtime_snapshots = array();
		foreach ( $ledger->records() as $record ) {
			if ( $record->builder_contract_version() !== $builder_contract_version ) {
				continue;
			}

			$runtime_identity = $record->etch_release() . ':' . $record->artifact_fingerprint();
			$runtime_history = $record->accepted_snapshot_version() . ':' . $record->accepted_snapshot_digest() . ':' . $record->classification();
			if ( isset( $runtime_snapshots[ $runtime_identity ] ) && $runtime_snapshots[ $runtime_identity ] !== $runtime_history ) {
				throw new ContractLabObservationException( 'conflict', sprintf( 'Contract Lab compatibility ledger has conflicting snapshot history for Etch release "%s" and artifact fingerprint "%s".', $record->etch_release(), $record->artifact_fingerprint() ) );
			}
			$runtime_snapshots[ $runtime_identity ] = $runtime_history;

			if ( in_array( $record->classification(), self::CERTIFIED_CLASSIFICATIONS, true ) ) {
				$certified[] = $record;
			}
		}

		if ( array() === $certified ) {
			throw new ContractLabObservationException( 'inconclusive', sprintf( 'Contract Lab compatibility ledger has no certified runtime for Builder contract version "%s".', $builder_contract_version ) );
		}

		$record = $certified[ count( $certified ) - 1 ];

		return self::from_array(
			array(
				'metadata_version'             => self::METADATA_VERSION,
				'builder_contract_version'     => $record->builder_contract_version(),
				'builder_source_revision'      => $record->builder_source_revision(),
				'compatibility_classification' => $record->classification(),
				'etch_release'                 => $record->etch_release(),
				'artifact_fingerprint'         => $record->artifact_fingerprint(),
				'accepted_snapshot_version'    => $record->accepted_snapshot_version(),
				'accepted_snapshot_digest'     => $record->accepted_snapshot_digest(),
				'source_record_id'             => $record->record_id(),
			)
		);
	}

	/**
	 * Rehydrate one canonical derived metadata record.
	 *
	 * @param array<string, mixed> $metadata
	 */
	public static function from_array( array $metadata ): self {
		AcyclicArrayGuard::assert_acyclic( $metadata );
		$expected = array(
			'accepted_snapshot_digest',
			'accepted_snapshot_version',
			'artifact_fingerprint',
			'builder_contract_version',
			'builder_source_revision',
			'compatibility_classification',
			'etch_release',
			'metadata_version',
			'source_record_id',
		);
		$actual = array_keys( $metadata );
		sort( $actual );
		$sorted_expected = $expected;
		sort( $sorted_expected );
		if ( $actual !== $sorted_expected ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility metadata has an unknown or missing field.' );
		}

		foreach ( $expected as $key ) {
			if ( ! is_string( $metadata[ $key ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab compatibility metadata field "%s" must be a string.', $key ) );
			}
		}
		if ( self::METADATA_VERSION !== $metadata['metadata_version'] ) {
			throw new ContractLabObservationException( 'unsupported', 'Contract Lab compatibility metadata version is unsupported.' );
		}
		if ( ! in_array( $metadata['compatibility_classification'], self::CERTIFIED_CLASSIFICATIONS, true ) ) {
			throw new ContractLabObservationException( 'unsupported', 'Contract Lab compatibility metadata must describe a certified green or yellow runtime.' );
		}
		try {
			ContractLabVersionConstraint::assert_version( $metadata['builder_contract_version'], 'Contract Lab compatibility metadata Builder contract version' );
			ContractLabVersionConstraint::assert_version( $metadata['etch_release'], 'Contract Lab compatibility metadata Etch release' );
			ContractLabManifestSafety::assert_digest( $metadata['artifact_fingerprint'], 'Contract Lab compatibility metadata artifact fingerprint' );
			ContractLabManifestSafety::assert_digest( $metadata['accepted_snapshot_digest'], 'Contract Lab compatibility metadata accepted snapshot digest' );
			ContractLabManifestSafety::assert_stable_id( $metadata['source_record_id'], 'Contract Lab compatibility metadata source record ID' );
		} catch ( \InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{7,64}$/D', $metadata['builder_source_revision'] ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility metadata Builder source revision must be a hexadecimal commit identifier.' );
		}
		if ( ContractLabSnapshot::SNAPSHOT_VERSION !== $metadata['accepted_snapshot_version'] ) {
			throw new ContractLabObservationException( 'unsupported', 'Contract Lab compatibility metadata accepted snapshot version is unsupported.' );
		}

		return new self(
			ImmutableArray::copy(
				array(
					'metadata_version'             => $metadata['metadata_version'],
					'builder_contract_version'     => $metadata['builder_contract_version'],
					'builder_source_revision'      => $metadata['builder_source_revision'],
					'compatibility_classification' => $metadata['compatibility_classification'],
					'etch_release'                 => $metadata['etch_release'],
					'artifact_fingerprint'         => $metadata['artifact_fingerprint'],
					'accepted_snapshot_version'    => $metadata['accepted_snapshot_version'],
					'accepted_snapshot_digest'     => $metadata['accepted_snapshot_digest'],
					'source_record_id'             => $metadata['source_record_id'],
				),
				'Contract Lab compatibility metadata must contain only persisted data.'
			)
		);
	}

	public function metadata_version(): string {
		return $this->metadata['metadata_version'];
	}

	public function builder_contract_version(): string {
		return $this->metadata['builder_contract_version'];
	}

	public function builder_source_revision(): string {
		return $this->metadata['builder_source_revision'];
	}

	public function compatibility_classification(): string {
		return $this->metadata['compatibility_classification'];
	}

	public function etch_release(): string {
		return $this->metadata['etch_release'];
	}

	public function artifact_fingerprint(): string {
		return $this->metadata['artifact_fingerprint'];
	}

	public function accepted_snapshot_version(): string {
		return $this->metadata['accepted_snapshot_version'];
	}

	public function accepted_snapshot_digest(): string {
		return $this->metadata['accepted_snapshot_digest'];
	}

	public function source_record_id(): string {
		return $this->metadata['source_record_id'];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return ImmutableArray::copy( $this->metadata, 'Contract Lab compatibility metadata must contain only persisted data.' );
	}
}
