<?php
/**
 * Built-in composite reference-site recipe catalog.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Orders the four reference-site surfaces from the Wayfinder decision.
 */
final class CoreCompositeAuthoringRecipeCatalog {

	private function __construct() {
	}

	public static function new(): AuthoringCompositeRecipeCatalog {
		return AuthoringCompositeRecipeCatalog::from_recipes(
			new MarketingReferenceSiteAuthoringRecipe(),
			new CmsBlogReferenceSiteAuthoringRecipe(),
			new OmeReferenceSiteAuthoringRecipe(),
			new WooReferenceSiteAuthoringRecipe()
		);
	}
}
