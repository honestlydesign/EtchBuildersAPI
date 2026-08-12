<?php
/**
 * Positive core component-definition Authoring Capability recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Demonstrates a typed component definition through SiteDefinition::compile.
 */
final class CoreComponentAuthoringRecipe extends AbstractAuthoringCapabilityRecipe {

	private const RECIPE_ID = 'recipe.site.component';
	private const VERSION   = '1.0';

	public function id(): string {
		return self::RECIPE_ID;
	}

	public function version(): string {
		return self::VERSION;
	}

	public function capability_ids(): array {
		return array( 'site.component.definition' );
	}

	public function prerequisite_ids(): array {
		return array();
	}

	public function inputs(): array {
		$component = self::build_component();

		return array(
			'component_key' => $component->get_key(),
			'description'   => $component->get_description(),
			'tag'           => CoreAuthoringRecipeFixtures::hero_tag(),
			'content'       => CoreAuthoringRecipeFixtures::content(),
		);
	}

	/**
	 * Return the exact typed component fixture reused by composite recipes.
	 */
	public static function build_component(): Component {
		return CoreAuthoringRecipeFixtures::hero_component();
	}

	protected function build(): SiteDefinition {
		return SiteDefinition::new()->component( self::build_component() );
	}

	public function expected_outcomes(): AuthoringRecipeExpectation {
		return AuthoringRecipeExpectation::for_plan(
			array(
				'entities'    => array(
					array(
						'type'     => 'component',
						'identity' => 'component:Hero',
						'payload'  => array(
							'name'        => 'Hero',
							'description' => 'Hero component',
							'blocks'      => '<!-- wp:etch/element {"tag":"section","attributes":[]} --><!-- wp:etch/text {"content":"Welcome to the site."} /--><!-- /wp:etch/element -->',
							'properties'  => array(),
						),
					),
				),
				'identities'   => array( 'component:Hero' ),
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
