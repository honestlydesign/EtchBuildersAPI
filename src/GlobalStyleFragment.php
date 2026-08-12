<?php
/**
 * Checked global stylesheet fragment.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Carries one explicitly classified global CSS fragment.
 */
final class GlobalStyleFragment {

	private function __construct(
		private readonly GlobalStyleCategory $category,
		private readonly string $css
	) {
	}

	/**
	 * Create a design-token fragment.
	 */
	public static function tokens( string $css ): self {
		return self::from_css( GlobalStyleCategory::TOKENS, $css );
	}

	/**
	 * Create an external framework/layer fragment.
	 */
	public static function framework( string $css ): self {
		return self::from_css( GlobalStyleCategory::FRAMEWORK, $css );
	}

	/**
	 * Create an explicitly registered utility fragment.
	 */
	public static function utility( string $css ): self {
		return self::from_css( GlobalStyleCategory::UTILITY, $css );
	}

	/**
	 * Create a font-face fragment.
	 */
	public static function font( string $css ): self {
		return self::from_css( GlobalStyleCategory::FONT, $css );
	}

	/**
	 * Create a global base/reset fragment.
	 */
	public static function base( string $css ): self {
		return self::from_css( GlobalStyleCategory::BASE, $css );
	}

	/**
	 * Create the only checked global presentation escape: a portal rule.
	 */
	public static function portal( PortalStyle $portal ): self {
		return new self( GlobalStyleCategory::PORTAL, $portal->to_css() );
	}

	/**
	 * Return the explicit global category.
	 */
	public function category(): GlobalStyleCategory {
		return $this->category;
	}

	/**
	 * Return the validated CSS payload.
	 */
	public function css(): string {
		return $this->css;
	}

	/**
	 * Construct and validate one non-portal fragment.
	 */
	private static function from_css( GlobalStyleCategory $category, string $css ): self {
		$css    = trim( $css );
		$errors = StylesValidator::validate_global_fragment( $category, $css );

		if ( array() !== $errors ) {
			throw new InvalidArgumentException( implode( ' ', $errors ) );
		}

		return new self( $category, $css );
	}
}
