<?php
/**
 * PHPUnit bootstrap — pure PHP, no WordPress.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

require __DIR__ . '/../vendor/autoload.php';

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Minimal WordPress-free slug normalizer for public Page::slug() tests.
	 *
	 * @param string $title Candidate title.
	 * @return string
	 */
	function sanitize_title( string $title ): string {
		$normalized = preg_replace( '/[^a-z0-9]+/i', '-', $title );

		return trim( strtolower( (string) $normalized ), '-' );
	}
}

if ( ! function_exists( 'etch_test_wire_node_to_array' ) ) {
	/**
	 * Convert one parser node to the WordPress parse_blocks() array shape.
	 *
	 * @param object $node Parser node with blockName/attrs/innerBlocks properties.
	 * @return array<string, mixed>
	 */
	function etch_test_wire_node_to_array( object $node ): array {
		$data = (array) $node;

		return array(
			'blockName'    => is_string( $data['blockName'] ?? null ) ? $data['blockName'] : '',
			'attrs'        => is_array( $data['attrs'] ?? null ) ? $data['attrs'] : array(),
			'innerBlocks'  => array_map(
				static fn ( object $child_node ): array => etch_test_wire_node_to_array( $child_node ),
				is_array( $data['innerBlocks'] ?? null ) ? $data['innerBlocks'] : array()
			),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	/**
	 * Minimal pure-PHP Gutenberg block parser for tests.
	 *
	 * Handles the subset of block-comment syntax the builder tests use:
	 * `<!-- wp:<name> <json>? -->...<!-- /wp:<name> -->` (paired) and
	 * `<!-- wp:<name> <json>? /-->` (self-closing). Builds the same nested
	 * innerBlocks tree WordPress returns, so guards that walk parsed trees
	 * see nested blocks exactly like the WordPress runtime.
	 *
	 * @param string $content Block markup.
	 * @return array<int, array<string, mixed>>
	 */
	function parse_blocks( string $content ): array {
		$root    = (object) array( 'innerBlocks' => array() );
		$stack   = array( $root );
		$pattern = '/<!--\s+wp:([a-z][a-z0-9\-\/]*)(\s+(\{.*?\}))?\s*(\/)?-->|(<!--\s+\/wp:([a-z][a-z0-9\-\/]*)\s+-->)/s';
		$offset  = 0;

		while ( preg_match( $pattern, $content, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$match_pos = $m[0][1];
			$offset    = $match_pos + strlen( $m[0][0] );

			// Closing tag: finish the innermost open block.
			if ( isset( $m[6][0] ) && '' !== $m[6][0] ) {
				if ( 1 < count( $stack ) ) {
					array_pop( $stack );
				}
				continue;
			}

			$block_name = isset( $m[1][0] ) ? (string) $m[1][0] : '';
			$attrs_json = isset( $m[3][0] ) ? (string) $m[3][0] : '';
			$self_close = isset( $m[5][0] ) && '' !== $m[5][0];

			$attrs = '' !== trim( $attrs_json ) ? json_decode( $attrs_json, true ) : array();
			if ( ! is_array( $attrs ) ) {
				$attrs = array();
			}

			$node = (object) array(
				'blockName'   => $block_name,
				'attrs'       => $attrs,
				'innerBlocks' => array(),
			);

			$stack[ count( $stack ) - 1 ]->innerBlocks[] = $node;

			if ( ! $self_close ) {
				$stack[] = $node;
			}
		}

		return array_map( 'etch_test_wire_node_to_array', $root->innerBlocks );
	}
}
