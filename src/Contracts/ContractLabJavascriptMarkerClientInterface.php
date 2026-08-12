<?php
/**
 * Browser-session boundary for one passive JavaScript marker read.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\ContractLabJavascriptMarker;

/**
 * Reads a marker from the already navigated composite browser flow.
 */
interface ContractLabJavascriptMarkerClientInterface {

	public function read_marker( ContractLabJavascriptMarker $marker ): ?string;
}
