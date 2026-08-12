<?php
/**
 * Semantic DOM normalization for Contract Lab frontend observations.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\ImmutableArray;

/**
 * Keeps element order and meaningful text while removing transport wrappers
 * and parser-only asset nodes.
 */
final class ContractLabFrontendDomNormalizer {

	/** @var array<int, string> */
	private const NON_CONTENT_ELEMENTS = array( 'link', 'meta', 'script', 'style', 'title' );

	private function __construct() {
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function normalize( string $html ): array {
		$document = self::parse_document( $html );
		$body     = $document->getElementsByTagName( 'body' )->item( 0 );
		$root     = $body instanceof \DOMElement ? $body : $document;
		$nodes    = array();
		foreach ( $root->childNodes as $child ) {
			$normalized = self::normalize_node( $child );
			if ( null !== $normalized ) {
				$nodes[] = $normalized;
			}
		}

		if ( array() === $nodes ) {
			throw new ContractLabObservationException( 'unsupported', 'Frontend fixture rendered no semantic DOM nodes.' );
		}

		return $nodes;
	}

	/**
	 * Parse HTML once so the probe can inspect both content and stylesheet tags.
	 */
	public static function parse_document( string $html ): \DOMDocument {
		if ( ! class_exists( '\DOMDocument' ) ) {
			throw new ContractLabObservationException( 'unavailable', 'PHP DOMDocument is unavailable for frontend observations.' );
		}
		if ( '' === trim( $html ) ) {
			throw new ContractLabObservationException( 'malformed', 'Frontend fixture HTTP response has an empty HTML body.' );
		}

		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$document = new \DOMDocument( '1.0', 'UTF-8' );
		$loaded   = $document->loadHTML( $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $loaded ) {
			throw new ContractLabObservationException( 'malformed', 'Frontend fixture HTTP response is not valid HTML.' );
		}

		return $document;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function normalize_node( \DOMNode $node ): ?array {
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			$value = preg_replace( '/\s+/u', ' ', trim( (string) $node->nodeValue ) );
			$value = is_string( $value ) ? $value : '';
			return '' === $value ? null : array( 'type' => 'text', 'value' => $value );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType || ! $node instanceof \DOMElement ) {
			return null;
		}

		$name = strtolower( $node->tagName );
		if ( in_array( $name, self::NON_CONTENT_ELEMENTS, true ) ) {
			return null;
		}
		$attributes = array();
		foreach ( $node->attributes as $attribute ) {
			if ( ! $attribute instanceof \DOMAttr ) {
				continue;
			}
			$attributes[ strtolower( $attribute->name ) ] = (string) $attribute->value;
		}
		ksort( $attributes );
		$children = array();
		foreach ( $node->childNodes as $child ) {
			$normalized = self::normalize_node( $child );
			if ( null !== $normalized ) {
				$children[] = $normalized;
			}
		}

		/** @var array<string, mixed> $attributes */
		$attributes = ImmutableArray::copy( $attributes, 'Normalized DOM attributes must contain scalar values.' );

		return array(
			'type'       => 'element',
			'name'       => $name,
			'attributes' => $attributes,
			'children'   => $children,
		);
	}
}
