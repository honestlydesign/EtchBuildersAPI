<?php
/**
 * Site persistence lifecycle tests: staged applies, hand-over, orphans,
 * adoption, translations, and editor-created style duplicates.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\CompiledSiteEntity;
use HonestlyDesign\EtchBuilders\CompiledSiteEntityType;
use HonestlyDesign\EtchBuilders\CompiledSiteOwnership;
use HonestlyDesign\EtchBuilders\CompiledSitePlan;
use HonestlyDesign\EtchBuilders\CompiledSiteResource;
use HonestlyDesign\EtchBuilders\CompiledSiteResourceType;
use HonestlyDesign\EtchBuilders\SitePersistenceReport;
use HonestlyDesign\EtchBuilders\SitePersistenceResult;
use HonestlyDesign\EtchBuilders\Support\WordPressSitePersistence;
use HonestlyDesign\EtchBuilders\Tests\Unit\Support\WordPressPersistenceStubs;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral tests for the WordPress persistence adapter across several applies.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class SitePersistenceLifecycleTest extends TestCase {

	protected function setUp(): void {
		WordPressPersistenceStubs::install();
		$GLOBALS['etch_builders_site_persistence_options'] = array();
		$GLOBALS['etch_builders_site_persistence_posts']   = array();
		$GLOBALS['etch_builders_site_persistence_meta']    = array();
		$GLOBALS['etch_builders_test_filters']             = array();
	}

	public function test_partial_bootstrap_apply_keeps_other_entities_styles_and_full_reapply_is_unchanged(): void {
		$persistence = new WordPressSitePersistence();
		$full        = $this->site_plan();

		$first = $persistence->apply( $full );
		self::assertTrue( $first->is_success() );

		$bootstrap = $persistence->apply( $this->component_only_plan()->as_partial() );
		self::assertTrue( $bootstrap->is_success() );
		self::assertSame( array( 'unchanged', 'unchanged' ), $this->outcomes( $bootstrap ) );
		self::assertArrayHasKey( 'page-intro', $this->option( 'etch_styles' ), 'A partial stage must not delete styles owned by entities it does not contain.' );
		self::assertArrayHasKey( 'global', $this->option( 'etch_global_stylesheets' ) );

		$again = $persistence->apply( $full );
		self::assertTrue( $again->is_success() );
		self::assertSame( array(), $again->applied_results(), 'An unchanged site must not report created or updated records.' );
		self::assertCount( count( $again->results() ), $again->unchanged_results() );
	}

	public function test_complete_plan_still_cleans_styles_of_removed_entities(): void {
		$persistence = new WordPressSitePersistence();
		$persistence->apply( $this->site_plan() );

		$persistence->apply( $this->component_only_plan() );

		self::assertArrayNotHasKey( 'page-intro', $this->option( 'etch_styles' ) );
		self::assertArrayHasKey( 'hero', $this->option( 'etch_styles' ) );
	}

	private function site_plan(): CompiledSitePlan {
		$component = $this->component_entity();
		$page      = CompiledSiteEntity::new(
			CompiledSiteEntityType::PAGE,
			'page:slug:intro',
			array( 'slug' => 'intro', 'blocks' => '<!-- wp:etch/text -->intro<!-- /wp:etch/text -->', 'post_title' => 'Intro' )
		);
		$hero_style = $this->style( 'hero', '.hero', 'color: red;' );
		$page_style = $this->style( 'page-intro', '.page-intro', 'margin: 0;' );
		$stylesheet = CompiledSiteResource::new(
			CompiledSiteResourceType::ASSET,
			'asset:stylesheet:site:root:global:hash',
			array( 'type' => 'stylesheet', 'id' => 'global', 'path' => '/tmp/global.css', 'css' => 'body { margin: 0; }' )
		);

		return CompiledSitePlan::from_sections(
			entities: array( $component, $page ),
			styles: array( $hero_style, $page_style ),
			assets: array( $stylesheet ),
			ownership: array(
				CompiledSiteOwnership::new( 'component:Hero', 'style:hero', 'style' ),
				CompiledSiteOwnership::new( 'page:slug:intro', 'style:page-intro', 'style' ),
				CompiledSiteOwnership::new( 'site:root', $stylesheet->identity(), 'stylesheet' ),
			)
		);
	}

	private function component_only_plan(): CompiledSitePlan {
		return CompiledSitePlan::from_sections(
			entities: array( $this->component_entity() ),
			styles: array( $this->style( 'hero', '.hero', 'color: red;' ) ),
			ownership: array( CompiledSiteOwnership::new( 'component:Hero', 'style:hero', 'style' ) )
		);
	}

	private function component_entity(): CompiledSiteEntity {
		return CompiledSiteEntity::new(
			CompiledSiteEntityType::COMPONENT,
			'component:Hero',
			array( 'name' => 'Hero', 'description' => 'Hero', 'blocks' => '<!-- wp:etch/text -->hero<!-- /wp:etch/text -->', 'properties' => array() )
		);
	}

	private function style( string $id, string $selector, string $css, string $collection = 'OhMyIDEtch:entity:component:Hero' ): CompiledSiteResource {
		return CompiledSiteResource::new(
			CompiledSiteResourceType::STYLE,
			'style:' . $id,
			array( 'selector' => $selector, 'collection' => $collection, 'css' => $css, 'type' => 'class', 'overwrite_on_register' => true )
		);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	private function option( string $name ): array {
		$value = $GLOBALS['etch_builders_site_persistence_options'][ $name ] ?? array();

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array<int, string>
	 */
	private function outcomes( SitePersistenceReport $report ): array {
		return array_map(
			static fn ( SitePersistenceResult $result ): string => $result->outcome()->value,
			$report->results()
		);
	}
}
