<?php
/**
 * Verified repository and artifact identity for package gates.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use RuntimeException;

/**
 * Captures identity only after inspecting the real Git checkout and artifact.
 */
final class ContractLabPackageGateIdentity {

	private function __construct(
		private readonly string $repository_root,
		private readonly string $artifact_root,
		private readonly string $source_revision,
		private readonly string $artifact_fingerprint
	) {
	}

	public static function inspect( string $working_directory, string $artifact_directory ): self {
		$working_root = self::real_directory( $working_directory, 'working directory' );
		$artifact_root = self::real_directory( $artifact_directory, 'artifact directory' );
		$repository_root = self::git( 'rev-parse --show-toplevel', $working_root );
		$repository_root = self::real_directory( $repository_root, 'Git repository root' );
		if ( $repository_root !== $working_root ) {
			throw new InvalidArgumentException( 'Contract Lab package gate working directory must be the Git repository root.' );
		}

		$status = self::git( 'status --porcelain=v1 --untracked-files=all', $repository_root );
		if ( '' !== $status ) {
			throw new InvalidArgumentException( 'Contract Lab package gates require a clean Git repository, including no untracked files.' );
		}

		$source_revision = self::git( 'rev-parse --verify HEAD', $repository_root );
		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/D', $source_revision ) ) {
			throw new RuntimeException( 'Contract Lab package gate Git HEAD is not a full commit identifier.' );
		}

		return new self( $repository_root, $artifact_root, $source_revision, ContractLabArtifactFingerprint::directory( $artifact_root ) );
	}

	public function assert_unchanged(): void {
		$current = self::inspect( $this->repository_root, $this->artifact_root );
		if ( $current->source_revision !== $this->source_revision || $current->artifact_fingerprint !== $this->artifact_fingerprint ) {
			throw new RuntimeException( 'Contract Lab package gate identity changed while the gate commands were running.' );
		}
	}

	public function source_revision(): string {
		return $this->source_revision;
	}

	public function artifact_fingerprint(): string {
		return $this->artifact_fingerprint;
	}

	/**
	 * @return array{source_identity: string, repository_clean: bool, artifact_identity: string}
	 */
	public function to_array(): array {
		return array(
			'source_identity'   => 'git-head',
			'repository_clean'  => true,
			'artifact_identity' => 'directory-sha256',
		);
	}

	private static function real_directory( string $path, string $label ): string {
		$resolved = realpath( $path );
		if ( false === $resolved || ! is_dir( $resolved ) || ! is_readable( $resolved ) ) {
			throw new InvalidArgumentException( sprintf( 'Contract Lab package gate %s must be an existing readable directory.', $label ) );
		}

		return rtrim( $resolved, DIRECTORY_SEPARATOR );
	}

	private static function git( string $arguments, string $working_directory ): string {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$process = proc_open( 'git ' . $arguments, $descriptors, $pipes, $working_directory );
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Contract Lab package gate could not start Git identity inspection.' );
		}
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );
		if ( 0 !== $exit_code ) {
			throw new RuntimeException( sprintf( 'Contract Lab package gate Git identity inspection failed (%d): %s', $exit_code, trim( is_string( $error ) ? $error : '' ) ) );
		}

		return trim( is_string( $output ) ? $output : '' );
	}
}
