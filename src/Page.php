<?php
/**
 * Builder for WordPress page content.
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

/**
 * Builds and safely persists WordPress page content.
 */
final class Page extends AbstractContentBuilder {

	/**
	 * Target page slug.
	 *
	 * @var string|null
	 */
	private ?string $slug = null;

	/**
	 * Target page post ID.
	 *
	 * @var int|null
	 */
	private ?int $post_id = null;

	/**
	 * Create a new page builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Target a page by slug.
	 *
	 * @param string $slug Page slug.
	 * @throws InvalidArgumentException When slug is empty after sanitization.
	 */
	public function slug( string $slug ): self {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			throw new InvalidArgumentException( 'Page builder slug must be non-empty.' );
		}

		$this->slug = $slug;

		return $this;
	}

	/**
	 * Target a page by post ID.
	 *
	 * @param int $post_id Page post ID.
	 * @throws InvalidArgumentException When post ID is not positive.
	 */
	public function id( int $post_id ): self {
		if ( 0 >= $post_id ) {
			throw new InvalidArgumentException( 'Page builder id must be positive.' );
		}

		$this->post_id = $post_id;

		return $this;
	}

	/**
	 * Register or update the page.
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
			return new WP_Error( 'omide_builder_page_script', $exception->getMessage() );
		}

		$target_id = $this->resolve_target_id();

		if ( $target_id instanceof WP_Error ) {
			return $target_id;
		}

		$registrar = ContentPostRegistrar::new( ContentPostRegistrar::SOURCE_PAGE );

		if ( null === $target_id ) {
			$insert_data   = $this->build_insert_data( 'page', (string) $this->slug, $markup );
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
	 * Return the owner key used for page stylesheet fragments.
	 */
	private function stylesheet_owner_key(): string {
		if ( null !== $this->post_id ) {
			return 'page:id:' . (string) $this->post_id;
		}

		return 'page:slug:' . (string) $this->slug;
	}

	/**
	 * Validate identity configuration.
	 *
	 * @throws InvalidArgumentException When identity is missing or conflicting.
	 */
	private function validate_identity(): void {
		if ( null === $this->slug && null === $this->post_id ) {
			throw new InvalidArgumentException( 'Page builder requires slug() or id().' );
		}

		if ( null !== $this->slug && null !== $this->post_id ) {
			throw new InvalidArgumentException( 'Page builder cannot use both slug() and id().' );
		}
	}

	/**
	 * Resolve target page ID.
	 *
	 * @return int|WP_Error|null Null when slug target does not exist.
	 */
	private function resolve_target_id(): int|WP_Error|null {
		if ( null !== $this->post_id ) {
			$post = get_post( $this->post_id );
			if ( null === $post || 'page' !== $post->post_type ) {
				return new WP_Error( 'omide_builder_invalid_page', 'Page builder id() must target an existing page.' );
			}

			return $this->post_id;
		}

		$existing = get_page_by_path( (string) $this->slug, OBJECT, 'page' );

		return null === $existing ? null : (int) $existing->ID;
	}
}
