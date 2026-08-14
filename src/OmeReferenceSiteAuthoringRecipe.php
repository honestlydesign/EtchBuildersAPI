<?php
/**
 * Composite OME reference-site recipe boundary.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Refuses to invent OME props or slots until an accepted component contract
 * catalog is supplied by the runtime/Contract Lab.
 */
final class OmeReferenceSiteAuthoringRecipe extends AbstractAuthoringCompositeRecipe {

	public function id(): string {
		return 'recipe.reference.ome';
	}

	public function version(): string {
		return '1.0';
	}

	public function capability_ids(): array {
		return array( 'site.ome.composition' );
	}

	public function prerequisite_ids(): array {
		return array( 'site.component.instance', 'site.component.slot', 'site.style.class-reference' );
	}

	public function optional_product_prerequisite_ids(): array {
		return array( 'ome.accepted-component-contracts' );
	}

	public function inputs(): array {
		return array(
			'contract_source' => 'accepted version-bound Component Contract Catalog',
			'component_keys' => array(),
			'prop_paths'     => array(),
			'slots'          => array(),
			'optional_products' => array( 'ome.accepted-component-contracts' ),
		);
	}

	protected function build(): SiteDefinition {
		throw new \LogicException( 'OME composite recipe requires an accepted component contract catalog.' );
	}

	public function expected_outcome(): AuthoringCompositeRecipeExpectation {
		return AuthoringCompositeRecipeExpectation::skipped( 'Optional product prerequisites are unavailable: ome.accepted-component-contracts.' );
	}
}
