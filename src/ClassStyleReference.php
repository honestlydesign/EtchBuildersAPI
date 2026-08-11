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
	 * @throws InvalidArgumentException When the ID or class-style identity is invalid.
	 */
	public static function registered( string $style_id ): self {
		if ( '' === $style_id || trim( $style_id ) !== $style_id ) {
			throw new InvalidArgumentException(
				sprintf( 'Class Style ID "%s" must be a registered opaque Etch style ID.', $style_id )
			);
		}

		$style = self::find_registered_style( $style_id );
		if ( null === $style ) {
			throw new InvalidArgumentException(
				sprintf( 'Class Style ID "%s" is not registered in etch_styles.', $style_id )
			);
		}

		$selector = isset( $style['selector'] ) && is_string( $style['selector'] ) ? trim( $style['selector'] ) : '';
		$has_type = array_key_exists( 'type', $style );
		$type     = $has_type && is_string( $style['type'] ) ? trim( $style['type'] ) : '';

		if ( ! $has_type && 1 === preg_match( self::EXACT_CLASS_SELECTOR_PATTERN, $selector ) ) {
			$type = 'class';
		}

		if ( 'class' !== $type ) {
			throw new InvalidArgumentException(
				sprintf( 'Class Style ID "%s" must reference a type=class Etch style.', $style_id )
			);
		}

		if ( 1 !== preg_match( self::EXACT_CLASS_SELECTOR_PATTERN, $selector ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Class Style ID "%s" must reference exactly one simple class selector such as ".card"; got "%s".',
					$style_id,
					$selector
				)
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
	 * Find a style by direct ID without importing or mutating it.
	 *
	 * @param string $style_id Opaque Etch style ID.
	 * @return array<string, mixed>|null
	 */
	private static function find_registered_style( string $style_id ): ?array {
		$registered = Style::registered_styles();
		if ( isset( $registered[ $style_id ] ) ) {
			return $registered[ $style_id ];
		}

		$persisted = Environment::storage()->get( self::STYLES_OPTION_NAME, array() );
		if ( ! is_array( $persisted ) || ! isset( $persisted[ $style_id ] ) || ! is_array( $persisted[ $style_id ] ) ) {
			return null;
		}

		return $persisted[ $style_id ];
	}
}
