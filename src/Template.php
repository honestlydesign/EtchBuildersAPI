<?php
/**
 * Builder for active-theme block template content.
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
 * Builds and safely persists active-theme wp_template content.
 */
final class Template extends AbstractContentBuilder {

	/**
	 * Target template slug.
	 *
	 * @var string|null
	 */
	private ?string $slug = null;

	/**
	 * Create a new template builder.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Target a template by slug for the active theme.
	 *
	 * @param string $slug Template slug.
	 * @throws InvalidArgumentException When slug is empty after sanitization.
	 */
	public function slug( string $slug ): self {
		$slug = sanitize_title( $slug );

		if ( '' === $slug ) {
			throw new InvalidArgumentException( 'Template builder slug must be non-empty.' );
		}

		$this->slug = $slug;

		return $this;
	}

	/**
	 * Register or update the active-theme template.
	 *
	 * @return int|WP_Error
	 * @throws InvalidArgumentException When slug or content is missing.
	 */
	public function register(): int|RegistrationResult|WP_Error {
		if ( $this->is_dev_only() && ! Environment::mode()->is_dev_mode() ) {
			return 0;
		}

		if ( null === $this->slug ) {
			throw new InvalidArgumentException( 'Template builder requires slug().' );
		}

		try {
			$markup = $this->content_markup();
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'omide_builder_template_script', $exception->getMessage() );
		}

		$target_id = $this->resolve_target_id();
		$registrar = ContentPostRegistrar::new( ContentPostRegistrar::SOURCE_TEMPLATE );

		if ( null === $target_id ) {
			$insert_data   = $this->build_insert_data( 'wp_template', (string) $this->slug, $markup );
			$owned_payload = $this->build_owned_payload( $insert_data );
			$result        = $registrar->insert( $insert_data, $owned_payload );

			if ( is_int( $result ) ) {
				wp_set_post_terms( $result, get_stylesheet(), 'wp_theme' );
			}

			return $this->register_stylesheets_for_result( $result, $this->stylesheet_owner_key() );
		}

		$update_data   = $this->build_update_data( $markup );
		$owned_payload = $this->build_owned_payload( $update_data );

		return $this->register_stylesheets_for_result(
			$registrar->update( $target_id, $update_data, $owned_payload, $this->overwrite_enabled() ),
			$this->stylesheet_owner_key()
		);
	}

	/**
	 * Return the owner key used for template stylesheet fragments.
	 */
	private function stylesheet_owner_key(): string {
		return 'template:slug:' . (string) $this->slug;
	}

	/**
	 * Resolve active-theme template ID.
	 */
	private function resolve_target_id(): ?int {
		$template = get_block_template( get_stylesheet() . '//' . $this->slug, 'wp_template' );

		if ( null === $template || ! isset( $template->wp_id ) || (int) $template->wp_id <= 0 ) {
			return null;
		}

		return (int) $template->wp_id;
	}
}
