<?php
/**
 * Composite reference-site recipe tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\AuthoringCompositeRecipeCatalog;
use HonestlyDesign\EtchBuilders\CmsBlogReferenceSiteAuthoringRecipe;
use HonestlyDesign\EtchBuilders\CoreCompositeAuthoringRecipeCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies complete-site composition and explicit optional prerequisites.
 */
final class AuthoringCompositeRecipeTest extends TestCase {

	protected function tearDown(): void {
		\HonestlyDesign\EtchBuilders\Environment::reset();
		\HonestlyDesign\EtchBuilders\Javascript::reset();
		\HonestlyDesign\EtchBuilders\LoopPreset::reset();
		\HonestlyDesign\EtchBuilders\Style::reset();
		parent::tearDown();
	}

	public function test_marketing_recipe_reuses_atomic_builders_and_asserts_complete_plan_semantics(): void {
		$catalog = CoreCompositeAuthoringRecipeCatalog::new();
		$recipe  = $catalog->recipe( 'recipe.reference.marketing' );
		$before_styles = \HonestlyDesign\EtchBuilders\Style::snapshot_state();
		$before_scripts = \HonestlyDesign\EtchBuilders\Javascript::snapshot();
		$before_loops = \HonestlyDesign\EtchBuilders\LoopPreset::snapshot();
		$result = $recipe->execute();

		self::assertSame( array(), $recipe->optional_product_prerequisite_ids() );
		self::assertSame( 'recipe.reference.marketing', $result->recipe_id() );
		self::assertSame( '1.0', $result->version() );
		self::assertTrue( $result->assertions_passed(), $result->failure_message() );
		self::assertSame( 'passed', $result->status() );
		self::assertSame( $before_styles, \HonestlyDesign\EtchBuilders\Style::snapshot_state() );
		self::assertSame( $before_scripts, \HonestlyDesign\EtchBuilders\Javascript::snapshot() );
		self::assertSame( $before_loops, \HonestlyDesign\EtchBuilders\LoopPreset::snapshot() );
		$plan = $result->plan();
		self::assertNotNull( $plan );
		self::assertSame(
			array( 'component:Hero', 'pattern:MarketingHero', 'page:slug:home', 'template:slug:index' ),
			$plan->resolved_identities()
		);
		self::assertSame(
			array( 'page:slug:home', 'pattern:MarketingHero', 'pattern' ),
			array_values( $plan->dependencies()[0]->to_array() )
		);
		self::assertSame( array( 'mode' => 'page', 'slug' => 'home' ), $plan->home_page_policy()->to_array() );
	}

	public function test_product_bound_recipes_do_not_pretend_optional_prerequisites_are_installed(): void {
		$catalog = CoreCompositeAuthoringRecipeCatalog::new();

		foreach (
			array(
				'recipe.reference.cms-blog' => array( 'wordpress.post-type.post', 'Optional product prerequisites are unavailable: wordpress.post-type.post.' ),
				'recipe.reference.ome'      => array( 'ome.accepted-component-contracts', 'Optional product prerequisites are unavailable: ome.accepted-component-contracts.' ),
				'recipe.reference.woo'      => array( 'woocommerce.runtime-contract', 'Optional product prerequisites are unavailable: woocommerce.runtime-contract.' ),
			) as $id => $expected
		) {
			$recipe = $catalog->recipe( $id );
			$result = $recipe->execute();

			self::assertSame( array( $expected[0] ), $recipe->optional_product_prerequisite_ids() );
			self::assertSame( 'skipped', $result->status() );
			self::assertTrue( $result->assertions_passed(), $result->failure_message() );
			self::assertNull( $result->plan() );
			self::assertSame( $expected[1], $result->reason() );
			self::assertFalse( $result->writes_detected() );
		}
	}

	public function test_cms_blog_recipe_compiles_its_complete_plan_when_the_runtime_supplies_the_post_type(): void {
		$recipe = new CmsBlogReferenceSiteAuthoringRecipe(
			\HonestlyDesign\EtchBuilders\Support\InMemorySiteRuntimeCapabilities::known( 'post' )
		);
		$result = $recipe->execute();

		self::assertSame( 'passed', $result->status(), $result->failure_message() );
		self::assertTrue( $result->assertions_passed(), $result->failure_message() );
		$plan = $result->plan();
		self::assertNotNull( $plan );
		self::assertSame(
			array( 'component:Hero', 'pattern:BlogIntro', 'page:slug:journal', 'post:post:hello-world', 'template:slug:single' ),
			$plan->resolved_identities()
		);
		self::assertFalse( $result->writes_detected() );
	}

	public function test_cms_blog_recipe_stays_skipped_when_the_runtime_denies_the_post_type(): void {
		$recipe = new CmsBlogReferenceSiteAuthoringRecipe(
			\HonestlyDesign\EtchBuilders\Support\InMemorySiteRuntimeCapabilities::unavailable()
		);
		$result = $recipe->execute();

		self::assertSame( 'skipped', $result->status() );
		self::assertTrue( $result->assertions_passed(), $result->failure_message() );
		self::assertNull( $result->plan() );
	}

	public function test_composite_catalog_projection_is_closed_and_rejects_duplicates(): void {
		$catalog = CoreCompositeAuthoringRecipeCatalog::new();
		$projection = $catalog->to_array();

		self::assertSame(
			array(
				'recipe.reference.marketing',
				'recipe.reference.cms-blog',
				'recipe.reference.ome',
				'recipe.reference.woo',
			),
			array_map( static fn ( array $recipe ): string => $recipe['id'], $projection['recipes'] )
		);
		self::assertSame( $projection, AuthoringCompositeRecipeCatalog::from_array( $projection, ...$catalog->all() )->to_array() );

		$recipe = $catalog->recipe( 'recipe.reference.marketing' );
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate recipe ID "recipe.reference.marketing"' );
		AuthoringCompositeRecipeCatalog::from_recipes( $recipe, $recipe );
	}
}
