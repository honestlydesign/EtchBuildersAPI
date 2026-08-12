<?php
/**
 * Contract for builders that retain non-wire class provenance.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\ClassToken;

/**
 * Exposes typed class ownership without changing serialized Etch markup.
 */
interface ClassTokenMetadataProviderInterface {

	/**
	 * Return explicit class declarations from the complete structured block tree.
	 *
	 * @return array<int, ClassToken>
	 */
	public function get_class_tokens(): array;
}
