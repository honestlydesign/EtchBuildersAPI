<?php
/**
 * Deterministic fingerprint for a maintainer-only artifact directory.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * Hashes relative file names and file contents, never the absolute machine
 * path. Symlinks are rejected so the same artifact cannot resolve differently
 * between the identity check and the Contract Lab run.
 */
final class ContractLabArtifactFingerprint {

	private function __construct() {
	}

	public static function directory( string $directory ): string {
		$root = realpath( $directory );
		if ( false === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
			throw new InvalidArgumentException( 'Contract Lab artifact fingerprint requires an existing readable directory.' );
		}

		$files = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isLink() ) {
				throw new InvalidArgumentException( 'Contract Lab artifact fingerprint does not allow symlinks.' );
			}
			if ( ! $file->isFile() || ! $file->isReadable() ) {
				throw new InvalidArgumentException( 'Contract Lab artifact fingerprint requires every artifact file to be readable.' );
			}

			$relative = ltrim( str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $root ) ) ), '/' );
			$digest   = hash_file( 'sha256', $file->getPathname() );
			if ( false === $digest ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab artifact fingerprint could not read "%s".', $relative ) );
			}
			$files[] = $relative . "\t" . $digest;
		}

		sort( $files, SORT_STRING );

		return hash( 'sha256', implode( "\n", $files ) . ( array() === $files ? '' : "\n" ) );
	}
}
