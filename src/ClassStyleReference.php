<?php
/**
 * Validated reference to one exact Etch class style.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Proves that an opaque style ID currently identifies one simple class selector.
 */
final class ClassStyleReference {

	private const STYLES_OPTION_NAME = 'etch_styles';

	private const EXACT_CLASS_SELECTOR_PATTERN = '/^\.[A-Za-z][A-Za-z0-9_-]*$/';

	/**
	 * Opaque Etch style ID.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Validated simple class selector.
	 *
	 * @var string
	 */
	private string $selector;

	/**
	 * Constructor.
	 *
	 * @param string $id Opaque Etch style ID.
	 * @param string $selector Validated simple class selector.
	 */
	private function __construct( string $id, string $selector ) {
		$this->id       = $id;
		$this->selector = $selector;
	}

	/**
	 * Create a reference from an existing request-local or persisted style ID.
	 *
	 * Resolution is direct and read-only. Request-local style definitions take
	 * precedence over persistence, matching the effective registration plan.
	 *
	 * @param string $style_id Opaque Etch style ID.
	 * @throws Exceptions\ClassStyleDiagnosticException When the ID or class-style identity is invalid.
	 */
	public static function registered( string $style_id ): self {
		if ( '' === $style_id || trim( $style_id ) !== $style_id ) {
			throw ClassStyleDiagnostic::failure(
				ClassStyleDiagnostic::UNKNOWN_ID,
				sprintf( 'Class Style ID "%s" is not a valid registered opaque Etch style ID.', $style_id ),
				'Register an exact type=class style, then pass its opaque ID to ClassStyleReference::registered().'
			);
		}

		if ( 1 === preg_match( '/^rt-/', $style_id ) ) {
			throw ClassStyleDiagnostic::failure(
				ClassStyleDiagnostic::RUNTIME_TOKEN,
				sprintf( 'Runtime token "%s" is owned by Etch and cannot be a component Class Style ID.', $style_id ),
				'Put the token on an element HTML class through ElementBlock::class(); do not pass it to ClassStyleReference::registered().'
			);
		}

		$styles = self::effective_registered_styles();
		$style  = isset( $styles[ $style_id ] ) ? $styles[ $style_id ] : null;
		if ( null === $style ) {
			$matching_ids = self::find_style_ids_for_input_class_name( $style_id, $styles );
			if ( array() !== $matching_ids ) {
				throw ClassStyleDiagnostic::failure(
					ClassStyleDiagnostic::CLASS_NAME_INPUT,
					sprintf(
						'Input "%s" is a class name or selector, not an opaque Class Style ID; matching registered ID(s): %s.',
						$style_id,
						implode( ', ', $matching_ids )
					),
					'Pass the matching opaque ID to ClassStyleReference::registered(), then use the resulting reference in ClassStyleSet.'
				);
			}

			throw ClassStyleDiagnostic::failure(
				ClassStyleDiagnostic::UNKNOWN_ID,
				sprintf( 'Class Style ID "%s" is not registered in etch_styles.', $style_id ),
				'Register an exact type=class style, then pass its opaque ID to ClassStyleReference::registered().'
			);
		}

		$selector = isset( $style['selector'] ) && is_string( $style['selector'] ) ? trim( $style['selector'] ) : '';
		$has_type = array_key_exists( 'type', $style );
		$type     = $has_type && is_string( $style['type'] ) ? trim( $style['type'] ) : '';

		if ( $has_type && 'class' !== $type ) {
			throw ClassStyleDiagnostic::failure(
				ClassStyleDiagnostic::NON_CLASS_STYLE,
				sprintf( 'Class Style ID "%s" references type "%s", not type=class.', $style_id, $type ),
				'Use a type=class style with exactly one simple selector, then pass its opaque ID to ClassStyleReference::registered().'
			);
		}

		if ( 1 !== preg_match( self::EXACT_CLASS_SELECTOR_PATTERN, $selector ) ) {
			throw ClassStyleDiagnostic::failure(
				ClassStyleDiagnostic::COMPOUND_SELECTOR,
				sprintf(
					'Class Style ID "%s" must reference exactly one simple class selector such as ".card"; got "%s".',
					$style_id,
					$selector
				),
				'Keep pseudo, descendant, state, and media rules inside the owning standalone class style, then reference that root style with ClassStyleReference::registered().'
			);
		}

		return new self( $style_id, $selector );
	}

	/**
	 * Return the unchanged opaque Etch style ID.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Return the validated simple class selector.
	 */
	public function selector(): string {
		return $this->selector;
	}

	/**
	 * Prove this opaque ID still denotes its originally validated selector.
	 *
	 * @throws InvalidArgumentException When current registry identity differs.
	 */
	public function assert_current(): void {
		$current = self::registered( $this->id );

		if ( $current->selector !== $this->selector ) {
			throw new InvalidArgumentException(
				sprintf(
					'Class Style ID "%s" changed selector identity from "%s" to "%s".',
					$this->id,
					$this->selector,
					$current->selector
				)
			);
		}
	}

	/**
	 * Find opaque IDs whose exact selector matches class-name-shaped input.
	 *
	 * @param string                                      $input Class name or selector candidate.
	 * @param array<array-key, array<string, mixed>> $styles Effective style records.
	 * @return array<int, string>
	 */
	private static function find_style_ids_for_input_class_name( string $input, array $styles ): array {
		$selector = '.' === substr( $input, 0, 1 ) ? $input : '.' . $input;
		if ( 1 !== preg_match( self::EXACT_CLASS_SELECTOR_PATTERN, $selector ) ) {
			return array();
		}

		$matching_ids = array();
		foreach ( $styles as $style_id => $style ) {
			$registered_selector = isset( $style['selector'] ) && is_string( $style['selector'] )
				? trim( $style['selector'] )
				: '';
			if ( $selector !== $registered_selector ) {
				continue;
			}

			if ( array_key_exists( 'type', $style ) && ( ! is_string( $style['type'] ) || 'class' !== trim( $style['type'] ) ) ) {
				continue;
			}

			$matching_ids[] = (string) $style_id;
		}

		sort( $matching_ids, SORT_STRING );

		return $matching_ids;
	}

	/**
	 * Return effective request-local and persisted styles without mutating either.
	 *
	 * Request-local definitions win for the same opaque ID, matching registration.
	 *
	 * @return array<array-key, array<string, mixed>>
	 */
	private static function effective_registered_styles(): array {
		$styles = Style::registered_styles();
		$stored = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			return $styles;
		}

		foreach ( $stored as $style_id => $style ) {
			if ( ! is_array( $style ) || array_key_exists( $style_id, $styles ) ) {
				continue;
			}

			$styles[ $style_id ] = $style;
		}

		return $styles;
	}
}
