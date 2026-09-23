<?php
/**
 * Tests for the package-owned entity content source contract.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\AuthoringContractCatalog;
use HonestlyDesign\EtchBuilders\CoreAuthoringRecipeCatalog;
use HonestlyDesign\EtchBuilders\CoreCompositeAuthoringRecipeCatalog;
use HonestlyDesign\EtchBuilders\CoreEntityContentAuthoringContract;
use HonestlyDesign\EtchBuilders\CoreNegativeAuthoringRecipeCatalog;
use PHPUnit\Framework\TestCase;

final class CoreEntityContentAuthoringContractTest extends TestCase {

	public function test_catalog_exports_pending_host_admission_and_versioned_source_facts(): void {
		$catalog = CoreEntityContentAuthoringContract::catalog( '2.0.5-dev' );

		self::assertSame(
			array(
				'site.entity.definition',
				'site.pattern.definition',
				'site.page.definition',
				'site.post.definition',
				'site.template.definition',
				'site.style.ownership',
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
		$catalog = CoreEntityContentAuthoringContract::catalog( '2.0.5-dev' );

		self::assertSame(
			array( 'new', 'component', 'pattern', 'page', 'post', 'template', 'supporting', 'global_asset' ),
			$this->method_names( $catalog, 'site.entity.definition' )
		);
		self::assertSame(
			array( 'new', 'key', 'category', 'blocks', 'pattern_use', 'stylesheet', 'add_style' ),
			$this->method_names( $catalog, 'site.pattern.definition' )
		);
		self::assertSame(
			array( 'new', 'slug', 'id', 'title', 'status', 'block', 'blocks_sequence', 'pattern_use', 'stylesheet', 'add_style', 'overwrite', 'dev_only' ),
			$this->method_names( $catalog, 'site.page.definition' )
		);
		self::assertSame(
			array( 'new', 'post_type', 'slug', 'id', 'title', 'status', 'block', 'blocks_sequence', 'pattern_use', 'stylesheet', 'add_style', 'overwrite', 'dev_only' ),
			$this->method_names( $catalog, 'site.post.definition' )
		);
		self::assertSame(
			array( 'new', 'slug', 'title', 'status', 'block', 'blocks_sequence', 'pattern_use', 'stylesheet', 'add_style', 'overwrite', 'dev_only' ),
			$this->method_names( $catalog, 'site.template.definition' )
		);
		self::assertSame(
			array( 'from_file', 'class_reference', 'new', 'css_file', 'register_references' ),
			$this->method_names( $catalog, 'site.style.ownership' )
		);
		self::assertNotContains( 'blocks_markup', $this->method_names( $catalog, 'site.page.definition' ) );
		self::assertNotContains( 'register', $this->method_names( $catalog, 'site.pattern.definition' ) );
	}

	public function test_recipe_diagnostic_and_prerequisite_references_resolve_at_contract_version(): void {
		$recipes = array_merge(
			CoreAuthoringRecipeCatalog::new()->all(),
			CoreNegativeAuthoringRecipeCatalog::new()->all(),
			CoreCompositeAuthoringRecipeCatalog::new()->all()
		);

		foreach ( CoreEntityContentAuthoringContract::capabilities()->all() as $capability ) {
			foreach ( $capability->recipe_ids() as $recipe_id ) {
				$matches = array_values( array_filter( $recipes, static fn ( $recipe ): bool => $recipe->id() === $recipe_id ) );
				self::assertCount( 1, $matches, 'Recipe must resolve in exactly one core catalog: ' . $recipe_id );
				self::assertSame( '1.0', $matches[0]->version() );
			}
		}
	}

	public function test_evidence_map_exposes_exact_missing_host_admission_evidence(): void {
		$map = CoreEntityContentAuthoringContract::evidence_map();

		foreach ( array( 'site.entity.definition', 'site.pattern.definition', 'site.page.definition', 'site.post.definition', 'site.template.definition' ) as $capability_id ) {
			self::assertNotContains( 'recipe', $map->missing_evidence_kinds( $capability_id ), $capability_id . ' must ship linked recipe evidence' );
		}
		self::assertSame( array( 'positive', 'recipe' ), $map->missing_evidence_kinds( 'site.style.ownership' ) );
	}

	public function test_catalog_loads_in_a_composer_only_process_without_wordpress_shims(): void {
		$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
		$code     = sprintf(
			'require %s; echo HonestlyDesign\\EtchBuilders\\CoreEntityContentAuthoringContract::catalog("2.0.5-dev")->contract_version();',
			var_export( $autoload, true )
		);
		$process  = proc_open(
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

	/** @return list<string> */
	private function method_names( AuthoringContractCatalog $catalog, string $capability_id ): array {
		return array_map(
			static fn ( $interface ): string => $interface->method_name(),
			$catalog->interfaces_for( $capability_id )
		);
	}
}
