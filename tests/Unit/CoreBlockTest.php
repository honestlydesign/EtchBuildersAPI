<?php
/**
 * CoreBlock builder tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\EtchBlocks\CoreBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;


/**
 * Verifies WordPress core block serialization.
 */
final class CoreBlockTest extends \PHPUnit\Framework\TestCase {

	public function test_post_content_renders_default_core_block_markup(): void {
		self::assertSame(
			'<!-- wp:post-content {"align":"full","layout":{"type":"default"}} /-->',
			CoreBlock::post_content()->to_block()->to_string()
		);
	}

	public function test_post_content_nests_inside_element_block_markup(): void {
		$markup = ElementBlock::new()
			->tag( 'main' )
			->child( CoreBlock::post_content()->to_block() )
			->to_block()
			->to_string();

		self::assertStringContainsString(
			'<!-- wp:post-content {"align":"full","layout":{"type":"default"}} /-->',
			$markup
		);
	}
}
