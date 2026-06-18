<?php
/**
 * In-memory StorageInterface implementation for tests and non-persistent use.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;

/**
 * Stores values in a PHP array for the lifetime of the process.
 */
final class NullStorage implements StorageInterface {

	/**
	 * Backing array.
	 *
	 * @var array<string, mixed>
	 */
	private array $values = array();

	/**
	 * {@inheritdoc}
	 */
	public function get( string $key, mixed $default = null ): mixed {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
	}

	/**
	 * {@inheritdoc}
	 */
	public function set( string $key, mixed $value ): bool {
		$this->values[ $key ] = $value;
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( string $key ): bool {
		unset( $this->values[ $key ] );
		return true;
	}
}
