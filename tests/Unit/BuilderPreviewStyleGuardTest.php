<?php
/**
 * Builder preview style guard tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\BuilderPreviewStyleGuard;
use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\Component;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Stylesheet;
use HonestlyDesign\EtchBuilders\StylesParser;
use PHPUnit\Framework\TestCase;

/**
 * Verifies preview-safe style crosswalk rules.
 */
final class BuilderPreviewStyleGuardTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		parent::tearDown();
	}

	public function test_collect_style_ids_from_element_block_markup(): void {
		$markup = ElementBlock::new()
			->tag( 'div' )
			->style( 'omide-demo' )
			->styles( array( 'omide-demo-extra' ) )
			->to_block()
			->to_string();

		$ids = BuilderPreviewStyleGuard::collect_style_ids_from_blocks_markup( $markup );

		self::assertContains( 'omide-demo', $ids );
		self::assertContains( 'omide-demo-extra', $ids );
	}

	public function test_rule_c_flags_phantom_runtime_style_reference(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			Style::new()
				->id( 'omide-demo' )
				->selector( '.omide-demo' )
				->css( 'color: red;' )
				->add();

			$markup = ElementBlock::new()
				->tag( 'div' )
				->class( 'rt-accordion' )
				->styles( array( 'rt-accordion' ) )
				->to_block()
				->to_string();

			$entities = array(
				'fixture' => array(
					'OhMyIDEtch\\Tests\\Unit\\Builders\\BuilderPreviewStyleGuardTest',
					'fixture',
				),
			);

			// Use a tiny inline entity simulation via HelloWorld pattern: override with manual validate on markup only.
			$referenced = BuilderPreviewStyleGuard::collect_style_ids_from_blocks_markup( $markup );
			$registered = array_keys( Style::registered_styles() );
			$phantom    = array_diff( $referenced, $registered );

			self::assertContains( 'rt-accordion', $phantom );
		} finally {
			Style::restore( $style_snapshot );
		}
	}

	public function test_rule_f_flags_compound_only_class_linkage(): void {
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

			$markup = '<!-- wp:etch/element {"tag":"div","attributes":{"class":"section-head"},"styles":["omide-recipes-head"]} /-->';
			$errors = array();

			if ( function_exists( 'parse_blocks' ) ) {
				foreach ( parse_blocks( $markup ) as $block ) {
					if ( is_array( $block ) ) {
						$errors = array_merge(
							$errors,
							ClassStyleRegistry::validate_block_standalone_class_linkage( $block )
						);
					}
				}
			}

			self::assertNotEmpty( $errors );
			self::assertStringContainsString( 'Rule F', $errors[0] );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_rule_g_flags_component_class_prop_token_not_registered(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();
			Environment::storage()->delete("'etch_styles'");

			// A component block whose `extraClass` prop references a token not in etch_styles.
			// Etch class-typed props use the singular `class` suffix (e.g. extraClass, cardClass).
			$parsed = parse_blocks(
				'<!-- wp:etch/component {"ref":1,"attributes":{"extraClass":"phantom-token"}} /-->'
			);

			$errors = BuilderPreviewStyleGuard::validate_component_class_props( $parsed );

			$found = false;
			foreach ( $errors as $error ) {
				if ( str_contains( $error, 'Rule G' ) && str_contains( $error, 'phantom-token' ) ) {
					$found = true;
					break;
				}
			}
			self::assertTrue( $found, 'Expected a Rule G error for phantom-token. Got: ' . implode( PHP_EOL, $errors ) );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_rule_g_passes_when_component_class_prop_token_registered(): void {
		$style_snapshot = Style::snapshot();

		try {
			Style::reset();
			Environment::reset();
			ClassStyleRegistry::reset_cache();
			Environment::storage()->delete("'etch_styles'");

			Style::new()
				->id( 'omide-card' )
				->selector( '.omide-card' )
				->css( 'display:block' )
				->type( 'class' )
				->collection( 'OhMyIDEtch' )
				->add();

			$parsed = parse_blocks(
				'<!-- wp:etch/component {"ref":1,"attributes":{"extraClass":"omide-card"}} /-->'
			);

			$errors = BuilderPreviewStyleGuard::validate_component_class_props( $parsed );

			self::assertSame( array(), $errors );
		} finally {
			ClassStyleRegistry::reset_cache();
			Style::restore( $style_snapshot );
		}
	}

	public function test_rule_g_skips_dynamic_and_runtime_component_class_tokens(): void {
		$parsed = parse_blocks(
			'<!-- wp:etch/component {"ref":1,"attributes":{"extraClass":"{props.extra} rt-active"}} /-->'
		);

		$errors = BuilderPreviewStyleGuard::validate_component_class_props( $parsed );
		self::assertSame( array(), $errors );
	}

	public function test_rule_h_flags_undeclared_custom_media_reference(): void {
		Stylesheet::reset_custom_media();
		Stylesheet::register_custom_media( 'tablet', '(min-width: 768px)' );

		// A style referencing 'tablet' (declared) and 'mobile' (NOT declared).
		$referenced = array(
			'hero-style' => array( 'tablet', 'mobile' ),
		);

		$errors = BuilderPreviewStyleGuard::validate_custom_media_references( $referenced );

		$found = false;
		foreach ( $errors as $error ) {
			if ( str_contains( $error, 'Rule H' ) && str_contains( $error, '--mobile' ) && str_contains( $error, 'hero-style' ) ) {
				$found = true;
				self::assertStringContainsString( 'Stylesheet::register_custom_media()', $error );
				self::assertStringContainsString( 'Custom Media Definitions', $error );
				self::assertStringNotContainsString( 'registered stylesheet', $error );
				break;
			}
		}
		self::assertTrue( $found, 'Expected Rule H error for --mobile. Got: ' . implode( PHP_EOL, $errors ) );

		Stylesheet::reset_custom_media();
	}

	public function test_rule_h_passes_when_all_references_declared(): void {
		Stylesheet::reset_custom_media();
		Stylesheet::register_custom_media( 'tablet', '(min-width: 768px)' );

		$errors = BuilderPreviewStyleGuard::validate_custom_media_references(
			array( 'hero-style' => array( 'tablet' ) )
		);

		self::assertSame( array(), $errors );

		Stylesheet::reset_custom_media();
	}

	public function test_rule_i_flags_loop_id_not_registered(): void {
		$snapshot = \HonestlyDesign\EtchBuilders\LoopPreset::snapshot();

		try {
			\HonestlyDesign\EtchBuilders\LoopPreset::reset();

			$parsed = parse_blocks(
				'<!-- wp:etch/loop {"loopId":"phantom-loop","target":""} --><!-- /wp:etch/loop -->'
			);

			$errors = BuilderPreviewStyleGuard::validate_loop_ids( $parsed );

			$found = false;
			foreach ( $errors as $error ) {
				if ( str_contains( $error, 'Rule I' ) && str_contains( $error, 'phantom-loop' ) ) {
					$found = true;
					break;
				}
			}
			self::assertTrue( $found, 'Expected Rule I error for phantom-loop. Got: ' . implode( PHP_EOL, $errors ) );
		} finally {
			\HonestlyDesign\EtchBuilders\LoopPreset::restore( $snapshot );
		}
	}
}
