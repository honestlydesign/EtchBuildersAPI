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
		private readonly array $failures
	) {
	}

	public static function observed( ContractLabJavascriptMarker $marker, string $observed_value ): self {
		return new self( $marker, 'observed', $observed_value, null, array() );
	}

	public static function failed( ContractLabJavascriptMarker $marker, ?string $observed_value, string $reason ): self {
		return new self( $marker, 'failed', $observed_value, $reason, array( 'marker missing or mismatched' ) );
	}

	public static function skipped( ContractLabJavascriptMarker $marker, string $reason ): self {
		return new self( $marker, 'skipped', null, $reason, array( 'unsupported prerequisite' ) );
	}

	public static function inconclusive( ContractLabJavascriptMarker $marker, string $reason ): self {
		return new self( $marker, 'inconclusive', null, $reason, array( 'browser infrastructure unavailable' ) );
	}

	public function marker(): ContractLabJavascriptMarker {
		return $this->marker;
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
