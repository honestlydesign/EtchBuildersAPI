<?php
/**
 * Checked narrow HTML fragment escape.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Carries one trusted inline fragment and the reason it cannot use typed APIs.
 *
 * This is deliberately not a general HTML/page/component input. Serialized
 * Gutenberg trees and broad document/section wrappers stay on legacy raw
 * compatibility paths and are visible to later checker tickets as escapes.
 */
final class RawFragment {

	private function __construct(
		private readonly string $html,
		private readonly string $reason
	) {
	}

	/**
	 * Create a checked narrow raw fragment.
	 *
	 * @param string $html   Trusted inline HTML fragment.
	 * @param string $reason Why typed builders cannot express this fragment.
	 * @throws InvalidArgumentException When the fragment is empty, broad, or lacks a reason.
	 */
	public static function new( string $html, string $reason ): self {
		if ( '' === trim( $html ) ) {
			throw new InvalidArgumentException( 'RawFragment content must be non-empty.' );
		}

		if ( '' === $reason || trim( $reason ) !== $reason ) {
			throw new InvalidArgumentException( 'RawFragment requires a non-empty reason without surrounding whitespace.' );
		}

		$normalized = strtolower( $html );
		if ( preg_match( '/<!--\s*\/?\s*wp[:\s]/i', $html ) ) {
			throw new InvalidArgumentException( 'RawFragment cannot contain serialized Gutenberg block trees.' );
		}

		if (
			preg_match( '/<!doctype\b|<\/?(?:html|head|body|main|section|article|header|footer|template)(?=[\s>\/])/i', $normalized )
			|| preg_match( '/\bdata-etch-component\s*=/i', $normalized )
		) {
			throw new InvalidArgumentException( 'RawFragment cannot contain whole document, page, component, or section wrappers.' );
		}

		return new self( $html, $reason );
	}

	/**
	 * Return the exact fragment content.
	 */
	public function html(): string {
		return $this->html;
	}

	/**
	 * Return the required rationale.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
