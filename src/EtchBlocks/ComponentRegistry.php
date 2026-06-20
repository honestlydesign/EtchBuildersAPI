<?php
/**
 * Centralized component registry for Etch component lookups.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use HonestlyDesign\EtchBuilders\Block;

/**
 * Provides centralized access to registered Etch components.
 * Handles lookup by component key and creates ComponentBlock instances.
 */
final class ComponentRegistry {

	private const COMPONENT_KEY_META = 'etch_component_html_key';

	/**
	 * Slug prefixes for wp_block posts, in resolution order (local site first).
	 *
	 * @var array<int, string>
	 */
	private const SLUG_PREFIXES = array(
		'omide-component-',
		'ome-component-',
	);

	/**
	 * Component key to post ID cache.
	 *
	 * @var array<string, int>
	 */
	private static array $cache = array();

	/**
	 * Reverse cache: post ID to component key.
	 *
	 * @var array<int, string>
	 */
	private static array $reverse_cache = array();

	/**
	 * Prevent instantiation of static utility class.
	 */
	private function __construct() {
	}

	/**
	 * Get component reference ID by component key.
	 *
	 * Resolves any published Etch wp_block with matching etch_component_html_key
	 * (Oh My IDE Etch site components, Oh My Etch, third-party plugins). Falls back
	 * to known component slug prefixes when meta is absent.
	 *
	 * @param string $component_key The component key (e.g. 'OmeCarousel', 'OmideHelloWorld').
	 * @return int The component reference ID, or 0 if not found.
	 */
	public static function ref_by_key( string $component_key ): int {
		if ( isset( self::$cache[ $component_key ] ) && self::$cache[ $component_key ] > 0 ) {
			return self::$cache[ $component_key ];
		}

		$ref_id = self::lookup_by_etch_key( $component_key );

		if ( 0 === $ref_id ) {
			$ref_id = self::lookup_by_slug_prefixes( $component_key );
		}

		self::remember_ref( $component_key, $ref_id );

		return $ref_id;
	}

	/**
	 * Get component key by reference ID (reverse lookup).
	 *
	 * @param int $ref The component reference ID (wp_block post ID).
	 * @return string|null The component key, or null if not found.
	 */
	public static function key_by_ref( int $ref ): ?string {
		if ( isset( self::$reverse_cache[ $ref ] ) ) {
			return self::$reverse_cache[ $ref ];
		}

		$post = \get_post( $ref );
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return null;
		}

		$component_key = \get_post_meta( $ref, self::COMPONENT_KEY_META, true );
		if ( ! is_string( $component_key ) || '' === $component_key ) {
			return null;
		}

		self::remember_ref( $component_key, $ref );

		return $component_key;
	}

	/**
	 * Check if a component is registered by key.
	 *
	 * @param string $component_key The component key to check.
	 * @return bool True if the component is registered, false otherwise.
	 */
	public static function has( string $component_key ): bool {
		return self::ref_by_key( $component_key ) > 0;
	}

	/**
	 * Create a ComponentBlock for a component by key.
	 *
	 * Convenience method that combines ref_by_key() with ComponentBlock::new().
	 *
	 * @param string               $component_key The component key (e.g. 'AccordionItem').
	 * @param array<string, mixed> $config Optional additional config for ComponentBlock.
	 * @return Block|null The configured Block, or null if component not found.
	 */
	public static function block( string $component_key, array $config = array() ): Block|null {
		$ref_id = self::ref_by_key( $component_key );

		if ( 0 === $ref_id ) {
			return null;
		}

		$block = ComponentBlock::new()
			->ref( $ref_id );

		if ( array() !== $config ) {
			foreach ( $config as $key => $value ) {
				if ( is_string( $key ) && ( is_string( $value ) || is_int( $value ) ) ) {
					$block->attribute( $key, (string) $value );
				}
			}
		}

		return $block->to_block();
	}

	/**
	 * Clear the component reference cache.
	 */
	public static function clear_cache(): void {
		self::$cache         = array();
		self::$reverse_cache = array();
	}

	/**
	 * Preload multiple component references at once.
	 *
	 * @param array<int, string> $component_keys Array of component keys to preload.
	 */
	public static function preload( array $component_keys ): void {
		self::preload_batch( $component_keys );
	}

	/**
	 * Batch preload component references with a small number of queries.
	 *
	 * @param array<int, string> $component_keys Array of component keys to preload.
	 */
	public static function preload_batch( array $component_keys ): void {
		if ( array() === $component_keys ) {
			return;
		}

		$uncached_keys = array_values(
			array_filter(
				$component_keys,
				static fn( string $key ): bool => ! isset( self::$cache[ $key ] ) || 0 === self::$cache[ $key ]
			)
		);

		if ( array() === $uncached_keys ) {
			return;
		}

		self::preload_batch_by_etch_key( $uncached_keys );

		$still_missing = array_values(
			array_filter(
				$uncached_keys,
				static fn( string $key ): bool => ! isset( self::$cache[ $key ] ) || 0 === self::$cache[ $key ]
			)
		);

		if ( array() !== $still_missing ) {
			self::preload_batch_by_slug_prefixes( $still_missing );
		}
	}

	/**
	 * @param string $component_key Component key.
	 */
	private static function lookup_by_etch_key( string $component_key ): int {
		if ( ! \function_exists( 'get_posts' ) ) {
			return 0;
		}

		$posts = \get_posts(
			array(
				'post_type'              => 'wp_block',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => self::COMPONENT_KEY_META,
						'value' => $component_key,
					),
				),
			)
		);

		$post = $posts[0] ?? null;

		if ( ! $post instanceof \WP_Post ) {
			return 0;
		}

		return (int) $post->ID;
	}

	/**
	 * @param string $component_key Component key.
	 */
	private static function lookup_by_slug_prefixes( string $component_key ): int {
		if ( ! \function_exists( 'get_page_by_path' ) ) {
			return 0;
		}

		$sanitized = \sanitize_key( $component_key );

		foreach ( self::SLUG_PREFIXES as $prefix ) {
			$existing = \get_page_by_path( $prefix . $sanitized, OBJECT, 'wp_block' );

			if ( $existing ) {
				return (int) $existing->ID;
			}
		}

		return 0;
	}

	/**
	 * @param array<int, string> $component_keys Component keys.
	 */
	private static function preload_batch_by_etch_key( array $component_keys ): void {
		if ( ! \function_exists( 'get_posts' ) ) {
			return;
		}

		$posts = \get_posts(
			array(
				'post_type'              => 'wp_block',
				'post_status'            => 'publish',
				'posts_per_page'         => count( $component_keys ),
				'no_found_rows'          => true,
				'suppress_filters'       => false,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => self::COMPONENT_KEY_META,
						'value'   => $component_keys,
						'compare' => 'IN',
					),
				),
			)
		);

		$key_to_id = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$meta_key = \get_post_meta( (int) $post->ID, self::COMPONENT_KEY_META, true );

			if ( is_string( $meta_key ) && '' !== $meta_key ) {
				$key_to_id[ $meta_key ] = (int) $post->ID;
			}
		}

		foreach ( $component_keys as $key ) {
			if ( isset( $key_to_id[ $key ] ) ) {
				self::remember_ref( $key, $key_to_id[ $key ] );
			}
		}
	}

	/**
	 * @param array<int, string> $component_keys Component keys.
	 */
	private static function preload_batch_by_slug_prefixes( array $component_keys ): void {
		if ( ! \function_exists( 'get_posts' ) ) {
			return;
		}

		$slug_to_key = array();

		foreach ( $component_keys as $key ) {
			if ( isset( self::$cache[ $key ] ) && self::$cache[ $key ] > 0 ) {
				continue;
			}

			$sanitized = \sanitize_key( $key );

			foreach ( self::SLUG_PREFIXES as $prefix ) {
				$slug_to_key[ $prefix . $sanitized ] = $key;
			}
		}

		if ( array() === $slug_to_key ) {
			return;
		}

		$posts = \get_posts(
			array(
				'post_type'              => 'wp_block',
				'post_status'            => 'publish',
				'post_name__in'          => array_keys( $slug_to_key ),
				'posts_per_page'         => count( $slug_to_key ),
				'no_found_rows'          => true,
				'suppress_filters'       => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$resolved = array();

		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$resolved[ $post->post_name ] = (int) $post->ID;
			}
		}

		foreach ( $component_keys as $key ) {
			if ( isset( self::$cache[ $key ] ) && self::$cache[ $key ] > 0 ) {
				continue;
			}

			$sanitized = \sanitize_key( $key );

			foreach ( self::SLUG_PREFIXES as $prefix ) {
				$slug = $prefix . $sanitized;

				if ( isset( $resolved[ $slug ] ) ) {
					self::remember_ref( $key, $resolved[ $slug ] );
					break;
				}
			}
		}
	}

	/**
	 * @param string $component_key Component key.
	 * @param int    $ref_id wp_block post ID (0 when missing).
	 */
	private static function remember_ref( string $component_key, int $ref_id ): void {
		self::$cache[ $component_key ] = $ref_id;

		if ( $ref_id > 0 ) {
			self::$reverse_cache[ $ref_id ] = $component_key;
		}
	}
}
