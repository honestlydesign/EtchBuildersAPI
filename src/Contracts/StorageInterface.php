<?php
/**
 * Key/value storage contract abstracting get_option/update_option/delete_option.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

/**
 * Persistence seam for the builder registries.
 */
interface StorageInterface {

	/**
	 * Read a value by key.
	 *
	 * @param string $key     Storage key (e.g. 'etch_styles').
	 * @param mixed  $default Default when the key is absent.
	 * @return mixed
	 */
	public function get( string $key, mixed $default = null ): mixed;

	/**
	 * Write a value by key.
	 *
	 * @param string $key   Storage key.
	 * @param mixed  $value Value to persist.
	 * @return bool Whether the write succeeded.
	 */
	public function set( string $key, mixed $value ): bool;

	/**
	 * Delete a value by key.
	 *
	 * @param string $key Storage key.
	 * @return bool Whether the delete succeeded (true even if the key was absent).
	 */
	public function delete( string $key ): bool;
}
