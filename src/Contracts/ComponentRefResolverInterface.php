<?php
/**
 * Component reference resolver contract abstracting ComponentRegistry.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

/**
 * Resolves component keys (e.g. 'Accordion') to numeric refs (wp_block post IDs)
 * and back. The concrete implementation typically queries WordPress post meta.
 */
interface ComponentRefResolverInterface {

	/**
	 * Resolve a component key to its numeric ref (post ID).
	 *
	 * @param string $component_key Component key.
	 * @return int The ref, or 0 when the key is unknown.
	 */
	public function ref_by_key( string $component_key ): int;

	/**
	 * Resolve a numeric ref back to its component key.
	 *
	 * @param int $ref Component ref (post ID).
	 * @return string|null The key, or null when the ref is unknown.
	 */
	public function key_by_ref( int $ref ): ?string;
}
