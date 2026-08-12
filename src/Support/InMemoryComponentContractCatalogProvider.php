<?php
/**
 * In-memory Component Contract Catalog provider.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;

/**
 * Carries an already validated catalog for pure tests and local definitions.
 */
final class InMemoryComponentContractCatalogProvider implements ComponentContractCatalogProviderInterface {

	private function __construct( private readonly ComponentContractCatalog $catalog ) {
	}

	public static function from_catalog( ComponentContractCatalog $catalog ): self {
		return new self( $catalog );
	}

	public static function empty(): self {
		return new self( ComponentContractCatalog::from_contracts() );
	}

	public function catalog(): ComponentContractCatalog {
		return $this->catalog;
	}
}
