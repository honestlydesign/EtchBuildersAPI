<?php
/**
 * Static wiring point for the package's host seams.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\AssetRegistryInterface;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;
use HonestlyDesign\EtchBuilders\Contracts\ComponentRefResolverInterface;
use HonestlyDesign\EtchBuilders\Contracts\ModeProviderInterface;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\InMemoryComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\NullComponentRefResolver;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use HonestlyDesign\EtchBuilders\Support\NullStorage;

/**
 * Holds the Storage/Mode/AssetRegistry/ComponentRefResolver implementations
 * for the current process.
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
	 * Component ref resolver implementation.
	 *
	 * @var ComponentRefResolverInterface|null
	 */
	private static ?ComponentRefResolverInterface $ref_resolver = null;

	/**
	 * Component authoring contract catalog provider.
	 */
	private static ?ComponentContractCatalogProviderInterface $component_contracts = null;

	/**
	 * Prevent instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Wire the seam implementations.
	 *
	 * @param StorageInterface               $storage      Storage adapter.
	 * @param ModeProviderInterface          $mode         Mode provider adapter.
	 * @param AssetRegistryInterface         $assets       Asset registry adapter.
	 * @param ComponentRefResolverInterface|null             $ref_resolver Component ref resolver adapter.
	 * @param ComponentContractCatalogProviderInterface|null $component_contracts Component contract provider adapter.
	 */
	public static function configure(
		StorageInterface $storage,
		ModeProviderInterface $mode,
		AssetRegistryInterface $assets,
		?ComponentRefResolverInterface $ref_resolver = null,
		?ComponentContractCatalogProviderInterface $component_contracts = null
	): void {
		self::$storage             = $storage;
		self::$mode                = $mode;
		self::$assets              = $assets;
		self::$ref_resolver        = $ref_resolver;
		self::$component_contracts = $component_contracts;
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
	 * Get the component ref resolver (defaults to NullComponentRefResolver).
	 */
	public static function ref_resolver(): ComponentRefResolverInterface {
		return self::$ref_resolver ??= new NullComponentRefResolver();
	}

	/**
	 * Get the component contract provider (defaults to an empty in-memory catalog).
	 */
	public static function component_contracts(): ComponentContractCatalogProviderInterface {
		return self::$component_contracts ??= InMemoryComponentContractCatalogProvider::empty();
	}

	/**
	 * Restore the Null* defaults (for tests).
	 */
	public static function reset(): void {
		self::$storage             = null;
		self::$mode                = null;
		self::$assets              = null;
		self::$ref_resolver        = null;
		self::$component_contracts = null;
	}
}
