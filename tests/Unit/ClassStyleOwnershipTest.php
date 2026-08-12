<?php
/**
 * Explicit class-style ownership tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ClassStyleRegistry;
use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\Exceptions\PersistedStyleIdentityException;
use HonestlyDesign\EtchBuilders\Style;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves selector lookup cannot adopt user/upstream style ownership.
 */
final class ClassStyleOwnershipTest extends TestCase {

	/**
	 * @var array{
	 *     registry: array<array-key, array{selector: string, collection: string, css: string, type: string, readonly?: bool, overwrite_on_register?: bool, name?: string}>,
 *     claimed_identities: array<array-key, array{selector: string, type: string, collection: string}>,
 *     retained_persisted_identities: array<array-key, array{selector: string, type: string, collection: string}>
	 * }
	 */
	private array $style_state;

	private ClassStyleOwnershipStorage $storage;

	protected function setUp(): void {
		parent::setUp();

		$this->style_state = Style::snapshot_state();
		$this->storage     = new ClassStyleOwnershipStorage();

		Style::reset();
		Environment::configure( $this->storage, new NullMode(), new NullAssetRegistry() );
		ClassStyleRegistry::reset_cache();
	}

	protected function tearDown(): void {
		ClassStyleRegistry::reset_cache();
		Environment::reset();
		Style::restore_state( $this->style_state );

		parent::tearDown();
	}

	public function test_external_standalone_style_is_linked_without_adoption_or_persistence_changes(): void {
		$persisted = array(
			'external-opaque-id' => array(
				'selector'       => '.visual-class',
				'collection'     => 'Etch user styles',
				'css'            => 'color:rebeccapurple',
				'type'           => 'class',
				'readonly'       => false,
				'name'           => 'User Visual Class',
				'upstreamCustom' => array( 'preserve' => true ),
			),
		);
		$this->storage->replace_styles( $persisted );

		$style_id = ClassStyleRegistry::ensure_registered_for_class( 'visual-class' );
		$attrs    = $this->extract_block_attrs(
			ElementBlock::new()->tag( 'div' )->class( 'visual-class' )->to_block()->to_string()
		);

		self::assertSame( 'external-opaque-id', $style_id );
		self::assertSame( array( 'external-opaque-id' ), $attrs['styles'] );
		self::assertSame( array(), Style::registered_styles() );
		self::assertTrue( Style::register_all() );
		self::assertSame( $persisted, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
		self::assertSame( 0, $this->storage->delete_calls );
	}

	public function test_explicitly_builder_owned_persisted_style_is_retained_without_rewrite(): void {
		$persisted = array(
			'builder-owned-id' => array(
				'selector'   => '.owned-class',
				'collection' => 'OhMyIDEtch:Auto Classes',
				'css'        => 'display:grid',
				'type'       => 'class',
				'readonly'   => true,
				'name'       => 'Owned Class',
			),
		);
		$this->storage->replace_styles( $persisted );

		self::assertSame( 'builder-owned-id', ClassStyleRegistry::ensure_registered_for_class( 'owned-class' ) );
		self::assertSame( array(), Style::registered_styles() );
		self::assertTrue( Style::register_all() );
		self::assertSame( $persisted, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_linked_external_style_with_legacy_code_prefix_is_not_pruned(): void {
		$persisted = array(
			'omide-user-owned-id' => array(
				'selector'   => '.prefixed-user-class',
				'collection' => 'User styles',
				'css'        => 'font-weight:700',
				'readonly'   => true,
				'customMeta' => array( 'preserve' => true ),
			),
		);
		$this->storage->replace_styles( $persisted );

		self::assertSame(
			'omide-user-owned-id',
			ClassStyleRegistry::ensure_registered_for_class( 'prefixed-user-class' )
		);
		self::assertSame( array(), Style::registered_styles() );

		Style::new()
			->id( 'builder-style' )
			->selector( '.builder-style' )
			->css( 'display:grid' )
			->type( 'class' )
			->collection( 'OhMyIDEtch' )
			->add();

		self::assertTrue( Style::register_all() );

		$stored = $this->storage->get( 'etch_styles' );
		self::assertIsArray( $stored );
		self::assertSame( $persisted['omide-user-owned-id'], $stored['omide-user-owned-id'] );
		self::assertArrayHasKey( 'builder-style', $stored );
		self::assertSame( 1, $this->storage->set_calls );
	}

	public function test_explicit_lookup_snapshot_is_observational_for_standalone_style(): void {
		$snapshot = array(
			'snapshot-opaque-id' => array(
				'selector'   => '.snapshot-class',
				'collection' => 'Snapshot source',
				'css'        => 'color:green',
				'type'       => 'class',
			),
		);
		$before   = Style::snapshot_state();

		self::assertSame(
			'snapshot-opaque-id',
			ClassStyleRegistry::resolve_style_id_for_class( 'snapshot-class', $snapshot )
		);
		self::assertSame( $before, Style::snapshot_state() );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_retained_external_style_does_not_bypass_same_id_identity_conflicts(): void {
		$persisted = array(
			'external-opaque-id' => array(
				'selector'   => '.visual-class',
				'collection' => 'User styles',
				'css'        => 'color:green',
				'type'       => 'class',
			),
		);
		$this->storage->replace_styles( $persisted );

		self::assertSame( 'external-opaque-id', ClassStyleRegistry::ensure_registered_for_class( 'visual-class' ) );

		try {
			Style::new()
				->id( 'external-opaque-id' )
				->selector( '.different-class' )
				->css( 'color:red' )
				->type( 'class' )
				->add();

			self::fail( 'Expected the persisted style identity conflict to throw.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'external-opaque-id', $exception->getMessage() );
		}

		self::assertSame( array(), Style::registered_styles() );
		self::assertSame( $persisted, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_register_all_rejects_a_concurrent_identity_change_for_a_linked_external_style(): void {
		$this->storage->replace_styles(
			array(
				'external-opaque-id' => array(
					'selector'   => '.visual-class',
					'collection' => 'User styles',
					'css'        => 'color:green',
					'type'       => 'class',
				),
			)
		);

		self::assertSame( 'external-opaque-id', ClassStyleRegistry::ensure_registered_for_class( 'visual-class' ) );

		$concurrent = array(
			'external-opaque-id' => array(
				'selector'   => '.changed-class',
				'collection' => 'User styles',
				'css'        => 'color:purple',
				'type'       => 'class',
			),
		);
		$this->storage->replace_styles( $concurrent );

		try {
			Style::register_all();
			self::fail( 'Expected the linked persisted style identity change to throw.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'external-opaque-id', $exception->getMessage() );
		}

		self::assertSame( $concurrent, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	/**
	 * @dataProvider malformed_persisted_type_provider
	 */
	public function test_initial_link_rejects_a_present_malformed_persisted_type( mixed $type ): void {
		$persisted = array(
			'external-opaque-id' => array(
				'selector'   => '.visual-class',
				'collection' => 'User styles',
				'css'        => 'color:green',
				'type'       => $type,
			),
		);
		$this->storage->replace_styles( $persisted );

		try {
			ClassStyleRegistry::ensure_registered_for_class( 'visual-class' );
			self::fail( 'Expected a present malformed persisted type to be rejected.' );
		} catch ( PersistedStyleIdentityException $exception ) {
			self::assertStringContainsString( 'type', $exception->getMessage() );
		}

		self::assertSame( array(), Style::registered_styles() );
		self::assertSame( $persisted, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	/**
	 * @dataProvider malformed_persisted_type_provider
	 */
	public function test_explicit_snapshot_rejects_a_present_malformed_type_without_state_changes( mixed $type ): void {
		$snapshot = array(
			'external-opaque-id' => array(
				'selector'   => '.visual-class',
				'collection' => 'Snapshot source',
				'css'        => 'color:green',
				'type'       => $type,
			),
		);
		$before = Style::snapshot_state();

		try {
			ClassStyleRegistry::resolve_style_id_for_class( 'visual-class', $snapshot );
			self::fail( 'Expected a present malformed snapshot type to be rejected.' );
		} catch ( PersistedStyleIdentityException $exception ) {
			self::assertStringContainsString( 'type', $exception->getMessage() );
		}

		self::assertSame( $before, Style::snapshot_state() );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_element_block_propagates_a_persisted_identity_failure(): void {
		$persisted = array(
			'omide-user-owned-id' => array(
				'selector'   => '.visual-class',
				'collection' => 'User styles',
				'css'        => 'color:green',
				'type'       => null,
			),
		);
		$this->storage->replace_styles( $persisted );

		try {
			ElementBlock::new()->tag( 'div' )->class( 'visual-class' );
			self::fail( 'Expected ElementBlock class linkage to propagate the persisted identity failure.' );
		} catch ( PersistedStyleIdentityException $exception ) {
			self::assertStringContainsString( 'type', $exception->getMessage() );
		}

		self::assertSame( array(), Style::registered_styles() );
		self::assertSame( $persisted, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_element_block_propagates_a_fallback_style_id_collision(): void {
		$persisted = array(
			'visual-class' => array(
				'selector'   => '.other-class',
				'collection' => 'User styles',
				'css'        => 'color:green',
				'type'       => 'class',
			),
		);
		$this->storage->replace_styles( $persisted );

		try {
			ElementBlock::new()->tag( 'div' )->class( 'visual-class' );
			self::fail( 'Expected ElementBlock class linkage to propagate the occupied style ID.' );
		} catch ( PersistedStyleIdentityException $exception ) {
			self::assertStringContainsString( 'visual-class', $exception->getMessage() );
		}

		self::assertSame( array(), Style::registered_styles() );
		self::assertSame( $persisted, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_active_numeric_opaque_id_is_linked_and_preserved_as_a_string(): void {
		$persisted = array(
			'123' => array(
				'selector'   => '.visual-class',
				'collection' => 'User styles',
				'css'        => 'color:green',
				'type'       => 'class',
			),
		);
		$this->storage->replace_styles( $persisted );

		$markup = ElementBlock::new()
			->tag( 'div' )
			->class( 'visual-class' )
			->to_block()
			->to_string();
		$attrs = $this->extract_block_attrs( $markup );

		self::assertSame( array( '123' ), $attrs['styles'] );
		self::assertSame( array(), Style::registered_styles() );
		self::assertTrue( Style::register_all() );
		self::assertSame( $persisted, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_explicit_snapshot_numeric_opaque_id_resolves_as_a_string_without_state_changes(): void {
		$snapshot = array(
			'123' => array(
				'selector' => '.visual-class',
				'type'     => 'class',
			),
		);
		$before = Style::snapshot_state();

		self::assertSame( '123', ClassStyleRegistry::resolve_style_id_for_class( 'visual-class', $snapshot ) );
		self::assertSame( $before, Style::snapshot_state() );
	}

	public function test_request_local_numeric_opaque_id_resolves_as_a_string(): void {
		Style::new()
			->id( '123' )
			->selector( '.visual-class' )
			->css( 'color:green' )
			->type( 'class' )
			->add();

		self::assertSame( '123', ClassStyleRegistry::resolve_style_id_for_class( 'visual-class' ) );
	}

	public function test_exact_selector_resolution_validates_candidates_after_the_first_match(): void {
		$snapshot = array(
			'first-valid-id' => array(
				'selector' => '.visual-class',
				'type'     => 'class',
			),
			'later-malformed-id' => array(
				'selector' => '.visual-class',
				'type'     => null,
			),
		);
		$before = Style::snapshot_state();

		try {
			ClassStyleRegistry::resolve_style_id_for_class( 'visual-class', $snapshot );
			self::fail( 'Expected every exact-selector candidate to be validated.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'type', $exception->getMessage() );
		}

		self::assertSame( $before, Style::snapshot_state() );
	}

	public function test_exact_selector_resolution_rejects_multiple_valid_ids(): void {
		$snapshot = array(
			'first-valid-id' => array(
				'selector' => '.visual-class',
				'type'     => 'class',
			),
			'second-valid-id' => array(
				'selector' => '.visual-class',
				'type'     => 'class',
			),
		);
		$before = Style::snapshot_state();

		try {
			ClassStyleRegistry::resolve_style_id_for_class( 'visual-class', $snapshot );
			self::fail( 'Expected duplicate exact-selector IDs to fail closed.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'first-valid-id', $exception->getMessage() );
			self::assertStringContainsString( 'second-valid-id', $exception->getMessage() );
		}

		self::assertSame( $before, Style::snapshot_state() );
	}

	/**
	 * @dataProvider valid_non_class_type_provider
	 */
	public function test_exact_selector_with_a_non_class_type_fails_instead_of_creating_a_destructive_alias( string $type ): void {
		$persisted = array(
			'external-opaque-id' => array(
				'selector'   => '.visual-class',
				'collection' => 'User styles',
				'css'        => 'color:green',
				'type'       => $type,
			),
		);
		$this->storage->replace_styles( $persisted );

		try {
			ClassStyleRegistry::ensure_registered_for_class( 'visual-class' );
			self::fail( 'Expected an exact selector with a non-class type to fail closed.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'type=class', $exception->getMessage() );
		}

		self::assertSame( array(), Style::registered_styles() );
		self::assertSame( $persisted, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_register_all_rejects_a_concurrent_change_from_missing_to_malformed_type(): void {
		$this->storage->replace_styles(
			array(
				'external-opaque-id' => array(
					'selector'   => '.visual-class',
					'collection' => 'User styles',
					'css'        => 'color:green',
				),
			)
		);
		self::assertSame( 'external-opaque-id', ClassStyleRegistry::ensure_registered_for_class( 'visual-class' ) );

		$concurrent = array(
			'external-opaque-id' => array(
				'selector'   => '.visual-class',
				'collection' => 'User styles',
				'css'        => 'color:purple',
				'type'       => null,
			),
		);
		$this->storage->replace_styles( $concurrent );

		try {
			Style::register_all();
			self::fail( 'Expected the concurrent malformed type to be rejected.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'type', $exception->getMessage() );
		}

		self::assertSame( $concurrent, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_add_rejects_an_identity_that_conflicts_with_an_earlier_retained_link(): void {
		$this->storage->replace_styles(
			array(
				'external-opaque-id' => array(
					'selector'   => '.visual-class',
					'collection' => 'User styles',
					'css'        => 'color:green',
					'type'       => 'class',
				),
			)
		);
		self::assertSame( 'external-opaque-id', ClassStyleRegistry::ensure_registered_for_class( 'visual-class' ) );

		$replacement = array(
			'external-opaque-id' => array(
				'selector'   => '.replacement-class',
				'collection' => 'User styles',
				'css'        => 'color:purple',
				'type'       => 'class',
			),
		);
		$this->storage->replace_styles( $replacement );

		try {
			Style::new()
				->id( 'external-opaque-id' )
				->selector( '.replacement-class' )
				->css( 'color:red' )
				->type( 'class' )
				->add();
			self::fail( 'Expected the retained request-local identity to reject the replacement.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'visual-class', $exception->getMessage() );
		}

		self::assertSame( array(), Style::registered_styles() );
		self::assertSame( $replacement, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_retain_rejects_an_identity_that_conflicts_with_an_earlier_claim(): void {
		Style::new()
			->id( 'external-opaque-id' )
			->selector( '.claimed-class' )
			->css( 'color:green' )
			->type( 'class' )
			->add();

		$this->storage->replace_styles(
			array(
				'external-opaque-id' => array(
					'selector'   => '.replacement-class',
					'collection' => 'User styles',
					'css'        => 'color:purple',
					'type'       => 'class',
				),
			)
		);
		$before = Style::snapshot_state();

		try {
			Style::retain_linked_persisted_style( 'external-opaque-id', '.replacement-class', 'class' );
			self::fail( 'Expected the claimed request-local identity to reject the retained replacement.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'claimed-class', $exception->getMessage() );
		}

		self::assertSame( $before, Style::snapshot_state() );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_register_all_rejects_a_new_registry_id_for_a_retained_selector(): void {
		$persisted = array(
			'external-opaque-id' => array(
				'selector'   => '.visual-class',
				'collection' => 'User styles',
				'css'        => 'color:green',
				'type'       => 'class',
			),
		);
		$this->storage->replace_styles( $persisted );

		self::assertSame( 'external-opaque-id', ClassStyleRegistry::ensure_registered_for_class( 'visual-class' ) );

		Style::new()
			->id( 'builder-duplicate-id' )
			->selector( '.visual-class' )
			->css( 'color:red' )
			->type( 'class' )
			->add();

		try {
			Style::register_all();
			self::fail( 'Expected the retained selector collision to throw.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'visual-class', $exception->getMessage() );
		}

		self::assertSame( $persisted, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_register_all_rejects_a_concurrent_persisted_id_for_a_retained_selector(): void {
		$original = array(
			'external-opaque-id' => array(
				'selector'   => '.visual-class',
				'collection' => 'User styles',
				'css'        => 'color:green',
				'type'       => 'class',
			),
		);
		$this->storage->replace_styles( $original );
		self::assertSame( 'external-opaque-id', ClassStyleRegistry::ensure_registered_for_class( 'visual-class' ) );

		$concurrent = $original;
		$concurrent['concurrent-duplicate-id'] = array(
			'selector'   => '.visual-class',
			'collection' => 'Other source',
			'css'        => 'color:purple',
			'type'       => 'class',
		);
		$this->storage->replace_styles( $concurrent );

		Style::new()
			->id( 'unrelated-builder-style' )
			->selector( '.unrelated-builder-style' )
			->css( 'display:grid' )
			->type( 'class' )
			->add();

		try {
			Style::register_all();
			self::fail( 'Expected the concurrent persisted selector collision to throw.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( 'visual-class', $exception->getMessage() );
		}

		self::assertSame( $concurrent, $this->storage->get( 'etch_styles' ) );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_explicit_lookup_snapshot_does_not_create_compound_only_alias(): void {
		$snapshot = array(
			'compound-id' => array(
				'selector'   => '.wrapper .snapshot-class',
				'collection' => 'Snapshot source',
				'css'        => 'color:green',
				'type'       => 'class',
			),
		);
		$before   = Style::snapshot_state();

		self::assertNull( ClassStyleRegistry::resolve_style_id_for_class( 'snapshot-class', $snapshot ) );
		self::assertSame( $before, Style::snapshot_state() );
		self::assertSame( 0, $this->storage->set_calls );
	}

	public function test_explicit_snapshot_compound_lookup_does_not_pollute_active_lookup_cache(): void {
		$snapshot = array(
			'compound-id' => array(
				'selector'   => '.wrapper .cache-safe-class',
				'collection' => 'Snapshot source',
				'css'        => 'color:green',
				'type'       => 'class',
			),
		);

		self::assertNull( ClassStyleRegistry::resolve_style_id_for_class( 'cache-safe-class', $snapshot ) );
		self::assertNull( ClassStyleRegistry::resolve_style_id_for_class( 'cache-safe-class' ) );
		self::assertSame( array(), Style::registered_styles() );
	}

	public function test_genuinely_missing_class_still_creates_builder_owned_placeholder(): void {
		self::assertSame( 'missing-class', ClassStyleRegistry::ensure_registered_for_class( 'missing-class' ) );

		$registered = Style::registered_styles();
		self::assertSame( '.missing-class', $registered['missing-class']['selector'] );
		self::assertSame( 'OhMyIDEtch', $registered['missing-class']['collection'] );
		self::assertTrue( $registered['missing-class']['readonly'] ?? false );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public function malformed_persisted_type_provider(): array {
		return array(
			'null'            => array( null ),
			'false'           => array( false ),
			'array'           => array( array() ),
			'empty string'    => array( '' ),
			'unknown string'  => array( 'unknown' ),
			'wrong case type' => array( 'CLASS' ),
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function valid_non_class_type_provider(): array {
		return array(
			'id'        => array( 'id' ),
			'tag'       => array( 'tag' ),
			'element'   => array( 'element' ),
			'attribute' => array( 'attribute' ),
			'custom'    => array( 'custom' ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function extract_block_attrs( string $markup ): array {
		$blocks = parse_blocks( $markup );
		$attrs  = $blocks[0]['attrs'] ?? array();

		self::assertIsArray( $attrs );
		return $attrs;
	}
}

/**
 * Mutable storage spy for style-ownership tests.
 */
final class ClassStyleOwnershipStorage implements StorageInterface {

	public int $set_calls = 0;

	public int $delete_calls = 0;

	/**
	 * @var array<string, mixed>
	 */
	private array $values = array();

	/**
	 * @param array<array-key, mixed> $styles Persisted styles.
	 */
	public function replace_styles( array $styles ): void {
		$this->values['etch_styles'] = $styles;
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
