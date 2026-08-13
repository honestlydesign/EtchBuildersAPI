<?php
/**
 * Result of one browser preservation sentinel.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Retains before/after observations for semantic drift diagnosis.
 */
final class ContractLabBrowserSentinelResult {

	/**
	 * @param array<int, string> $failures
	 */
	private function __construct(
		private readonly ContractLabBrowserSentinel $sentinel,
		private readonly string $status,
		private readonly ?ContractLabFrontendObservation $before,
		private readonly ?ContractLabFrontendObservation $after,
		private readonly ?string $reason,
		private readonly array $failures,
		private readonly ?ContractLabExecutionProvenance $execution_provenance
	) {
	}

	public static function matched(
		ContractLabBrowserSentinel $sentinel,
		ContractLabFrontendObservation $before,
		ContractLabFrontendObservation $after
	): self {
		return new self( $sentinel, 'matched', $before, $after, null, array(), null );
	}

	public static function drift(
		ContractLabBrowserSentinel $sentinel,
		ContractLabFrontendObservation $before,
		ContractLabFrontendObservation $after
	): self {
		return new self( $sentinel, 'drift', $before, $after, 'Semantic observation changed after save and reload.', array( 'semantic drift' ), null );
	}

	public static function skipped( ContractLabBrowserSentinel $sentinel, ?ContractLabFrontendObservation $before, string $reason ): self {
		return new self( $sentinel, 'skipped', $before, null, $reason, array( 'unsupported prerequisite' ), null );
	}

	public static function inconclusive( ContractLabBrowserSentinel $sentinel, ?ContractLabFrontendObservation $before, string $reason ): self {
		return new self( $sentinel, 'inconclusive', $before, null, $reason, array( 'browser infrastructure unavailable' ), null );
	}

	public static function failed( ContractLabBrowserSentinel $sentinel, ?ContractLabFrontendObservation $before, ?ContractLabFrontendObservation $after, string $reason ): self {
		return new self( $sentinel, 'failed', $before, $after, $reason, array( 'browser sentinel failure' ), null );
	}

	public function with_execution_provenance( ContractLabExecutionProvenance $provenance ): self {
		if ( ! $provenance->matches_browser( $this->sentinel, $this->to_array() ) ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Browser execution provenance does not identify sentinel "%s" exactly.', $this->sentinel->logical_id() ) );
		}

		return new self( $this->sentinel, $this->status, $this->before, $this->after, $this->reason, $this->failures, $provenance );
	}

	public function sentinel(): ContractLabBrowserSentinel {
		return $this->sentinel;
	}

	public function status(): string {
		return $this->status;
	}

	public function assertions_passed(): bool {
		return 'matched' === $this->status && array() === $this->failures;
	}

	public function before(): ?ContractLabFrontendObservation {
		return $this->before;
	}

	public function after(): ?ContractLabFrontendObservation {
		return $this->after;
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

		return sprintf(
			'Browser sentinel "%s" for fixture "%s" is %s: %s',
			$this->sentinel->logical_id(),
			$this->sentinel->fixture_id(),
			$this->status,
			implode( '; ', $details )
		);
	}

	/**
	 * Return the durable semantic browser result without fixture URLs or
	 * machine paths. This is the exact candidate-facing projection.
	 *
	 * @return array<string, mixed>
	 */
	public function semantic_projection(): array {
		$before = null !== $this->before ? $this->before->to_array() : null;
		$after  = null !== $this->after ? $this->after->to_array() : null;
		if ( is_array( $before ) ) {
			unset( $before['fixture_path'] );
		}
		if ( is_array( $after ) ) {
			unset( $after['fixture_path'] );
		}

		return array(
			'sentinel' => $this->sentinel->logical_id(),
			'status'   => $this->status,
			'before'   => $before,
			'after'    => $after,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'sentinel' => $this->sentinel->to_array(),
			'status'   => $this->status,
			'before'   => null !== $this->before ? $this->before->to_array() : null,
			'after'    => null !== $this->after ? $this->after->to_array() : null,
			'reason'   => $this->reason,
			'failures' => $this->failures,
		);
	}
}
