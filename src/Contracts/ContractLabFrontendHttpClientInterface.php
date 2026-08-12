<?php
/**
 * HTTP client boundary for maintainer-only Contract Lab frontend probes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\ContractLabFrontendHttpResponse;

/**
 * Keeps transport and LocalWP concerns outside the pure frontend probe.
 */
interface ContractLabFrontendHttpClientInterface {

	/**
	 * Fetch one same-origin fixture or stylesheet path.
	 */
	public function get( string $path ): ContractLabFrontendHttpResponse;
}
