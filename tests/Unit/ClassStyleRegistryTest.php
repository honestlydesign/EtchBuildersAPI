<?php
/**
 * Class style registry tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\StylesParser;
use PHPUnit\Framework\TestCase;

/**
 * Verifies class-token registration and resolution.
 */
final class ClassStyleRegistryTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		parent::tearDown();
	}

	public function test_auto_registers_missing_class_with_empty_css_body(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$style_id = ClassStyleRegistry::ensure_registered_for_class( 'stack' );

			self::assertSame( 'stack', $style_id );
			self::assertArrayHasKey( 'stack', Style::registered_styles() );
			self::assertSame( '.stack', Style::registered_styles()['stack']['selector'] );
			self::assertStringContainsString( 'builder preview', Style::registered_styles()['stack']['css'] );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_skips_runtime_class_tokens(): void {
		self::assertTrue( ClassStyleRegistry::should_skip_class_token( 'rt-accordion' ) );
		self::assertNull( ClassStyleRegistry::resolve_style_id_for_class( 'rt-accordion' ) );
	}

	public function test_extract_class_tokens_from_compound_selector(): void {
		self::assertSame(
			array( 'omide-recipes-section', 'section-head--center' ),
			ClassStyleRegistry::extract_class_tokens_from_selector(
				'.omide-recipes-section .section-head--center'
			)
		);
	}

	public function test_compound_only_style_triggers_standalone_alias(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			Style::new()
				->id( 'omide-recipes-head' )
				->selector( '.omide-recipes-section .section-head' )
				->css( 'display: flex;' )
				->add();

			$standalone_id = ClassStyleRegistry::resolve_style_id_for_class( 'section-head' );

			self::assertSame( 'section-head', $standalone_id );
			self::assertArrayHasKey( 'section-head', Style::registered_styles() );
			self::assertSame( '.section-head', Style::registered_styles()['section-head']['selector'] );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_element_block_links_compound_and_standalone_styles(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			Style::new()
				->id( 'omide-recipes-head' )
				->selector( '.omide-recipes-section .section-head' )
				->css( 'display: flex;' )
				->add();

			$markup = ElementBlock::new()
				->tag( 'div' )
				->attribute( 'class', 'section-head' )
				->styles( array( 'omide-recipes-head' ) )
				->to_block()
				->to_string();

			$ids = array();
			if ( function_exists( 'parse_blocks' ) ) {
				$blocks = parse_blocks( $markup );
				$block  = $blocks[0] ?? array();
				$attrs  = is_array( $block ) && isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$ids    = isset( $attrs['styles'] ) && is_array( $attrs['styles'] ) ? $attrs['styles'] : array();
			}

			self::assertContains( 'omide-recipes-head', $ids );
			self::assertContains( 'section-head', $ids );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_attribute_class_appends_resolved_style_ids_to_block(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$markup = ElementBlock::new()
				->tag( 'div' )
				->attribute( 'class', 'stack section' )
				->to_block()
				->to_string();

			$ids = array();
			if ( function_exists( 'parse_blocks' ) ) {
				$blocks = parse_blocks( $markup );
				$block  = $blocks[0] ?? array();
				$attrs  = is_array( $block ) && isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$ids    = isset( $attrs['styles'] ) && is_array( $attrs['styles'] ) ? $attrs['styles'] : array();
			}

			self::assertContains( 'stack', $ids );
			self::assertContains( 'section', $ids );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_runtime_class_skip_patterns_returns_non_empty_array(): void {
		$patterns = ClassStyleRegistry::runtime_class_skip_patterns();

		self::assertNotEmpty( $patterns );
		foreach ( $patterns as $pattern ) {
			self::assertIsString( $pattern );
			self::assertSame( 1, preg_match( $pattern, 'rt-anything' ), sprintf( 'Pattern %s should match rt- prefixed tokens.', $pattern ) );
		}
	}

	public function test_should_skip_class_token_honors_every_runtime_skip_pattern(): void {
		foreach ( ClassStyleRegistry::runtime_class_skip_patterns() as $pattern ) {
			self::assertTrue(
				ClassStyleRegistry::should_skip_class_token( 'rt-sample' ),
				sprintf( 'should_skip_class_token must skip tokens matching pattern %s.', $pattern )
			);
		}
	}

	public function test_auto_created_class_style_marked_with_omide_collection(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			ClassStyleRegistry::ensure_registered_for_class( 'stack' );

			$registered = Style::registered_styles();
			self::assertArrayHasKey( 'stack', $registered );
			self::assertSame( 'OhMyIDEtch', $registered['stack']['collection'] );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_non_prefixed_orphan_is_detected_as_code_owned(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			// Simulate a persisted style that was auto-created with a bare id (no omide-/clayo- prefix)
			// but carries the OhMyIDEtch collection marker.
			Environment::storage()->set(
				'etch_styles',
				array(
					'stack' => array(
						'selector'   => '.stack',
						'collection' => 'OhMyIDEtch',
						'css'        => 'display:flex',
						'type'       => 'class',
					),
				)
			);

			// Orphan detection should fire: the style is in the DB but not in the in-memory registry,
			// and it carries the code-owned collection marker.
			$reflection = new \ReflectionClass( Style::class );
			$method     = $reflection->getMethod( 'is_orphaned_code_owned_style' );
			$method->setAccessible( true );

			$result = $method->invoke(
				null,
				'stack',
				array(
					'selector'   => '.stack',
					'collection' => 'OhMyIDEtch',
					'css'        => 'display:flex',
					'type'       => 'class',
				)
			);

			self::assertTrue( $result, 'A style with collection=OhMyIDEtch must be detected as code-owned orphan even without an omide-/clayo- id prefix.' );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
			Environment::storage()->delete("'etch_styles'");
		}
	}

	public function test_resolve_class_tokens_to_style_ids_resolves_known_tokens(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			// Register a known class style.
			Style::new()
				->id( 'omide-card' )
				->selector( '.omide-card' )
				->css( 'display:block' )
				->type( 'class' )
				->collection( 'OhMyIDEtch' )
				->add();

			$ids = ClassStyleRegistry::resolve_class_tokens_to_style_ids( array( 'omide-card' ) );

			self::assertSame( array( 'omide-card' ), $ids );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_resolve_class_tokens_to_style_ids_auto_registers_missing(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();

			$ids = ClassStyleRegistry::resolve_class_tokens_to_style_ids( array( 'stack' ) );

			self::assertSame( array( 'stack' ), $ids );
			self::assertArrayHasKey( 'stack', Style::registered_styles() );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_resolve_class_tokens_to_style_ids_skips_dynamic_and_runtime_tokens(): void {
		$ids = ClassStyleRegistry::resolve_class_tokens_to_style_ids(
			array( '{props.classes}', 'rt-accordion' )
		);

		self::assertSame( array(), $ids );
	}

	public function test_resolve_class_tokens_to_style_ids_throws_on_invalid_token(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid!' );

		ClassStyleRegistry::resolve_class_tokens_to_style_ids( array( 'invalid!token' ) );
	}
}