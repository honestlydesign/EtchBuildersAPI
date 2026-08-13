<?php
/**
 * Executable boundary for the composite Contract Lab core probe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabBlockWireAdapterInterface;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabCoreProbeSourceInterface;

/**
 * Binds the public core probe observations into maintainer-gate evidence.
 *
 * The private constructor is intentional. A caller can provide observations
 * to the real runner, but cannot manufacture the producer capability required
 * by ContractLabCoreProbeEvidence::from_execution().
 */
final class ContractLabCoreProbeRunner {

	private function __construct() {
	}

	/**
	 * Execute the composite core probe from raw public runtime surfaces.
	 *
	 * The registry, persistence, and resolution arrays are deliberately raw
	 * adapter output. This method performs the normalization and the real block
	 * wire round trip before it can issue maintainer-gate evidence.
	 *
	 */
	public static function run(
		ContractLabCoreProbeSourceInterface $source,
		ComponentContractCatalog $component_catalog,
		string $markup,
		ContractLabBlockWireAdapterInterface $wire_adapter
	): ContractLabCoreProbeEvidence {
		$runner = new self();
		$runtime_shape = ContractLabRuntimeShapeObservation::from_public_surfaces( $source->required_block_names(), $source->registry_records(), $component_catalog, $source->required_component_keys() );
		$block_round_trip = ContractLabBlockRoundTripProbe::run( $markup, $wire_adapter );
		$handoff_surfaces = $source->persistence_handoff_surfaces();
		$persistence_handoff = ContractLabPersistenceHandoffObservation::from_public_surfaces( $handoff_surfaces['styles'], $handoff_surfaces['components'] );
		$runtime_resolution = ContractLabEtchRuntimeResolutionObservation::from_array( $source->runtime_resolution_record() );

		return ContractLabCoreProbeEvidence::from_execution( $runner, $runtime_shape, $block_round_trip, $persistence_handoff, $runtime_resolution );
	}
}
