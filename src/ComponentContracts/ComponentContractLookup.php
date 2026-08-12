<?php
/**
 * Read-only lookup over one Component Contract Catalog provider.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentContracts;

use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;

/**
 * Gives agents and future query commands one exact component-key lookup path.
 */
final class ComponentContractLookup {

	private function __construct( private readonly ComponentContractCatalogProviderInterface $provider ) {
	}

	public static function from_provider( ComponentContractCatalogProviderInterface $provider ): self {
		return new self( $provider );
	}

	public function for_key( string $component_key ): ComponentContractLookupResult {
		return ComponentContractLookupResult::from_contract(
			$this->provider->catalog()->contract( $component_key )
		);
	}
}
