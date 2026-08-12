<?php
/**
 * Deterministic runtime capability adapter for pure tests and callers.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\Contracts\SiteRuntimeCapabilitiesInterface;

/**
 * Represents a known post-type capability set without WordPress.
 */
final class InMemorySiteRuntimeCapabilities implements SiteRuntimeCapabilitiesInterface {

	/**
	 * @param array<int, string>|null $post_types Known registered post types, or null when unavailable.
	 */
	private function __construct( private readonly ?array $post_types ) {
	}

	/**
	 * Build a known capability set.
	 *
	 * @param string ...$post_types Known post types.
	 */
	public static function known( string ...$post_types ): self {
		$normalized = array();
		foreach ( $post_types as $post_type ) {
			$post_type = trim( $post_type );
			if ( '' !== $post_type && ! in_array( $post_type, $normalized, true ) ) {
				$normalized[] = $post_type;
			}
		}

		return new self( $normalized );
	}

	/**
	 * Build an adapter that knows no runtime capabilities.
	 */
	public static function unavailable(): self {
		return new self( null );
	}

	/**
	 * {@inheritdoc}
	 */
	public function post_type_exists( string $post_type ): ?bool {
		if ( null === $this->post_types ) {
			return null;
		}

		return in_array( $post_type, $this->post_types, true );
	}
}
