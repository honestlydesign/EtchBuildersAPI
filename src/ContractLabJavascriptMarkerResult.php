<?php
/**
 * Result of one passive Contract Lab JavaScript marker assertion.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Separates marker mismatch from navigation/browser infrastructure outcomes.
 */
final class ContractLabJavascriptMarkerResult {

	/**
	 * @param array<int, string> $failures
	 */
	private function __construct(
		private readonly ContractLabJavascriptMarker $marker,
		private readonly string $status,
		private readonly ?string $observed_value,
		private readonly ?string $reason,
		private readonly array $failures,
		private readonly ?ContractLabExecutionProvenance $execution_provenance
	) {
	}

	public static function observed( ContractLabJavascriptMarker $marker, string $observed_value ): self {
		return new self( $marker, 'observed', $observed_value, null, array(), null );
	}

	public static function failed( ContractLabJavascriptMarker $marker, ?string $observed_value, string $reason ): self {
		return new self( $marker, 'failed', $observed_value, $reason, array( 'marker missing or mismatched' ), null );
	}

	public static function skipped( ContractLabJavascriptMarker $marker, string $reason ): self {
		return new self( $marker, 'skipped', null, $reason, array( 'unsupported prerequisite' ), null );
	}

	public static function inconclusive( ContractLabJavascriptMarker $marker, string $reason ): self {
		return new self( $marker, 'inconclusive', null, $reason, array( 'browser infrastructure unavailable' ), null );
	}

	public function with_execution_provenance( ContractLabExecutionProvenance $provenance ): self {
		if ( ! $provenance->matches_javascript( $this->marker, $this->to_array() ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'JavaScript execution provenance does not identify marker "%s" exactly.', $this->marker->logical_id() ) );
		}

		return new self( $this->marker, $this->status, $this->observed_value, $this->reason, $this->failures, $provenance );
	}

	public function marker(): ContractLabJavascriptMarker {
		return $this->marker;
	}

	public function execution_provenance(): ?ContractLabExecutionProvenance {
		return $this->execution_provenance;
	}

	public function status(): string {
		return $this->status;
	}

	public function assertions_passed(): bool {
		return 'observed' === $this->status && array() === $this->failures;
	}

	public function observed_value(): ?string {
		return $this->observed_value;
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

		return sprintf( 'JavaScript marker "%s" on fixture "%s" is %s: %s', $this->marker->logical_id(), $this->marker->fixture_id(), $this->status, implode( '; ', $details ) );
	}

	/**
	 * Return the exact candidate-facing marker result projection.
	 *
	 * @return array<string, mixed>
	 */
	public function semantic_projection(): array {
		return array(
			'marker'         => $this->marker->to_array(),
			'status'         => $this->status,
			'observed_value' => $this->observed_value,
			'reason'         => $this->reason,
			'failures'       => $this->failures,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'marker'         => $this->marker->to_array(),
			'status'         => $this->status,
			'observed_value' => $this->observed_value,
			'reason'         => $this->reason,
			'failures'       => $this->failures,
		);
	}
}
