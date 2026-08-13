<?php
/**
 * Result of probing one composite frontend fixture.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Distinguishes observed, skipped, inconclusive, and failed frontend runs.
 */
final class ContractLabFrontendProbeResult {

	/**
	 * @param array<int, string> $failures
	 */
	private function __construct(
		private readonly string $fixture_id,
		private readonly string $status,
		private readonly ?ContractLabFrontendObservation $observation,
		private readonly ?string $reason,
		private readonly array $failures,
		private readonly ?ContractLabExecutionProvenance $execution_provenance
	) {
	}

	public static function observed( ContractLabFrontendObservation $observation ): self {
		return new self( $observation->fixture_id(), 'observed', $observation, null, array(), null );
	}

	public static function skipped( string $fixture_id, string $reason ): self {
		return new self( $fixture_id, 'skipped', null, $reason, array( 'unsupported prerequisite' ), null );
	}

	public static function inconclusive( string $fixture_id, string $reason ): self {
		return new self( $fixture_id, 'inconclusive', null, $reason, array( 'transport unavailable' ), null );
	}

	/**
	 * @param array<int, string> $failures
	 */
	public static function failed( ContractLabFrontendObservation $observation, array $failures, ?string $reason = null ): self {
		return new self( $observation->fixture_id(), 'failed', $observation, $reason, array_values( $failures ), null );
	}

	public static function failed_without_observation( string $fixture_id, string $reason ): self {
		return new self( $fixture_id, 'failed', null, $reason, array( 'malformed observation' ), null );
	}

	public function with_execution_provenance( ContractLabExecutionProvenance $provenance ): self {
		if ( ! $provenance->matches_frontend( $this->fixture_id, $this->to_array() ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Frontend execution provenance does not identify fixture "%s" exactly.', $this->fixture_id ) );
		}

		return new self( $this->fixture_id, $this->status, $this->observation, $this->reason, $this->failures, $provenance );
	}

	public function fixture_id(): string {
		return $this->fixture_id;
	}

	public function status(): string {
		return $this->status;
	}

	public function assertions_passed(): bool {
		return 'observed' === $this->status && array() === $this->failures;
	}

	public function observation(): ?ContractLabFrontendObservation {
		return $this->observation;
	}

	public function execution_provenance(): ?ContractLabExecutionProvenance {
		return $this->execution_provenance;
	}

	public function reason(): ?string {
		return $this->reason;
	}

	/**
	 * @return array<int, string>
	 */
	public function failures(): array {
		return $this->failures;
	}

	public function failure_message(): string {
		if ( $this->assertions_passed() ) {
			return '';
		}

		$details = array();
		if ( null !== $this->reason ) {
			$details[] = $this->reason;
		}
		if ( array() !== $this->failures ) {
			$details[] = implode( ', ', $this->failures );
		}

		return sprintf( 'Frontend fixture "%s" is %s: %s', $this->fixture_id, $this->status, implode( '; ', $details ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'fixture_id'  => $this->fixture_id,
			'status'      => $this->status,
			'observation' => null !== $this->observation ? $this->observation->to_array() : null,
			'reason'      => $this->reason,
			'failures'    => $this->failures,
		);
	}
}
