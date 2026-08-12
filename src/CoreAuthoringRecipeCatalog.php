<?php
/**
 * Built-in positive core Authoring Capability recipe catalog.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Supplies the smallest reusable core recipe set before composite project
 * recipes and negative fixtures are introduced by later Wayfinder tickets.
 */
final class CoreAuthoringRecipeCatalog {

	private function __construct() {
	}

	public static function new(): AuthoringRecipeCatalog {
		return AuthoringRecipeCatalog::from_recipes(
			new CoreComponentAuthoringRecipe(),
			new CorePageAuthoringRecipe()
		);
	}
}
