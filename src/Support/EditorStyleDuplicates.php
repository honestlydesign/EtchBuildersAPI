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
 * When the Etch editor rebuilds an element from its class attribute (create,
 * paste, move, code edit), it resolves each class only inside the `default`
 * style collection. Builders 2.1.0 and earlier registered code-owned styles
 * in `OhMyIDEtch` and `OhMyIDEtch:entity:*` collections, which the editor
 * missed: it created an empty `default` style with the same selector and a
 * random ID and linked the element to it, so the element rendered without
 * its CSS.
 *
 * Since 2.1.1 every code-owned style is written to the `default` collection,
 * so the editor links the existing style ID instead. The collection is
 * presentation only and never proves ownership. Builder ownership is read
 * from Builders-owned state: the compiled resource ledger (`style:<id>`
 * records) and, within one request, the Style registry owner map. Records
 * still in a legacy `OhMyIDEtch` collection were written by Builders 2.1.0
 * or earlier and keep counting as Builder-written until the next apply moves
 * them to `default` under the same ID.
 *
 * A `default` record that no Builders-owned state records, that is not an
 * adopted mirror, and that uses the same single-class selector as a
 * code-owned style is an editor duplicate. An empty or identical duplicate is
 * safe to adopt as a mirror of the code-owned CSS; a duplicate holding
 * different CSS was written by a person and is reported, never overwritten.
 *
 * @internal Shared by Style ID resolution, Entity Style Sets, and the WordPress persistence store.
 */
final class EditorStyleDuplicates {

	public const RESOURCES_OPTION = 'etch_builders_site_persistence_resources';

	public const ALIASES_OPTION = 'etch_builders_site_persistence_style_aliases';

	public const EDITOR_COLLECTION = 'default';

	/**
	 * Collection used by Builders 2.1.0 and earlier for builder preview class styles.
	 */
	public const LEGACY_COLLECTION = 'OhMyIDEtch';

	/**
	 * Collection prefix used by Builders 2.1.0 and earlier for Entity Style Sets.
	 */
	public const LEGACY_ENTITY_COLLECTION_PREFIX = 'OhMyIDEtch:entity:';

	/**
	 * CSS of the readonly class style Builders registers for a class token
	 * that has no style yet, so the builder preview can link it.
	 */
	public const PREVIEW_PLACEHOLDER_CSS = '/* class style registered for builder preview */';

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
	 * Whether a Builder ledger record exists for a native style ID and selector.
	 *
	 * @param array<string, string> $ledger Result of ledger_selectors().
	 */
	public static function is_recorded( array $ledger, string $style_id, mixed $style ): bool {
		if ( ! isset( $ledger[ $style_id ] ) ) {
			return false;
		}

		$selector = is_array( $style ) && is_string( $style['selector'] ?? null )
			? StylesParserRuleScanner::normalize_selector_key( $style['selector'] )
			: null;

		return null === $selector || $ledger[ $style_id ] === $selector;
	}

	/**
	 * Whether a native record still lives in a legacy Entity Style Set collection.
	 *
	 * Only Builders 2.1.0 and earlier wrote these collections, so such a record
	 * is Builder-written even when no ledger record exists for it.
	 */
	public static function is_legacy_entity_collection( mixed $style ): bool {
		return null !== self::legacy_entity_owner( $style );
	}

	/**
	 * Return the Entity Style Set identity of a legacy entity collection record.
	 */
	public static function legacy_entity_owner( mixed $style ): ?string {
		if ( ! is_array( $style ) || ! is_string( $style['collection'] ?? null ) ) {
			return null;
		}

		return self::legacy_entity_owner_of_collection( $style['collection'] );
	}

	/**
	 * Return the Entity Style Set identity encoded in a legacy collection name.
	 */
	public static function legacy_entity_owner_of_collection( string $collection ): ?string {
		if ( ! str_starts_with( $collection, self::LEGACY_ENTITY_COLLECTION_PREFIX ) ) {
			return null;
		}

		$owner = substr( $collection, strlen( self::LEGACY_ENTITY_COLLECTION_PREFIX ) );

		return '' === $owner ? null : $owner;
	}

	/**
	 * Whether a collection name is one only Builders 2.1.0 and earlier wrote.
	 */
	public static function is_legacy_builder_collection( mixed $collection ): bool {
		return is_string( $collection )
			&& ( self::LEGACY_COLLECTION === $collection || str_starts_with( $collection, self::LEGACY_COLLECTION . ':' ) );
	}

	/**
	 * Whether a native record lives in the collection the Etch editor resolves
	 * classes in and writes to.
	 *
	 * Code-owned styles live there too since 2.1.1, so this alone never tells
	 * who wrote a record; callers first exclude Builder-recorded IDs.
	 */
	public static function is_editor_collection( mixed $style ): bool {
		if ( ! is_array( $style ) ) {
			return false;
		}

		$collection = $style['collection'] ?? self::EDITOR_COLLECTION;

		return self::EDITOR_COLLECTION === $collection;
	}

	/**
	 * Whether a native record is a `default` collection style without CSS.
	 *
	 * Only meaningful for records no Builders-owned state records: those are
	 * the empty styles the Etch editor creates when it misses a class.
	 */
	public static function is_empty_placeholder( mixed $style ): bool {
		return self::is_editor_collection( $style )
			&& is_array( $style )
			&& '' === trim( (string) ( $style['css'] ?? '' ) );
	}

	/**
	 * Whether a native record is the readonly preview placeholder Builders
	 * registers for a class token without a style.
	 *
	 * Etch keeps `readonly` and `css` when it saves, so this stays
	 * recognizable after an editor save in any collection.
	 */
	public static function is_builder_preview_placeholder( mixed $style ): bool {
		return is_array( $style )
			&& true === ( $style['readonly'] ?? null )
			&& self::PREVIEW_PLACEHOLDER_CSS === trim( (string) ( $style['css'] ?? '' ) )
			&& null !== self::single_class_selector( $style );
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
