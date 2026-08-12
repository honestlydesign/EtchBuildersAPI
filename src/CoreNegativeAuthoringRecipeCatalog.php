<?php
/**
 * Built-in negative Authoring Capability recipe catalog.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Supplies the stable invalid routes required before negative fixtures grow
 * into composite reference-site coverage.
 */
final class CoreNegativeAuthoringRecipeCatalog {

	private function __construct() {
	}

	public static function new(): AuthoringNegativeRecipeCatalog {
		return AuthoringNegativeRecipeCatalog::from_recipes(
			new NegativeComponentPathAuthoringRecipe(),
			new NegativeClassStyleIdAuthoringRecipe(),
			new NegativeLoopExpressionAuthoringRecipe(),
			new NegativeRawFallbackAuthoringRecipe(),
			new NegativeStyleOwnershipAuthoringRecipe()
		);
	}
}
