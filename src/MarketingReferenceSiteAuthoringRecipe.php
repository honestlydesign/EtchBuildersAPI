<?php
/**
 * Composite marketing reference-site recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\EtchBlocks\CoreBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;

/**
 * Covers component, registered pattern, page, template, BEM-owned styles,
 * file-based vanilla JavaScript, dependency, and home-page policy composition.
 */
final class MarketingReferenceSiteAuthoringRecipe extends AbstractAuthoringCompositeRecipe {

	public function id(): string {
		return 'recipe.reference.marketing';
	}

	public function version(): string {
		return '1.0';
	}

	public function capability_ids(): array {
		return array( 'site.component.definition', 'site.pattern.definition', 'site.page.definition', 'site.template.definition', 'site.style.ownership', 'site.javascript.file' );
	}

	public function prerequisite_ids(): array {
		return array( 'site.entity.definition' );
	}

	public function optional_product_prerequisite_ids(): array {
		return array();
	}

	public function inputs(): array {
		return array(
			'component_recipe_id' => 'recipe.site.component',
			'page_recipe_id'      => 'recipe.site.page',
			'pattern_key'         => 'MarketingHero',
			'home_slug'           => CoreAuthoringRecipeFixtures::home_slug(),
			'template_slug'       => 'index',
			'optional_products'   => array(),
		);
	}

	protected function build(): SiteDefinition {
		$pattern = Pattern::new( 'Marketing Hero', 'Marketing hero pattern' )
			->key( 'MarketingHero' );
		$pattern->add_style(
			Style::new()
				->id( 'marketing-hero' )
				->selector( '.marketing-hero' )
				->owner_local_css( 'display: grid; gap: 1rem;' )
		);
		$pattern->add_style(
			Style::new()
				->id( 'marketing-hero__title' )
				->selector( '.marketing-hero__title' )
				->owner_local_css( 'font-size: 2rem; line-height: 1.1;' )
		);
		$script_placeholder = Javascript::set_from_file( 'marketing-hero', __DIR__ . '/AuthoringFixtures/marketing.js' );
		$hero_block         = ElementBlock::new()
			->tag( 'section' )
			->class( 'marketing-hero' )
			->attribute( 'data-omide-marketing-hero', 'true' )
			->script( 'marketing-hero', $script_placeholder )
			->child(
				ElementBlock::new()
					->tag( 'h1' )
					->class( 'marketing-hero__title' )
					->content( CoreAuthoringRecipeFixtures::content() )
					->to_block()
			)
			->to_block();
		$pattern->blocks( $hero_block );
		$page = CorePageAuthoringRecipe::build_page()->pattern_use( PatternUse::registered( $pattern ) );
		$template = Template::new()->slug( 'index' )->block( CoreBlock::post_content() );

		return SiteDefinition::new()
			->component( CoreComponentAuthoringRecipe::build_component() )
			->pattern( $pattern )
			->page( $page )
			->template( $template )
			->home_page( SiteHomePolicy::page( 'home' ) );
	}

	public function expected_outcome(): AuthoringCompositeRecipeExpectation {
		return AuthoringCompositeRecipeExpectation::for_plan(
			AuthoringRecipeExpectation::for_plan(
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
						array(
							'type'     => 'pattern',
							'identity' => 'pattern:MarketingHero',
							'payload'  => array(
								'name'        => 'Marketing Hero',
								'description' => 'Marketing hero pattern',
					'blocks'      => '<!-- wp:etch/element {"tag":"section","attributes":{"class":"marketing-hero","data-omide-marketing-hero":"true"},"script":{"id":"marketing-hero","code":"ZG9jdW1lbnQuZG9jdW1lbnRFbGVtZW50LmRhdGFzZXQuZXRjaE1hcmtldGluZ1JlYWR5ID0gJ3RydWUnOwo="},"styles":["marketing-hero"]} --><!-- wp:etch/element {"tag":"h1","attributes":{"class":"marketing-hero__title"},"styles":["marketing-hero__title"]} --><!-- wp:etch/text {"content":"Welcome to the site."} /--><!-- /wp:etch/element --><!-- /wp:etch/element -->',
								'categories'  => array(),
							),
						),
						array(
							'type'     => 'page',
							'identity' => 'page:slug:home',
							'payload'  => array(
					'blocks' => '<!-- wp:etch/text {"content":"Welcome to the site."} /--><!-- wp:etch/element {"tag":"section","attributes":{"class":"marketing-hero","data-omide-marketing-hero":"true"},"script":{"id":"marketing-hero","code":"ZG9jdW1lbnQuZG9jdW1lbnRFbGVtZW50LmRhdGFzZXQuZXRjaE1hcmtldGluZ1JlYWR5ID0gJ3RydWUnOwo="},"styles":["marketing-hero"]} --><!-- wp:etch/element {"tag":"h1","attributes":{"class":"marketing-hero__title"},"styles":["marketing-hero__title"]} --><!-- wp:etch/text {"content":"Welcome to the site."} /--><!-- /wp:etch/element --><!-- /wp:etch/element -->',
								'slug'   => 'home',
							),
						),
						array(
							'type'     => 'template',
							'identity' => 'template:slug:index',
							'payload'  => array(
								'blocks' => '<!-- wp:post-content {"align":"full","layout":{"type":"default"}} /-->',
								'slug'   => 'index',
							),
						),
					),
					'identities'   => array( 'component:Hero', 'pattern:MarketingHero', 'page:slug:home', 'template:slug:index' ),
					'dependencies' => array( array( 'consumer' => 'page:slug:home', 'dependency' => 'pattern:MarketingHero', 'kind' => 'pattern' ) ),
					'styles'       => array(
						array(
							'type'     => 'style',
							'identity' => 'style:marketing-hero',
							'payload'  => array(
								'selector'             => '.marketing-hero',
								'collection'           => 'default',
								'css'                  => 'display: grid; gap: 1rem;',
								'type'                 => 'class',
								'overwrite_on_register' => true,
							),
						),
						array(
							'type'     => 'style',
							'identity' => 'style:marketing-hero__title',
							'payload'  => array(
								'selector'             => '.marketing-hero__title',
								'collection'           => 'default',
								'css'                  => 'font-size: 2rem; line-height: 1.1;',
								'type'                 => 'class',
								'overwrite_on_register' => true,
							),
						),
					),
					'assets'       => array(),
					'ownership'    => array(
						array( 'owner' => 'pattern:MarketingHero', 'resource' => 'style:marketing-hero', 'role' => 'style' ),
						array( 'owner' => 'pattern:MarketingHero', 'resource' => 'style:marketing-hero__title', 'role' => 'style' ),
					),
					'diagnostics'  => array(),
					'home_page'    => array( 'mode' => 'page', 'slug' => 'home' ),
				)
			)
		);
	}
}
