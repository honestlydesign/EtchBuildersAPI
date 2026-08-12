<?php
/**
 * Entity Style Set tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\ClassStyleSet;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\EntityStyleSet;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use PHPUnit\Framework\TestCase;

/**
 * Proves entity CSS issues class-style references without exposing ID maps.
 */
final class EntityStyleSetTest extends TestCase {

	/**
	 * @var array<int, string>
	 */
	private array $temporary_files = array();

	protected function tearDown(): void {
		foreach ( $this->temporary_files as $file_path ) {
			if ( file_exists( $file_path ) ) {
				unlink( $file_path );
			}
		}

		ClassStyleRegistry::reset_cache();
		Environment::reset();
		Style::reset();
		parent::tearDown();
	}

	public function test_entity_css_registers_owned_definitions_and_issues_an_exact_class_reference(): void {
		$styles     = EntityStyleSet::from_file(
			'component:Hero',
			$this->write_css( '.hero__title { color: red; }' )
		);
		$reference  = $styles->class_reference( '.hero__title' );
		$registered = Style::registered_styles();

		self::assertSame( 'component:Hero', $styles->entity_id() );
		self::assertSame( '.hero__title', $reference->selector() );
		self::assertArrayHasKey( $reference->id(), $registered );
		self::assertSame( 'OhMyIDEtch:entity:component:Hero', $registered[ $reference->id() ]['collection'] );
		self::assertTrue( $registered[ $reference->id() ]['overwrite_on_register'] ?? false );
		self::assertSame( array( $reference->id() ), ClassStyleSet::of( $reference )->ids() );
	}

	public function test_same_owner_reuses_a_persisted_opaque_id_without_writing(): void {
		$persisted = array(
			'opaque-hero-title' => array(
				'selector'   => '.hero__title',
				'collection' => 'OhMyIDEtch:entity:component:Hero',
				'css'        => 'color: blue',
				'type'       => 'class',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );

		$styles    = EntityStyleSet::from_file(
			'component:Hero',
			$this->write_css( '.hero__title { color: red; }' )
		);
		$reference = $styles->class_reference( '.hero__title' );

		self::assertSame( 'opaque-hero-title', $reference->id() );
		self::assertSame( '.hero__title', $reference->selector() );
		self::assertSame( $persisted, Environment::storage()->get( 'etch_styles', array() ) );

		self::assertTrue( Style::register_all() );
		self::assertSame( 'color: red', Environment::storage()->get( 'etch_styles', array() )['opaque-hero-title']['css'] );
	}

	public function test_same_owner_reuses_a_numeric_opaque_id_as_a_string(): void {
		$persisted = array(
			'123' => array(
				'selector'   => '.hero__title',
				'collection' => 'OhMyIDEtch:entity:component:Hero',
				'css'        => 'color: blue',
				'type'       => 'class',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );

		$styles = EntityStyleSet::from_file(
			'component:Hero',
			$this->write_css( '.hero__title { color: red; }' )
		);

		self::assertSame( '123', $styles->class_reference( '.hero__title' )->id() );
		self::assertSame( $persisted, Environment::storage()->get( 'etch_styles', array() ) );
	}

	public function test_persisted_style_owned_by_another_source_is_not_adopted(): void {
		$persisted = array(
			'external-opaque-id' => array(
				'selector'   => '.hero__title',
				'collection' => 'User styles',
				'css'        => 'color: blue',
				'type'       => 'class',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );
		$before = Style::snapshot_state();

		try {
			EntityStyleSet::from_file(
				'component:Hero',
				$this->write_css( '.hero__title { color: red; }' )
			);
			self::fail( 'Entity CSS must not adopt a style owned by another source.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'external-opaque-id', $exception->getMessage() );
			self::assertStringContainsString( 'User styles', $exception->getMessage() );
		}

		self::assertSame( $before, Style::snapshot_state() );
		self::assertSame( $persisted, Environment::storage()->get( 'etch_styles', array() ) );
	}

	public function test_request_local_style_owned_by_another_entity_is_not_adopted(): void {
		Style::new()
			->id( 'header-title-id' )
			->selector( '.shared__title' )
			->css( 'color: blue' )
			->collection( 'OhMyIDEtch:entity:pattern:Header' )
			->overwrite_on_register( true )
			->add();
		$before = Style::snapshot_state();

		try {
			EntityStyleSet::from_file(
				'component:Hero',
				$this->write_css( '.shared__title { color: red; }' )
			);
			self::fail( 'Two entities must not own the same request-local style.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'header-title-id', $exception->getMessage() );
			self::assertStringContainsString( 'pattern:Header', $exception->getMessage() );
		}

		self::assertSame( $before, Style::snapshot_state() );
	}

	public function test_construction_rolls_back_earlier_definitions_when_a_later_owner_check_fails(): void {
		$persisted = array(
			'external-body-id' => array(
				'selector'   => '.hero__body',
				'collection' => 'User styles',
				'css'        => 'color: blue',
				'type'       => 'class',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );
		$before = Style::snapshot_state();

		try {
			EntityStyleSet::from_file(
				'component:Hero',
				$this->write_css(
					'.hero__title { color: red; } .hero__body { color: green; }'
				)
			);
			self::fail( 'A later ownership failure must roll back earlier definitions.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'external-body-id', $exception->getMessage() );
		}

		self::assertSame( $before, Style::snapshot_state() );
		self::assertSame( $persisted, Environment::storage()->get( 'etch_styles', array() ) );
	}

	/**
	 * @dataProvider invalid_class_reference_selectors
	 */
	public function test_class_reference_rejects_non_exact_or_non_class_selectors( string $selector, string $message ): void {
		$styles = EntityStyleSet::from_file(
			'component:Hero',
			$this->write_css( '.hero__title { color: red; } .hero__title:hover { color: blue; } p { margin: 0; }' )
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		$styles->class_reference( $selector );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function invalid_class_reference_selectors(): array {
		return array(
			'bare class name'  => array( 'hero__title', 'exact simple class selector' ),
			'raw style id'     => array( 'opaque-style-id', 'exact simple class selector' ),
			'compound selector' => array( '.hero__title:hover', 'exact simple class selector' ),
			'tag selector'     => array( 'p', 'exact simple class selector' ),
			'whitespace'       => array( ' .hero__title', 'exact simple class selector' ),
			'unknown class'    => array( '.hero__missing', 'does not define class selector' ),
		);
	}

	/**
	 * @dataProvider malformed_entity_ids
	 */
	public function test_entity_identity_must_be_an_exact_stable_type_and_key( string $entity_id ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'type:key' );

		EntityStyleSet::from_file( $entity_id, '/missing-must-not-be-read.css' );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function malformed_entity_ids(): array {
		return array(
			'empty'          => array( '' ),
			'leading space'  => array( ' component:Hero' ),
			'trailing LF'    => array( "component:Hero\n" ),
			'trailing CRLF'  => array( "component:Hero\r\n" ),
			'uppercase type' => array( 'Component:Hero' ),
			'missing type'   => array( ':Hero' ),
			'missing key'    => array( 'component:' ),
			'missing colon'  => array( 'component-Hero' ),
			'extra colon'    => array( 'component:Hero:title' ),
			'path-like key'  => array( 'component:../Hero' ),
			'whitespace key' => array( 'component:Hero title' ),
		);
	}

	public function test_construction_is_read_only_at_the_storage_seam(): void {
		$persisted = array(
			'opaque-hero-title' => array(
				'selector'   => '.hero__title',
				'collection' => 'OhMyIDEtch:entity:component:Hero',
				'css'        => 'color: blue',
				'type'       => 'class',
			),
		);
		$storage = new EntityStyleSetStorage( array( 'etch_styles' => $persisted ) );
		Environment::configure( $storage, new NullMode(), new NullAssetRegistry() );

		$styles = EntityStyleSet::from_file(
			'component:Hero',
			$this->write_css( '.hero__title { color: red; }' )
		);

		self::assertSame( 'opaque-hero-title', $styles->class_reference( '.hero__title' )->id() );
		self::assertSame( 0, $storage->set_calls );
		self::assertSame( 0, $storage->delete_calls );
		self::assertSame( $persisted, $storage->get( 'etch_styles' ) );
	}

	public function test_same_owner_reload_replaces_the_collection_in_current_file_order(): void {
		$first_file = $this->write_css(
			'.hero { display: grid; } .hero__title { color: red; } .hero__body { color: green; }'
		);
		$first     = EntityStyleSet::from_file( 'component:Hero', $first_file );
		$first_ids = array(
			$first->class_reference( '.hero' )->id(),
			$first->class_reference( '.hero__title' )->id(),
			$first->class_reference( '.hero__body' )->id(),
		);

		$second = EntityStyleSet::from_file(
			'component:Hero',
			$this->write_css( '.hero__body { color: lime; } .hero { display: flex; }' )
		);
		$second_ids = array(
			$second->class_reference( '.hero__body' )->id(),
			$second->class_reference( '.hero' )->id(),
		);

		self::assertSame( array( $first_ids[2], $first_ids[0] ), $second_ids );
		self::assertSame( $second_ids, array_map( 'strval', array_keys( Style::registered_styles() ) ) );
		self::assertArrayNotHasKey( $first_ids[1], Style::registered_styles() );

		EntityStyleSet::from_file( 'component:Hero', $this->write_css( '' ) );
		self::assertSame( array(), Style::registered_styles() );
	}

	public function test_same_owner_reload_removes_evicted_claims_without_touching_unrelated_claims(): void {
		Style::new()
			->id( 'evicted-hero-id' )
			->selector( '.shared-selector' )
			->css( 'color: red' )
			->collection( 'OhMyIDEtch:entity:component:Hero' )
			->add();
		Style::new()
			->id( 'active-hero-id' )
			->selector( '.shared-selector' )
			->css( 'color: blue' )
			->collection( 'OhMyIDEtch:entity:component:Hero' )
			->add();
		Style::new()
			->id( 'header-id' )
			->selector( '.header' )
			->css( 'display: flex' )
			->collection( 'OhMyIDEtch:entity:pattern:Header' )
			->add();

		EntityStyleSet::from_file( 'component:Hero', $this->write_css( '' ) );
		$state = Style::snapshot_state();

		self::assertSame( array( 'header-id' ), array_map( 'strval', array_keys( $state['registry'] ) ) );
		self::assertSame( array( 'header-id' ), array_map( 'strval', array_keys( $state['claimed_identities'] ) ) );
	}

	public function test_same_owner_reload_removes_retained_persisted_identity_without_touching_unrelated_retention(): void {
		$persisted = array(
			'hero-old-id' => array(
				'selector'   => '.hero__old',
				'collection' => 'OhMyIDEtch:entity:component:Hero',
				'css'        => 'color: red',
				'type'       => 'class',
			),
			'header-id' => array(
				'selector'   => '.header',
				'collection' => 'OhMyIDEtch:entity:pattern:Header',
				'css'        => 'display: flex',
				'type'       => 'class',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );

		self::assertSame( 'hero-old-id', ClassStyleRegistry::ensure_registered_for_class( 'hero__old' ) );
		self::assertSame( 'header-id', ClassStyleRegistry::ensure_registered_for_class( 'header' ) );

		EntityStyleSet::from_file( 'component:Hero', $this->write_css( '' ) );
		$state = Style::snapshot_state();

		self::assertSame( array( 'header-id' ), Style::retained_persisted_style_ids() );
		self::assertSame( array( 'header-id' ), array_map( 'strval', array_keys( $state['retained_persisted_identities'] ) ) );
		self::assertTrue( Style::register_all() );
		self::assertSame( array( 'header-id' ), array_map( 'strval', array_keys( Environment::storage()->get( 'etch_styles', array() ) ) ) );
	}

	public function test_same_owner_reload_preserves_a_released_style_when_its_payload_changes(): void {
		$persisted = array(
			'hero-old-id' => array(
				'selector'   => '.hero__old',
				'collection' => 'OhMyIDEtch:entity:component:Hero',
				'css'        => 'color: red',
				'type'       => 'class',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );

		self::assertSame( 'hero-old-id', ClassStyleRegistry::ensure_registered_for_class( 'hero__old' ) );
		EntityStyleSet::from_file( 'component:Hero', $this->write_css( '' ) );
		$persisted['hero-old-id']['css'] = 'color: user-customized';
		Environment::storage()->set( 'etch_styles', $persisted );

		self::assertTrue( Style::register_all() );
		self::assertSame( $persisted, Environment::storage()->get( 'etch_styles', array() ) );
	}

	public function test_same_owner_reload_preserves_another_owners_retention_when_the_opaque_id_is_shared(): void {
		$persisted = array(
			'shared-opaque-id' => array(
				'selector'   => '.shared-selector',
				'collection' => 'OhMyIDEtch:entity:pattern:Header',
				'css'        => 'color: red',
				'type'       => 'class',
			),
		);
		Environment::storage()->set( 'etch_styles', $persisted );
		self::assertSame( 'shared-opaque-id', ClassStyleRegistry::ensure_registered_for_class( 'shared-selector' ) );

		Style::new()
			->id( 'shared-opaque-id' )
			->selector( '.shared-selector' )
			->css( 'color: blue' )
			->collection( 'OhMyIDEtch:entity:component:Hero' )
			->add();

		EntityStyleSet::from_file( 'component:Hero', $this->write_css( '' ) );

		self::assertSame( array( 'shared-opaque-id' ), Style::retained_persisted_style_ids() );
		self::assertTrue( Style::register_all() );
		self::assertSame( $persisted, Environment::storage()->get( 'etch_styles', array() ) );
	}

	/**
	 * @dataProvider invalid_entity_css
	 */
	public function test_parser_failures_leave_existing_request_local_state_unchanged( string $css, string $message ): void {
		Style::new()
			->id( 'existing-style-id' )
			->selector( '.existing' )
			->css( 'display: block' )
			->collection( 'OhMyIDEtch:entity:pattern:Existing' )
			->add();
		$before = Style::snapshot_state();

		try {
			EntityStyleSet::from_file( 'component:Hero', $this->write_css( $css ) );
			self::fail( 'Invalid entity CSS must fail closed.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( $message, $exception->getMessage() );
		}

		self::assertSame( $before, Style::snapshot_state() );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function invalid_entity_css(): array {
		return array(
			'root media query'   => array(
				'@media (width > 40rem) { .hero { display: grid; } }',
				'cannot parse root-level @media',
			),
			'duplicate selector' => array(
				'.hero { color: red; } .hero { color: blue; }',
				'Duplicate selector `.hero`',
			),
		);
	}

	private function write_css( string $content ): string {
		$file_path = tempnam( sys_get_temp_dir(), 'entity-style-set-' );
		self::assertIsString( $file_path );
		file_put_contents( $file_path, $content );
		$this->temporary_files[] = $file_path;

		return $file_path;
	}
}

/**
 * Storage spy proving Entity Style Set construction never persists.
 */
final class EntityStyleSetStorage implements StorageInterface {

	public int $set_calls = 0;

	public int $delete_calls = 0;

	/**
	 * @param array<string, mixed> $values Initial values.
	 */
	public function __construct( private array $values = array() ) {
	}

	public function get( string $key, mixed $default = null ): mixed {
		return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
	}

	public function set( string $key, mixed $value ): bool {
		++$this->set_calls;
		$this->values[ $key ] = $value;

		return true;
	}

	public function delete( string $key ): bool {
		++$this->delete_calls;
		unset( $this->values[ $key ] );

		return true;
	}
}
