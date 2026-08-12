<?php
/**
 * A validated file-based global JavaScript asset.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Stores the source identity needed by the narrow Javascript file API.
 *
 * This value object does not register or inject a script. Compilation and
 * persistence remain separate tickets and decide when to call Javascript.
 */
final class JavascriptAsset {

	private function __construct(
		private readonly string $id,
		private readonly string $file_path
	) {
	}

	/**
	 * Create a validated file asset.
	 *
	 * @param string $id Script identifier accepted by Javascript::set_from_file().
	 * @param string $file_path Readable, non-empty JavaScript source file.
	 * @throws InvalidArgumentException When the identity or file is invalid.
	 */
	public static function new( string $id, string $file_path ): self {
		$id        = trim( $id );
		$file_path = trim( $file_path );

		if ( '' === $id || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $id ) ) {
			throw new InvalidArgumentException( 'JavascriptAsset id must match /^[A-Za-z0-9_-]+$/.' );
		}

		if ( '' === $file_path ) {
			throw new InvalidArgumentException( 'JavascriptAsset file path must be non-empty.' );
		}

		if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			throw new InvalidArgumentException( 'JavascriptAsset file must be readable.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source file after path validation.
		$source = file_get_contents( $file_path );
		if ( false === $source || '' === trim( $source ) ) {
			throw new InvalidArgumentException( 'JavascriptAsset file must contain JavaScript source.' );
		}

		return new self( $id, $file_path );
	}

	/**
	 * Return the JavaScript identifier.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Return the source file path.
	 */
	public function file_path(): string {
		return $this->file_path;
	}

	/**
	 * Return a deterministic non-wire asset record.
	 *
	 * @return array{type: string, id: string, path: string}
	 */
	public function to_array(): array {
		return array(
			'type' => 'javascript',
			'id'   => $this->id,
			'path' => $this->file_path,
		);
	}
}
