<?php
/**
 * Site identity/dependency compiler tests.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\BlockSequence;
use HonestlyDesign\EtchBuilders\Component;
use HonestlyDesign\EtchBuilders\ClassStyleReference;
use HonestlyDesign\EtchBuilders\CompiledSiteEntityType;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\SlotPlaceholderBlock;
use HonestlyDesign\EtchBuilders\Javascript;
use HonestlyDesign\EtchBuilders\JavascriptAsset;
use HonestlyDesign\EtchBuilders\LoopPreset;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\StylesheetReference;
use HonestlyDesign\EtchBuilders\ComponentProperties\Types\Primitive\StringProperty;
use HonestlyDesign\EtchBuilders\Page;
use HonestlyDesign\EtchBuilders\Pattern;
use HonestlyDesign\EtchBuilders\PatternUse;
use HonestlyDesign\EtchBuilders\Post;
use HonestlyDesign\EtchBuilders\SiteCompiler;
use HonestlyDesign\EtchBuilders\SiteDefinition;
use HonestlyDesign\EtchBuilders\SiteHomePolicy;
use HonestlyDesign\EtchBuilders\Support\InMemorySiteRuntimeCapabilities;
use HonestlyDesign\EtchBuilders\Template;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the first no-write identity/dependency compiler phase.
 */
final class SiteCompilerTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		Javascript::reset();
		Style::reset();
		LoopPreset::reset();
		parent::tearDown();
	}

	private function typed_pattern( string $key, string $content = '' ): Pattern {
		return Pattern::new( $key, $key . ' pattern.' )
			->key( $key )
			->blocks( BlockSequence::new()->append( TextBlock::new()->content( $content ?: $key ) ) );
	}

	private function typed_page( string $slug ): Page {
		$page     = Page::new()->blocks_sequence( BlockSequence::new()->append( TextBlock::new()->content( $slug ) ) );
		$property = new \ReflectionProperty( Page::class, 'slug' );
		$property->setValue( $page, $slug );

		return $page;
	}

	private function typed_template( string $slug ): Template {
		$template = Template::new()->blocks_sequence( BlockSequence::new()->append( TextBlock::new()->content( $slug ) ) );
		$property = new \ReflectionProperty( Template::class, 'slug' );
		$property->setValue( $template, $slug );

		return $template;
	}

	private function post( string $post_type, string $slug ): Post {
		return Post::new()
			->post_type( $post_type )
			->slug( $slug )
			->blocks_sequence( BlockSequence::new()->append( TextBlock::new()->content( $slug ) ) );
	}

	public function test_clean_definition_compiles_all_entity_lanes_and_pattern_dependencies(): void {
		$pattern = $this->typed_pattern( 'Hero' );
		$page    = $this->typed_page( 'home' )->pattern_use( PatternUse::registered( $pattern ) );
		$definition = SiteDefinition::new()
			->component( Component::new( 'Shell', 'Shell component' )->key( 'Shell' )->blocks( TextBlock::new()->content( 'Shell' ) ) )
			->pattern( $pattern )
			->page( $page )
			->template( $this->typed_template( 'index' ) );

		$plan = $definition->compile();

		self::assertFalse( $plan->has_errors() );
		self::assertSame(
			array( 'component:Shell', 'pattern:Hero', 'page:slug:home', 'template:slug:index' ),
			$plan->resolved_identities()
		);
		self::assertSame( array( 'page:slug:home', 'pattern:Hero', 'pattern' ), array_values( $plan->dependencies()[0]->to_array() ) );
		self::assertSame( 'page:slug:home', $plan->dependencies()[0]->consumer_identity() );
	}

	public function test_missing_pattern_use_is_a_stable_error_without_dropping_the_consumer_entity(): void {
		$missing = $this->typed_pattern( 'Missing' );
		$page = $this->typed_page( 'home' )->pattern_use( PatternUse::registered( $missing ) );
		$plan = SiteCompiler::compile( SiteDefinition::new()->page( $page ) );

		self::assertTrue( $plan->has_errors() );
		self::assertSame( array( 'page:slug:home' ), $plan->resolved_identities() );
		self::assertSame( 'ETCH_SITE_PATTERN_MISSING', $plan->diagnostics()[0]->code() );
	}

	public function test_pattern_cycles_are_reported_without_recursion_or_writes(): void {
		$first  = $this->typed_pattern( 'First' );
		$second = $this->typed_pattern( 'Second' );
		$first->pattern_use( PatternUse::registered( $second ) );
		$second->pattern_use( PatternUse::registered( $first ) );

		$plan = SiteDefinition::new()->pattern( $first )->pattern( $second )->compile();

		self::assertTrue( $plan->has_errors() );
		self::assertNotEmpty( array_filter( $plan->diagnostics(), static fn ( $diagnostic ): bool => 'ETCH_SITE_PATTERN_CYCLE' === $diagnostic->code() ) );
	}

	public function test_mutated_duplicate_identity_is_reported_as_a_compiler_diagnostic(): void {
		$first  = Page::new()->id( 10 );
		$second = Page::new()->id( 11 );
		$definition = SiteDefinition::new()->page( $first )->page( $second );
		$first->id( 11 );

		$plan = $definition->compile();

		self::assertTrue( $plan->has_errors() );
		self::assertSame( 'ETCH_SITE_IDENTITY_INVALID', $plan->diagnostics()[0]->code() );
	}

	public function test_known_and_invalid_post_types_are_checked_by_a_read_only_runtime_adapter(): void {
		$known_plan = SiteDefinition::new()->post( $this->post( 'post', 'article' ) )->compile(
			InMemorySiteRuntimeCapabilities::known( 'post' )
		);
		$invalid_plan = SiteDefinition::new()->post( $this->post( 'missing', 'article' ) )->compile(
			InMemorySiteRuntimeCapabilities::known( 'post' )
		);

		self::assertFalse( $known_plan->has_errors() );
		self::assertTrue( $invalid_plan->has_errors() );
		self::assertSame( 'ETCH_SITE_POST_TYPE_INVALID', $invalid_plan->diagnostics()[0]->code() );
	}

	public function test_unavailable_post_type_capability_is_distinguished_from_invalid_type(): void {
		$plan = SiteDefinition::new()->post( $this->post( 'post', 'article' ) )->compile(
			InMemorySiteRuntimeCapabilities::unavailable()
		);

		self::assertTrue( $plan->has_errors() );
		self::assertSame( 'ETCH_SITE_RUNTIME_UNAVAILABLE', $plan->diagnostics()[0]->code() );
	}

	public function test_empty_content_is_reported_as_serialization_failure(): void {
		$page     = Page::new();
		$property = new \ReflectionProperty( Page::class, 'slug' );
		$property->setValue( $page, 'empty' );
		$plan = SiteCompiler::compile( SiteDefinition::new()->page( $page ) );

		self::assertTrue( $plan->has_errors() );
		self::assertSame( 'ETCH_SITE_SERIALIZATION_FAILED', $plan->diagnostics()[0]->code() );
	}

	public function test_compilation_does_not_mutate_environment_storage(): void {
		Environment::reset();
		$before = Environment::storage()->get( 'etch_styles', array() );

		SiteDefinition::new()->component(
			Component::new( 'Shell', 'Shell component' )->key( 'Shell' )->blocks( TextBlock::new()->content( 'Shell' ) )
		)->compile();

		self::assertSame( $before, Environment::storage()->get( 'etch_styles', array() ) );
	}

	public function test_component_and_pattern_blocks_resolve_registered_javascript_placeholders(): void {
		$placeholder = Javascript::set_from_file( 'compiler-test', __DIR__ . '/../fixtures/test-script.js' );
		$markup      = '<!-- wp:etch/text {"script":"' . $placeholder . '"} /-->';
		$plan        = SiteDefinition::new()
			->component( Component::new( 'Script Component', 'Script component' )->blocks( $markup ) )
			->pattern( Pattern::new( 'Script Pattern', 'Script pattern' )->blocks( $markup ) )
			->compile();

		self::assertFalse( $plan->has_errors() );
		self::assertCount( 2, $plan->entities() );
		foreach ( $plan->entities() as $entity ) {
			self::assertStringNotContainsString( $placeholder, (string) $entity->payload()['blocks'] );
			self::assertStringContainsString( base64_encode( "window.__etchBuilderTest = true;\n" ), (string) $entity->payload()['blocks'] );
		}
	}

	public function test_runtime_capability_adapter_rejects_empty_known_values(): void {
		$adapter = InMemorySiteRuntimeCapabilities::known( '', 'post', 'post' );
		self::assertTrue( $adapter->post_type_exists( 'post' ) );
		self::assertFalse( $adapter->post_type_exists( 'page' ) );
	}

	public function test_compiler_emits_properties_styles_assets_ownership_and_loop_dependencies_without_writes(): void {
		$style_id = Style::new()
			->id( 'hero-style' )
			->selector( '.hero' )
			->owner_local_css( 'color: red;' )
			->collection( 'OhMyIDEtch' )
			->add();
		$reference = ClassStyleReference::registered( $style_id );
		$block     = ElementBlock::new()->tag( 'div' )->class_style( $reference )->to_block();
		$loop      = LoopPreset::new( 'Recent Posts' )->key( 'recent-posts' )->main_query();
		$loop_block = \HonestlyDesign\EtchBuilders\Block::new(
			'loop',
			array( 'target' => 'items', 'loopId' => 'recent-posts' )
		);
		$component = Component::new( 'Hero', 'Hero component' )
			->prop( StringProperty::new( 'Title' )->key( 'title' )->default( 'Welcome' ) )
			->blocks( BlockSequence::new()->append( $block ) );
		$page = $this->typed_page( 'home' )->blocks_sequence( BlockSequence::new()->append( $loop_block ) );
		$definition = SiteDefinition::new()
			->component( $component )
			->page( $page )
			->supporting( $loop )
			->global_asset( StylesheetReference::new( 'site-global', __DIR__ . '/../fixtures/test-stylesheet.css' ) )
			->global_asset( JavascriptAsset::new( 'site-script', __DIR__ . '/../fixtures/test-script.js' ) );

		$before = Environment::storage()->get( 'etch_styles', array() );
		$plan   = $definition->compile();

		self::assertFalse( $plan->has_errors() );
		self::assertSame( array( 'component:Hero', 'page:slug:home', 'loop_preset:recent-posts' ), $plan->resolved_identities() );
		self::assertSame( array( 'style:hero-style' ), array_map( static fn ( $resource ): string => $resource->identity(), $plan->styles() ) );
		self::assertCount( 2, $plan->assets() );
		self::assertContains( 'page:slug:home>loop_preset:recent-posts:loop', array_map( static fn ( $dependency ): string => $dependency->consumer_identity() . '>' . $dependency->dependency_identity() . ':' . $dependency->kind(), $plan->dependencies() ) );
		self::assertContains( 'component:Hero>style:hero-style:presentation_class', array_map( static fn ( $ownership ): string => $ownership->owner_identity() . '>' . $ownership->resource_identity() . ':' . $ownership->role(), $plan->ownership() ) );
		self::assertSame( $before, Environment::storage()->get( 'etch_styles', array() ) );
		self::assertSame( 'Welcome', $plan->entities()[0]->payload()['properties'][0]['default'] );
	}

	public function test_unknown_loop_reference_is_a_stable_compiler_error(): void {
		$page = $this->typed_page( 'home' )->blocks_sequence(
			BlockSequence::new()->append(
				\HonestlyDesign\EtchBuilders\Block::new( 'loop', array( 'target' => 'items', 'loopId' => 'missing-loop' ) )
			)
		);
		$plan = SiteDefinition::new()->page( $page )->compile();

		self::assertTrue( $plan->has_errors() );
		self::assertSame( 'ETCH_SITE_LOOP_INVALID', $plan->diagnostics()[0]->code() );
	}

	public function test_component_root_slot_placeholder_is_valid_but_content_root_is_rejected(): void {
		$component = Component::new( 'Card', 'Card component' )->key( 'Card' )->blocks(
			BlockSequence::new()->append( SlotPlaceholderBlock::new()->name( 'default' ) )
		);
		$component_plan = SiteDefinition::new()->component( $component )->compile();

		self::assertFalse( $component_plan->has_errors() );

		$page = $this->typed_page( 'home' )->blocks_sequence(
			BlockSequence::new()->append(
				\HonestlyDesign\EtchBuilders\Block::new( 'slot-content', array( 'name' => 'default' ) )
			)
		);
		$page_plan = SiteDefinition::new()->page( $page )->compile();

		self::assertTrue( $page_plan->has_errors() );
		self::assertSame( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID', $page_plan->diagnostics()[0]->code() );
	}

	public function test_raw_loop_markup_is_checked_against_site_supporting_presets(): void {
		$loop = LoopPreset::new( 'Recent Posts' )->key( 'recent-posts' )->main_query();
		$page = Page::new();
		$slug = new \ReflectionProperty( Page::class, 'slug' );
		$slug->setValue( $page, 'home' );
		$page->blocks_markup( '<!-- wp:etch/loop {"target":"items","loopId":"missing-loop"} /-->' );

		$plan = SiteDefinition::new()->page( $page )->supporting( $loop )->compile();

		self::assertTrue( $plan->has_errors() );
		self::assertNotEmpty(
			array_filter(
				$plan->diagnostics(),
				static fn ( $diagnostic ): bool => 'ETCH_SITE_LOOP_INVALID' === $diagnostic->code()
			)
		);
		self::assertSame( array(), $plan->dependencies() );
	}

	public function test_compiled_plan_carries_the_explicit_home_page_policy(): void {
		$plan = SiteDefinition::new()
			->home_page( SiteHomePolicy::page( 'home' ) )
			->page( $this->typed_page( 'home' ) )
			->compile();

		self::assertFalse( $plan->has_errors() );
		self::assertTrue( $plan->has_home_page_policy() );
		self::assertSame( array( 'mode' => 'page', 'slug' => 'home' ), $plan->home_page_policy()->to_array() );
		self::assertSame( array( 'mode' => 'page', 'slug' => 'home' ), $plan->to_array()['home_page'] );
	}

	public function test_content_entities_project_explicit_native_post_fields_without_inventing_defaults(): void {
		$page = $this->typed_page( 'home' )->title( 'Homepage' )->status( 'draft' )->excerpt( 'Intro' );
		$post = $this->post( 'book', 'article' )->title( 'Article' )->status( 'private' );
		$template = $this->typed_template( 'index' )->title( 'Index template' );

		$plan = SiteDefinition::new()->page( $page )->post( $post )->template( $template )->compile(
			InMemorySiteRuntimeCapabilities::known( 'book' )
		);

		self::assertFalse( $plan->has_errors() );
		self::assertSame(
			array( 'blocks' => $page->get_blocks(), 'slug' => 'home', 'post_title' => 'Homepage', 'post_status' => 'draft', 'post_excerpt' => 'Intro' ),
			$plan->entities()[0]->payload()
		);
		self::assertSame( array( 'blocks' => $post->get_blocks(), 'slug' => 'article', 'post_type' => 'book', 'post_title' => 'Article', 'post_status' => 'private' ), $plan->entities()[1]->payload() );
		self::assertSame( array( 'blocks' => $template->get_blocks(), 'slug' => 'index', 'post_title' => 'Index template' ), $plan->entities()[2]->payload() );
	}
}
