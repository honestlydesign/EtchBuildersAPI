<?php
/**
 * Positive core page-definition Authoring Capability recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Demonstrates a typed page through SiteDefinition::compile.
 */
final class CorePageAuthoringRecipe extends AbstractAuthoringCapabilityRecipe {

	private const RECIPE_ID = 'recipe.site.page';
	private const VERSION   = '1.0';

	public function id(): string {
		return self::RECIPE_ID;
	}

	public function version(): string {
		return self::VERSION;
	}

	public function capability_ids(): array {
		return array( 'site.page.definition' );
	}

	public function prerequisite_ids(): array {
		return array();
	}

	public function inputs(): array {
		$page = self::build_page();

		return array(
			'slug'    => $page->get_slug(),
			'content' => CoreAuthoringRecipeFixtures::content(),
		);
	}

	/**
	 * Return the exact typed page fixture reused by composite recipes.
	 */
	public static function build_page(): Page {
		return CoreAuthoringRecipeFixtures::home_page();
	}

	protected function build(): SiteDefinition {
		return SiteDefinition::new()->page( self::build_page() );
	}

	public function expected_outcomes(): AuthoringRecipeExpectation {
		return AuthoringRecipeExpectation::for_plan(
			array(
				'entities'    => array(
					array(
						'type'     => 'page',
						'identity' => 'page:slug:home',
						'payload'  => array(
							'blocks' => '<!-- wp:etch/text {"content":"Welcome to the site."} /-->',
							'slug'   => 'home',
						),
					),
				),
				'identities'   => array( 'page:slug:home' ),
				'dependencies' => array(),
				'styles'       => array(),
				'assets'       => array(),
				'ownership'    => array(),
				'diagnostics'  => array(),
				'home_page'    => array( 'mode' => 'none' ),
			)
		);
	}
}
