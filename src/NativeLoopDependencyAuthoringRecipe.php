<?php
/**
 * Positive Authoring Capability recipe for an Etch-native loop dependency.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Demonstrates the exact typed declaration used when Etch owns a loop preset.
 */
final class NativeLoopDependencyAuthoringRecipe extends AbstractAuthoringCapabilityRecipe {

	public function id(): string {
		return 'recipe.site.native-loop-dependency';
	}

	public function version(): string {
		return '1.0';
	}

	public function capability_ids(): array {
		return array( 'site.dynamic.loop' );
	}

	public function prerequisite_ids(): array {
		return array( 'site.page.definition' );
	}

	public function inputs(): array {
		return array(
			'loop_id'          => 'k7mrbkq',
			'loop_key'         => 'posts',
			'loop_type'        => 'wp-query',
			'query_args'       => array( 'post_type' => 'post' ),
			'persistence_intent' => CompiledSiteEntityPersistenceIntent::VERIFY_NATIVE->value,
			'ownership'        => 'native runtime; verify-only',
		);
	}

	protected function build(): SiteDefinition {
		$loop = LoopPreset::new( 'Posts' )
			->id( 'k7mrbkq' )
			->key( 'posts' )
			->native_dependency()
			->wp_query( array( 'post_type' => 'post' ) );
		$page = Page::new()->slug( 'blog' )->block(
			Block::new( 'loop', array( 'target' => 'items', 'loopId' => 'posts' ) )
		);

		return SiteDefinition::new()
			->page( $page )
			->supporting( $loop );
	}

	public function expected_outcomes(): AuthoringRecipeExpectation {
		return AuthoringRecipeExpectation::for_plan(
			array(
				'entities'    => array(
					array(
						'type'     => 'page',
						'identity' => 'page:slug:blog',
						'payload'  => array(
			'blocks' => '<!-- wp:etch/loop {"target":"items","loopId":"posts"} --><!-- /wp:etch/loop -->',
							'slug'   => 'blog',
						),
					),
					array(
						'type'             => 'loop_preset',
						'identity'         => 'loop_preset:posts',
						'payload'          => array(
							'name'   => 'Posts',
							'key'    => 'posts',
							'global' => true,
							'config' => array( 'type' => 'wp-query', 'args' => array( 'post_type' => 'post' ) ),
							'id'     => 'k7mrbkq',
						),
						'persistence_intent' => 'verify_native',
					),
				),
				'identities'   => array( 'page:slug:blog', 'loop_preset:posts' ),
				'dependencies' => array( array( 'consumer' => 'page:slug:blog', 'dependency' => 'loop_preset:posts', 'kind' => 'loop' ) ),
				'styles'       => array(),
				'assets'       => array(),
				'ownership'    => array(),
				'diagnostics'  => array(),
				'home_page'    => array( 'mode' => 'none' ),
			)
		);
	}
}
