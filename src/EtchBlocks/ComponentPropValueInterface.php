<?php
/**
 * Component prop value encoder contract.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

/**
 * Encodes Etch component prop values into stored attribute strings.
 */
interface ComponentPropValueInterface {

	/**
	 * Encode the prop value for storage in etch/component attrs.attributes.
	 */
	public function encode(): string;
}
