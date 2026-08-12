<?php
/**
 * Immutable content-addressed semantic Contract Lab snapshot.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use JsonException;

/**
 * Stores only the canonical semantic payload of a candidate observation.
 *
 * Candidate metadata and the snapshot envelope are deliberately excluded from
 * the digest, so equivalent observations can share one content address.
 */
final class ContractLabSnapshot {

	public const SNAPSHOT_VERSION = '1';

	/** @var array<int, string> */
	private const PAYLOAD_FIELDS = array( 'candidate_version', 'contract_version', 'integration_outcomes', 'runtime_shape', 'schema_version' );

	/** @var array<int, string> */
	private const FORBIDDEN_KEYS = array(
		'artifact_fingerprint',
		'etch_release',
		'filesystem_path',
		'fixture_path',
		'machine_path',
		'machine_paths',
		'metadata',
		'observed_at',
		'request_id',
		'resource_url',
		'site_url',
		'url',
		'urls',
		'wordpress_root',
	);

	/**
	 * @param array<string, mixed> $payload
	 */
	private function __construct(
		private readonly array $payload,
		private readonly string $digest
	) {
	}

	public static function from_candidate( ContractLabCandidateObservation $candidate ): self {
		// This is a disposable canonical candidate value. Only the explicit
		// CompatibilityWorkflow::accept() action promotes it to accepted truth.
		return self::from_payload( $candidate->semantic_projection() );
	}

	/**
	 * Rehydrate a snapshot envelope and verify its content address.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		self::assert_exact_keys( $record, array( 'digest', 'payload', 'snapshot_version' ), 'Contract Lab snapshot' );
		if ( self::SNAPSHOT_VERSION !== ( $record['snapshot_version'] ?? null ) ) {
			throw new ContractLabObservationException( 'unsupported', 'Contract Lab snapshot version is unsupported.' );
		}
		if ( ! is_string( $record['digest'] ?? null ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab snapshot digest must be a string.' );
		}
		try {
			ContractLabManifestSafety::assert_digest( $record['digest'], 'Contract Lab snapshot digest' );
		} catch ( \InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}
		if ( ! is_array( $record['payload'] ?? null ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab snapshot payload must be an object.' );
		}

		$snapshot = self::from_payload( $record['payload'] );
		if ( $snapshot->digest() !== $record['digest'] ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab snapshot digest does not match its canonical semantic payload.' );
		}

		return $snapshot;
	}

	public function snapshot_version(): string {
		return self::SNAPSHOT_VERSION;
	}

	public function digest(): string {
		return $this->digest;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return ImmutableArray::copy( $this->payload, 'Contract Lab snapshot payload must contain only persisted data.' );
	}

	/**
	 * @return array{snapshot_version: string, digest: string, payload: array<string, mixed>}
	 */
	public function to_array(): array {
		return array(
			'snapshot_version' => self::SNAPSHOT_VERSION,
			'digest'           => $this->digest,
			'payload'          => $this->payload(),
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function from_payload( array $payload ): self {
		try {
			$canonical = self::normalize_payload( $payload );
			$digest    = self::digest_for( $canonical );
		} catch ( ContractLabObservationException $error ) {
			throw $error;
		} catch ( \InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}

		return new self( $canonical, $digest );
	}

	/**
	 * Validate all payload shape before computing a content address.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private static function normalize_payload( array $payload ): array {
		AcyclicArrayGuard::assert_acyclic( $payload );
		$payload = ImmutableArray::copy( $payload, 'Contract Lab snapshot payload must contain only persisted data.' );
		self::assert_exact_keys( $payload, self::PAYLOAD_FIELDS, 'Contract Lab snapshot payload' );

		$schema_version    = $payload['schema_version'] ?? null;
		$candidate_version = $payload['candidate_version'] ?? null;
		$contract_version  = $payload['contract_version'] ?? null;
		if ( ContractLabCandidateObservationSchema::SCHEMA_VERSION !== $schema_version || ContractLabCandidateObservationSchema::CANDIDATE_VERSION !== $candidate_version ) {
			throw new ContractLabObservationException( 'unsupported', 'Contract Lab snapshot semantic schema version is unsupported.' );
		}
		if ( ! is_string( $contract_version ) || '' === $contract_version || trim( $contract_version ) !== $contract_version || preg_match( '/[[:cntrl:]]/', $contract_version ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab snapshot contract version must be a safe non-empty string.' );
		}

		$runtime_shape = $payload['runtime_shape'] ?? null;
		if ( ! is_array( $runtime_shape ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab snapshot runtime_shape must be an object.' );
		}
		$runtime_shape = ContractLabRuntimeShapeObservation::from_array( $runtime_shape )->to_array();

		$outcome_records = $payload['integration_outcomes'] ?? null;
		if ( ! is_array( $outcome_records ) || ! array_is_list( $outcome_records ) || array() === $outcome_records ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab snapshot integration_outcomes must be a non-empty ordered list.' );
		}
		$outcomes   = array();
		$seen_names = array();
		foreach ( $outcome_records as $outcome_record ) {
			if ( ! is_array( $outcome_record ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab snapshot integration outcomes must be records.' );
			}
			$outcome = ContractLabIntegrationOutcome::from_array( $outcome_record );
			if ( isset( $seen_names[ $outcome->name() ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab snapshot contains duplicate integration outcome "%s".', $outcome->name() ) );
			}
			$seen_names[ $outcome->name() ] = true;
			$outcomes[] = $outcome->to_array();
		}

		$canonical = array(
			'schema_version'       => $schema_version,
			'candidate_version'    => $candidate_version,
			'contract_version'     => $contract_version,
			'runtime_shape'        => $runtime_shape,
			'integration_outcomes' => $outcomes,
		);
		self::assert_semantic_content( $canonical );

		return $canonical;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function digest_for( array $payload ): string {
		try {
			$encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( JsonException $error ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab snapshot semantic payload cannot be encoded as canonical JSON.' );
		}

		return hash( 'sha256', $encoded );
	}

	/**
	 * Reject environment identity or sensitive fields before they can become a
	 * durable semantic payload. Contract-defined property paths remain allowed.
	 *
	 * @param mixed $value
	 */
	private static function assert_semantic_content( mixed $value ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				if ( is_string( $key ) && self::is_forbidden_key( $key ) ) {
					throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab snapshot contains forbidden semantic field "%s".', $key ) );
				}
				self::assert_semantic_content( $child );
			}
			return;
		}

		if ( is_string( $value ) && ( 1 === preg_match( '~^(?:https?|file)://~i', $value ) || 1 === preg_match( '#^(?:/Users/|/home/|/private/|/var/|/tmp/|[A-Za-z]:[\\\\/])#', $value ) ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab snapshot cannot contain URLs or machine paths.' );
		}
	}

	private static function is_forbidden_key( string $key ): bool {
		return in_array( strtolower( $key ), self::FORBIDDEN_KEYS, true )
			|| 1 === preg_match( '/(?:secret|password|credential|api[_-]?key|private[_-]?key|proprietary|license|token)/i', $key );
	}

	/**
	 * @param array<string, mixed> $record
	 * @param array<int, string>   $expected
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
