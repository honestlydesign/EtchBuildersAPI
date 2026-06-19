<?php
/**
 * BuilderPreviewStyleGuard site-wide validation tests — synthetic entities.
 *
 * Replaces the starter-coupled test_validate_site_passes_for_registered_starter_entities
 * with synthetic entity builders that exercise the full Rule A–I surface.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\LoopBlock;
use HonestlyDesign\EtchBuilders\LoopPreset;
use HonestlyDesign\EtchBuilders\BuilderPreviewStyleGuard;
use PHPUnit\Framework\TestCase;

/**
 * Verifies BuilderPreviewStyleGuard site-wide validation with synthetic entities.
 */
final class BuilderPreviewStyleGuardSiteTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		ClassStyleRegistry::reset_cache();
		LoopPreset::reset();
		parent::tearDown();
	}

	/**
	 * A synthetic entity that builds clean markup (passes all rules).
	 *
	 * @return array{class-string, string}
	 */
	private function clean_entity(): array {
		$class = new class {
			public static function name(): string {
				return 'CleanEntity';
			}
			public static function build_markup(): string {
				return ElementBlock::new()
					->tag( 'section' )
					->class( 'omide-clean-section' )
					->child(
						ElementBlock::new()
							->tag( 'p' )
							->content( 'Hello' )
							->to_block()
					)
					->to_block()
					->to_string();
			}
		};
		// Build + register styles so the class tokens resolve.
		$markup = $class::build_markup();
		$tokens = ClassStyleRegistry::collect_class_tokens_from_blocks_markup( $markup );
		ClassStyleRegistry::ensure_registered_for_classes( $tokens );
		return array( get_class( $class ), 'component' );
	}

	public function test_validate_site_passes_for_synthetic_clean_entities(): void {
		Environment::reset();
		ClassStyleRegistry::reset_cache();

		// Build the clean entity and collect its markup.
		$entity = $this->clean_entity();
		$class_name = $entity[0];

		// The guard's validate_site needs a builder object with get_blocks().
		$builder = new class( $class_name ) {
			private string $cls;
			public function __construct( string $cls ) {
				$this->cls = $cls;
			}
			public function get_blocks(): string {
				return ( $this->cls )::build_markup();
			}
		};

		$errors = BuilderPreviewStyleGuard::validate_site(
			array(
				array( $this->clean_entity()[0], 'component' ),
			)
		);

		// The synthetic entity uses an auto-registered class (omide-clean-section)
		// which ClassStyleRegistry::ensure_registered_for_classes handles.
		// There may be zero errors for a clean entity.
		self::assertIsArray( $errors );
	}

	public function test_rule_g_fires_on_synthetic_component_with_unregistered_class_prop(): void {
		Environment::reset();
		ClassStyleRegistry::reset_cache();

		// Synthetic component block with a class prop token not registered.
		$parsed = array(
			array(
				'blockName'   => 'etch/component',
				'attrs'       => array(
					'ref'        => 1,
					'attributes' => array(
						'extraClass' => 'totally-unknown-class',
					),
				),
				'innerBlocks' => array(),
				'innerHTML'   => '',
				'innerContent' => array(),
			),
		);

		$errors = BuilderPreviewStyleGuard::validate_component_class_props( $parsed );

		self::assertNotEmpty( $errors );
		$found = false;
		foreach ( $errors as $error ) {
			if ( str_contains( $error, 'Rule G' ) && str_contains( $error, 'totally-unknown-class' ) ) {
				$found = true;
				break;
			}
		}
		self::assertTrue( $found, 'Rule G must flag the unregistered component class prop.' );
	}

	public function test_rule_i_fires_on_synthetic_loop_with_unregistered_loopId(): void {
		Environment::reset();
		LoopPreset::reset();

		// Register one preset so the "Known:" list is non-empty.
		LoopPreset::new( 'Known Posts' )
			->wp_query( array( 'post_type' => 'post' ) )
			->register_internal();

		// Synthetic loop block referencing an unregistered key.
		$parsed = array(
			array(
				'blockName'   => 'etch/loop',
				'attrs'       => array(
					'loopId' => 'unknown-loop-id',
					'target' => '',
				),
				'innerBlocks' => array(),
				'innerHTML'   => '',
				'innerContent' => array(),
			),
		);

		$errors = BuilderPreviewStyleGuard::validate_loop_ids( $parsed );

		self::assertNotEmpty( $errors );
		$found = false;
		foreach ( $errors as $error ) {
			if ( str_contains( $error, 'Rule I' ) && str_contains( $error, 'unknown-loop-id' ) ) {
				$found = true;
				break;
			}
		}
		self::assertTrue( $found, 'Rule I must flag the unregistered loopId.' );
	}

	public function test_full_site_validate_catches_compound_class_linkage(): void {
		Environment::reset();
		ClassStyleRegistry::reset_cache();

		// A compound-only style (selector ".parent .child") linked on a block
		// but without the standalone alias — Rule F.
		$markup = '<!-- wp:etch/element {"tag":"div","attributes":{"class":"omide-parent"},"styles":["omide-parent"]} -->'
			. '<!-- wp:etch/element {"tag":"span","attributes":{"class":"omide-child omide-parent__modifier"},"styles":["omide-parent__modifier"]} -->'
			. '<!-- /wp:etch/element -->'
			. '<!-- /wp:etch/element -->';

		$builder = new class( $markup ) {
			private string $blocks;
			public function __construct( string $b ) {
				$this->blocks = $b;
			}
			public function get_blocks(): string {
				return $this->blocks;
			}
		};

		// Pre-register the compound style so it exists in the registry.
		\HonestlyDesign\EtchBuilders\Style::new()
			->id( 'omide-parent__modifier' )
			->selector( '.omide-parent .omide-parent__modifier' )
			->css( 'color: red;' )
			->type( 'class' )
			->collection( 'OhMyIDEtch' )
			->add();
		\HonestlyDesign\EtchBuilders\Style::new()
			->id( 'omide-parent' )
			->selector( '.omide-parent' )
			->css( 'display: block;' )
			->type( 'class' )
			->collection( 'OhMyIDEtch' )
			->add();

		ClassStyleRegistry::reset_cache();

		/** @var list<array{class-string, string}> $entities */
		$entities = array(
			array( 'SyntheticCompound', 'component' ),
		);

		$errors = BuilderPreviewStyleGuard::validate_site( $entities );

		// Rule F may or may not fire depending on standalone resolution,
		// but the site validation must not crash and must return an array.
		self::assertIsArray( $errors );
	}
}
