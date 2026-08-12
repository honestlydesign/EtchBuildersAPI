<?php
/**
 * Schema-backed component slot tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\Block;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\Contracts\ComponentContractCatalogProviderInterface;
use HonestlyDesign\EtchBuilders\Contracts\ComponentRefResolverInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\SlotContentBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\SlotPlaceholderBlock;
use HonestlyDesign\EtchBuilders\Support\AcceptedComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\InMemoryComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
use HonestlyDesign\EtchBuilders\Support\NullMode;
use HonestlyDesign\EtchBuilders\Support\NullStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies that exact component contracts own instance slot boundaries.
 */
final class SchemaBackedComponentSlotTest extends TestCase {

	protected function tearDown(): void {
		Environment::reset();
		parent::tearDown();
	}

	/**
	 * @dataProvider catalog_provider
	 */
	public function test_known_filled_slots_serialize_the_existing_etch_wire( string $provider_kind ): void {
		$this->configure( $this->provider( $provider_kind ) );

		$markup = ComponentBlock::for_key( 'FeatureCard' )
			->slot( 'default', Block::new_self_closing( 'text', array( 'content' => 'Body' ) ) )
			->slot(
				'actions',
				Block::new_self_closing( 'text', array( 'content' => 'Primary' ) ),
				Block::new_self_closing( 'text', array( 'content' => 'Secondary' ) )
			)
			->to_block()
			->to_string();

		self::assertSame(
			'<!-- wp:etch/component {"ref":77,"attributes":[]} -->'
			. '<!-- wp:etch/slot-content {"name":"default"} -->'
			. '<!-- wp:etch/text {"content":"Body"} /-->'
			. '<!-- /wp:etch/slot-content -->'
			. '<!-- wp:etch/slot-content {"name":"actions"} -->'
			. '<!-- wp:etch/text {"content":"Primary"} /-->'
			. '<!-- wp:etch/text {"content":"Secondary"} /-->'
			. '<!-- /wp:etch/slot-content -->'
			. '<!-- /wp:etch/component -->',
			$markup
		);
	}

	public function test_slot_snapshot_preserves_wordpress_core_block_identity(): void {
		$this->configure( $this->provider( 'memory' ) );

		$markup = ComponentBlock::for_key( 'FeatureCard' )
			->slot( 'default', Block::new_core( 'paragraph', array( 'content' => 'Core' ) ) )
			->to_block()
			->to_string();

		self::assertStringContainsString( '<!-- wp:paragraph {"content":"Core"} /-->', $markup );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function catalog_provider(): array {
		return array(
			'in-memory catalog' => array( 'memory' ),
			'accepted catalog'  => array( 'accepted' ),
		);
	}

	public function test_explicit_empty_default_and_named_slots_use_the_same_empty_wire(): void {
		$this->configure( $this->provider( 'memory' ) );

		$markup = ComponentBlock::for_key( 'FeatureCard' )
			->empty_default_slot()
			->empty_slot( 'actions' )
			->to_block()
			->to_string();

		self::assertSame(
			'<!-- wp:etch/component {"ref":77,"attributes":[]} -->'
			. '<!-- wp:etch/slot-content {"name":"default"} --><!-- /wp:etch/slot-content -->'
			. '<!-- wp:etch/slot-content {"name":"actions"} --><!-- /wp:etch/slot-content -->'
			. '<!-- /wp:etch/component -->',
			$markup
		);
	}

	public function test_default_slot_convenience_requires_content_and_uses_the_default_contract_name(): void {
		$this->configure( $this->provider( 'memory' ) );

		$markup = ComponentBlock::for_key( 'FeatureCard' )
			->default_slot( Block::new_self_closing( 'text', array( 'content' => 'Body' ) ) )
			->to_block()
			->to_string();

		self::assertStringContainsString( '<!-- wp:etch/slot-content {"name":"default"} -->', $markup );
	}

	public function test_numeric_looking_exact_slot_name_remains_a_string_on_the_wire(): void {
		$this->configure( $this->provider( 'memory' ) );

		$markup = ComponentBlock::for_key( 'FeatureCard' )
			->empty_slot( '0' )
			->to_block()
			->to_string();

		self::assertStringContainsString( '<!-- wp:etch/slot-content {"name":"0"} -->', $markup );
	}

	/**
	 * @dataProvider invalid_slot_name
	 */
	public function test_unknown_or_inexact_slot_names_fail_closed( string $name ): void {
		$this->configure( $this->provider( 'memory' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'has no exact slot named' );

		ComponentBlock::for_key( 'FeatureCard' )
			->empty_slot( $name );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function invalid_slot_name(): array {
		return array(
			'unknown'            => array( 'invented' ),
			'empty'              => array( '' ),
			'whitespace padded'  => array( ' default ' ),
			'case mismatch'      => array( 'Default' ),
		);
	}

	public function test_duplicate_filled_or_empty_assignments_fail_instead_of_using_etch_first_wins(): void {
		$this->configure( $this->provider( 'memory' ) );

		$component = ComponentBlock::for_key( 'FeatureCard' )
			->slot( 'actions', Block::new_self_closing( 'text' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'already has a schema-backed assignment' );

		$component->empty_slot( 'actions' );
	}

	public function test_schema_backed_slots_require_an_exact_keyed_component_contract(): void {
		$this->configure( $this->provider( 'memory' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'requires for_key() or ref_by_key()' );

		ComponentBlock::new()
			->ref( 77 )
			->empty_slot( 'default' );
	}

	/**
	 * @dataProvider raw_direct_component_slot_boundary
	 */
	public function test_raw_and_schema_backed_direct_slot_boundaries_cannot_be_mixed( Block $raw_boundary ): void {
		$this->configure( $this->provider( 'memory' ) );

		$component = ComponentBlock::for_key( 'FeatureCard' )
			->child( $raw_boundary )
			->empty_slot( 'default' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot mix schema-backed slots with legacy direct component children' );

		$component->to_block();
	}

	public function test_schema_backed_slots_reject_ordinary_unassigned_direct_component_children(): void {
		$this->configure( $this->provider( 'memory' ) );

		$component = ComponentBlock::for_key( 'FeatureCard' )
			->child( Block::new_self_closing( 'text', array( 'content' => 'Silently lost by Etch' ) ) )
			->empty_slot( 'default' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'cannot mix schema-backed slots with legacy direct component children' );

		$component->to_block();
	}

	/**
	 * @return array<string, array{Block}>
	 */
	public function raw_direct_component_slot_boundary(): array {
		return array(
			'instance slot content' => array( SlotContentBlock::new()->name( 'actions' )->to_block() ),
			'definition placeholder' => array( SlotPlaceholderBlock::new()->name( 'default' )->to_block() ),
		);
	}

	/**
	 * @dataProvider raw_slot_boundary
	 */
	public function test_raw_slot_boundaries_cannot_be_smuggled_in_as_golden_path_slot_content( Block $raw_boundary ): void {
		$this->configure( $this->provider( 'memory' ) );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'raw slot-content or slot-placeholder boundary' );

		ComponentBlock::for_key( 'FeatureCard' )
			->slot( 'default', $raw_boundary );
	}

	/**
	 * @dataProvider raw_slot_boundary
	 */
	public function test_wrapped_raw_slot_boundaries_cannot_be_smuggled_into_golden_path_content( Block $raw_boundary ): void {
		$this->configure( $this->provider( 'memory' ) );

		$wrapper = Block::new( 'element' )
			->add_child( $raw_boundary );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'raw slot-content or slot-placeholder boundary' );

		ComponentBlock::for_key( 'FeatureCard' )
			->slot( 'default', $wrapper );
	}

	public function test_slot_assignment_snapshots_a_retained_block_before_later_mutation(): void {
		$this->configure( $this->provider( 'memory' ) );

		$wrapper   = Block::new( 'element' );
		$component = ComponentBlock::for_key( 'FeatureCard' )
			->slot( 'default', $wrapper );

		$wrapper->add_child( SlotPlaceholderBlock::new()->name( 'default' )->to_block() );
		$markup = $component->to_block()->to_string();

		self::assertStringNotContainsString( 'etch/slot-placeholder', $markup );
	}

	public function test_compiled_slot_does_not_alias_a_mutable_caller_owned_block(): void {
		$this->configure( $this->provider( 'memory' ) );

		$wrapper   = Block::new( 'element' );
		$component = ComponentBlock::for_key( 'FeatureCard' )
			->slot( 'default', $wrapper )
			->to_block();

		$wrapper->add_child( SlotPlaceholderBlock::new()->name( 'default' )->to_block() );
		$markup = $component->to_string();

		self::assertStringNotContainsString( 'etch/slot-placeholder', $markup );
		self::assertSame( 1, substr_count( $markup, '<!-- wp:etch/element' ) );
	}

	public function test_cyclic_slot_content_graph_fails_closed_during_assignment(): void {
		$this->configure( $this->provider( 'memory' ) );

		$cycle = Block::new( 'element' );
		$cycle->add_child( $cycle );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'finite, non-recursive block tree' );

		ComponentBlock::for_key( 'FeatureCard' )
			->slot( 'default', $cycle );
	}

	public function test_cycle_through_a_nested_component_ownership_boundary_fails_closed(): void {
		$this->configure( $this->provider( 'memory' ) );

		$wrapper = Block::new( 'element' );
		$nested  = ComponentBlock::for_key( 'FeatureCard' )
			->empty_slot( 'actions' )
			->to_block();
		$wrapper->add_child( $nested );
		$nested->add_child( $wrapper );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'finite, non-recursive block tree' );

		ComponentBlock::for_key( 'FeatureCard' )
			->slot( 'default', $wrapper );
	}

	/**
	 * @return array<string, array{Block}>
	 */
	public function raw_slot_boundary(): array {
		return array(
			'raw slot content' => array( SlotContentBlock::new()->name( 'default' )->to_block() ),
			'raw placeholder'  => array( SlotPlaceholderBlock::new()->name( 'default' )->to_block() ),
		);
	}

	public function test_legacy_raw_slot_content_remains_available_outside_the_golden_path(): void {
		$markup = ComponentBlock::new()
			->ref( 77 )
			->child( SlotContentBlock::new()->name( 'legacy' )->to_block() )
			->to_block()
			->to_string();

		self::assertStringContainsString( '<!-- wp:etch/slot-content {"name":"legacy"} -->', $markup );
	}

	public function test_nested_component_with_its_own_schema_backed_slots_is_valid_slot_content(): void {
		$this->configure( $this->provider( 'memory' ) );

		$nested = ComponentBlock::for_key( 'FeatureCard' )
			->empty_slot( 'actions' )
			->to_block();
		$markup = ComponentBlock::for_key( 'FeatureCard' )
			->slot( 'default', $nested )
			->to_block()
			->to_string();

		self::assertSame( 2, substr_count( $markup, '<!-- wp:etch/slot-content' ) );
		self::assertStringContainsString( '<!-- wp:etch/component {"ref":77,"attributes":[]} --><!-- wp:etch/slot-content {"name":"actions"}', $markup );
	}

	public function test_wrapped_nested_component_remains_an_ownership_boundary_for_its_own_slots(): void {
		$this->configure( $this->provider( 'memory' ) );

		$nested = ComponentBlock::for_key( 'FeatureCard' )
			->empty_slot( 'actions' )
			->to_block();
		$wrapper = Block::new( 'element' )
			->add_child( $nested );
		$markup = ComponentBlock::for_key( 'FeatureCard' )
			->slot( 'default', $wrapper )
			->to_block()
			->to_string();

		self::assertSame( 2, substr_count( $markup, '<!-- wp:etch/slot-content' ) );
		self::assertStringContainsString( '<!-- wp:etch/element --><!-- wp:etch/component', $markup );
	}

	public function test_safe_slot_method_signatures_require_typed_block_content(): void {
		$slot       = new ReflectionMethod( ComponentBlock::class, 'slot' );
		$parameters = $slot->getParameters();

		self::assertCount( 3, $parameters );
		self::assertSame( 'string', (string) $parameters[0]->getType() );
		self::assertSame( Block::class, (string) $parameters[1]->getType() );
		self::assertSame( Block::class, (string) $parameters[2]->getType() );
		self::assertTrue( $parameters[2]->isVariadic() );
	}

	private function configure( ComponentContractCatalogProviderInterface $provider ): void {
		Environment::configure(
			new NullStorage(),
			new NullMode(),
			new NullAssetRegistry(),
			new SchemaBackedSlotRefResolver(),
			$provider
		);
	}

	private function provider( string $kind ): ComponentContractCatalogProviderInterface {
		$catalog = ComponentContractCatalog::from_contracts(
			ComponentContract::from_schema( 'FeatureCard', array(), array( 'default', 'actions', '0' ) ),
			ComponentContract::from_schema( 'NoSlots', array(), array() )
		);

		return 'accepted' === $kind
			? AcceptedComponentContractCatalogProvider::from_array( $catalog->to_array() )
			: InMemoryComponentContractCatalogProvider::from_catalog( $catalog );
	}
}

/**
 * Deterministic component ref lookup for schema-backed slot tests.
 */
final class SchemaBackedSlotRefResolver implements ComponentRefResolverInterface {

	public function ref_by_key( string $component_key ): int {
		return array_search( $component_key, array( 77 => 'FeatureCard', 88 => 'NoSlots' ), true ) ?: 0;
	}

	public function key_by_ref( int $ref ): ?string {
		return array( 77 => 'FeatureCard', 88 => 'NoSlots' )[ $ref ] ?? null;
	}
}
