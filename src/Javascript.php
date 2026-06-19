<?php
/**
 * JavaScript placeholder and base64 builder for Etch script attributes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders;

use BadMethodCallException;
use InvalidArgumentException;
use RuntimeException;
use HonestlyDesign\EtchBuilders\Support\Esc;

/**
 * Resolves JavaScript source files into base64 payloads via placeholders.
 */
final class Javascript {

	private const PLACEHOLDER_PREFIX = '__OH_MY_ID_ETCH_SCRIPT__';
	private const SOURCE_BASE64      = 'base64';

	/**
	 * Placeholder registry.
	 *
	 * @var array<string, array{
	 *     script_id: string,
	 *     source: string,
	 *     base64?: string
	 * }>
	 */
	private static array $registry = array();

	/**
	 * Prevent instantiation of static utility class.
	 */
	private function __construct() {
	}

	/**
	 * Manifest/Vite component scripts are not supported in this starter.
	 *
	 * @param string $slug Component slug (e.g. 'example-component').
	 * @throws BadMethodCallException Always.
	 */
	public static function set( string $slug ): string {
		throw new BadMethodCallException( 'Javascript::set() is not supported in this starter. Use Javascript::set_from_file().' );
	}

	/**
	 * Manifest/Vite entries are not supported in this starter.
	 *
	 * @param string $script_id Script identifier exposed to Etch.
	 * @param string $manifest_entry Vite manifest entry key.
	 * @throws BadMethodCallException Always.
	 */
	public static function set_manifest_entry( string $script_id, string $manifest_entry ): string {
		throw new BadMethodCallException( 'Javascript::set_manifest_entry() is not supported in this starter. Use Javascript::set_from_file().' );
	}

	/**
	 * Register a direct base64 payload and return a placeholder token.
	 *
	 * @param string $script_id Script identifier exposed to Etch.
	 * @param string $base64_script Prebuilt base64-encoded JavaScript payload.
	 * @throws InvalidArgumentException When script id or payload is invalid.
	 */
	private static function set_base64( string $script_id, string $base64_script ): string {
		$normalized_script_id = self::normalize_script_id( $script_id );
		$normalized_base64    = self::normalize_base64_payload( $base64_script );
		$placeholder          = self::build_placeholder( $normalized_script_id );

		self::$registry[ $placeholder ] = array(
			'script_id' => $normalized_script_id,
			'source'    => self::SOURCE_BASE64,
			'base64'    => $normalized_base64,
		);

		return $placeholder;
	}

	/**
	 * Register a JavaScript source file by path (reads and base64-encodes).
	 *
	 * The file is read at registration time, base64-encoded, and inlined into
	 * the pattern markup.
	 *
	 * @param string $script_id  Script identifier exposed to Etch.
	 * @param string $file_path  Absolute or relative path to the JS source file.
	 * @return string Placeholder token.
	 * @throws InvalidArgumentException When script id or file path is invalid.
	 */
	public static function set_from_file( string $script_id, string $file_path ): string {
		$normalized_script_id = self::normalize_script_id( $script_id );
		$normalized_path      = trim( $file_path );

		if ( '' === $normalized_path ) {
			throw new InvalidArgumentException( 'JavaScript file path must be non-empty.' );
		}

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Path is for debugging in exception message; esc_html would mangle filesystem paths.
		if ( ! is_readable( $normalized_path ) || ! is_file( $normalized_path ) ) {
			throw new InvalidArgumentException(
				sprintf( 'JavaScript file not readable: %s', $normalized_path )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$source_code = file_get_contents( $normalized_path );
		if ( false === $source_code || '' === trim( $source_code ) ) {
			throw new InvalidArgumentException(
				sprintf( 'JavaScript file is empty or unreadable: %s', $normalized_path )
			);
		}
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inline JS payload for pattern script.
		return self::set_base64( $normalized_script_id, base64_encode( $source_code ) );
	}

	/**
	 * Replace all known placeholders in block markup with base64 JavaScript.
	 *
	 * @param string $blocks Serialized Gutenberg blocks.
	 * @throws RuntimeException When script cannot be resolved.
	 */
	public static function inject_placeholders( string $blocks ): string {
		if ( '' === $blocks || array() === self::$registry ) {
			return $blocks;
		}

		$replacements = array();

		foreach ( self::$registry as $placeholder => $registration ) {
			if ( ! str_contains( $blocks, $placeholder ) ) {
				continue;
			}

			$source = $registration['source'];

			$script_base64  = '';
			$error_location = 'source: unknown';

			if ( self::SOURCE_BASE64 === $source ) {
				$base64_payload = $registration['base64'] ?? '';
				if ( is_string( $base64_payload ) ) {
					$script_base64      = $base64_payload;
					$error_location = 'source: file';
				}
			}

			if ( '' === $script_base64 ) {
				$script_id = $registration['script_id'];

					throw new RuntimeException(
						sprintf(
							'Unable to resolve JavaScript for script "%s" (%s).',
							Esc::html( $script_id ),
							Esc::html( $error_location ),
						)
					);
			}

			$replacements[ $placeholder ] = $script_base64;
		}

		if ( array() === $replacements ) {
			return $blocks;
		}

		return strtr( $blocks, $replacements );
	}

	/**
	 * Clear the in-memory registry.
	 */
	public static function reset(): void {
		self::$registry = array();
	}

	/**
	 * Capture the current JavaScript registry.
	 *
	 * @return array<string, array{script_id: string, source: string, base64?: string}>
	 */
	public static function snapshot(): array {
		return self::$registry;
	}

	/**
	 * Restore the JavaScript registry from a snapshot.
	 *
	 * @param array $snapshot JavaScript snapshot.
	 * @phpstan-param array<string, array{script_id: string, source: string, base64?: string}> $snapshot JavaScript snapshot.
	 */
	public static function restore( array $snapshot ): void {
		self::$registry = $snapshot;
	}

	/**
	 * Normalize script identifier (slug).
	 *
	 * @param string $script_id Raw script identifier.
	 * @throws InvalidArgumentException When script_id is empty or invalid.
	 */
	private static function normalize_script_id( string $script_id ): string {
		$normalized_script_id = trim( $script_id );
		if ( '' === $normalized_script_id ) {
			throw new InvalidArgumentException( 'JavaScript script id must be non-empty.' );
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $normalized_script_id ) ) {
			throw new InvalidArgumentException( 'JavaScript script id must match /^[A-Za-z0-9_-]+$/.' );
		}

		return $normalized_script_id;
	}

	/**
	 * Normalize and validate a base64 payload.
	 *
	 * @param string $base64_script Raw base64 payload.
	 * @throws InvalidArgumentException When payload is empty or invalid.
	 * @return string Normalized payload.
	 */
	private static function normalize_base64_payload( string $base64_script ): string {
		$normalized_payload = trim( $base64_script );
		if ( '' === $normalized_payload ) {
			throw new InvalidArgumentException( 'JavaScript base64 payload must be non-empty.' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded_payload = base64_decode( $normalized_payload, true );
		if ( false === $decoded_payload ) {
			throw new InvalidArgumentException( 'JavaScript base64 payload must be valid base64.' );
		}

		return $normalized_payload;
	}

	/**
	 * Build a placeholder token from script id.
	 *
	 * @param string $script_id Normalized script identifier.
	 * @return string Placeholder token.
	 */
	private static function build_placeholder( string $script_id ): string {
		return self::PLACEHOLDER_PREFIX . strtoupper( $script_id ) . '__';
	}

}
