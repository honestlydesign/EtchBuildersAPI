<?php
/**
 * Positive core component-definition Authoring Capability recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;

/**
 * Demonstrates a typed component definition through SiteDefinition::compile.
 */
final class CoreComponentAuthoringRecipe extends AbstractAuthoringCapabilityRecipe {

	private const RECIPE_ID = 'recipe.site.component';
	private const VERSION   = '1.0';
	private const COMPONENT = 'Hero';
	private const DESCRIPTION = 'Hero component';
	private const TAG       = 'section';
	private const CONTENT   = 'Welcome to the site.';

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
		return array(
			'component_key' => self::COMPONENT,
			'description'   => self::DESCRIPTION,
			'tag'           => self::TAG,
			'content'       => self::CONTENT,
		);
	}

	protected function build(): SiteDefinition {
		$component = Component::new( self::COMPONENT, self::DESCRIPTION )
			->key( self::COMPONENT )
			->blocks(
				ElementBlock::new()
					->tag( self::TAG )
					->content( self::CONTENT )
			);

		return SiteDefinition::new()->component( $component );
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
