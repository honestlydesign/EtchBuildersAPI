<?php
/**
 * Shared content builder mechanics for Page, Post, and Template builders.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\Content;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\EtchBlocks\Contracts\EtchBlockBuilderInterface;
use HonestlyDesign\EtchBuilders\Javascript;
use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Stylesheet;
use HonestlyDesign\EtchBuilders\StylesheetReference;
use RuntimeException;


/**
 * Provides common content, ownership, title, and status behavior.
 */
abstract class AbstractContentBuilder {

	/**
	 * Allowed post statuses.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_STATUSES = array( 'publish', 'draft', 'private', 'pending', 'future' );

	/**
	 * Builder-owned title.
	 *
	 * @var string|null
	 */
	private ?string $title = null;

	/**
	 * Builder-owned status.
	 *
	 * @var string|null
	 */
	private ?string $status = null;

	/**
	 * Builder-owned excerpt.
	 *
	 * @var string|null
	 */
	private ?string $excerpt = null;

	/**
	 * Whether excerpt() was called explicitly.
	 *
	 * @var bool
	 */
	private bool $excerpt_provided = false;

	/**
	 * Whether existing or edited content may be overwritten.
	 *
	 * @var bool
	 */
	private bool $overwrite = false;

	/**
	 * Whether this content should only register in dev mode.
	 *
	 * @var bool
	 */
	private bool $dev_only = false;

	/**
	 * Content buffer.
	 *
	 * @var ContentBuffer
	 */
	private ContentBuffer $content;

	/**
	 * Global stylesheet references declared by this content builder.
	 *
	 * @var array<int, StylesheetReference>
	 */
	private array $stylesheet_references = array();

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->content = ContentBuffer::new();
	}

	/**
	 * Set builder-owned title.
	 *
	 * @param string $title Post title.
	 * @throws InvalidArgumentException When title is empty.
	 */
	public function title( string $title ): static {
		$title = trim( $title );

		if ( '' === $title ) {
			throw new InvalidArgumentException( 'Content builder title must be non-empty.' );
		}

		$this->title = $title;

		return $this;
	}

	/**
	 * Set builder-owned status.
	 *
	 * @param string $status Post status.
	 * @throws InvalidArgumentException When status is not allowed.
	 */
	public function status( string $status ): static {
		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			throw new InvalidArgumentException( 'Content builder status must be publish, draft, private, pending, or future.' );
		}

		$this->status = $status;

		return $this;
	}

	/**
	 * Set builder-owned excerpt.
	 *
	 * @param string $excerpt Post excerpt. Empty string clears excerpt on overwrite.
	 */
	public function excerpt( string $excerpt ): static {
		$this->excerpt           = trim( $excerpt );
		$this->excerpt_provided = true;

		return $this;
	}

	/**
	 * Append a structured content block.
	 *
	 * @param Block|EtchBlockBuilderInterface $block Block or block builder.
	 */
	public function block( Block|EtchBlockBuilderInterface $block ): static {
		$this->content->block( $block );

		return $this;
	}

	/**
	 * Set serialized block markup.
	 *
	 * @param string $markup Serialized markup.
	 */
	public function blocks_markup( string $markup ): static {
		$this->content->blocks_markup( $markup );

		return $this;
	}

	/**
	 * Attach a CSS file to an Etch global stylesheet.
	 *
	 * Multiple builders can target the same stylesheet ID; their CSS is stacked
	 * into the same Etch global stylesheet.
	 *
	 * @param string $id Stylesheet ID and display name.
	 * @param string $file_path CSS file path.
	 */
	public function stylesheet( string $id, string $file_path ): static {
		$this->stylesheet_references[] = StylesheetReference::new( $id, $file_path );

		return $this;
	}

	/**
	 * Add a parsed Etch style owned by this code builder.
	 *
	 * @param Style $style Style builder instance.
	 * @return string Registered style id.
	 */
	public function add_style( Style $style ): string {
		return $style->overwrite_on_register( true )->add();
	}

	/**
	 * Allow replacing existing or edited content.
	 *
	 * @param bool $overwrite Whether to overwrite.
	 */
	public function overwrite( bool $overwrite = true ): static {
		$this->overwrite = $overwrite;

		return $this;
	}

	/**
	 * Mark this content as dev-only.
	 *
	 * Dev-only pages and templates are silently skipped during registration
	 * when not running in development mode.
	 *
	 * @param bool $dev_only Whether this content is dev-only.
	 */
	public function dev_only( bool $dev_only = true ): static {
		$this->dev_only = $dev_only;

		return $this;
	}

	/**
	 * Whether this content is dev-only.
	 */
	public function is_dev_only(): bool {
		return $this->dev_only;
	}

	/**
	 * Whether title() was set on this builder.
	 */
	protected function has_title(): bool {
		return null !== $this->title;
	}

	/**
	 * Render buffered content as serialized markup.
	 *
	 * @throws InvalidArgumentException When content is empty.
	 * @throws RuntimeException When JavaScript placeholders cannot be resolved.
	 */
	protected function content_markup(): string {
		return Javascript::inject_placeholders( $this->content->to_markup() );
	}

	/**
	 * Whether overwrite was requested.
	 */
	protected function overwrite_enabled(): bool {
		return $this->overwrite;
	}

	/**
	 * Register global stylesheet references declared by this content builder.
	 *
	 * @param string $owner_key Builder owner key.
	 */
	protected function register_stylesheets( string $owner_key ): bool|RegistrationResult {
		return Stylesheet::register_references( $owner_key, $this->stylesheet_references );
	}

	/**
	 * Preserve a successful content result unless stylesheet registration fails.
	 *
	 * @param int|RegistrationResult $result Content registration result.
	 * @param string       $owner_key Builder owner key.
	 * @return int|RegistrationResult
	 */
	/**
	 * Register stylesheets and return the combined result.
	 *
	 * Accepts int (post ID), RegistrationResult, or WP_Error. WP_Error is
	 * converted to a RegistrationResult::error() before returning.
	 *
	 * @param int|RegistrationResult|\WP_Error $result   The insertion/update result.
	 * @param string                           $owner_key Stylesheet owner key.
	 * @return int|RegistrationResult
	 */
	protected function register_stylesheets_for_result( int|RegistrationResult|\WP_Error $result, string $owner_key ): int|RegistrationResult {
		if ( $result instanceof \WP_Error ) {
			return RegistrationResult::error( $result->get_error_code(), $result->get_error_message() );
		}

		if ( $result instanceof RegistrationResult ) {
			return $result;
		}

		$stylesheets_result = $this->register_stylesheets( $owner_key );
		if ( $stylesheets_result instanceof RegistrationResult ) {
			return $stylesheets_result;
		}

		return $result;
	}

	/**
	 * Build insert post data.
	 *
	 * @param string $post_type Post type.
	 * @param string $slug Post slug.
	 * @param string $markup Serialized content.
	 * @return array<string, mixed>
	 */
	protected function build_insert_data( string $post_type, string $slug, string $markup ): array {
		$post_data = array(
			'post_type'    => $post_type,
			'post_name'    => $slug,
			'post_title'   => $this->title ?? $this->humanize_slug( $slug ),
			'post_status'  => $this->status ?? 'publish',
			'post_content' => $markup,
		);

		if ( $this->excerpt_provided ) {
			$post_data['post_excerpt'] = $this->excerpt ?? '';
		}

		return $post_data;
	}

	/**
	 * Build update post data for explicitly owned fields.
	 *
	 * @param string $markup Serialized content.
	 * @return array<string, mixed>
	 */
	protected function build_update_data( string $markup ): array {
		$post_data = array(
			'post_content' => $markup,
		);

		if ( null !== $this->title ) {
			$post_data['post_title'] = $this->title;
		}

		if ( null !== $this->status ) {
			$post_data['post_status'] = $this->status;
		}

		if ( $this->excerpt_provided ) {
			$post_data['post_excerpt'] = $this->excerpt ?? '';
		}

		return $post_data;
	}

	/**
	 * Build owned payload from post data.
	 *
	 * @param array<string, mixed> $post_data Post data.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException When post content is missing.
	 */
	protected function build_owned_payload( array $post_data ): array {
		$post_content = $post_data['post_content'] ?? null;
		if ( ! is_string( $post_content ) ) {
			throw new InvalidArgumentException( 'Content builder owned payload requires post_content.' );
		}

		$payload = array(
			'post_content' => $post_content,
		);

		if ( null !== $this->title && isset( $post_data['post_title'] ) ) {
			$payload['post_title'] = $post_data['post_title'];
		}

		if ( null !== $this->status && isset( $post_data['post_status'] ) ) {
			$payload['post_status'] = $post_data['post_status'];
		}

		if ( $this->excerpt_provided && isset( $post_data['post_excerpt'] ) ) {
			$payload['post_excerpt'] = $post_data['post_excerpt'];
		}

		return $payload;
	}

	/**
	 * Humanize a slug for insert defaults.
	 *
	 * @param string $slug Slug.
	 */
	private function humanize_slug( string $slug ): string {
		return ucwords( str_replace( '-', ' ', $slug ) );
	}
}
