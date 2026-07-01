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

	private const FIXTURE_CSS = __DIR__ . '/../fixtures/test-global-stylesheet.css';

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
		$css     = self::FIXTURE_CSS;

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
		$css     = self::FIXTURE_CSS;

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
		$css1    = self::FIXTURE_CSS;

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

	public function test_register_custom_media_persists_custom_media_definitions_stylesheet(): void {
		Environment::reset();
		$storage = $this->storage();
		Stylesheet::reset_custom_media();

		$result = Stylesheet::register_custom_media( 'tablet', '(min-width: 768px)' );

		self::assertTrue( $result );

		$stylesheets = $storage->get( 'etch_global_stylesheets', array() );
		self::assertIsArray( $stylesheets );
		self::assertArrayHasKey( 'etch-builders-custom-media', $stylesheets );
		self::assertSame( 'Custom Media Definitions', $stylesheets['etch-builders-custom-media']['name'] );
		self::assertSame( '@custom-media', $stylesheets['etch-builders-custom-media']['type'] );
		self::assertSame( "@custom-media --tablet (min-width: 768px);\n", $stylesheets['etch-builders-custom-media']['css'] );
		self::assertContains( 'tablet', Stylesheet::declared_custom_media_names() );
	}

	public function test_register_custom_media_rejects_invalid_inputs(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Custom media name must match /^[A-Za-z0-9_-]+$/.' );

		Stylesheet::register_custom_media( 'tablet;body', '(min-width: 768px)' );
	}

	public function test_sync_custom_media_definitions_prunes_builder_owned_entry_when_empty(): void {
		Environment::reset();
		$storage = $this->storage();
		Stylesheet::reset_custom_media();
		$storage->set(
			'etch_global_stylesheets',
			array(
				'default'                    => array(
					'name' => 'Main',
					'css'  => 'body { color: black; }',
					'type' => 'default',
				),
				'etch-builders-custom-media' => array(
					'name' => 'Custom Media Definitions',
					'css'  => "@custom-media --tablet (min-width: 768px);\n",
					'type' => '@custom-media',
				),
			)
		);

		$result = Stylesheet::sync_custom_media_definitions();

		self::assertTrue( $result );
		$stylesheets = $storage->get( 'etch_global_stylesheets', array() );
		self::assertIsArray( $stylesheets );
		self::assertArrayHasKey( 'default', $stylesheets );
		self::assertArrayNotHasKey( 'etch-builders-custom-media', $stylesheets );
	}

	public function test_stylesheet_css_rejects_custom_media_declarations(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Use Stylesheet::register_custom_media().' );

		Stylesheet::new()
			->id( 'bad-custom-media' )
			->name( 'Bad Custom Media' )
			->css( '@custom-media --tablet (min-width: 768px);' )
			->register();
	}

	protected function tearDown(): void {
		Environment::reset();
		Stylesheet::reset_active_owner_keys();
		Stylesheet::reset_custom_media();
		parent::tearDown();
	}
}
