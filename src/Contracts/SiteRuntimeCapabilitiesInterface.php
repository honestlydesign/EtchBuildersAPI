<?php
/**
 * Read-only runtime capability seam for Site Definition compilation.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

/**
 * Answers capability questions without permitting compiler writes.
 */
interface SiteRuntimeCapabilitiesInterface {

	/**
	 * Determine whether one post type is available.
	 *
	 * @return bool|null True/false when known; null when the runtime is unavailable.
	 */
	public function post_type_exists( string $post_type ): ?bool;
}
