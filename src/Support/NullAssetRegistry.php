<?php
/**
 * In-memory AssetRegistryInterface implementation.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\Contracts\AssetRegistryInterface;

/**
 * Records assets in a PHP array for tests and non-enqueue consumers.
 */
final class NullAssetRegistry implements AssetRegistryInterface {

	/**
	 * Backing map: component_key => type => list of {handle, path}.
	 *
	 * @var array<string, array<string, array<int, array{handle: string, path: string}>>>
	 */
	private array $assets = array();

	/**
	 * {@inheritdoc}
	 */
	public function register( string $component_key, string $type, string $handle, string $path ): void {
		$this->assets[ $component_key ][ $type ][] = array(
			'handle' => $handle,
			'path'   => $path,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_assets( string $component_key ): array {
		return $this->assets[ $component_key ] ?? array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function has_assets( string $component_key ): bool {
		return isset( $this->assets[ $component_key ] ) && array() !== $this->assets[ $component_key ];
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_registered_keys(): array {
		return array_keys( $this->assets );
	}
}
