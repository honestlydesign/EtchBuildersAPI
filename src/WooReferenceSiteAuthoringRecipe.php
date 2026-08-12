<?php
/**
 * Composite Woo reference-site recipe boundary.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Refuses to invent Woo component/runtime contracts until the optional product
 * and accepted contract evidence are available.
 */
final class WooReferenceSiteAuthoringRecipe extends AbstractAuthoringCompositeRecipe {

	public function id(): string {
		return 'recipe.reference.woo';
	}

	public function version(): string {
		return '1.0';
	}

	public function capability_ids(): array {
		return array( 'site.woo.composition' );
	}

	public function prerequisite_ids(): array {
		return array( 'site.component.instance', 'site.dynamic.loop' );
	}

	public function optional_product_prerequisite_ids(): array {
		return array( 'woocommerce.runtime-contract' );
	}

	public function inputs(): array {
		return array(
			'contract_source' => 'accepted version-bound Woo runtime/component contract',
			'component_keys' => array(),
			'prop_paths'     => array(),
			'slots'          => array(),
			'optional_products' => array( 'woocommerce.runtime-contract' ),
		);
	}

	protected function build(): SiteDefinition {
		throw new \LogicException( 'Woo composite recipe requires an accepted Woo runtime contract.' );
	}

	protected function is_available(): bool {
		return false;
	}

	public function expected_outcome(): AuthoringCompositeRecipeExpectation {
		return AuthoringCompositeRecipeExpectation::skipped( 'Accepted Woo runtime/component contracts are unavailable; no guessed product props or slots are emitted.' );
	}
}
