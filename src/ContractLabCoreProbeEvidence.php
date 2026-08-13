<?php
/**
 * Typed raw evidence from the non-browser Contract Lab probes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Binds candidate outcome projections to the observations actually produced
 * by the runtime-shape, wire, persistence, and Etch-resolution probes.
 */
final class ContractLabCoreProbeEvidence {

	private function __construct(
		private readonly ContractLabRuntimeShapeObservation $runtime_shape,
		private readonly ContractLabBlockRoundTripResult $block_round_trip,
		private readonly ContractLabPersistenceHandoffObservation $persistence_handoff,
		private readonly ContractLabEtchRuntimeResolutionObservation $runtime_resolution,
		private readonly string $execution_receipt,
		private readonly string $execution_digest
	) {
	}

	/**
	 * Bind the four independently observed probe values to the executable core
	 * probe runner. The runner type is a private-constructor capability: callers
	 * can only obtain it through ContractLabCoreProbeRunner::run().
	 */
	public static function from_execution(
		ContractLabCoreProbeRunner $runner,
		ContractLabRuntimeShapeObservation $runtime_shape,
		ContractLabBlockRoundTripResult $block_round_trip,
		ContractLabPersistenceHandoffObservation $persistence_handoff,
		ContractLabEtchRuntimeResolutionObservation $runtime_resolution
	): self {
		return new self(
			$runtime_shape,
			$block_round_trip,
			$persistence_handoff,
			$runtime_resolution,
			bin2hex( random_bytes( 16 ) ),
			self::digest( $runtime_shape, $block_round_trip, $persistence_handoff, $runtime_resolution )
		);
	}

	public function has_execution_binding(): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/D', $this->execution_receipt )
			&& hash_equals( $this->execution_digest, self::digest( $this->runtime_shape, $this->block_round_trip, $this->persistence_handoff, $this->runtime_resolution ) );
	}

	public function execution_digest(): string {
		return $this->execution_digest;
	}

	public function runtime_shape(): ContractLabRuntimeShapeObservation {
		return $this->runtime_shape;
	}

	public function block_round_trip(): ContractLabBlockRoundTripResult {
		return $this->block_round_trip;
	}

	public function persistence_handoff(): ContractLabPersistenceHandoffObservation {
		return $this->persistence_handoff;
	}

	public function runtime_resolution(): ContractLabEtchRuntimeResolutionObservation {
		return $this->runtime_resolution;
	}

	private static function digest(
		ContractLabRuntimeShapeObservation $runtime_shape,
		ContractLabBlockRoundTripResult $block_round_trip,
		ContractLabPersistenceHandoffObservation $persistence_handoff,
		ContractLabEtchRuntimeResolutionObservation $runtime_resolution
	): string {
		return hash(
			'sha256',
			json_encode(
				array(
					'runtime_shape'       => $runtime_shape->to_array(),
					'block_round_trip'    => $block_round_trip->to_array(),
					'persistence_handoff' => $persistence_handoff->to_array(),
					'runtime_resolution'  => $runtime_resolution->to_array(),
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
			)
		);
	}
}
