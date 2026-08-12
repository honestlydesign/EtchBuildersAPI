<?php
/**
 * Explicit global stylesheet rule for a portal-rendered BEM node.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Carries one reason-bearing portal rule before it is added to a Stylesheet.
 */
final class PortalStyle {

	private function __construct(
		private readonly string $selector,
		private readonly string $css,
		private readonly string $reason
	) {
	}

	/**
	 * Create a checked portal style escape.
	 *
	 * A portal rule must name at least one site-owned BEM class. Generic global
	 * selectors stay on the normal stylesheet policy path instead.
	 *
	 * @param string $selector CSS selector for the portal node.
	 * @param string $css Ruleset body.
	 * @param string $reason Why the node is rendered outside its serialized host.
	 * @throws InvalidArgumentException When the selector, CSS, or reason is invalid.
	 */
	public static function new( string $selector, string $css, string $reason ): self {
		$selector = StylesParserRuleScanner::normalize_selector_key( trim( $selector ) );
		$css      = trim( $css );
		$reason   = trim( $reason );

		if ( '' === $selector ) {
			throw new InvalidArgumentException( 'Portal style selector must be non-empty.' );
		}

		if ( '' === $css ) {
			throw new InvalidArgumentException( 'Portal style CSS must be non-empty.' );
		}

		if ( '' === $reason ) {
			throw new InvalidArgumentException( 'Portal style escape requires a non-empty reason.' );
		}

		if ( null !== StylesValidator::root_at_rule_for_escape( $selector ) || preg_match( '/[{};]/', $selector ) ) {
			throw new InvalidArgumentException( 'Portal style selector must contain only a CSS selector, without root at-rules or declarations.' );
		}

		$class_tokens = array();
		if ( preg_match_all( '/(?<![A-Za-z0-9_-])\.([A-Za-z][A-Za-z0-9_-]*)/', $selector, $matches ) >= 1 ) {
			$class_tokens = $matches[1];
		}

		$has_bem_class = false;
		foreach ( $class_tokens as $class_token ) {
			if ( ClassNamingPolicy::is_site_presentation( $class_token ) ) {
				$has_bem_class = true;
				break;
			}
		}

		if ( ! $has_bem_class ) {
			throw new InvalidArgumentException(
				'Portal style selector must include a BEM-namespaced site class such as .dialog__portal.'
			);
		}

		$errors = StylesValidator::validate( $selector . ' { ' . $css . ' }', StylesParserMode::FIXED );
		if ( array() !== $errors ) {
			throw new InvalidArgumentException( implode( ' ', $errors ) );
		}

		return new self( $selector, $css, $reason );
	}

	/**
	 * Return the normalized selector.
	 */
	public function selector(): string {
		return $this->selector;
	}

	/**
	 * Return the validated ruleset body.
	 */
	public function css(): string {
		return $this->css;
	}

	/**
	 * Return the required escape rationale.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Render the rule for a global stylesheet payload.
	 */
	public function to_css(): string {
		return $this->selector . ' { ' . $this->css . ' }';
	}
}
