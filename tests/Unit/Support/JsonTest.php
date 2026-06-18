<?php
/**
 * Json helper tests.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit\Support;

use HonestlyDesign\EtchBuilders\Support\Json;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Json::encode matches wp_json_encode for Etch's wire format.
 */
final class JsonTest extends TestCase {

	public function test_encodes_array_with_unescaped_slashes_and_unicode(): void {
		self::assertSame(
			'{"class":"café/site"}',
			Json::encode( array( 'class' => 'café/site' ) )
		);
	}

	public function test_encodes_stdclass_as_object(): void {
		$obj       = new \stdClass();
		$obj->k    = 'v';
		self::assertSame( '{"k":"v"}', Json::encode( $obj ) );
	}

	public function test_preserves_numeric_keys_as_array_not_object(): void {
		self::assertSame( '[1,2,3]', Json::encode( array( 1, 2, 3 ) ) );
	}

	public function test_empty_list_array_is_brackets(): void {
		self::assertSame( '[]', Json::encode( array() ) );
	}

	public function test_encodes_nested_assoc(): void {
		self::assertSame(
			'{"a":{"b":"c"}}',
			Json::encode( array( 'a' => array( 'b' => 'c' ) ) )
		);
	}
}
