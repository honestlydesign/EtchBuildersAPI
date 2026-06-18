<?php
/**
 * Environment tests.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Contracts\AssetRegistryInterface;
use HonestlyDesign\EtchBuilders\Contracts\ModeProviderInterface;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use HonestlyDesign\EtchBuilders\Support\NullStorage;
use PHPUnit\Framework\TestCase;

/**
 * Verifies Environment defaults and configure/reset behavior.
 */
final class EnvironmentTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		parent::tearDown();
	}

	public function test_defaults_to_null_storage(): void {
		self::assertInstanceOf( NullStorage::class, Environment::storage() );
	}

	public function test_defaults_to_null_mode(): void {
		self::assertInstanceOf( NullMode::class, Environment::mode() );
	}

	public function test_defaults_to_null_asset_registry(): void {
		self::assertInstanceOf( NullAssetRegistry::class, Environment::assets() );
	}

	public function test_storage_implements_interface(): void {
		self::assertInstanceOf( StorageInterface::class, Environment::storage() );
	}

	public function test_mode_implements_interface(): void {
		self::assertInstanceOf( ModeProviderInterface::class, Environment::mode() );
	}

	public function test_assets_implements_interface(): void {
		self::assertInstanceOf( AssetRegistryInterface::class, Environment::assets() );
	}

	public function test_configure_swaps_implementations(): void {
		$storage = new NullStorage();
		$mode    = new NullMode();
		$assets  = new NullAssetRegistry();

		Environment::configure( $storage, $mode, $assets );
		self::assertSame( $storage, Environment::storage() );
		self::assertSame( $mode, Environment::mode() );
		self::assertSame( $assets, Environment::assets() );
	}

	public function test_reset_restores_defaults(): void {
		$storage = new NullStorage();
		Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );
		self::assertSame( $storage, Environment::storage() );

		Environment::reset();
		self::assertNotSame( $storage, Environment::storage() );
		self::assertInstanceOf( NullStorage::class, Environment::storage() );
	}
}
