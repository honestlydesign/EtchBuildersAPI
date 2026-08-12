<?php
/**
 * Typed Site Definition aggregate tests.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit {

use HonestlyDesign\EtchBuilders\Component;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\JavascriptAsset;
use HonestlyDesign\EtchBuilders\LoopPreset;
use HonestlyDesign\EtchBuilders\Page;
use HonestlyDesign\EtchBuilders\Pattern;
use HonestlyDesign\EtchBuilders\Post;
use HonestlyDesign\EtchBuilders\SiteDefinition;
use HonestlyDesign\EtchBuilders\SiteHomePolicy;
use HonestlyDesign\EtchBuilders\StylesheetReference;
use HonestlyDesign\EtchBuilders\Template;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the no-write typed registry boundary for complete authored sites.
 */
final class SiteDefinitionTest extends TestCase {

	private const FIXTURE_CSS = __DIR__ . '/../fixtures/test-global-stylesheet.css';

	private const FIXTURE_JS = __DIR__ . '/../fixtures/test-script.js';

	private function post_with_type_and_id( int $id ): Post {
		$post     = Post::new()->id( $id );
		$property = new \ReflectionProperty( Post::class, 'post_type' );
		$property->setValue( $post, 'post' );

		return $post;
	}

	private function template_with_slug( string $slug ): Template {
		$template = Template::new();
		$property = new \ReflectionProperty( Template::class, 'slug' );
		$property->setValue( $template, $slug );

		return $template;
	}

	private function page_with_slug_and_id( string $slug, int $id ): Page {
		$page     = Page::new()->id( $id );
		$property = new \ReflectionProperty( Page::class, 'slug' );
		$property->setValue( $page, $slug );

		return $page;
	}

	public function test_registry_keeps_all_site_entity_lanes_and_typed_supporting_assets(): void {
		$component = Component::new( 'Hero', 'Hero component' )->key( 'Hero' );
		$pattern   = Pattern::new( 'Hero Pattern', 'Hero composition' )->key( 'HeroPattern' );
		$page      = Page::new()->id( 10 );
		$post      = $this->post_with_type_and_id( 11 );
		$template  = $this->template_with_slug( 'index' );
		$loop      = LoopPreset::new( 'Recent Posts' )->key( 'recent-posts' )->main_query();
		$catalog   = ComponentContractCatalog::from_contracts(
			ComponentContract::from_schema( 'Hero', array(), array() )
		);
		$stylesheet = StylesheetReference::new( 'global', self::FIXTURE_CSS );
		$script     = JavascriptAsset::new( 'site-script', self::FIXTURE_JS );

		$definition = SiteDefinition::new()
			->component( $component )
			->pattern( $pattern )
			->page( $page )
			->post( $post )
			->template( $template )
			->supporting( $loop )
			->supporting( $catalog )
			->global_asset( $stylesheet )
			->global_asset( $script )
			->home_page( SiteHomePolicy::page( 'home' ) );

		self::assertSame( array( $component ), $definition->components() );
		self::assertSame( array( $pattern ), $definition->patterns() );
		self::assertSame( array( $page ), $definition->pages() );
		self::assertSame( array( $post ), $definition->posts() );
		self::assertSame( array( $template ), $definition->templates() );
		self::assertSame( array( $loop, $catalog ), $definition->supporting_definitions() );
		self::assertSame( array( $stylesheet, $script ), $definition->global_assets() );
		self::assertSame( SiteHomePolicy::page( 'home' )->to_array(), $definition->home_page_policy()->to_array() );

		self::assertSame(
			array(
				'components'             => array( 'Hero' ),
				'patterns'               => array( 'HeroPattern' ),
				'pages'                  => array( 'page:id:10' ),
				'posts'                  => array( 'post:id:11' ),
				'templates'              => array( 'template:slug:index' ),
				'supporting_definitions' => array(
					array( 'type' => 'loop_preset', 'key' => 'recent-posts' ),
					array( 'type' => 'component_contract_catalog', 'key' => 'component-contract-catalog' ),
				),
				'global_assets'          => array(
					array( 'type' => 'stylesheet', 'id' => 'global' ),
					array( 'type' => 'javascript', 'id' => 'site-script', 'path' => self::FIXTURE_JS ),
				),
				'home_page'              => array( 'mode' => 'page', 'slug' => 'home' ),
			),
			$definition->to_array()
		);
	}

	public function test_home_page_policy_is_explicit_and_value_based(): void {
		self::assertSame( array( 'mode' => 'none' ), SiteHomePolicy::none()->to_array() );
		self::assertSame( array( 'mode' => 'latest_posts' ), SiteHomePolicy::latest_posts()->to_array() );
		self::assertSame( array( 'mode' => 'page', 'slug' => 'home' ), SiteHomePolicy::page( 'home' )->to_array() );

		$this->expectException( InvalidArgumentException::class );
		SiteHomePolicy::page( 'not a slug' );
	}

	public function test_home_page_policy_rejects_a_slug_that_differs_from_page_normalization(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'normalized page slug' );
		SiteHomePolicy::page( 'Home' );
	}

	public function test_home_page_policy_rejects_trailing_slug_separators(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'normalized page slug' );
		SiteHomePolicy::page( 'home-' );
	}

	public function test_shared_stylesheet_ids_preserve_distinct_fragments_but_exact_duplicates_fail(): void {
		$first  = StylesheetReference::new( 'global', self::FIXTURE_CSS );
		$second = StylesheetReference::new( 'global', __DIR__ . '/../fixtures/test-stylesheet.css' );
		$definition = SiteDefinition::new()
			->global_asset( $first )
			->global_asset( $second );

		self::assertSame( array( $first, $second ), $definition->global_assets() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate global asset identity' );
		$definition->global_asset( StylesheetReference::new( 'global', self::FIXTURE_CSS ) );
	}

	public function test_builder_identity_mutation_is_reflected_and_duplicate_mutation_fails_closed(): void {
		$page = Page::new()->id( 10 );
		$definition = SiteDefinition::new()->page( $page );

		$page->id( 11 );
		self::assertSame( array( 'page:id:11' ), $definition->to_array()['pages'] );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate page identity' );
		$definition->page( Page::new()->id( 11 ) );
	}

	public function test_ambiguous_content_identity_is_rejected_before_compilation(): void {
		$page = $this->page_with_slug_and_id( 'home', 10 );
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot use both' );
		SiteDefinition::new()->page( $page );
	}

	public function test_id_only_post_is_rejected_before_registration_contract_is_broken(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'requires post_type' );
		SiteDefinition::new()->post( Post::new()->id( 11 ) );
	}

	public function test_duplicate_identity_is_rejected_without_replacing_existing_definition(): void {
		$first = Component::new( 'Hero', 'First' )->key( 'Hero' );
		$second = Component::new( 'Other', 'Second' )->key( 'Hero' );
		$definition = SiteDefinition::new()->component( $first );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate component identity' );
		try {
			$definition->component( $second );
		} finally {
			self::assertSame( array( $first ), $definition->components() );
		}
	}

	public function test_duplicate_identities_are_rejected_for_each_registry_lane(): void {
		$definition = SiteDefinition::new()
			->pattern( Pattern::new( 'Hero', 'First' )->key( 'Hero' ) )
			->page( Page::new()->id( 10 ) )
			->post( $this->post_with_type_and_id( 11 ) )
			->template( $this->template_with_slug( 'index' ) )
			->supporting( LoopPreset::new( 'Recent' )->key( 'recent' )->main_query() )
			->global_asset( JavascriptAsset::new( 'site-script', self::FIXTURE_JS ) );

		$duplicate_cases = array(
			'pattern'    => static fn (): mixed => $definition->pattern( Pattern::new( 'Other', 'Second' )->key( 'Hero' ) ),
			'page'       => static fn (): mixed => $definition->page( Page::new()->id( 10 ) ),
			'post'       => fn (): mixed => $definition->post( $this->post_with_type_and_id( 11 ) ),
			'template'   => fn (): mixed => $definition->template( $this->template_with_slug( 'index' ) ),
			'supporting' => static fn (): mixed => $definition->supporting( LoopPreset::new( 'Other' )->key( 'recent' )->main_query() ),
			'asset'      => static fn (): mixed => $definition->global_asset( JavascriptAsset::new( 'site-script', self::FIXTURE_JS ) ),
		);

		foreach ( $duplicate_cases as $lane => $duplicate ) {
			try {
				$duplicate();
				self::fail( sprintf( 'Expected duplicate %s identity to fail.', $lane ) );
			} catch ( InvalidArgumentException $exception ) {
				self::assertStringContainsString( 'duplicate', strtolower( $exception->getMessage() ) );
			}
		}
	}

	public function test_registration_is_not_performed_when_building_a_definition(): void {
		Environment::reset();
		$storage_before = array(
			'etch_styles'              => Environment::storage()->get( 'etch_styles', array() ),
			'etch_global_stylesheets'  => Environment::storage()->get( 'etch_global_stylesheets', array() ),
			'etch_loops'               => Environment::storage()->get( 'etch_loops', array() ),
		);

		SiteDefinition::new()
			->component( Component::new( 'Hero', 'Hero component' )->key( 'Hero' ) )
			->global_asset( StylesheetReference::new( 'global', self::FIXTURE_CSS ) );

		self::assertSame(
			$storage_before,
			array(
				'etch_styles'             => Environment::storage()->get( 'etch_styles', array() ),
				'etch_global_stylesheets' => Environment::storage()->get( 'etch_global_stylesheets', array() ),
				'etch_loops'              => Environment::storage()->get( 'etch_loops', array() ),
			)
		);
	}
}
}
