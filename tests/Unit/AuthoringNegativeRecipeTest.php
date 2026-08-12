<?php
/**
 * Negative Authoring Capability recipe tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\CoreNegativeAuthoringRecipeCatalog;
use HonestlyDesign\EtchBuilders\AuthoringNegativeRecipeCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies executable invalid routes fail with stable corrective diagnostics.
 */
final class AuthoringNegativeRecipeTest extends TestCase {

	protected function tearDown(): void {
		\HonestlyDesign\EtchBuilders\Environment::reset();
		\HonestlyDesign\EtchBuilders\Javascript::reset();
		\HonestlyDesign\EtchBuilders\LoopPreset::reset();
		\HonestlyDesign\EtchBuilders\Style::reset();
		parent::tearDown();
	}

	public function test_core_negative_fixtures_declare_stable_diagnostics_and_fail_closed_without_writes(): void {
		$catalog = CoreNegativeAuthoringRecipeCatalog::new();

		self::assertSame(
			array(
				'recipe.negative.component-path',
				'recipe.negative.class-style-id',
				'recipe.negative.loop-expression',
				'recipe.negative.raw-fallback',
				'recipe.negative.style-ownership',
			),
			array_map(
				static fn ( array $recipe ): string => $recipe['id'],
				$catalog->to_array()['recipes']
			)
		);

		foreach ( $catalog->execute_all() as $result ) {
			self::assertTrue( $result->assertions_passed(), $result->failure_message() );
			self::assertFalse( $result->writes_detected() );
		}
	}

	public function test_guessed_component_path_requires_the_exact_compiler_diagnostic_and_remediation(): void {
		$result = CoreNegativeAuthoringRecipeCatalog::new()->execute( 'recipe.negative.component-path' );
		$actual = $result->actual_diagnostic();

		self::assertTrue( $result->assertions_passed(), $result->failure_message() );
		self::assertNotNull( $actual );
		self::assertSame( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID', $actual['code'] );
		self::assertSame( 'Rule G: Component ref "77" does not resolve to an exact component key.', $actual['message'] );
		self::assertTrue( $result->plan()?->has_errors() );
	}

	public function test_catalog_rejects_duplicate_negative_recipe_ids(): void {
		$recipe = CoreNegativeAuthoringRecipeCatalog::new()->recipe( 'recipe.negative.loop-expression' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate recipe ID "recipe.negative.loop-expression"' );
		AuthoringNegativeRecipeCatalog::from_recipes( $recipe, $recipe );
	}

	public function test_changed_diagnostic_code_or_remediation_fails_the_result(): void {
		$result = CoreNegativeAuthoringRecipeCatalog::new()->execute( 'recipe.negative.class-style-id' );
		$expected = $result->expected_outcome();
		$changed = $expected->with_message( 'The remediation changed.' );
		$failed = $result->recheck( $changed );

		self::assertFalse( $failed->assertions_passed() );
		self::assertStringContainsString( 'diagnostic', $failed->failure_message() );
	}
}
