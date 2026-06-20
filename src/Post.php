<?php
/**
 * Builder for WordPress post content in custom post types.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Content\AbstractContentBuilder;
use HonestlyDesign\EtchBuilders\Content\ContentPostRegistrar;
use HonestlyDesignEtchBuildersEnvironment;
use RuntimeException;
use WP_Error;
use WP_Post;

/**
 * Builds and safely persists catalog and other non-page post content.
 */
final class Post extends AbstractContentBuilder {

	/**
	 * Target post type.
	 *
	 * @var string|null
	 */
	private ?string $post_type = null;

	/**
	 * Target post slug.
	 *
	 * @var string|null
	 */
	private ?string $slug = null;

	/**
	 * Target post ID.
	 *
	 * @var int|null
	 */
	private ?int $post_id = null;

	/**
	 * Create a new post builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Target a registered post type.
	 *
	 * @param string $post_type Post type slug.
	 * @throws InvalidArgumentException When post type is invalid.
	 */
	public function post_type( string $post_type ): self {
		$post_type = sanitize_key( $post_type );

		if ( '' === $post_type ) {
			throw new InvalidArgumentException( 'Post builder post_type must be non-empty.' );
		}

		if ( 'page' === $post_type ) {
			throw new InvalidArgumentException( 'Post builder cannot target post_type page. Use Page instead.' );
		}

		if ( ! post_type_exists( $post_type ) ) {
			throw new InvalidArgumentException( 'Post builder post_type must be registered.' );
		}

		$this->post_type = $post_type;

		return $this;
	}

	/**
	 * Target a post by slug within the configured post type.
	 *
	 * @param string $slug Post slug.
	 * @throws InvalidArgumentException When slug is empty after sanitization.
	 */
	public function slug( string $slug ): self {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			throw new InvalidArgumentException( 'Post builder slug must be non-empty.' );
		}

		$this->slug = $slug;

		return $this;
	}

	/**
	 * Target a post by ID.
	 *
	 * @param int $post_id Post ID.
	 * @throws InvalidArgumentException When post ID is not positive.
	 */
	public function id( int $post_id ): self {
		if ( 0 >= $post_id ) {
			throw new InvalidArgumentException( 'Post builder id must be positive.' );
		}

		$this->post_id = $post_id;

		return $this;
	}

	/**
	 * Register or update the post.
	 *
	 * @return int|WP_Error
	 */
	public function register(): int|WP_Error {
		if ( $this->is_dev_only() && ! Environment::mode()->is_dev_mode() ) {
			return 0;
		}

		$this->validate_identity();

		try {
			$markup = $this->content_markup();
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'omide_builder_post_script', $exception->getMessage() );
		}

		$target_id = $this->resolve_target_id();

		if ( $target_id instanceof WP_Error ) {
			return $target_id;
		}

		if ( null === $target_id && ! $this->has_title() ) {
			throw new InvalidArgumentException( 'Post builder requires title() on insert.' );
		}

		$registrar = ContentPostRegistrar::new( ContentPostRegistrar::SOURCE_POST );
		$post_type = (string) $this->post_type;

		if ( null === $target_id ) {
			$insert_data   = $this->build_insert_data( $post_type, (string) $this->slug, $markup );
			$owned_payload = $this->build_owned_payload( $insert_data );

			return $this->register_stylesheets_for_result(
				$registrar->insert( $insert_data, $owned_payload ),
				$this->stylesheet_owner_key()
			);
		}

		$update_data   = $this->build_update_data( $markup );
		$owned_payload = $this->build_owned_payload( $update_data );

		return $this->register_stylesheets_for_result(
			$registrar->update( $target_id, $update_data, $owned_payload, $this->overwrite_enabled() ),
			$this->stylesheet_owner_key()
		);
	}

	/**
	 * Return the owner key used for post stylesheet fragments.
	 */
	private function stylesheet_owner_key(): string {
		if ( null !== $this->post_id ) {
			return 'post:id:' . (string) $this->post_id;
		}

		return 'post:' . (string) $this->post_type . ':' . (string) $this->slug;
	}

	/**
	 * Validate identity configuration.
	 *
	 * @throws InvalidArgumentException When identity is missing or conflicting.
	 */
	private function validate_identity(): void {
		if ( null === $this->post_type ) {
			throw new InvalidArgumentException( 'Post builder requires post_type().' );
		}

		if ( null === $this->slug && null === $this->post_id ) {
			throw new InvalidArgumentException( 'Post builder requires slug() or id().' );
		}

		if ( null !== $this->slug && null !== $this->post_id ) {
			throw new InvalidArgumentException( 'Post builder cannot use both slug() and id().' );
		}
	}

	/**
	 * Resolve target post ID.
	 *
	 * @return int|WP_Error|null Null when slug target does not exist.
	 */
	private function resolve_target_id(): int|WP_Error|null {
		if ( null !== $this->post_id ) {
			$post = get_post( $this->post_id );
			if ( ! $post instanceof WP_Post || (string) $this->post_type !== $post->post_type ) {
				return new WP_Error( 'omide_builder_invalid_post', 'Post builder id() must target an existing post of the configured post_type.' );
			}

			return $this->post_id;
		}

		$posts = get_posts(
			array(
				'name'           => (string) $this->slug,
				'post_type'      => (string) $this->post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		$post_id = current( $posts );
		if ( false === $post_id ) {
			return null;
		}

		return (int) $post_id;
	}
}