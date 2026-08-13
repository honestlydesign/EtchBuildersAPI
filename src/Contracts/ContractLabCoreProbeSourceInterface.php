<?php
/**
 * Raw public-surface source for the composite Contract Lab core probe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

/**
 * Keeps environment/API access outside the evidence value and runner.
 */
interface ContractLabCoreProbeSourceInterface {

	/**
	 * @return array<int, string>
	 */
	public function required_block_names(): array;

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function registry_records(): array;

	/**
	 * @return array<int, string>
	 */
	public function required_component_keys(): array;

	/**
	 * @return array{styles: array<int, array<string, mixed>>, components: array<int, array<string, mixed>>}
	 */
	public function persistence_handoff_surfaces(): array;

	/**
	 * @return array<string, mixed>
	 */
	public function runtime_resolution_record(): array;
}
