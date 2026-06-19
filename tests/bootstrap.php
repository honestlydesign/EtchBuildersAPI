<?php
/**
 * PHPUnit bootstrap — pure PHP, no WordPress.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

require __DIR__ . '/../vendor/autoload.php';

if ( ! function_exists( 'parse_blocks' ) ) {
	/**
	 * Minimal pure-PHP Gutenberg block parser for tests.
	 *
	 * Handles the subset of block-comment syntax the builder tests use:
	 * `<!-- wp:<name> <json>? -->...<!-- /wp:<name> -->` (paired) and
	 * `<!-- wp:<name> <json>? /-->` (self-closing).
	 *
	 * @param string $content Block markup.
	 * @return array<int, array<string, mixed>>
	 */
	function parse_blocks( string $content ): array {
		$blocks    = array();
		$pattern   = '/<!--\s+wp:([a-z][a-z0-9\-\/]*)(\s+(\{.*?\}))?\s*(\/)?-->|(<!--\s+\/wp:([a-z][a-z0-9\-\/]*)\s+-->)/s';
		$stack     = array();
		$offset    = 0;

		while ( preg_match( $pattern, $content, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$match_pos = $m[0][1];
			$offset    = $match_pos + strlen( $m[0][0] );

			// Closing tag.
			if ( isset( $m[6][0] ) && '' !== $m[6][0] ) {
				if ( ! empty( $stack ) ) {
					$open = array_pop( $stack );
					$inner = substr( $content, $open['content_start'], $match_pos - $open['content_start'] );
					$open['innerHTML'] = $inner;
					$open['innerContent'] = array( $inner );
					$blocks[] = $open;
				}
				continue;
			}

			$block_name = $m[1][0];
			$attrs_json = $m[3][0] ?? '';
			$self_close = isset( $m[5][0] ) && '' !== $m[5][0];

			$attrs = '' !== trim( $attrs_json ) ? json_decode( $attrs_json, true ) : array();
			if ( ! is_array( $attrs ) ) {
				$attrs = array();
			}

			$block = array(
				'blockName'    => $block_name,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);

			if ( $self_close ) {
				$blocks[] = $block;
			} else {
				$block['content_start'] = $offset;
				$stack[] = $block;
			}
		}

		// Any unclosed blocks left on the stack (malformed input).
		foreach ( $stack as $open ) {
			$blocks[] = $open;
		}

		return $blocks;
	}
}
