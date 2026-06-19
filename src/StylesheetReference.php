<?php
/**
 * Reference to a CSS file used by an Etch global stylesheet.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use RuntimeException;

/**
 * Holds one code-declared stylesheet fragment.
 */
final class StylesheetReference {

	/**
	 * Stylesheet ID.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * CSS file path.
	 *
	 * @var string
	 */
	private string $file_path;

	/**
	 * CSS contents.
	 *
	 * @var string
	 */
	private string $css;

	/**
	 * Constructor.
	 *
	 * @param string $id Stylesheet ID.
	 * @param string $file_path CSS file path.
	 * @throws InvalidArgumentException When ID or file path is empty.
	 * @throws RuntimeException When the file cannot be read.
	 */
	private function __construct( string $id, string $file_path ) {
		$id        = trim( $id );
		$file_path = trim( $file_path );

		if ( '' === $id ) {
			throw new InvalidArgumentException( 'Stylesheet id must be non-empty.' );
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $id ) ) {
			throw new InvalidArgumentException( 'Stylesheet id must match /^[A-Za-z0-9_-]+$/.' );
		}

		if ( '' === $file_path ) {
			throw new InvalidArgumentException( 'Stylesheet CSS file path must be non-empty.' );
		}

		if ( ! is_file( $file_path ) ) {
			throw new RuntimeException( 'Stylesheet CSS file not found.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local CSS file after is_file() validation.
		$css = file_get_contents( $file_path );
		if ( false === $css ) {
			throw new RuntimeException( 'Stylesheet CSS file could not be read.' );
		}

		$this->id        = $id;
		$this->file_path = $file_path;
		$this->css       = $css;
	}

	/**
	 * Create a stylesheet reference.
	 *
	 * @param string $id Stylesheet ID.
	 * @param string $file_path CSS file path.
	 */
	public static function new( string $id, string $file_path ): self {
		return new self( $id, $file_path );
	}

	/**
	 * Return stylesheet ID.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Return CSS file path.
	 */
	public function file_path(): string {
		return $this->file_path;
	}

	/**
	 * Return CSS contents.
	 */
	public function css(): string {
		return $this->css;
	}

	/**
	 * Return the stable source key for this reference and owner.
	 *
	 * @param string $owner_key Builder owner key.
	 */
	public function source_key( string $owner_key ): string {
		return $owner_key . ':' . hash( 'sha256', $this->id ) . ':' . hash( 'sha256', $this->file_path );
	}
}
