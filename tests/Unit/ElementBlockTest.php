<?php
/**
 * ElementBlock builder tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;

/**
 * Verifies element wrapper serialization.
 */
final class ElementBlockTest extends \PHPUnit\Framework\TestCase {

	public function test_etch_section_helper_adds_native_section_attrs_and_default_style(): void {
		$markup = ElementBlock::new()
			->is_etch_section()
			->to_block()
			->to_string();

		self::assertSame(
			'<!-- wp:etch/element {"tag":"section","attributes":{"data-etch-element":"section"},"metadata":{"name":"Section"},"styles":["etch-section-style"]} --><!-- /wp:etch/element -->',
			$markup
		);
	}

	public function test_etch_section_container_helper_adds_native_container_attrs_and_default_style(): void {
		$markup = ElementBlock::new()
			->is_etch_section_container()
			->to_block()
			->to_string();

		self::assertSame(
			'<!-- wp:etch/element {"tag":"div","attributes":{"data-etch-element":"container"},"metadata":{"name":"Container"},"styles":["etch-container-style"]} --><!-- /wp:etch/element -->',
			$markup
		);
	}

	public function test_etch_section_helper_does_not_duplicate_default_style(): void {
		$markup = ElementBlock::new()
			->style( 'etch-section-style' )
			->is_etch_section()
			->to_block()
			->to_string();

		self::assertSame(
			1,
			substr_count( $markup, 'etch-section-style' )
		);
	}
}
