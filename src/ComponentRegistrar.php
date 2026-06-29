<?php
/**
 * Component registrar for Etch wp_block persistence.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesignEtchBuildersEnvironment;
use RuntimeException;
use WP_Error;

/**
 * Persists component builders to wp_block posts + Etch component meta.
 */
final class ComponentRegistrar {

	private const COMPONENT_POST_TYPE = 'wp_block';

	private const COMPONENT_KEY_META = 'etch_component_html_key';

	private const COMPONENT_PROPERTIES_META = 'etch_component_properties';

	private const COMPONENT_SLUG_PREFIX = 'omide-component-';

	/**
	 * V1 starter has no built-in component catalog. Site components are supplied
	 * explicitly through SiteRegistry.
	 *
	 * @var array<int, class-string>
	 */
	private const BUILTIN_COMPONENTS = array();

	/**
	 * Component class list for this registrar instance.
	 *
	 * @var array<int, class-string>
	 */
	private array $component_classes;

	/**
	 * Constructor.
	 *
	 * @param array<int, class-string>|null $component_classes Optional component class override for tests.
	 */
	private function __construct( ?array $component_classes = null ) {
		$this->component_classes = is_array( $component_classes ) ? $component_classes : self::BUILTIN_COMPONENTS;
	}

	/**
	 * Create a new ComponentRegistrar instance.
	 *
	 * @param array<int, class-string>|null $component_classes Optional component class override for tests.
	 */
	public static function new( ?array $component_classes = null ): self {
		return new self( $component_classes );
	}

	/**
	 * Prepare configured Etch components for runtime usage without persisting posts.
	 *
	 * @return array{
	 *     registered_keys: array<int, string>,
	 *     failed: array<string, string>
	 * }
	 */
	public function prepare_runtime_components(): array {
		$report     = array(
			'registered_keys' => array(),
			'failed'          => array(),
		);
		$components = $this->build_components();

		foreach ( $components as $component ) {
			if ( $component->should_skip_registration() ) {
				continue;
			}

			$styles_registration_error = $this->register_manifest_css_assets( $component );
			if ( $styles_registration_error instanceof WP_Error ) {
				$report['failed'][ $component->get_key() ] = $styles_registration_error->get_error_message();

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && Environment::mode()->is_dev_mode() ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[OhMyIDEtch] Component runtime bootstrap failed: ' . $component->get_key() . ' - ' . $styles_registration_error->get_error_message() );
				}

				continue;
			}

			$stylesheet_registration_error = self::registration_error( $component->register_stylesheets() );
			if ( $stylesheet_registration_error instanceof WP_Error ) {
				$report['failed'][ $component->get_key() ] = $stylesheet_registration_error->get_error_message();

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && Environment::mode()->is_dev_mode() ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[OhMyIDEtch] Component runtime bootstrap failed: ' . $component->get_key() . ' - ' . $stylesheet_registration_error->get_error_message() );
				}

				continue;
			}

			$report['registered_keys'][] = $component->get_key();
		}

		return $report;
	}

	/**
	 * Registers built-in Etch components.
	 *
	 * @return array{
	 *     registered_keys: array<int, string>,
	 *     failed: array<string, string>
	 * }
	 */
	public function register_components(): array {
		$report     = array(
			'registered_keys' => array(),
			'failed'          => array(),
		);
		$components = $this->build_components();

		foreach ( $components as $component ) {
			$result = $this->register( $component );
			if ( $result instanceof WP_Error ) {
				$report['failed'][ $component->get_key() ] = $result->get_error_message();

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && Environment::mode()->is_dev_mode() ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[OhMyIDEtch] Component registration failed: ' . $component->get_key() . ' - ' . $result->get_error_message() );
				}

				continue;
			}

			if ( $result <= 0 ) {
				continue;
			}

			$report['registered_keys'][] = $component->get_key();
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && Environment::mode()->is_dev_mode() ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[OhMyIDEtch] Component registration summary: registered=%d failed=%d',
					count( $report['registered_keys'] ),
					count( $report['failed'] )
				)
			);
		}

		return $report;
	}

	/**
	 * Build component instances for the configured class list.
	 *
	 * @return array<int, Component>
	 */
	private function build_components(): array {
		return array_map(
			static fn( string $component_class ): Component => $component_class::build(),
			$this->component_classes
		);
	}

	/**
	 * Registers or updates a component by its unique Etch key.
	 *
	 * @param Component $component The component builder to persist.
	 * @return int|WP_Error Positive post ID on persistence, 0 when intentionally skipped.
	 */
	public function register( Component $component ): int|WP_Error {
		if ( $component->should_skip_registration() ) {
			return 0;
		}

		$slug        = self::COMPONENT_SLUG_PREFIX . \sanitize_key( $component->get_key() );
		$existing_id = $this->find_post_id_by_slug( $slug );

		try {
			$blocks = Javascript::inject_placeholders( $component->get_blocks() );
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'oh_my_id_etch_component_script', $exception->getMessage() );
		}

		$styles_registration_error = $this->register_manifest_css_assets( $component );
		if ( $styles_registration_error instanceof WP_Error ) {
			return $styles_registration_error;
		}

		$stylesheet_registration_error = self::registration_error( $component->register_stylesheets() );
		if ( $stylesheet_registration_error instanceof WP_Error ) {
			return $stylesheet_registration_error;
		}

		if ( $existing_id > 0 && ! $this->component_requires_update( $existing_id, $component, $blocks, $slug ) ) {
			return $existing_id;
		}

		$post_data = array(
			'post_type'    => self::COMPONENT_POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => \sanitize_text_field( $component->get_name() ),
			'post_name'    => $slug,
			'post_excerpt' => \sanitize_text_field( $component->get_description() ),
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

		\update_post_meta( $post_id, self::COMPONENT_KEY_META, \sanitize_text_field( $component->get_key() ) );
		\update_post_meta( $post_id, self::COMPONENT_PROPERTIES_META, $component->get_properties() );

		$this->cleanup_duplicate_components( $component->get_key(), $slug, (int) $post_id );

		return $this->find_post_id_by_slug( $slug );
	}

	/**
	 * Convert package registration results into the registrar's WordPress error contract.
	 *
	 * @param bool|RegistrationResult|WP_Error|null $result Registration result.
	 */
	private static function registration_error( bool|RegistrationResult|WP_Error|null $result ): ?WP_Error {
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( $result instanceof RegistrationResult && ! $result->is_success() ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message() );
		}

		return null;
	}

	/**
	 * Looks up an existing component post ID by slug.
	 *
	 * @param string $slug Post slug to search.
	 */
	private function find_post_id_by_slug( string $slug ): int {
		$existing = \get_page_by_path( $slug, OBJECT, self::COMPONENT_POST_TYPE );

		if ( ! $existing ) {
			return 0;
		}

		return (int) $existing->ID;
	}

	/**
	 * Looks up duplicate component post IDs by Etch component key.
	 *
	 * Uses a hard limit of 2 because cleanup only needs to detect whether
	 * duplicates exist and remove extras, not fetch every historical record.
	 *
	 * @param string $component_key Component key to search.
	 * @return array<int, int>
	 */
	private function find_duplicate_component_ids( string $component_key ): array {
		$component_ids = \get_posts(
			array(
				'post_type'        => self::COMPONENT_POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'future', 'pending', 'private' ),
				'posts_per_page'   => 2,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					array(
						'key'   => self::COMPONENT_KEY_META,
						'value' => $component_key,
					),
				),
			)
		);

		if ( ! is_array( $component_ids ) || array() === $component_ids ) {
			return array();
		}

		return array_values(
			array_map(
				'intval',
				$component_ids
			)
		);
	}

	/**
	 * Remove duplicate component posts for the same component key.
	 *
	 * @param string $component_key Component key.
	 * @param string $expected_slug Expected slug for the primary post.
	 * @param int    $primary_id Primary post ID to keep.
	 */
	private function cleanup_duplicate_components( string $component_key, string $expected_slug, int $primary_id ): void {
		$component_ids = $this->find_duplicate_component_ids( $component_key );

		foreach ( $component_ids as $component_id ) {
			$component_id = (int) $component_id;
			if ( $component_id === $primary_id ) {
				continue;
			}

			\wp_delete_post( $component_id, true );
		}
	}

	/**
	 * Checks whether a stored component differs from incoming payload.
	 *
	 * @param int       $existing_id Existing wp_block post ID.
	 * @param Component $component Incoming component object.
	 * @param string    $blocks Final block markup with injected script code.
	 * @param string    $slug Expected post slug.
	 */
	private function component_requires_update( int $existing_id, Component $component, string $blocks, string $slug ): bool {
		$existing_post = \get_post( $existing_id );
		if ( null === $existing_post ) {
			return true;
		}

		if ( $slug !== $existing_post->post_name ) {
			return true;
		}

		if ( \sanitize_text_field( $component->get_name() ) !== $existing_post->post_title ) {
			return true;
		}

		if ( \sanitize_text_field( $component->get_description() ) !== $existing_post->post_excerpt ) {
			return true;
		}

		if ( $blocks !== $existing_post->post_content ) {
			return true;
		}

		$stored_key = \get_post_meta( $existing_id, self::COMPONENT_KEY_META, true );
		if ( '' === $stored_key ) {
			return true;
		}

		if ( \sanitize_text_field( $component->get_key() ) !== $stored_key ) {
			return true;
		}

		return $component->get_properties() !== \get_post_meta( $existing_id, self::COMPONENT_PROPERTIES_META, true );
	}

	/**
	 * Manifest CSS assets are intentionally disabled in V1.
	 *
	 * @param Component $component Component builder.
	 * @return WP_Error|null Always null in the local code-owned starter.
	 */
	// @phpstan-ignore-next-line Return type preserves the upstream registrar API.
	private function register_manifest_css_assets( Component $component ): ?WP_Error {
		return null;
	}
}
