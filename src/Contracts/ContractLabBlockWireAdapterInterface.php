<?php
/**
 * Public WordPress block wire adapter boundary for Contract Lab probes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

/**
 * Adapters must delegate parsing and serialization to the real runtime.
 */
interface ContractLabBlockWireAdapterInterface {

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function parse( string $markup ): array;

	/**
	 * Serialize a parsed WordPress block tree through the runtime.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 */
	public function serialize( array $blocks ): string;
}
