<?php
/**
 * Contract Lab lock tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabLock;
use HonestlyDesign\EtchBuilders\ContractLabLockException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies exclusive execution and explicit stale-lock recovery.
 */
final class ContractLabLockTest extends TestCase {

	/**
	 * @var array<int, string>
	 */
	private array $temporary_paths = array();

	protected function tearDown(): void {
		foreach ( $this->temporary_paths as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
			$directory = dirname( $path );
			if ( is_dir( $directory ) ) {
				rmdir( $directory );
			}
		}
		$this->temporary_paths = array();
	}

	public function test_lock_is_exclusive_until_explicit_release(): void {
		$path  = $this->path();
		$first = ContractLabLock::acquire( $path, 900, 1000 );

		try {
			ContractLabLock::acquire( $path, 900, 1000 );
			self::fail( 'A second owner must not acquire the Contract Lab lock.' );
		} catch ( ContractLabLockException $error ) {
			self::assertSame( 'busy', $error->reason() );
		}

		self::assertSame( '1', $first->metadata()['lock_version'] );
		$first->release();
		$second = ContractLabLock::acquire( $path, 900, 1001 );
		self::assertSame( 1001, $second->metadata()['started_at'] );
		$second->release();
	}

	public function test_stale_lock_requires_explicit_recovery_and_recovery_rewrites_owner(): void {
		$path = $this->path();
		mkdir( dirname( $path ), 0770, true );
		file_put_contents(
			$path,
			json_encode(
				array(
					'lock_version'        => '1',
					'owner_id'            => 'dead-owner',
					'pid'                 => 99999999,
					'started_at'          => 1,
					'stale_after_seconds' => 10,
				),
				JSON_THROW_ON_ERROR
			) . "\n"
		);

		try {
			ContractLabLock::acquire( $path, 10, 100 );
			self::fail( 'A stale lock must require the explicit recovery path.' );
		} catch ( ContractLabLockException $error ) {
			self::assertSame( 'stale', $error->reason() );
		}

		$recovered = ContractLabLock::recover_stale( $path, 10, 100 );
		self::assertNotSame( 'dead-owner', $recovered->metadata()['owner_id'] );
		self::assertSame( 100, $recovered->metadata()['started_at'] );
		$recovered->release();
	}

	public function test_live_lock_cannot_be_recovered_as_stale(): void {
		$path = $this->path();
		$lock = ContractLabLock::acquire( $path, 1, 100 );

		try {
			ContractLabLock::recover_stale( $path, 1, 10000 );
			self::fail( 'A live lock must not be recovered.' );
		} catch ( ContractLabLockException $error ) {
			self::assertSame( 'busy', $error->reason() );
		} finally {
			$lock->release();
		}
	}

	private function path(): string {
		$path                   = sys_get_temp_dir() . '/contract-lab-lock-' . uniqid( '', true ) . '/lock.json';
		$this->temporary_paths[] = $path;

		return $path;
	}
}
