<?php
/**
 * Explicit escape for a non-simple style selector.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Marks a selector that cannot be expressed as a class-owned nested rule.
 *
 * The value is intentionally separate from a plain selector string so the
 * exceptional path is visible at the call site and always carries a reason.
 */
final class ScopedSelector {

	private function __construct(
		private readonly string $selector,
		private readonly string $reason
	) {
	}

	/**
	 * Create a checked scoped selector escape.
	 *
	 * @param string $selector CSS selector.
	 * @param string $reason Why native owner-local nesting cannot express it.
	 * @throws InvalidArgumentException When the selector or reason is invalid.
	 */
	public static function new( string $selector, string $reason ): self {
		$selector = StylesParserRuleScanner::normalize_selector_key( trim( $selector ) );
		$reason   = trim( $reason );

		if ( '' === $selector ) {
			throw new InvalidArgumentException( 'Scoped selector must be non-empty.' );
		}

		if ( '' === $reason ) {
			throw new InvalidArgumentException( 'Scoped selector escape requires a non-empty reason.' );
		}

		if ( null !== StylesValidator::root_at_rule_for_escape( $selector ) ) {
			throw new InvalidArgumentException( 'Scoped selector cannot be a root at-rule. Use Stylesheet for global CSS.' );
		}

		if ( preg_match( '/[{};]/', $selector ) ) {
			throw new InvalidArgumentException( 'Scoped selector must contain only a CSS selector, without braces or declarations.' );
		}

		if ( null !== StylesParserRuleScanner::single_class_token( $selector ) ) {
			throw new InvalidArgumentException(
				'Scoped selector must be non-simple; use the owning Class Style for one simple class selector.'
			);
		}

		return new self( $selector, $reason );
	}

	/**
	 * Return the normalized CSS selector.
	 */
	public function selector(): string {
		return $this->selector;
	}

	/**
	 * Return the required escape rationale.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
