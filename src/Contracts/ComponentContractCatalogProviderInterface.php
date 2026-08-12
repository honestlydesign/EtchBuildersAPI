<?php
/**
 * Component Contract Catalog provider port.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;

/**
 * Supplies one validated immutable component authoring catalog.
 */
interface ComponentContractCatalogProviderInterface {

	public function catalog(): ComponentContractCatalog;
}
