<?php
/**
 * Shared post persistence for Page, Post, and Template builders.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\Content;

use InvalidArgumentException;
use WP_Error;

/**
 * Persists builder-owned post fields with conflict protection.
 */
final class ContentPostRegistrar {

	public const SOURCE_PAGE     = 'HonestlyDesign\\EtchBuilders\\Page';
	public const SOURCE_POST     = 'HonestlyDesign\\EtchBuilders\\Post';
	public const SOURCE_TEMPLATE = 'HonestlyDesign\\EtchBuilders\\Template';

	private const HASH_META         = '_omide_builder_hash';
	private const SOURCE_META       = '_omide_builder_source';
	private const OWNED_FIELDS_META = '_omide_builder_owned_fields';

	/**
	 * Builder source class.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * Constructor.
	 *
	 * @param string $source Builder source class.
	 */
	private function __construct( string $source ) {
		$this->source = $source;
	}

	/**
	 * Create a registrar.
	 *
	 * @param string $source Builder source class.
	 */
	public static function new( string $source ): self {
		return new self( $source );
	}

	/**
	 * Insert a post and store ownership metadata.
	 *
	 * @param array<string, mixed> $post_data Insert payload.
	 * @param array<string, mixed> $owned_payload Builder-owned payload.
	 * @return int|WP_Error
	 */
	public function insert( array $post_data, array $owned_payload ): int|WP_Error {
		$post_id = wp_insert_post( $this->slash_post_data( $post_data ), true );

		if ( $post_id instanceof WP_Error ) {
			return new WP_Error( 'omide_builder_update_failed', $post_id->get_error_message() );
		}

		$this->store_metadata( (int) $post_id, $owned_payload );

		return (int) $post_id;
	}

	/**
	 * Update a post if ownership rules allow it.
	 *
	 * @param int                  $post_id Existing post ID.
	 * @param array<string, mixed> $post_data Update payload without ID.
	 * @param array<string, mixed> $owned_payload Builder-owned payload.
	 * @param bool                 $overwrite Whether to bypass conflicts.
	 * @return int|WP_Error
	 */
	public function update( int $post_id, array $post_data, array $owned_payload, bool $overwrite ): int|WP_Error {
		if ( ! $overwrite && ! $this->target_is_builder_owned_and_unchanged( $post_id ) ) {
			return new WP_Error(
				'omide_builder_content_conflict',
				'Target content has been edited outside this builder. Use overwrite() to replace it intentionally.'
			);
		}

		$post_data['ID'] = $post_id;
		$result          = wp_update_post( $this->slash_post_data( $post_data ), true );

		if ( $result instanceof WP_Error ) {
			return new WP_Error( 'omide_builder_update_failed', $result->get_error_message() );
		}

		$this->store_metadata( $post_id, $owned_payload );

		return (int) $result;
	}

	/**
	 * Determine whether a target is builder-owned and unchanged.
	 *
	 * @param int $post_id Post ID.
	 */
	private function target_is_builder_owned_and_unchanged( int $post_id ): bool {
		$stored_hash = get_post_meta( $post_id, self::HASH_META, true );

		if ( ! is_string( $stored_hash ) || '' === $stored_hash ) {
			return false;
		}

		$stored_source = get_post_meta( $post_id, self::SOURCE_META, true );
		if ( $this->source !== $stored_source ) {
			return false;
		}

		$owned_fields = get_post_meta( $post_id, self::OWNED_FIELDS_META, true );
		if ( ! is_array( $owned_fields ) || array() === $owned_fields ) {
			return false;
		}

		$current_payload = $this->current_payload_for_fields( $post_id, $owned_fields );

		return hash_equals( $stored_hash, self::hash_payload( $current_payload ) );
	}

	/**
	 * Build current payload for stored owned fields.
	 *
	 * @param int          $post_id Post ID.
	 * @param array<mixed> $fields Owned field names.
	 * @return array<string, mixed>
	 */
	private function current_payload_for_fields( int $post_id, array $fields ): array {
		$post = get_post( $post_id );
		if ( null === $post ) {
			return array();
		}

		$payload = array();
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) ) {
				continue;
			}

			if ( property_exists( $post, $field ) ) {
				$payload[ $field ] = $post->{$field};
			}
		}

		return $payload;
	}

	/**
	 * Store ownership metadata.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $owned_payload Builder-owned payload.
	 */
	private function store_metadata( int $post_id, array $owned_payload ): void {
		update_post_meta( $post_id, self::HASH_META, self::hash_payload( $owned_payload ) );
		// WordPress meta writes unslash values; preserve namespace separators in source class names.
		update_post_meta( $post_id, self::SOURCE_META, wp_slash( $this->source ) );
		update_post_meta( $post_id, self::OWNED_FIELDS_META, array_keys( $owned_payload ) );
	}

	/**
	 * Hash owned payload.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @throws InvalidArgumentException When the payload cannot be JSON encoded.
	 */
	private static function hash_payload( array $payload ): string {
		ksort( $payload );

		$encoded_payload = wp_json_encode( $payload );
		if ( false === $encoded_payload ) {
			throw new InvalidArgumentException( 'Unable to encode builder-owned payload.' );
		}

		return hash( 'sha256', $encoded_payload );
	}

	/**
	 * Slash post content fields before WordPress post writes.
	 *
	 * @param array<string, mixed> $post_data Post data.
	 * @return array<string, mixed>
	 */
	private function slash_post_data( array $post_data ): array {
		if ( isset( $post_data['post_content'] ) && is_string( $post_data['post_content'] ) ) {
			$post_data['post_content'] = wp_slash( $post_data['post_content'] );
		}

		if ( isset( $post_data['post_title'] ) && is_string( $post_data['post_title'] ) ) {
			$post_data['post_title'] = wp_slash( $post_data['post_title'] );
		}

		if ( isset( $post_data['post_excerpt'] ) && is_string( $post_data['post_excerpt'] ) ) {
			$post_data['post_excerpt'] = wp_slash( $post_data['post_excerpt'] );
		}

		return $post_data;
	}
}
