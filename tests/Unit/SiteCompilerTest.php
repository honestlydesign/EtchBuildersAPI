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
use HonestlyDesign\EtchBuilders\CompiledSiteEntityType;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;
use HonestlyDesign\EtchBuilders\Javascript;
use HonestlyDesign\EtchBuilders\Page;
use HonestlyDesign\EtchBuilders\Pattern;
use HonestlyDesign\EtchBuilders\PatternUse;
use HonestlyDesign\EtchBuilders\Post;
use HonestlyDesign\EtchBuilders\SiteCompiler;
use HonestlyDesign\EtchBuilders\SiteDefinition;
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
		$post = Post::new();
		$type = new \ReflectionProperty( Post::class, 'post_type' );
		$type->setValue( $post, $post_type );
		$slug_property = new \ReflectionProperty( Post::class, 'slug' );
		$slug_property->setValue( $post, $slug );
		$post->blocks_sequence( BlockSequence::new()->append( TextBlock::new()->content( $slug ) ) );

		return $post;
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
}
