<?php
/**
 * Static wiring point for the package's three seams.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\AssetRegistryInterface;
use HonestlyDesign\EtchBuilders\Contracts\ModeProviderInterface;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use HonestlyDesign\EtchBuilders\Support\NullStorage;

/**
 * Holds the Storage/Mode/AssetRegistry implementations for the current process.
 *
 * Consumers (e.g. the WordPress starter) call configure() once at bootstrap.
 * Defaults to Null* implementations so the package is usable without a host.
 */
final class Environment {

	/**
	 * Storage implementation.
	 *
	 * @var StorageInterface|null
	 */
	private static ?StorageInterface $storage = null;

	/**
	 * Mode provider implementation.
	 *
	 * @var ModeProviderInterface|null
	 */
	private static ?ModeProviderInterface $mode = null;

	/**
	 * Asset registry implementation.
	 *
	 * @var AssetRegistryInterface|null
	 */
	private static ?AssetRegistryInterface $assets = null;

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Wire the three seam implementations.
	 *
	 * @param StorageInterface        $storage Storage adapter.
	 * @param ModeProviderInterface   $mode    Mode provider adapter.
	 * @param AssetRegistryInterface  $assets  Asset registry adapter.
	 */
	public static function configure( StorageInterface $storage, ModeProviderInterface $mode, AssetRegistryInterface $assets ): void {
		self::$storage = $storage;
		self::$mode    = $mode;
		self::$assets  = $assets;
	}

	/**
	 * Get the storage implementation (defaults to NullStorage).
	 */
	public static function storage(): StorageInterface {
		return self::$storage ??= new NullStorage();
	}

	/**
	 * Get the mode provider (defaults to NullMode).
	 */
	public static function mode(): ModeProviderInterface {
		return self::$mode ??= new NullMode();
	}

	/**
	 * Get the asset registry (defaults to NullAssetRegistry).
	 */
	public static function assets(): AssetRegistryInterface {
		return self::$assets ??= new NullAssetRegistry();
	}

	/**
	 * Restore the Null* defaults (for tests).
	 */
	public static function reset(): void {
		self::$storage = null;
		self::$mode    = null;
		self::$assets  = null;
	}
}
