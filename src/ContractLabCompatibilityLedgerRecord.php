<?php
/**
 * One immutable, reviewed Contract Lab compatibility ledger record.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;

/**
 * Records runtime identity separately from the accepted semantic snapshot.
 */
final class ContractLabCompatibilityLedgerRecord {

	public const RECORD_VERSION = '1';

	/** @var array<int, string> */
	private const CLASSIFICATIONS = array( 'green', 'yellow', 'red' );

	/** @var array<int, string> */
	private const ENVIRONMENT_FIELDS = array(
		'doctor_status',
		'environment_version',
		'lab_id',
		'localwp_version',
		'marker_verified',
		'observation_schema_version',
		'php_version',
		'probe_schema_version',
		'site_id',
		'wordpress_version',
	);

	private function __construct( private readonly array $record ) {
	}

	/**
	 * Create a record only from a validated accepted snapshot.
	 *
	 * @param array<string, mixed> $environment
	 * @param array<int, array<string, mixed>> $evidence
	 */
	public static function from_snapshot(
		string $record_id,
		string $builder_contract_version,
		string $builder_source_revision,
		string $etch_release,
		string $artifact_fingerprint,
		array $environment,
		string $classification,
		ContractLabSnapshot $accepted_snapshot,
		array $evidence,
		?ContractLabCompatibilityReview $review = null
	): self {
		$record = array(
			'record_version'            => self::RECORD_VERSION,
			'record_id'                 => $record_id,
			'builder_contract_version'  => $builder_contract_version,
			'builder_source_revision'   => $builder_source_revision,
			'etch_release'              => $etch_release,
			'artifact_fingerprint'      => $artifact_fingerprint,
			'environment'               => $environment,
			'classification'            => $classification,
			'accepted_snapshot_version' => $accepted_snapshot->snapshot_version(),
			'accepted_snapshot_digest'  => $accepted_snapshot->digest(),
			'evidence'                  => $evidence,
		);
		if ( null !== $review ) {
			$record['review'] = $review->to_array();
		}

		return self::from_array( $record );
	}

	/**
	 * Rehydrate and validate one canonical ledger record.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$expected_keys = array(
				'artifact_fingerprint',
				'accepted_snapshot_digest',
				'accepted_snapshot_version',
				'builder_contract_version',
				'builder_source_revision',
				'classification',
				'etch_release',
				'environment',
				'evidence',
				'record_id',
				'record_version',
		);
		if ( array_key_exists( 'review', $record ) ) {
			$expected_keys[] = 'review';
		}
		self::assert_exact_keys( $record, $expected_keys, 'Contract Lab compatibility ledger record' );

		$strings = array(
			'record_version',
			'record_id',
			'builder_contract_version',
			'builder_source_revision',
			'etch_release',
			'artifact_fingerprint',
			'classification',
			'accepted_snapshot_version',
			'accepted_snapshot_digest',
		);
		foreach ( $strings as $key ) {
			if ( ! is_string( $record[ $key ] ?? null ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab ledger field "%s" must be a string.', $key ) );
			}
		}
		if ( self::RECORD_VERSION !== $record['record_version'] ) {
			throw new ContractLabObservationException( 'unsupported', 'Contract Lab compatibility ledger record version is unsupported.' );
		}
		try {
			ContractLabManifestSafety::assert_stable_id( $record['record_id'], 'Contract Lab ledger record ID' );
			ContractLabVersionConstraint::assert_version( $record['builder_contract_version'], 'Contract Lab Builder contract version' );
			ContractLabVersionConstraint::assert_version( $record['etch_release'], 'Contract Lab Etch release' );
			ContractLabManifestSafety::assert_digest( $record['artifact_fingerprint'], 'Contract Lab ledger artifact fingerprint' );
			ContractLabManifestSafety::assert_digest( $record['accepted_snapshot_digest'], 'Contract Lab ledger accepted snapshot digest' );
		} catch ( \InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}
		if ( ! in_array( $record['classification'], self::CLASSIFICATIONS, true ) ) {
			throw new ContractLabObservationException( 'unsupported', sprintf( 'Contract Lab ledger classification "%s" is unsupported; inconclusive results are not compatibility records.', $record['classification'] ) );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{7,64}$/D', $record['builder_source_revision'] ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab Builder source revision must be a hexadecimal commit identifier.' );
		}
		if ( ContractLabSnapshot::SNAPSHOT_VERSION !== $record['accepted_snapshot_version'] ) {
			throw new ContractLabObservationException( 'unsupported', 'Contract Lab ledger accepted snapshot version is unsupported.' );
		}

		$environment = $record['environment'] ?? null;
		if ( ! is_array( $environment ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab ledger environment evidence must be an object.' );
		}
		$environment = self::normalize_environment( $environment );
		$evidence    = $record['evidence'] ?? null;
		if ( ! is_array( $evidence ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab ledger evidence must be an ordered list.' );
		}
		$evidence = self::normalize_evidence( $evidence );
		$review   = null;
		if ( array_key_exists( 'review', $record ) ) {
			if ( ! is_array( $record['review'] ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab ledger review must be an object.' );
			}
			$review = ContractLabCompatibilityReview::from_array( $record['review'] );
			if ( $review->classification() !== $record['classification'] ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab ledger review classification must match the ledger classification.' );
			}
		}

		$canonical = array(
			'record_version'            => self::RECORD_VERSION,
			'record_id'                 => $record['record_id'],
			'builder_contract_version'  => $record['builder_contract_version'],
			'builder_source_revision'   => $record['builder_source_revision'],
			'etch_release'              => $record['etch_release'],
			'artifact_fingerprint'      => $record['artifact_fingerprint'],
			'environment'               => $environment,
			'classification'            => $record['classification'],
			'accepted_snapshot_version' => ContractLabSnapshot::SNAPSHOT_VERSION,
			'accepted_snapshot_digest'  => $record['accepted_snapshot_digest'],
			'evidence'                  => $evidence,
		);
		if ( null !== $review ) {
			$canonical['review'] = $review->to_array();
		}

		return new self( $canonical );
	}

	public function record_id(): string {
		return $this->record['record_id'];
	}

	public function builder_contract_version(): string {
		return $this->record['builder_contract_version'];
	}

	public function builder_source_revision(): string {
		return $this->record['builder_source_revision'];
	}

	public function etch_release(): string {
		return $this->record['etch_release'];
	}

	public function artifact_fingerprint(): string {
		return $this->record['artifact_fingerprint'];
	}

	public function accepted_snapshot_version(): string {
		return $this->record['accepted_snapshot_version'];
	}

	public function classification(): string {
		return $this->record['classification'];
	}

	public function is_compatible(): bool {
		return in_array( $this->classification(), array( 'green', 'yellow' ), true );
	}

	public function accepted_snapshot_digest(): string {
		return $this->record['accepted_snapshot_digest'];
	}

	public function review(): ?ContractLabCompatibilityReview {
		if ( ! isset( $this->record['review'] ) ) {
			return null;
		}

		return ContractLabCompatibilityReview::from_array( $this->record['review'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return ImmutableArray::copy( $this->record, 'Contract Lab ledger records must contain only persisted data.' );
	}

	/**
	 * @param array<string, mixed> $environment
	 * @return array<string, mixed>
	 */
	private static function normalize_environment( array $environment ): array {
		self::assert_exact_keys( $environment, self::ENVIRONMENT_FIELDS, 'Contract Lab ledger environment evidence' );
		$required_strings = array( 'environment_version', 'lab_id', 'site_id', 'wordpress_version', 'php_version', 'localwp_version', 'probe_schema_version', 'observation_schema_version', 'doctor_status' );
		foreach ( $required_strings as $key ) {
			if ( ! is_string( $environment[ $key ] ?? null ) || '' === $environment[ $key ] || trim( $environment[ $key ] ) !== $environment[ $key ] || preg_match( '/[[:cntrl:]]/', $environment[ $key ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab ledger environment field "%s" must be a safe string.', $key ) );
			}
		}
		if ( '1' !== $environment['environment_version'] || ContractLabBinding::LAB_ID !== $environment['lab_id'] || 'ready' !== $environment['doctor_status'] ) {
			throw new ContractLabObservationException( 'inconclusive', 'Contract Lab ledger environment must be marker-verified and doctor-ready.' );
		}
		if ( ! is_bool( $environment['marker_verified'] ) || true !== $environment['marker_verified'] ) {
			throw new ContractLabObservationException( 'inconclusive', 'Contract Lab ledger requires verified Contract Lab marker evidence.' );
		}
		try {
			ContractLabManifestSafety::assert_local_identifier( $environment['site_id'], 'Contract Lab ledger site ID' );
			ContractLabVersionConstraint::assert_version( $environment['wordpress_version'], 'Contract Lab ledger WordPress version' );
			ContractLabVersionConstraint::assert_version( $environment['php_version'], 'Contract Lab ledger PHP version' );
			ContractLabVersionConstraint::assert_version( $environment['localwp_version'], 'Contract Lab ledger LocalWP version' );
		} catch ( \InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}

		return array(
			'environment_version'        => $environment['environment_version'],
			'lab_id'                     => $environment['lab_id'],
			'site_id'                    => $environment['site_id'],
			'wordpress_version'          => $environment['wordpress_version'],
			'php_version'                => $environment['php_version'],
			'localwp_version'            => $environment['localwp_version'],
			'probe_schema_version'       => $environment['probe_schema_version'],
			'observation_schema_version' => $environment['observation_schema_version'],
			'doctor_status'              => $environment['doctor_status'],
			'marker_verified'            => $environment['marker_verified'],
		);
	}

	/**
	 * @param array<int, mixed> $evidence
	 * @return array<int, array{kind: string, status: string, summary: string}>
	 */
	private static function normalize_evidence( array $evidence ): array {
		if ( ! array_is_list( $evidence ) || array() === $evidence ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab ledger evidence must be a non-empty ordered list.' );
		}
		$normalized = array();
		$seen       = array();
		foreach ( $evidence as $item ) {
			if ( ! is_array( $item ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab ledger evidence entries must be records.' );
			}
			self::assert_exact_keys( $item, array( 'kind', 'status', 'summary' ), 'Contract Lab ledger evidence entry' );
			if ( ! is_string( $item['kind'] ?? null ) || ! is_string( $item['status'] ?? null ) || ! is_string( $item['summary'] ?? null ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab ledger evidence entries must contain strings.' );
			}
			try {
				ContractLabManifestSafety::assert_stable_token( $item['kind'], 'Contract Lab ledger evidence kind' );
			} catch ( \InvalidArgumentException $error ) {
				throw new ContractLabObservationException( 'malformed', $error->getMessage() );
			}
			if ( ! in_array( $item['status'], array( 'passed', 'failed' ), true ) ) {
				throw new ContractLabObservationException( 'inconclusive', 'Contract Lab ledger cannot record inconclusive evidence.' );
			}
			if ( '' === $item['summary'] || trim( $item['summary'] ) !== $item['summary'] || preg_match( '/[[:cntrl:]]/', $item['summary'] ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab ledger evidence summary must be a safe non-empty string.' );
			}
			if ( isset( $seen[ $item['kind'] ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab ledger evidence has duplicate kind "%s".', $item['kind'] ) );
			}
			$seen[ $item['kind'] ] = true;
			$normalized[] = array( 'kind' => $item['kind'], 'status' => $item['status'], 'summary' => $item['summary'] );
		}

		return $normalized;
	}

	/**
	 * @param array<array-key, mixed> $record
	 * @param array<int, string>      $expected
	 */
	private static function assert_exact_keys( array $record, array $expected, string $label ): void {
		$actual = array_keys( $record );
		$left   = $actual;
		$right  = $expected;
		sort( $left );
		sort( $right );
		if ( $left !== $right ) {
			throw new ContractLabObservationException( 'malformed', $label . ' has an unknown or missing field.' );
		}
	}
}
