<?php
/**
 * In-process WordPress function stubs for persistence adapter tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit\Support;

/**
 * Installs the minimal WordPress API the WordPress persistence store calls.
 *
 * State lives in $GLOBALS (options, posts, meta, filters) so each test can
 * seed and inspect native records. Tests using it run in a separate process.
 */
final class WordPressPersistenceStubs {

	public static function install(): void {
		eval(
			<<<'PHP'
	namespace {
		if ( ! class_exists( 'WP_Error', false ) ) {
			final class WP_Error {
				public function __construct( private string $code, private string $message ) {}

				public function get_error_code(): string {
					return $this->code;
				}

				public function get_error_message(): string {
					return $this->message;
				}
			}
		}

		if ( ! defined( 'OBJECT' ) ) {
			define( 'OBJECT', 'OBJECT' );
		}

		if ( ! function_exists( 'is_wp_error' ) ) {
			function is_wp_error( mixed $value ): bool {
				return $value instanceof WP_Error;
			}
		}

		if ( ! function_exists( 'sanitize_key' ) ) {
			function sanitize_key( string $key ): string {
				return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) ) ?? '';
			}
		}

		if ( ! function_exists( 'sanitize_text_field' ) ) {
			function sanitize_text_field( mixed $value ): string {
				return trim( strip_tags( (string) $value ) );
			}
		}

		if ( ! function_exists( 'wp_slash' ) ) {
			function wp_slash( mixed $value ): mixed {
				return $value;
			}
		}

		if ( ! function_exists( 'sanitize_title' ) ) {
			function sanitize_title( string $title ): string {
				$title = strtolower( trim( $title ) );

				return trim( preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '', '-' );
			}
		}

		if ( ! function_exists( 'get_option' ) ) {
			function get_option( string $option, mixed $default = false ): mixed {
				$options = $GLOBALS['etch_builders_site_persistence_options'] ?? array();

				return array_key_exists( $option, $options ) ? $options[ $option ] : $default;
			}
		}

		if ( ! function_exists( 'etch_builders_site_persistence_record_write' ) ) {
			function etch_builders_site_persistence_record_write( string $operation ): void {
				if ( ! isset( $GLOBALS['etch_builders_site_persistence_write_calls'] ) || ! is_array( $GLOBALS['etch_builders_site_persistence_write_calls'] ) ) {
					$GLOBALS['etch_builders_site_persistence_write_calls'] = array();
				}

				$GLOBALS['etch_builders_site_persistence_write_calls'][ $operation ] = ( $GLOBALS['etch_builders_site_persistence_write_calls'][ $operation ] ?? 0 ) + 1;
			}
		}

		if ( ! function_exists( 'add_option' ) ) {
			function add_option( string $option, mixed $value = '', string $deprecated = '', bool $autoload = true ): bool {
				etch_builders_site_persistence_record_write( 'add_option' );
				// Lets a test run another request's work just before this option is claimed.
				$before = $GLOBALS['etch_builders_site_persistence_before_add_option'][ $option ] ?? null;
				if ( null !== $before ) {
					unset( $GLOBALS['etch_builders_site_persistence_before_add_option'][ $option ] );
					$before();
				}
				$options = $GLOBALS['etch_builders_site_persistence_options'] ?? array();
				if ( array_key_exists( $option, $options ) ) {
					return false;
				}

				$GLOBALS['etch_builders_site_persistence_options'][ $option ] = $value;

				return true;
			}
		}

		if ( ! function_exists( 'update_option' ) ) {
			function update_option( string $option, mixed $value, bool $autoload = true ): bool {
				etch_builders_site_persistence_record_write( 'update_option' );
				if ( ( $GLOBALS['etch_builders_site_persistence_failing_option'] ?? null ) === $option ) {
					return false;
				}
				// Like WordPress, report false when the stored value is already identical.
				if ( array_key_exists( $option, $GLOBALS['etch_builders_site_persistence_options'] ?? array() ) && $GLOBALS['etch_builders_site_persistence_options'][ $option ] === $value ) {
					return false;
				}

				$GLOBALS['etch_builders_site_persistence_options'][ $option ] = $value;

				return true;
			}
		}

		if ( ! function_exists( 'delete_option' ) ) {
			function delete_option( string $option ): bool {
				etch_builders_site_persistence_record_write( 'delete_option' );
				$exists = array_key_exists( $option, $GLOBALS['etch_builders_site_persistence_options'] ?? array() );
				unset( $GLOBALS['etch_builders_site_persistence_options'][ $option ] );

				return $exists;
			}
		}

		if ( ! function_exists( 'get_page_by_path' ) ) {
			function get_page_by_path( string $slug, mixed $output = OBJECT, string $post_type = 'page' ): ?object {
				foreach ( $GLOBALS['etch_builders_site_persistence_posts'] ?? array() as $post ) {
					if ( $post->post_name === $slug && $post->post_type === $post_type ) {
						return $post;
					}
				}

				return null;
			}
		}

		if ( ! function_exists( 'get_post' ) ) {
			function get_post( int $post_id ): ?object {
				$post = $GLOBALS['etch_builders_site_persistence_posts'][ $post_id ] ?? null;

				return is_object( $post ) ? $post : null;
			}
		}

		if ( ! function_exists( 'post_type_exists' ) ) {
			function post_type_exists( string $post_type ): bool {
				return in_array( $post_type, array( 'post', 'page', 'wp_template', 'book' ), true );
			}
		}

		if ( ! function_exists( 'get_stylesheet' ) ) {
			function get_stylesheet(): string {
				return 'test-theme';
			}
		}

		if ( ! function_exists( 'get_posts' ) ) {
			function get_posts( array $args ): array {
				$posts      = $GLOBALS['etch_builders_site_persistence_posts'] ?? array();
				$post_type  = $args['post_type'] ?? null;
				$post_types = null === $post_type || 'any' === $post_type ? null : (array) $post_type;
				$statuses   = isset( $args['post_status'] ) && 'any' !== $args['post_status'] ? (array) $args['post_status'] : null;
				$name       = $args['name'] ?? null;
				$meta_key   = $args['meta_key'] ?? null;
				$meta_value = $args['meta_value'] ?? null;
				$parent     = $args['post_parent'] ?? null;
				$matches    = array();

				foreach ( $posts as $post_id => $post ) {
					if ( null !== $post_types && ! in_array( $post->post_type ?? null, $post_types, true ) ) {
						continue;
					}
					if ( null !== $statuses && ! in_array( $post->post_status ?? 'publish', $statuses, true ) ) {
						continue;
					}
					if ( null !== $name && ( $post->post_name ?? null ) !== $name ) {
						continue;
					}
					if ( null !== $parent && (int) ( $post->post_parent ?? 0 ) !== (int) $parent ) {
						continue;
					}
					if ( null !== $meta_key && null === $meta_value && ! isset( $GLOBALS['etch_builders_site_persistence_meta'][ $post_id ][ $meta_key ] ) ) {
						continue;
					}
					if ( null !== $meta_key && null !== $meta_value && ( $GLOBALS['etch_builders_site_persistence_meta'][ $post_id ][ $meta_key ] ?? null ) !== $meta_value ) {
						continue;
					}

					$matches[] = $post;
				}

				return $matches;
			}
		}

		if ( ! function_exists( 'get_post_types' ) ) {
			function get_post_types( array $args = array(), string $output = 'names' ): array {
				return array( 'post' => 'post', 'page' => 'page', 'wp_template' => 'wp_template', 'wp_block' => 'wp_block', 'book' => 'book' );
			}
		}

		if ( ! function_exists( 'post_type_supports' ) ) {
			function post_type_supports( string $post_type, string $feature ): bool {
				return 'revisions' !== $feature || ! in_array( $post_type, $GLOBALS['etch_builders_site_persistence_no_revisions'] ?? array(), true );
			}
		}

		if ( ! function_exists( 'wp_save_post_revision' ) ) {
			function wp_save_post_revision( int $post_id ): ?int {
				etch_builders_site_persistence_record_write( 'wp_save_post_revision' );
				$post = $GLOBALS['etch_builders_site_persistence_posts'][ $post_id ] ?? null;
				if ( null === $post ) {
					return null;
				}
				$GLOBALS['etch_builders_site_persistence_revisions'][ $post_id ][] = array( 'post_content' => (string) $post->post_content );

				return count( $GLOBALS['etch_builders_site_persistence_revisions'][ $post_id ] );
			}
		}

		if ( ! function_exists( 'has_filter' ) ) {
			function has_filter( string $hook_name, mixed $callback = false ): bool {
				return isset( $GLOBALS['etch_builders_test_filters'][ $hook_name ] );
			}
		}

		if ( ! function_exists( 'apply_filters' ) ) {
			function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
				$callback = $GLOBALS['etch_builders_test_filters'][ $hook_name ] ?? null;

				return is_callable( $callback ) ? $callback( $value, ...$args ) : $value;
			}
		}

		if ( ! function_exists( 'delete_post_meta' ) ) {
			function delete_post_meta( int $post_id, string $key ): bool {
				etch_builders_site_persistence_record_write( 'delete_post_meta' );
				$exists = isset( $GLOBALS['etch_builders_site_persistence_meta'][ $post_id ][ $key ] );
				unset( $GLOBALS['etch_builders_site_persistence_meta'][ $post_id ][ $key ] );

				return $exists;
			}
		}

		if ( ! function_exists( 'wp_insert_post' ) ) {
			function wp_insert_post( array $post_data, bool $wp_error = false ): int {
				etch_builders_site_persistence_record_write( 'wp_insert_post' );
				if ( $GLOBALS['etch_builders_site_persistence_throw_insert'] ?? false ) {
					throw new RuntimeException( 'insert failed' );
				}

				$id              = count( $GLOBALS['etch_builders_site_persistence_posts'] ?? array() ) + 1;
				$post_data['ID'] = $id;
				$GLOBALS['etch_builders_site_persistence_posts'][ $id ] = (object) $post_data;

				return $id;
			}
		}

		if ( ! function_exists( 'wp_update_post' ) ) {
			function wp_update_post( array $post_data, bool $wp_error = false ): int {
				etch_builders_site_persistence_record_write( 'wp_update_post' );
				$id = (int) ( $post_data['ID'] ?? 0 );
				if ( $id <= 0 || ! isset( $GLOBALS['etch_builders_site_persistence_posts'][ $id ] ) ) {
					return 0;
				}

				$GLOBALS['etch_builders_site_persistence_posts'][ $id ] = (object) array_merge(
					(array) $GLOBALS['etch_builders_site_persistence_posts'][ $id ],
					$post_data
				);

				return $id;
			}
		}

		if ( ! function_exists( 'wp_delete_post' ) ) {
			function wp_delete_post( int $post_id, bool $force_delete = false ): ?object {
				etch_builders_site_persistence_record_write( 'wp_delete_post' );
				if ( $GLOBALS['etch_builders_site_persistence_fail_delete'] ?? false ) {
					return null;
				}

				$post = $GLOBALS['etch_builders_site_persistence_posts'][ $post_id ] ?? null;
				unset( $GLOBALS['etch_builders_site_persistence_posts'][ $post_id ], $GLOBALS['etch_builders_site_persistence_meta'][ $post_id ] );

				return is_object( $post ) ? $post : null;
			}
		}

		if ( ! function_exists( 'update_post_meta' ) ) {
			function update_post_meta( int $post_id, string $key, mixed $value ): bool {
				etch_builders_site_persistence_record_write( 'update_post_meta' );
				if ( ( $GLOBALS['etch_builders_site_persistence_fail_meta'] ?? null ) === $key ) {
					return false;
				}

				// Like WordPress, report false when the stored value is already identical.
				if ( array_key_exists( $key, $GLOBALS['etch_builders_site_persistence_meta'][ $post_id ] ?? array() ) && $GLOBALS['etch_builders_site_persistence_meta'][ $post_id ][ $key ] === $value ) {
					return false;
				}

				$GLOBALS['etch_builders_site_persistence_meta'][ $post_id ][ $key ] = $value;

				return true;
			}
		}

		if ( ! function_exists( 'get_post_meta' ) ) {
			function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
				return $GLOBALS['etch_builders_site_persistence_meta'][ $post_id ][ $key ] ?? '';
			}
		}

		if ( ! function_exists( 'taxonomy_exists' ) ) {
			function taxonomy_exists( string $taxonomy ): bool {
				return (bool) ( $GLOBALS['etch_builders_site_persistence_taxonomy_available'] ?? false );
			}
		}

		if ( ! function_exists( 'wp_set_object_terms' ) ) {
			function wp_set_object_terms( int $post_id, array $terms, string $taxonomy ): array|WP_Error {
				etch_builders_site_persistence_record_write( 'wp_set_object_terms' );
				if ( $GLOBALS['etch_builders_site_persistence_fail_terms'] ?? false ) {
					return new WP_Error( 'term_failed', 'term synchronization failed' );
				}

				$GLOBALS['etch_builders_site_persistence_term_inputs'] = $terms;
				$GLOBALS['etch_builders_site_persistence_terms'][ $post_id ] = array_map(
					static fn ( string $term ): object => (object) array( 'slug' => sanitize_title( $term ) ),
					$terms
				);

				return array( 1 );
			}
		}

		if ( ! function_exists( 'get_the_terms' ) ) {
			function get_the_terms( int $post_id, string $taxonomy ): array|WP_Error {
				return $GLOBALS['etch_builders_site_persistence_terms'][ $post_id ] ?? array();
			}
		}
	}
PHP
		);
	}
}
