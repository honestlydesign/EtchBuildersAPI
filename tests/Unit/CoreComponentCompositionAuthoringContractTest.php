<?php
/**
 * Tests for the package-owned component composition source contract.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\AuthoringContractCatalog;
use HonestlyDesign\EtchBuilders\CoreAuthoringRecipeCatalog;
use HonestlyDesign\EtchBuilders\CoreComponentCompositionAuthoringContract;
use HonestlyDesign\EtchBuilders\CoreCompositeAuthoringRecipeCatalog;
use HonestlyDesign\EtchBuilders\CoreNegativeAuthoringRecipeCatalog;
use PHPUnit\Framework\TestCase;

final class CoreComponentCompositionAuthoringContractTest extends TestCase {

	public function test_catalog_exports_pending_host_admission_and_versioned_source_facts(): void {
		$catalog = CoreComponentCompositionAuthoringContract::catalog( '2.0.0-dev' );

		self::assertSame(
			array(
				'site.style.class-reference',
				'site.component.instance',
				'site.component.slot',
				'site.ome.composition',
				'site.dynamic.loop',
			),
			array_map( static fn ( $capability ): string => $capability->id(), $catalog->capabilities() )
		);
		foreach ( $catalog->capabilities() as $capability ) {
			self::assertSame( 'pending', $capability->status()->value );
			self::assertStringContainsString( 'host', $capability->status_reason() );
		}
		self::assertSame( '1.0', $catalog->contract_version() );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $catalog->source_digest() );
	}

	public function test_catalog_exposes_exact_source_derived_interface_facts_only(): void {
		$catalog = CoreComponentCompositionAuthoringContract::catalog( '2.0.0-dev' );

		self::assertSame( array( 'registered', 'of', 'none', 'class_prop' ), $this->method_names( $catalog, 'site.style.class-reference' ) );
		self::assertSame(
			array(
				'for_key',
				'prop_value',
				'expression_prop',
				'string',
				'numeric_string',
				'boolean',
				'object',
				'array',
				'color',
				'loop_reference',
				'url',
				'image',
				'select_option',
				'wordpress_media_id',
				'empty_repeater',
				'source',
			),
			$this->method_names( $catalog, 'site.component.instance' )
		);
		self::assertSame( array( 'slot' ), $this->method_names( $catalog, 'site.component.slot' ) );
		self::assertSame( array( 'for_key', 'prop_value', 'expression_prop', 'class_prop', 'slot' ), $this->method_names( $catalog, 'site.ome.composition' ) );
		self::assertSame( array( 'new', 'id', 'key', 'native_dependency', 'wp_query' ), $this->method_names( $catalog, 'site.dynamic.loop' ) );
		self::assertNotContains( 'ref_by_key', $this->method_names( $catalog, 'site.component.instance' ) );
		self::assertNotContains( 'prop_group', $this->method_names( $catalog, 'site.ome.composition' ) );
	}

	public function test_recipe_diagnostic_and_prerequisite_references_resolve_at_contract_version(): void {
		$recipes = array_merge(
			CoreAuthoringRecipeCatalog::new()->all(),
			CoreNegativeAuthoringRecipeCatalog::new()->all(),
			CoreCompositeAuthoringRecipeCatalog::new()->all()
		);
		$diagnostics = array();
		foreach ( CoreNegativeAuthoringRecipeCatalog::new()->all() as $recipe ) {
			$diagnostics[ $recipe->expected_outcome()->diagnostic_record()['code'] ] = $recipe->version();
		}

		foreach ( CoreComponentCompositionAuthoringContract::capabilities()->all() as $capability ) {
			foreach ( $capability->recipe_ids() as $recipe_id ) {
				$matches = array_values( array_filter( $recipes, static fn ( $recipe ): bool => $recipe->id() === $recipe_id ) );
				self::assertCount( 1, $matches, 'Recipe must resolve in exactly one core catalog: ' . $recipe_id );
				self::assertSame( '1.0', $matches[0]->version() );
			}
			foreach ( $capability->diagnostic_ids() as $diagnostic_id ) {
				self::assertSame( '1.0', $diagnostics[ $diagnostic_id ] ?? null );
			}
		}

		$ome = CoreCompositeAuthoringRecipeCatalog::new()->recipe( 'recipe.reference.ome' );
		self::assertSame( array( 'site.ome.composition' ), $ome->capability_ids() );
		self::assertSame( array( 'site.component.instance', 'site.component.slot', 'site.style.class-reference' ), $ome->prerequisite_ids() );
	}

	public function test_evidence_map_exposes_exact_missing_host_admission_evidence(): void {
		$map = CoreComponentCompositionAuthoringContract::evidence_map();

		self::assertSame( array( 'positive', 'recipe' ), $map->missing_evidence_kinds( 'site.style.class-reference' ) );
		self::assertSame( array( 'positive', 'recipe' ), $map->missing_evidence_kinds( 'site.component.instance' ) );
		self::assertSame( array( 'positive', 'negative' ), $map->missing_evidence_kinds( 'site.component.slot' ) );
		self::assertSame( array( 'positive', 'negative' ), $map->missing_evidence_kinds( 'site.ome.composition' ) );
		self::assertSame( array( 'positive', 'negative', 'recipe' ), $map->missing_evidence_kinds( 'site.dynamic.loop' ) );
	}

	public function test_catalog_loads_in_a_composer_only_process_without_wordpress_shims(): void {
		$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
		$code = sprintf(
			'require %s; echo HonestlyDesign\\EtchBuilders\\CoreComponentCompositionAuthoringContract::catalog("2.0.0-dev")->contract_version();',
			var_export( $autoload, true )
		);
		$process = proc_open(
			array( PHP_BINARY, '-r', $code ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		$output = false === $output ? '' : $output;
		$error  = false === $error ? '' : $error;
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		self::assertSame( 0, $exit_code, $error );
		self::assertSame( '1.0', $output );
	}

	public function test_versioned_recipe_catalogs_execute_in_a_composer_only_process_without_wordpress_shims(): void {
		$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
		$code = sprintf(
			'require %s; $catalogs = array( HonestlyDesign\\EtchBuilders\\CoreAuthoringRecipeCatalog::new(), HonestlyDesign\\EtchBuilders\\CoreNegativeAuthoringRecipeCatalog::new(), HonestlyDesign\\EtchBuilders\\CoreCompositeAuthoringRecipeCatalog::new() ); $results = array(); foreach ( $catalogs as $catalog ) { foreach ( $catalog->execute_all() as $result ) { $result_data = $result->to_array(); $results[ $result_data["recipe_id"] ] = $result->assertions_passed(); } } HonestlyDesign\\EtchBuilders\\Page::new()->slug("page-slug"); HonestlyDesign\\EtchBuilders\\Post::new()->post_type("post")->slug("post-slug"); HonestlyDesign\\EtchBuilders\\Template::new()->slug("template-slug"); $results["normalized-slugs"] = true; foreach ( array( "Needs WordPress", " page-slug " ) as $invalid_slug ) { try { HonestlyDesign\\EtchBuilders\\Page::new()->slug( $invalid_slug ); $results["non-normalized-slugs-rejected"] = false; break; } catch ( InvalidArgumentException $exception ) { $results["non-normalized-slugs-rejected"] = true; } } echo json_encode( $results, JSON_THROW_ON_ERROR );',
			var_export( $autoload, true )
		);
		$process = proc_open(
			array( PHP_BINARY, '-r', $code ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		$output = false === $output ? '' : $output;
		$error  = false === $error ? '' : $error;
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		self::assertSame( 0, $exit_code, $error );
		self::assertSame(
			array(
				'recipe.site.component'              => true,
				'recipe.site.page'                   => true,
				'recipe.site.native-loop-dependency' => true,
				'recipe.negative.component-path'     => true,
				'recipe.negative.class-style-id'     => true,
				'recipe.negative.loop-expression'    => true,
				'recipe.negative.raw-fallback'       => true,
				'recipe.negative.style-ownership'    => true,
				'recipe.reference.marketing'         => true,
				'recipe.reference.cms-blog'          => true,
				'recipe.reference.ome'               => true,
				'recipe.reference.woo'               => true,
				'normalized-slugs'                   => true,
				'non-normalized-slugs-rejected'      => true,
			),
			json_decode( $output, true, 512, JSON_THROW_ON_ERROR )
		);
	}

	/** @return list<string> */
	private function method_names( AuthoringContractCatalog $catalog, string $capability_id ): array {
		return array_map(
			static fn ( $interface ): string => $interface->method_name(),
			$catalog->interfaces_for( $capability_id )
		);
	}
}
