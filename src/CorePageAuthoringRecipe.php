<?php
/**
 * Positive core page-definition Authoring Capability recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;

/**
 * Demonstrates a typed page through SiteDefinition::compile.
 */
final class CorePageAuthoringRecipe extends AbstractAuthoringCapabilityRecipe {

	private const RECIPE_ID = 'recipe.site.page';
	private const VERSION   = '1.0';
	private const SLUG      = 'home';
	private const CONTENT   = 'Welcome to the site.';

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
		return array(
			'slug'    => self::SLUG,
			'content' => self::CONTENT,
		);
	}

	protected function build(): SiteDefinition {
		$page = Page::new()
			->slug( self::SLUG )
			->block( TextBlock::new()->content( self::CONTENT ) );

		return SiteDefinition::new()->page( $page );
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
