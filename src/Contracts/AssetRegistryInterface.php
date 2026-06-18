<?php
/**
 * Component asset registry contract abstracting ComponentAssetRegistry.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

/**
 * Records per-component CSS/JS assets to be enqueued at render time.
 */
interface AssetRegistryInterface {

	/**
	 * Register one asset for a component.
	 *
	 * @param string $component_key Component key.
	 * @param string $type          'styles' or 'scripts'.
	 * @param string $handle        Unique enqueue handle.
	 * @param string $path          Path relative to the consumer's assets dir.
	 */
	public function register( string $component_key, string $type, string $handle, string $path ): void;

	/**
	 * All registered assets for a component, grouped by type.
	 *
	 * @param string $component_key Component key.
	 * @return array<string, mixed> Map of type => list of {handle, path}.
	 */
	public function get_assets( string $component_key ): array;

	/**
	 * Whether a component has any registered assets.
	 *
	 * @param string $component_key Component key.
	 */
	public function has_assets( string $component_key ): bool;

	/**
	 * Keys of all components that have at least one registered asset.
	 *
	 * @return array<int, string>
	 */
	public function get_registered_keys(): array;
}
