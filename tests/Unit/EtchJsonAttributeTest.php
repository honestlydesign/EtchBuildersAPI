<?php
/**
 * EtchJsonAttribute builder tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\Support\EtchJsonAttribute;


/**
 * Verifies Etch JSON attribute encoding for block builders.
 */
final class EtchJsonAttributeTest extends \PHPUnit\Framework\TestCase {

	public function test_encode_value_escapes_object_json_from_array(): void {
		self::assertSame(
			'{{"foo":"bar"}}',
			EtchJsonAttribute::encode_value( array( 'foo' => 'bar' ) )
		);
	}

	public function test_encode_value_escapes_object_json_from_string(): void {
		self::assertSame(
			'[{{"from":"a"}}]',
			EtchJsonAttribute::encode_value( '[{"from":"a"}]' )
		);
	}

	public function test_encode_value_leaves_empty_array_unchanged(): void {
		self::assertSame(
			'[]',
			EtchJsonAttribute::encode_value( array() )
		);
	}

	public function test_element_block_json_attribute_serializes_escaped_json(): void {
		$markup = ElementBlock::new()
			->tag( 'div' )
			->json_attribute( 'data-edges', array( array( 'from' => 'a', 'to' => 'b' ) ) )
			->to_block()
			->to_string();

		self::assertStringContainsString(
			'"data-edges":"[{{\"from\":\"a\",\"to\":\"b\"}}]"',
			$markup
		);
	}
}