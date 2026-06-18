<?php
/**
 * NullStorage tests.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit\Support;

use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Support\NullStorage;
use PHPUnit\Framework\TestCase;

/**
 * Verifies NullStorage honors StorageInterface and behaves as an in-memory store.
 */
final class NullStorageTest extends TestCase {

	public function test_implements_storage_interface(): void {
		self::assertInstanceOf( StorageInterface::class, new NullStorage() );
	}

	public function test_get_returns_default_when_key_absent(): void {
		$storage = new NullStorage();
		self::assertNull( $storage->get( 'missing' ) );
		self::assertSame( array(), $storage->get( 'missing', array() ) );
		self::assertSame( 'fallback', $storage->get( 'missing', 'fallback' ) );
	}

	public function test_set_then_get_roundtrip(): void {
		$storage = new NullStorage();
		self::assertTrue( $storage->set( 'etch_styles', array( 'a' => 1 ) ) );
		self::assertSame( array( 'a' => 1 ), $storage->get( 'etch_styles' ) );
	}

	public function test_set_overwrites_existing(): void {
		$storage = new NullStorage();
		$storage->set( 'k', 'first' );
		$storage->set( 'k', 'second' );
		self::assertSame( 'second', $storage->get( 'k' ) );
	}

	public function test_delete_removes_key(): void {
		$storage = new NullStorage();
		$storage->set( 'k', 'v' );
		self::assertTrue( $storage->delete( 'k' ) );
		self::assertNull( $storage->get( 'k' ) );
	}

	public function test_delete_returns_true_even_when_key_absent(): void {
		$storage = new NullStorage();
		self::assertTrue( $storage->delete( 'never_set' ) );
	}
}
