<?php
/**
 * Shared rules for styles the Etch editor duplicates into its default collection.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\StylesParserRuleScanner;

/**
 * Recognizes editor-created duplicates of code-owned class styles.
 *
 * When the Etch editor rebuilds an element from its class attribute (paste,
 * move, code edit), it resolves each class only inside the `default` style
 * collection. A code-owned style registered in an `OhMyIDEtch:entity:*`
 * collection is missed, so Etch creates an empty `default` style with the
 * same selector and a random ID and links the element to it. The element
 * then renders without its CSS, because Etch renders styles by referenced ID.
 *
 * Builders treats such a record as an editor duplicate of the code-owned
 * style when it sits in the `default` collection, is not recorded as
 * Builder-owned, and uses the same single-class selector. An empty or
 * identical duplicate is safe to adopt as a mirror of the code-owned CSS; a
 * duplicate holding different CSS was written by a person and is reported,
 * never overwritten.
 *
 * @internal Shared by Style ID resolution and the WordPress persistence store.
 */
final class EditorStyleDuplicates {

	public const RESOURCES_OPTION = 'etch_builders_site_persistence_resources';

	public const ALIASES_OPTION = 'etch_builders_site_persistence_style_aliases';

	public const EDITOR_COLLECTION = 'default';

	public const ENTITY_COLLECTION_PREFIX = 'OhMyIDEtch:entity:';

	private function __construct() {
	}

	/**
	 * Return Builder-owned style IDs recorded in the resource ledger with
	 * their normalized selector.
	 *
	 * @return array<string, string>
	 */
	public static function ledger_selectors( mixed $resources ): array {
		if ( ! is_array( $resources ) ) {
			return array();
		}

		$selectors = array();
		foreach ( $resources as $identity => $record ) {
			if ( ! is_string( $identity ) || ! str_starts_with( $identity, 'style:' ) || ! is_array( $record ) ) {
				continue;
			}
			if ( 'style' !== ( $record['kind'] ?? null ) || true !== ( $record['owned'] ?? null ) || ! is_array( $record['payload'] ?? null ) ) {
				continue;
			}

			$selector = $record['payload']['selector'] ?? null;
			if ( is_string( $selector ) && '' !== trim( $selector ) ) {
				$selectors[ substr( $identity, strlen( 'style:' ) ) ] = StylesParserRuleScanner::normalize_selector_key( $selector );
			}
		}

		return $selectors;
	}

	/**
	 * Return adopted editor duplicates keyed by their native style ID.
	 *
	 * `original` is the duplicate as it was before its first adoption, so
	 * removing the code-owned style can give a person's style back as it was.
	 *
	 * @return array<string, array{canonical: string, hash: string, original?: array<array-key, mixed>}>
	 */
	public static function aliases( mixed $aliases ): array {
		if ( ! is_array( $aliases ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $aliases as $alias_id => $alias ) {
			if ( ! is_string( $alias_id ) || ! is_array( $alias ) ) {
				continue;
			}

			$canonical = $alias['canonical'] ?? null;
			$hash      = $alias['hash'] ?? null;
			if ( is_string( $canonical ) && '' !== $canonical && is_string( $hash ) && '' !== $hash ) {
				$normalized[ $alias_id ] = array(
					'canonical' => $canonical,
					'hash'      => $hash,
				);
				if ( is_array( $alias['original'] ?? null ) ) {
					$normalized[ $alias_id ]['original'] = $alias['original'];
				}
			}
		}

		return $normalized;
	}

	/**
	 * Whether a native record lives in an Entity Style Set collection.
	 */
	public static function is_code_collection( mixed $style ): bool {
		return is_array( $style )
			&& is_string( $style['collection'] ?? null )
			&& str_starts_with( $style['collection'], self::ENTITY_COLLECTION_PREFIX );
	}

	/**
	 * Whether a native record lives in the collection the Etch editor writes to.
	 */
	public static function is_editor_collection( mixed $style ): bool {
		if ( ! is_array( $style ) ) {
			return false;
		}

		$collection = $style['collection'] ?? self::EDITOR_COLLECTION;

		return self::EDITOR_COLLECTION === $collection;
	}

	/**
	 * Whether a native record is an editor-collection style without CSS.
	 */
	public static function is_empty_placeholder( mixed $style ): bool {
		return self::is_editor_collection( $style )
			&& is_array( $style )
			&& '' === trim( (string) ( $style['css'] ?? '' ) );
	}

	/**
	 * Return the normalized single-class selector of a style payload, or null.
	 */
	public static function single_class_selector( mixed $style ): ?string {
		if ( ! is_array( $style ) || ! is_string( $style['selector'] ?? null ) ) {
			return null;
		}

		$selector = StylesParserRuleScanner::normalize_selector_key( $style['selector'] );

		return null === StylesParserRuleScanner::single_class_token( $selector ) ? null : $selector;
	}

	/**
	 * Whether two CSS bodies are the same apart from whitespace and a trailing semicolon.
	 */
	public static function css_matches( string $left, string $right ): bool {
		return self::normalize_css( $left ) === self::normalize_css( $right );
	}

	/**
	 * Build the mirror payload written into an adopted editor duplicate.
	 *
	 * The duplicate keeps its own ID, collection, and any editor metadata, so
	 * Etch keeps reusing it for later pastes; only the rendered fields follow
	 * the code-owned style.
	 *
	 * @param array<string, mixed> $duplicate Native editor duplicate.
	 * @param array<string, mixed> $canonical Compiled code-owned style payload.
	 * @return array<string, mixed>
	 */
	public static function mirror_payload( array $duplicate, array $canonical ): array {
		$mirror = $duplicate;
		foreach ( array( 'selector', 'css', 'type' ) as $field ) {
			if ( array_key_exists( $field, $canonical ) ) {
				$mirror[ $field ] = $canonical[ $field ];
			}
		}
		unset( $mirror['overwrite_on_register'] );

		return $mirror;
	}

	/**
	 * Stable hash of one native style payload.
	 *
	 * @param array<array-key, mixed> $payload
	 */
	public static function payload_hash( array $payload ): string {
		$encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );

		return hash( 'sha256', false === $encoded ? serialize( $payload ) : $encoded );
	}

	private static function normalize_css( string $css ): string {
		$css = preg_replace( '/\s+/', ' ', trim( $css ) ) ?? trim( $css );

		return rtrim( trim( $css ), '; ' );
	}
}
