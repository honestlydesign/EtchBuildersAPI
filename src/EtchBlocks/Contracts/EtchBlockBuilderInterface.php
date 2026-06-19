<?php
/**
 * Contract for Etch block builders.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks\Contracts;

use HonestlyDesign\EtchBuilders\Block;

/**
 * Interface for fluent Etch block builders.
 *
 * All Etch block type builders (ElementBlock, TextBlock, etc.) implement
 * this interface to ensure a consistent API contract.
 */
interface EtchBlockBuilderInterface {

	/**
	 * Build and return the Block instance.
	 *
	 * @return Block
	 */
	public function to_block(): Block;
}
