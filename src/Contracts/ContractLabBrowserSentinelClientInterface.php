<?php
/**
 * Browser adapter boundary for Contract Lab preservation sentinels.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinel;
use HonestlyDesign\EtchBuilders\ContractLabFrontendObservation;

/**
 * Leaves browser controls and product-specific UI selectors to one adapter.
 */
interface ContractLabBrowserSentinelClientInterface {

	public function capture( ContractLabBrowserSentinel $sentinel ): ContractLabFrontendObservation;

	public function save( ContractLabBrowserSentinel $sentinel ): void;

	public function reload( ContractLabBrowserSentinel $sentinel ): ContractLabFrontendObservation;
}
