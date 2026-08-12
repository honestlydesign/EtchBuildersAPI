<?php
/**
 * Exclusive maintainer-only Contract Lab lock.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\Json;
use RuntimeException;

/**
 * Uses an OS file lock and requires explicit recovery for stale metadata.
 */
final class ContractLabLock {

	public const LOCK_VERSION = '1';

	/**
	 * @var resource|null
	 */
	private mixed $handle;

	/**
	 * @param resource                         $handle
	 * @param array{lock_version: string, owner_id: string, pid: int, started_at: int, stale_after_seconds: int} $metadata
	 */
	private function __construct( mixed $handle, private readonly string $path, private readonly array $metadata ) {
		$this->handle = $handle;
	}

	/**
	 * Acquire the lock, refusing stale metadata until recover_stale() is used.
	 */
	public static function acquire( string $path, int $stale_after_seconds = 900, ?int $now = null ): self {
		$path = self::prepare_path( $path );
		self::assert_stale_after( $stale_after_seconds );
		$handle = self::open_handle( $path );
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			throw new ContractLabLockException( 'busy', 'Contract Lab lock is held by another process.' );
		}

		try {
			$existing = self::read_metadata( $handle );
			if ( array() !== $existing ) {
				$existing = self::assert_metadata( $existing );
				if ( self::is_stale( $existing, $now ?? time() ) ) {
					throw new ContractLabLockException( 'stale', 'Contract Lab lock metadata is stale; use explicit stale-lock recovery.' );
				}
				throw new ContractLabLockException( 'busy', 'Contract Lab lock metadata still identifies an active owner.' );
			}

			$metadata = self::new_metadata( $stale_after_seconds, $now ?? time() );
			self::write_metadata( $handle, $metadata );

			return new self( $handle, $path, $metadata );
		} catch ( RuntimeException $error ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			throw $error;
		}
	}

	/**
	 * Recover only metadata whose owner is no longer alive and whose age is
	 * beyond its declared stale threshold.
	 */
	public static function recover_stale( string $path, int $stale_after_seconds = 900, ?int $now = null ): self {
		$path = self::prepare_path( $path );
		self::assert_stale_after( $stale_after_seconds );
		$handle = self::open_handle( $path );
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			throw new ContractLabLockException( 'busy', 'Contract Lab lock is held by another process and cannot be recovered.' );
		}

		try {
			$metadata = self::read_metadata( $handle );
			if ( array() === $metadata ) {
				throw new ContractLabLockException( 'invalid', 'Contract Lab lock has no recoverable stale metadata.' );
			}
			$metadata = self::assert_metadata( $metadata );
			$timestamp = $now ?? time();
			if ( self::process_is_alive( $metadata['pid'] ) ) {
				throw new ContractLabLockException( 'busy', 'Contract Lab lock owner is still alive; stale recovery is refused.' );
			}
			if ( ! self::is_stale( $metadata, $timestamp ) ) {
				throw new ContractLabLockException( 'stale', 'Contract Lab lock is not old enough for stale recovery.' );
			}

			$recovered = self::new_metadata( $stale_after_seconds, $timestamp );
			self::write_metadata( $handle, $recovered );

			return new self( $handle, $path, $recovered );
		} catch ( RuntimeException $error ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			throw $error;
		}
	}

	public function path(): string {
		return $this->path;
	}

	/**
	 * Whether this lock instance still owns its OS lock handle.
	 */
	public function is_active(): bool {
		return is_resource( $this->handle );
	}

	/**
	 * @return array{lock_version: string, owner_id: string, pid: int, started_at: int, stale_after_seconds: int}
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	public function release(): void {
		if ( ! is_resource( $this->handle ) ) {
			return;
		}
		ftruncate( $this->handle, 0 );
		fflush( $this->handle );
		flock( $this->handle, LOCK_UN );
		fclose( $this->handle );
		$this->handle = null;
	}

	public function __destruct() {
		$this->release();
	}

	private static function prepare_path( string $path ): string {
		$path = ContractLabManifestSafety::normalize_absolute_path( $path, 'Contract Lab lock path' );
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0770, true ) && ! is_dir( $directory ) ) {
			throw new ContractLabLockException( 'io', 'Contract Lab lock directory could not be created.' );
		}

		return $path;
	}

	/**
	 * @return resource
	 */
	private static function open_handle( string $path ) {
		$handle = fopen( $path, 'c+' );
		if ( false === $handle ) {
			throw new ContractLabLockException( 'io', 'Contract Lab lock file could not be opened.' );
		}

		return $handle;
	}

	/**
	 * @param resource $handle
	 * @return array<string, mixed>
	 */
	private static function read_metadata( mixed $handle ): array {
		if ( ! is_resource( $handle ) || 0 !== fseek( $handle, 0 ) ) {
			throw new ContractLabLockException( 'io', 'Contract Lab lock metadata could not be read.' );
		}
		$raw = stream_get_contents( $handle );
		if ( false === $raw || '' === trim( $raw ) ) {
			return array();
		}
		try {
			$decoded = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new ContractLabLockException( 'invalid', 'Contract Lab lock metadata is not valid JSON.' );
		}
		if ( ! is_array( $decoded ) ) {
			throw new ContractLabLockException( 'invalid', 'Contract Lab lock metadata must be an object.' );
		}

		return $decoded;
	}

	/**
	 * @param resource                                                                 $handle
	 * @param array{lock_version: string, owner_id: string, pid: int, started_at: int, stale_after_seconds: int} $metadata
	 */
	private static function write_metadata( mixed $handle, array $metadata ): void {
		if ( ! is_resource( $handle ) || ! ftruncate( $handle, 0 ) || 0 !== fseek( $handle, 0 ) ) {
			throw new ContractLabLockException( 'io', 'Contract Lab lock metadata could not be prepared.' );
		}
		$json = Json::encode( $metadata );
		if ( '' === $json || false === fwrite( $handle, $json . "\n" ) || ! fflush( $handle ) ) {
			throw new ContractLabLockException( 'io', 'Contract Lab lock metadata could not be written.' );
		}
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array{lock_version: string, owner_id: string, pid: int, started_at: int, stale_after_seconds: int}
	 */
	private static function assert_metadata( array $metadata ): array {
		ContractLabManifestSafety::assert_exact_keys( $metadata, array( 'lock_version', 'owner_id', 'pid', 'started_at', 'stale_after_seconds' ), 'Contract Lab lock metadata' );
		if ( ! is_string( $metadata['lock_version'] ) || self::LOCK_VERSION !== $metadata['lock_version'] || ! is_string( $metadata['owner_id'] ) || ! is_int( $metadata['pid'] ) || ! is_int( $metadata['started_at'] ) || ! is_int( $metadata['stale_after_seconds'] ) || $metadata['pid'] < 0 || $metadata['started_at'] < 0 || $metadata['stale_after_seconds'] < 1 ) {
			throw new ContractLabLockException( 'invalid', 'Contract Lab lock metadata has an unsupported shape.' );
		}
		ContractLabManifestSafety::assert_stable_id( $metadata['owner_id'], 'Contract Lab lock owner ID' );

		return array(
			'lock_version'        => $metadata['lock_version'],
			'owner_id'            => $metadata['owner_id'],
			'pid'                 => $metadata['pid'],
			'started_at'          => $metadata['started_at'],
			'stale_after_seconds' => $metadata['stale_after_seconds'],
		);
	}

	/**
	 * @return array{lock_version: string, owner_id: string, pid: int, started_at: int, stale_after_seconds: int}
	 */
	private static function new_metadata( int $stale_after_seconds, int $started_at ): array {
		$pid = getmypid();
		$pid = false === $pid ? 0 : $pid;

		return array(
			'lock_version'        => self::LOCK_VERSION,
			'owner_id'            => sprintf( 'owner-%d-%d', $pid, hrtime( true ) ),
			'pid'                 => $pid,
			'started_at'          => $started_at,
			'stale_after_seconds' => $stale_after_seconds,
		);
	}

	/**
	 * @param array{lock_version: string, owner_id: string, pid: int, started_at: int, stale_after_seconds: int} $metadata
	 */
	private static function is_stale( array $metadata, int $now ): bool {
		return $now >= $metadata['started_at'] + $metadata['stale_after_seconds'];
	}

	private static function process_is_alive( int $pid ): bool {
		if ( $pid < 1 || ! function_exists( 'posix_kill' ) ) {
			return false;
		}

		return posix_kill( $pid, 0 );
	}

	private static function assert_stale_after( int $stale_after_seconds ): void {
		if ( $stale_after_seconds < 1 ) {
			throw new ContractLabLockException( 'invalid', 'Contract Lab stale-lock threshold must be positive.' );
		}
	}
}
