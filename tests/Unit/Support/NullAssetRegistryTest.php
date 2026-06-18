<?php
/**
 * NullAssetRegistry tests.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit\Support;

use HonestlyDesign\EtchBuilders\Contracts\AssetRegistryInterface;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies NullAssetRegistry honors AssetRegistryInterface and records assets in memory.
 */
final class NullAssetRegistryTest extends TestCase {

	public function test_implements_asset_registry_interface(): void {
		self::assertInstanceOf( AssetRegistryInterface::class, new NullAssetRegistry() );
	}

	public function test_register_then_get_assets_roundtrip(): void {
		$registry = new NullAssetRegistry();
		$registry->register( 'Accordion', 'styles', 'accordion-css', '/dist/accordion.css' );
		$registry->register( 'Accordion', 'scripts', 'accordion-js', '/dist/accordion.js' );

		$assets = $registry->get_assets( 'Accordion' );
		self::assertArrayHasKey( 'styles', $assets );
		self::assertArrayHasKey( 'scripts', $assets );
	}

	public function test_has_assets_reflects_registration(): void {
		$registry = new NullAssetRegistry();
		self::assertFalse( $registry->has_assets( 'Card' ) );
		$registry->register( 'Card', 'styles', 'card-css', '/dist/card.css' );
		self::assertTrue( $registry->has_assets( 'Card' ) );
	}

	public function test_get_assets_returns_empty_for_unknown_component(): void {
		$registry = new NullAssetRegistry();
		self::assertSame( array(), $registry->get_assets( 'Unknown' ) );
	}

	public function test_get_registered_keys_lists_registered_components(): void {
		$registry = new NullAssetRegistry();
		$registry->register( 'A', 'styles', 'a-css', '/a.css' );
		$registry->register( 'B', 'scripts', 'b-js', '/b.js' );
		self::assertSame( array( 'A', 'B' ), $registry->get_registered_keys() );
	}
}
