<?php
/**
 * Executable Authoring Capability recipe tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\AuthoringRecipeCatalog;
use HonestlyDesign\EtchBuilders\AuthoringRecipeExpectation;
use HonestlyDesign\EtchBuilders\AuthoringRecipeResult;
use HonestlyDesign\EtchBuilders\CoreAuthoringRecipeCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that positive recipes are machine-readable executable authority.
 */
final class AuthoringRecipeTest extends TestCase {

	public function test_core_recipes_declare_versioned_intents_and_execute_the_public_golden_path(): void {
		$catalog = CoreAuthoringRecipeCatalog::new();

		self::assertSame(
			array( 'recipe.site.component', 'recipe.site.page', 'recipe.site.native-loop-dependency' ),
			array_map(
				static fn ( array $recipe ): string => $recipe['id'],
				$catalog->to_array()['recipes']
			)
		);

		$page = $catalog->recipe( 'recipe.site.page' );
		self::assertSame( '1.0', $page->version() );
		self::assertSame( array( 'site.page.definition' ), $page->capability_ids() );
		self::assertSame( array(), $page->prerequisite_ids() );
		self::assertSame(
			array( 'slug' => 'home', 'content' => 'Welcome to the site.' ),
			$page->inputs()
		);
		self::assertSame( $page->expected_outcomes()->to_array(), $page->to_array()['expected_outcomes'] );

		$results = $catalog->execute_all();
		self::assertCount( 3, $results );
		foreach ( $results as $result ) {
			self::assertTrue( $result->assertions_passed(), $result->failure_message() );
			self::assertNotNull( $result->plan() );
			self::assertSame( array(), $result->failures() );
		}
	}

	public function test_recipe_result_exposes_exact_semantic_plan_and_wire_projection(): void {
		$result = CoreAuthoringRecipeCatalog::new()->execute( 'recipe.site.component' );
		$plan   = $result->plan();

		self::assertTrue( $result->assertions_passed(), $result->failure_message() );
		self::assertNotNull( $plan );
		self::assertSame(
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
			),
			$plan->to_array()
		);
		self::assertSame(
			array(
				'recipe_id' => 'recipe.site.component',
				'version'   => '1.0',
				'passed'    => true,
				'failures'  => array(),
				'plan'      => $plan->to_array(),
				'error'     => null,
			),
			$result->to_array()
		);
	}

	public function test_catalog_rejects_duplicate_recipe_ids(): void {
		$recipe = CoreAuthoringRecipeCatalog::new()->recipe( 'recipe.site.page' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate recipe ID "recipe.site.page"' );
		AuthoringRecipeCatalog::from_recipes( $recipe, $recipe );
	}

	public function test_catalog_is_a_stable_machine_readable_projection_for_docs_and_queries(): void {
		$catalog = CoreAuthoringRecipeCatalog::new();
		$projection = $catalog->to_array();

		self::assertSame( array( 'recipes' ), array_keys( $projection ) );
		self::assertSame(
			array(
				'id',
				'version',
				'capability_ids',
				'prerequisite_ids',
				'inputs',
				'expected_outcomes',
			),
			array_keys( $projection['recipes'][0] )
		);
		self::assertSame( $projection, $catalog->to_array() );
	}

	public function test_expectation_rejects_a_changed_wire_payload_instead_of_matching_a_substring(): void {
		$result = CoreAuthoringRecipeCatalog::new()->execute( 'recipe.site.component' );
		$plan   = $result->plan();
		self::assertNotNull( $plan );

		$expected = $plan->to_array();
		$expected['entities'][0]['payload']['blocks'] = '<!-- wp:etch/element {"tag":"section"} /-->';
		$failed = AuthoringRecipeResult::from_plan(
			'recipe.site.component',
			'1.0',
			$plan,
			AuthoringRecipeExpectation::for_plan( $expected )
		);

		self::assertFalse( $failed->assertions_passed() );
		self::assertSame( '$[\'entities\'][0][\'payload\'][\'blocks\']', $failed->failures()[0]['path'] );
	}
}
