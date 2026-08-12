<?php
/**
 * One named, normalized Contract Lab integration outcome.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;

/**
 * Keeps an integration result in a small semantic, JSON-like shape.
 */
final class ContractLabIntegrationOutcome {

	/** @var array<int, string> */
	private const STATUSES = array( 'observed', 'matched', 'drift', 'failed', 'skipped', 'inconclusive' );

	/**
	 * @param array<string, mixed>|null $observation
	 */
	private function __construct(
		private readonly string $name,
		private readonly string $status,
		private readonly ?array $observation,
		private readonly ?string $reason
	) {
	}

	/**
	 * Rehydrate one canonical named outcome.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$allowed = array( 'name', 'observation', 'reason', 'status' );
		$keys    = array_keys( $record );
		sort( $keys );
		$required = array( 'name', 'observation', 'status' );
		if ( array_key_exists( 'reason', $record ) ) {
			$required[] = 'reason';
		}
		sort( $required );
		if ( $keys !== $required || array_diff( $keys, $allowed ) !== array() ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab integration outcome has an unknown or missing field.' );
		}

		$name   = $record['name'] ?? null;
		$status = $record['status'] ?? null;
		if ( ! is_string( $name ) || ! is_string( $status ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab integration outcome name and status must be strings.' );
		}
		try {
			ContractLabManifestSafety::assert_stable_id( $name, 'Contract Lab integration outcome name' );
		} catch ( \InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new ContractLabObservationException( 'unsupported', sprintf( 'Contract Lab integration outcome status "%s" is unsupported.', $status ) );
		}

		$observation = $record['observation'] ?? null;
		if ( null !== $observation && ! is_array( $observation ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab integration outcome observation must be an object or null.' );
		}
		if ( is_array( $observation ) ) {
			$observation = ImmutableArray::canonicalize(
				ImmutableArray::copy( $observation, 'Contract Lab integration outcome observation must contain only persisted data.' )
			);
		}

		$reason = null;
		if ( array_key_exists( 'reason', $record ) ) {
			$reason = $record['reason'];
			if ( ! is_string( $reason ) || '' === $reason || trim( $reason ) !== $reason || 1 === preg_match( '/[\x00-\x1F\x7F]/', $reason ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab integration outcome reason must be a safe non-empty string.' );
			}
		}

		return new self( $name, $status, $observation, $reason );
	}

	public function name(): string {
		return $this->name;
	}

	public function status(): string {
		return $this->status;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function observation(): ?array {
		return $this->observation;
	}

	public function reason(): ?string {
		return $this->reason;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$record = array(
			'name'        => $this->name,
			'status'      => $this->status,
			'observation' => $this->observation,
		);
		if ( null !== $this->reason ) {
			$record['reason'] = $this->reason;
		}

		return $record;
	}
}
