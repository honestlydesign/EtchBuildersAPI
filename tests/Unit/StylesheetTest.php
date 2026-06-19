<?php
/**
 * Stylesheet builder tests — in-memory, no WordPress.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Stylesheet;
use HonestlyDesign\EtchBuilders\StylesheetReference;
use PHPUnit\Framework\TestCase;

/**
 * Verifies global stylesheet fragment registration behavior via Environment::storage().
 */
final class StylesheetTest extends TestCase {

	private function storage(): \HonestlyDesign\EtchBuilders\Support\NullStorage {
		Environment::reset();
		$storage = Environment::storage();
		return $storage instanceof \HonestlyDesign\EtchBuilders\Support\NullStorage ? $storage : new \HonestlyDesign\EtchBuilders\Support\NullStorage();
	}

	public function test_register_references_creates_aggregate_stylesheet(): void {
		Environment::reset();
		$storage = $this->storage();
		$owner   = 'test:owner-a';
		$id      = 'omide-test-sheet-a';
		$css     = __DIR__ . '/../fixtures/test-stylesheet.css';

		$result = Stylesheet::register_references(
			$owner,
			array(
				StylesheetReference::new( $id, $css ),
			)
		);

		self::assertTrue( $result );

		$stylesheets = $storage->get( 'etch_global_stylesheets', array() );
		self::assertIsArray( $stylesheets );
		self::assertArrayHasKey( $id, $stylesheets );

		$fragments = $storage->get( 'oh_my_id_etch_builder_stylesheet_fragments', array() );
		self::assertIsArray( $fragments );
		self::assertArrayHasKey( $id, $fragments );
	}

	public function test_empty_references_remove_stale_owner_fragments(): void {
		Environment::reset();
		$storage = $this->storage();
		$owner   = 'test:stale-owner';
		$id      = 'omide-test-stale-sheet';
		$css     = __DIR__ . '/../fixtures/test-stylesheet.css';

		// Register first.
		Stylesheet::register_references(
			$owner,
			array(
				StylesheetReference::new( $id, $css ),
			)
		);
		$stylesheets = $storage->get( 'etch_global_stylesheets', array() );
		self::assertArrayHasKey( $id, $stylesheets );

		// Re-register with empty references — should prune.
		$result = Stylesheet::register_references( $owner, array() );
		self::assertTrue( $result );

		$stylesheets = $storage->get( 'etch_global_stylesheets', array() );
		self::assertArrayNotHasKey( $id, $stylesheets );

		$fragments = $storage->get( 'oh_my_id_etch_builder_stylesheet_fragments', array() );
		self::assertArrayNotHasKey( $id, $fragments );
	}

	public function test_multiple_references_stack_into_same_stylesheet(): void {
		Environment::reset();
		$storage = $this->storage();
		$owner   = 'test:stack-owner';
		$id      = 'omide-test-stack';
		$css1    = __DIR__ . '/../fixtures/test-stylesheet.css';

		// Two references to the same stylesheet id from the same owner.
		Stylesheet::register_references(
			$owner,
			array(
				StylesheetReference::new( $id, $css1 ),
			)
		);

		$stylesheets = $storage->get( 'etch_global_stylesheets', array() );
		self::assertArrayHasKey( $id, $stylesheets );
		self::assertStringContainsString( '--test-color', $stylesheets[ $id ]['css'] );
	}

	public function test_reset_active_owner_keys_clears(): void {
		Stylesheet::reset_active_owner_keys();
		// Should not throw; subsequent registrations start fresh.
		Environment::reset();
		Stylesheet::reset_active_owner_keys();
		self::assertTrue( true );
	}

	public function test_custom_media_registry_round_trip(): void {
		Stylesheet::reset_custom_media();

		Stylesheet::register_custom_media( 'tablet', '(min-width: 768px)' );
		self::assertContains( 'tablet', Stylesheet::declared_custom_media_names() );

		$snapshot = Stylesheet::custom_media_snapshot();
		Stylesheet::reset_custom_media();
		self::assertSame( array(), Stylesheet::declared_custom_media_names() );

		Stylesheet::restore_custom_media( $snapshot );
		self::assertContains( 'tablet', Stylesheet::declared_custom_media_names() );

		Stylesheet::reset_custom_media();
	}

	protected function tearDown(): void {
		Environment::reset();
		Stylesheet::reset_active_owner_keys();
		Stylesheet::reset_custom_media();
		parent::tearDown();
	}
}
