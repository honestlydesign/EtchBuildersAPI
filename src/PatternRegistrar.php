<?php
/**
 * Pattern registrar for Etch wp_block persistence.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesignEtchBuildersEnvironment;
use RuntimeException;
use WP_Error;

/**
 * Persists pattern builders to wp_block posts + Etch pattern meta.
 */
final class PatternRegistrar {

	private const PATTERN_POST_TYPE = 'wp_block';

	private const PATTERN_KEY_META = 'oh_my_id_etch_pattern_key';

	private const PATTERN_SYNC_META = 'wp_pattern_sync_status';

	private const UNSYNCED_STATUS = 'unsynced';

	private const PATTERN_SLUG_PREFIX = 'omide-pattern-';

	private const PATTERN_CATEGORY_TAXONOMY = 'wp_pattern_category';

	/**
	 * Pattern definitions for this registrar instance.
	 *
	 * @var array<int, array{
	 *     class: class-string,
	 *     key: string,
	 *     required_components: array<int, string>
	 * }>
	 */
	private array $pattern_definitions;

	/**
	 * Constructor.
	 *
	 * @phpstan-param array<int, array{
	 *     class: class-string,
	 *     key: string,
	 *     required_components: array<int, string>
	 * }>|null $pattern_definitions
	 * @param array<int, array<string, mixed>>|null $pattern_definitions Optional pattern definition override for tests.
	 */
	private function __construct( ?array $pattern_definitions = null ) {
		$this->pattern_definitions = $pattern_definitions ?? array();
	}

	/**
	 * Create a new PatternRegistrar instance.
	 *
	 * @phpstan-param array<int, array{
	 *     class: class-string,
	 *     key: string,
	 *     required_components: array<int, string>
	 * }>|null $pattern_definitions
	 * @param array<int, array<string, mixed>>|null $pattern_definitions Optional pattern definition override for tests.
	 */
	public static function new( ?array $pattern_definitions = null ): self {
		return new self( $pattern_definitions );
	}

	/**
	 * Registers configured Etch patterns.
	 *
	 * @param array<int, string> $available_component_keys Registered component keys available to patterns.
	 * @return array{
	 *     registered_keys: array<int, string>,
	 *     skipped: array<string, string>,
	 *     failed: array<string, string>
	 * }
	 */
	public function register_patterns( array $available_component_keys ): array {
		$report = array(
			'registered_keys' => array(),
			'skipped'         => array(),
			'failed'          => array(),
		);

		if ( ! Environment::mode()->is_dev_mode() ) {
			foreach ( $this->pattern_definitions as $pattern_definition ) {
				$report['skipped'][ $pattern_definition['key'] ] = 'Pattern registration is only enabled in DEV mode.';
			}

			return $report;
		}

		$available_component_lookup = array_fill_keys( $available_component_keys, true );
		if ( ! is_array( $available_component_lookup ) ) {
			$available_component_lookup = array();
		}

		foreach ( $this->pattern_definitions as $pattern_definition ) {
			$pattern_key         = $pattern_definition['key'];
			$required_components = $pattern_definition['required_components'];
			$missing_components  = $this->find_missing_components(
				$required_components,
				$available_component_lookup
			);

			if ( array() !== $missing_components ) {
				$message = 'Missing required components: ' . implode( ', ', $missing_components );

				$report['skipped'][ $pattern_key ] = $message;

				if ( Environment::mode()->is_dev_mode() ) { // @phpstan-ignore if.alwaysTrue
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[OhMyIDEtch] Pattern registration skipped: ' . $pattern_key . ' - ' . $message );
				}

				continue;
			}

				$pattern_class = $pattern_definition['class'];
				$pattern       = $pattern_class::build();

				$result = $this->register( $pattern );

			if ( $result instanceof WP_Error ) {
				$report['failed'][ $pattern->get_key() ] = $result->get_error_message();

				if ( Environment::mode()->is_dev_mode() ) { // @phpstan-ignore if.alwaysTrue
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[OhMyIDEtch] Pattern registration failed: ' . $pattern->get_key() . ' - ' . $result->get_error_message() );
				}
				continue;
			}

			$report['registered_keys'][] = $pattern->get_key();
		}

		if ( Environment::mode()->is_dev_mode() ) { // @phpstan-ignore if.alwaysTrue
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[OhMyIDEtch] Pattern registration summary: registered=%d skipped=%d failed=%d',
					count( $report['registered_keys'] ),
					count( $report['skipped'] ),
					count( $report['failed'] )
				)
			);
		}

		return $report;
	}

	/**
	 * Find missing required components.
	 *
	 * @param array<int, string>        $required_components Required component keys.
	 * @param array<string, bool|mixed> $available_component_lookup Map of available component keys.
	 * @return array<int, string>
	 */
	private function find_missing_components( array $required_components, array $available_component_lookup ): array {
		$missing = array();

		foreach ( $required_components as $required_component ) {
			if ( ! isset( $available_component_lookup[ $required_component ] ) ) {
				$missing[] = $required_component;
			}
		}

		return $missing;
	}

	/**
	 * Registers or updates a pattern by its unique internal key.
	 *
	 * @param Pattern $pattern The pattern builder to persist.
	 * @return int|WP_Error
	 */
	public function register( Pattern $pattern ): int|WP_Error {
		$key  = $pattern->get_key();
		$slug = self::PATTERN_SLUG_PREFIX . \sanitize_key( $key );

		$existing_id = $this->find_post_id_by_slug( $slug );

		try {
			$blocks = Javascript::inject_placeholders( $pattern->get_blocks() );
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'oh_my_id_etch_pattern_script', $exception->getMessage() );
		}

		$stylesheet_registration_error = $pattern->register_stylesheets();
		if ( $stylesheet_registration_error instanceof WP_Error ) {
			return $stylesheet_registration_error;
		}

		if ( $existing_id > 0 && ! $this->pattern_requires_update( $existing_id, $pattern, $blocks, $slug ) ) {
			return $existing_id;
		}

		$post_data = array(
			'post_type'    => self::PATTERN_POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => \sanitize_text_field( $pattern->get_name() ),
			'post_name'    => $slug,
			'post_excerpt' => \sanitize_text_field( $pattern->get_description() ),
			'post_content' => \wp_slash( $blocks ),
		);

		if ( $existing_id > 0 ) {
			$post_data['ID'] = $existing_id;
			$post_id         = \wp_update_post( $post_data, true );
		} else {
			$post_id = \wp_insert_post( $post_data, true );
		}

		if ( $post_id instanceof WP_Error ) {
			return $post_id;
		}

		\update_post_meta( $post_id, self::PATTERN_KEY_META, \sanitize_text_field( $pattern->get_key() ) );

		// Patterns are always non-synced (unsynced).
		\update_post_meta( $post_id, self::PATTERN_SYNC_META, self::UNSYNCED_STATUS );

		$this->sync_pattern_categories( (int) $post_id, $pattern->get_categories() );

		$this->cleanup_duplicate_patterns( $pattern->get_key(), $slug, (int) $post_id );

		return $this->find_post_id_by_slug( $slug );
	}

	/**
	 * Checks whether a stored pattern differs from incoming payload.
	 *
	 * @param int     $existing_id Existing wp_block post ID.
	 * @param Pattern $pattern Incoming pattern object.
	 * @param string  $blocks Final block markup with injected script code.
	 * @param string  $slug Expected post slug.
	 */
	private function pattern_requires_update( int $existing_id, Pattern $pattern, string $blocks, string $slug ): bool {
		$existing_post = \get_post( $existing_id );
		if ( null === $existing_post ) {
			return true;
		}

		if ( $slug !== $existing_post->post_name ) {
			return true;
		}

		if ( \sanitize_text_field( $pattern->get_name() ) !== $existing_post->post_title ) {
			return true;
		}

		if ( \sanitize_text_field( $pattern->get_description() ) !== $existing_post->post_excerpt ) {
			return true;
		}

		if ( $blocks !== $existing_post->post_content ) {
			return true;
		}

		$stored_key = \get_post_meta( $existing_id, self::PATTERN_KEY_META, true );
		if ( '' === $stored_key ) {
			return true;
		}

		if ( \sanitize_text_field( $pattern->get_key() ) !== $stored_key ) {
			return true;
		}

		// Patterns are always non-synced - check stored status.
		$existing_unsynced = \get_post_meta( $existing_id, self::PATTERN_SYNC_META, true ) === self::UNSYNCED_STATUS;
		if ( ! $existing_unsynced ) {
			return true;
		}

		return $this->categories_require_update( $existing_id, $pattern->get_categories() );
	}

	/**
	 * Looks up an existing pattern post ID by slug.
	 *
	 * @param string $slug Post slug to search.
	 */
	private function find_post_id_by_slug( string $slug ): int {
		$existing = \get_page_by_path( $slug, OBJECT, self::PATTERN_POST_TYPE );

		if ( ! $existing ) {
			return 0;
		}

		return (int) $existing->ID;
	}

	/**
	 * Looks up all pattern post IDs by internal pattern key (for cleanup).
	 *
	 * @param string $pattern_key Pattern key to search.
	 * @return array<int, int>
	 */
	private function find_existing_pattern_ids( string $pattern_key ): array {
		$pattern_ids = \get_posts(
			array(
				'post_type'        => self::PATTERN_POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'future', 'pending', 'private' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					array(
						'key'   => self::PATTERN_KEY_META,
						'value' => $pattern_key,
					),
				),
			)
		);

		if ( ! is_array( $pattern_ids ) || array() === $pattern_ids ) {
			return array();
		}

		return array_values(
			array_map(
				'intval',
				$pattern_ids
			)
		);
	}

	/**
	 * Remove duplicate pattern posts for the same pattern key.
	 *
	 * @param string $pattern_key Pattern key.
	 * @param string $expected_slug Expected slug for the primary post.
	 * @param int    $primary_id Primary post ID to keep.
	 */
	private function cleanup_duplicate_patterns( string $pattern_key, string $expected_slug, int $primary_id ): void {
		$pattern_ids = $this->find_existing_pattern_ids( $pattern_key );

		foreach ( $pattern_ids as $pattern_id ) {
			$pattern_id = (int) $pattern_id;
			if ( $pattern_id === $primary_id ) {
				continue;
			}

			\wp_delete_post( $pattern_id, true );
		}
	}

	/**
	 * Determine whether taxonomy terms need synchronization.
	 *
	 * @param int                $post_id Pattern post ID.
	 * @param array<int, string> $incoming_categories Incoming category names/slugs.
	 */
	private function categories_require_update( int $post_id, array $incoming_categories ): bool {
		if ( ! \taxonomy_exists( self::PATTERN_CATEGORY_TAXONOMY ) ) {
			return false;
		}

		$existing_categories  = $this->get_pattern_category_slugs( $post_id );
		$incoming_identifiers = $this->normalize_category_identifiers( $incoming_categories );

		sort( $existing_categories );
		sort( $incoming_identifiers );

		return $existing_categories !== $incoming_identifiers;
	}

	/**
	 * Synchronize pattern taxonomy terms.
	 *
	 * @param int                $post_id Pattern post ID.
	 * @param array<int, string> $categories Category names/slugs.
	 */
	private function sync_pattern_categories( int $post_id, array $categories ): void {
		if ( ! \taxonomy_exists( self::PATTERN_CATEGORY_TAXONOMY ) ) {
			return;
		}

		$result = \wp_set_object_terms( $post_id, $categories, self::PATTERN_CATEGORY_TAXONOMY );
		if ( $result instanceof WP_Error && Environment::mode()->is_dev_mode() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Oh My IDE Etch pattern category sync failed: ' . $result->get_error_message() );
		}
	}

	/**
	 * Get stored pattern category slugs.
	 *
	 * @param int $post_id Pattern post ID.
	 * @return array<int, string>
	 */
	private function get_pattern_category_slugs( int $post_id ): array {
		$terms = \get_the_terms( $post_id, self::PATTERN_CATEGORY_TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$slugs = array();
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! property_exists( $term, 'slug' ) || ! is_string( $term->slug ) ) {
				continue;
			}

			$slugs[] = $term->slug;
		}

		return $this->normalize_category_identifiers( $slugs );
	}

	/**
	 * Normalize category names/slugs into comparable identifiers.
	 *
	 * @param array<int, string> $categories Category names/slugs.
	 * @return array<int, string>
	 */
	private function normalize_category_identifiers( array $categories ): array {
		$identifiers = array();

		foreach ( $categories as $category ) {
			$identifier = \sanitize_title( trim( $category ) );
			if ( '' === $identifier ) {
				continue;
			}

			$identifiers[ $identifier ] = true;
		}

		return array_keys( $identifiers );
	}
}
