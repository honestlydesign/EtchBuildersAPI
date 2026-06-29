<?php
/**
 * Style persistence tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Style;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Etch style option merge and pruning behavior.
 */
final class StyleTest extends TestCase {

	public function test_register_all_preserves_external_readonly_styles_when_registry_is_empty(): void {
		Environment::reset();
		Style::reset();

		Environment::storage()->set(
			'etch_styles',
			array(
				'etch-section-style' => array(
					'selector'   => ':where([data-etch-element="section"])',
					'collection' => 'default',
					'css'        => 'inline-size: 100%;',
					'readonly'   => true,
					'type'       => 'element',
				),
				'omide-stale-style' => array(
					'selector'   => '.omide-stale-style',
					'collection' => 'default',
					'css'        => 'display: block;',
					'readonly'   => true,
					'type'       => 'class',
				),
			)
		);

		self::assertTrue( Style::register_all() );

		$styles = Environment::storage()->get( 'etch_styles', array() );
		self::assertIsArray( $styles );
		self::assertArrayHasKey( 'etch-section-style', $styles );
		self::assertArrayNotHasKey( 'omide-stale-style', $styles );
	}

	protected function tearDown(): void {
		Style::reset();
		Environment::reset();
		parent::tearDown();
	}
}
