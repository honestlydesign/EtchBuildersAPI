<?php
/**
 * Read-only WordPress capability adapter for Site compilation.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\Contracts\SiteRuntimeCapabilitiesInterface;

/**
 * Delegates only to WordPress's public post_type_exists() query.
 */
final class WordPressSiteRuntimeCapabilities implements SiteRuntimeCapabilitiesInterface {

	public static function new(): self {
		return new self();
	}

	/**
	 * {@inheritdoc}
	 */
	public function post_type_exists( string $post_type ): ?bool {
		if ( ! function_exists( 'post_type_exists' ) ) {
			return null;
		}

		return post_type_exists( $post_type );
	}
}
