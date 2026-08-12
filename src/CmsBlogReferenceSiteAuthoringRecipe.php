<?php
/**
 * Composite CMS/blog reference-site recipe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\EtchBlocks\CoreBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;

/**
 * Declares a real WordPress post-type prerequisite. In a pure package process
 * it stays explicitly skipped until a host supplies the required runtime evidence.
 */
final class CmsBlogReferenceSiteAuthoringRecipe extends AbstractAuthoringCompositeRecipe {

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
		return false;
	}

	public function expected_outcome(): AuthoringCompositeRecipeExpectation {
		return AuthoringCompositeRecipeExpectation::skipped( 'WordPress post-type runtime is not installed in the pure package execution context.' );
	}
}
