<?php
/**
 * Composite CMS/blog reference-site recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\SiteRuntimeCapabilitiesInterface;
use HonestlyDesign\EtchBuilders\EtchBlocks\CoreBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;

/**
 * Declares a real WordPress post-type prerequisite. Without a runtime that
 * supplies the post type it stays explicitly skipped; with one it compiles a
 * complete plan that must match the exact projection below.
 */
final class CmsBlogReferenceSiteAuthoringRecipe extends AbstractAuthoringCompositeRecipe {

	public function __construct( private readonly ?SiteRuntimeCapabilitiesInterface $runtime = null ) {
	}

	public function id(): string {
		return 'recipe.reference.cms-blog';
	}

	public function version(): string {
		return '1.0';
	}

	public function capability_ids(): array {
		return array( 'site.component.definition', 'site.pattern.definition', 'site.page.definition', 'site.post.definition', 'site.template.definition', 'site.dynamic.loop' );
	}

	public function prerequisite_ids(): array {
		return array( 'site.entity.definition', 'site.dynamic.loop' );
	}

	public function optional_product_prerequisite_ids(): array {
		return array( 'wordpress.post-type.post' );
	}

	public function inputs(): array {
		return array(
			'component_recipe_id' => 'recipe.site.component',
			'post_type'          => 'post',
			'post_slug'          => 'hello-world',
			'template_slug'      => 'single',
			'optional_products'  => array( 'wordpress.post-type.post' ),
		);
	}

	protected function runtime(): ?SiteRuntimeCapabilitiesInterface {
		return $this->runtime;
	}

	protected function build(): SiteDefinition {
		$pattern = Pattern::new( 'Blog Intro', 'Blog intro pattern' )
			->key( 'BlogIntro' )
			->blocks( TextBlock::new()->content( 'Latest articles' ) );
		$page = Page::new()->slug( 'journal' )->pattern_use( PatternUse::registered( $pattern ) );
		$post = Post::new()->post_type( 'post' )->slug( 'hello-world' )->title( 'Hello World' )->block( TextBlock::new()->content( 'Article content' ) );
		$template = Template::new()->slug( 'single' )->block( CoreBlock::post_content() );

		return SiteDefinition::new()
			->component( CoreComponentAuthoringRecipe::build_component() )
			->pattern( $pattern )
			->page( $page )
			->post( $post )
			->template( $template )
			->home_page( SiteHomePolicy::page( 'journal' ) );
	}

	protected function is_available(): bool {
		return null !== $this->runtime && true === $this->runtime->post_type_exists( 'post' );
	}

	public function expected_outcome(): AuthoringCompositeRecipeExpectation {
		if ( $this->is_available() ) {
			return AuthoringCompositeRecipeExpectation::for_plan(
				AuthoringRecipeExpectation::for_plan(
					array(
						'entities' => array(
							array(
								'type' => 'component',
								'identity' => 'component:Hero',
								'payload' => array(
									'name' => 'Hero',
									'description' => 'Hero component',
									'blocks' => '<!-- wp:etch/element {"tag":"section","attributes":[]} --><!-- wp:etch/text {"content":"Welcome to the site."} /--><!-- /wp:etch/element -->',
									'properties' => array(),
								),
							),
							array(
								'type' => 'pattern',
								'identity' => 'pattern:BlogIntro',
								'payload' => array(
									'name' => 'Blog Intro',
									'description' => 'Blog intro pattern',
									'blocks' => '<!-- wp:etch/text {"content":"Latest articles"} /-->',
									'categories' => array(),
								),
							),
							array(
								'type' => 'page',
								'identity' => 'page:slug:journal',
								'payload' => array(
									'blocks' => '<!-- wp:etch/text {"content":"Latest articles"} /-->',
									'slug' => 'journal',
								),
							),
							array(
								'type' => 'post',
								'identity' => 'post:post:hello-world',
								'payload' => array(
									'blocks' => '<!-- wp:etch/text {"content":"Article content"} /-->',
									'slug' => 'hello-world',
									'post_type' => 'post',
									'post_title' => 'Hello World',
								),
							),
							array(
								'type' => 'template',
								'identity' => 'template:slug:single',
								'payload' => array(
									'blocks' => '<!-- wp:post-content {"align":"full","layout":{"type":"default"}} /-->',
									'slug' => 'single',
								),
							),
						),
						'identities' => array(
							'component:Hero',
							'pattern:BlogIntro',
							'page:slug:journal',
							'post:post:hello-world',
							'template:slug:single',
						),
						'dependencies' => array(
							array(
								'consumer' => 'page:slug:journal',
								'dependency' => 'pattern:BlogIntro',
								'kind' => 'pattern',
							),
						),
						'styles' => array(),
						'assets' => array(),
						'ownership' => array(),
						'diagnostics' => array(),
						'home_page' => array(
							'mode' => 'page',
							'slug' => 'journal',
						),
					)
				)
			);
		}

		return AuthoringCompositeRecipeExpectation::skipped( 'Optional product prerequisites are unavailable: wordpress.post-type.post.' );
	}
}
