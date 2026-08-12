<?php
/**
 * Component Contract lookup tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractLookup;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractMetadata;
use HonestlyDesign\EtchBuilders\Support\AcceptedComponentContractCatalogProvider;
use HonestlyDesign\EtchBuilders\Support\InMemoryComponentContractCatalogProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves one compact query exposes only exact component authoring facts.
 */
final class ComponentContractLookupTest extends TestCase {

	/**
	 * @dataProvider provider_kind
	 */
	public function test_lookup_reports_exact_paths_value_kinds_slots_status_and_recipes( string $provider_kind ): void {
		$catalog  = $this->catalog(
			ComponentContractMetadata::supported(
				'component.feature-card',
				'component.feature-card.advanced'
			)
		);
		$provider = 'accepted' === $provider_kind
			? AcceptedComponentContractCatalogProvider::from_array( $catalog->to_array() )
			: InMemoryComponentContractCatalogProvider::from_catalog( $catalog );

		$result = ComponentContractLookup::from_provider( $provider )->for_key( 'FeatureCard' );

		self::assertSame( 'FeatureCard', $result->component_key() );
		self::assertSame( 'supported', $result->status()->value );
		self::assertSame( array( 'default', 'actions' ), $result->slots() );
		self::assertSame(
			array( 'root_classes', 'styling.rootClass', 'items[].item_classes' ),
			$result->class_property_paths()
		);
		self::assertSame(
			array( 'component.feature-card', 'component.feature-card.advanced' ),
			$result->recipe_ids()
		);
		self::assertSame(
			array(
				'component_key'       => 'FeatureCard',
				'status'              => 'supported',
				'property_paths'      => array(
					$this->path( 'title', 'title', array( 'primitive' => 'string' ), array( 'string' ) ),
					$this->path( 'visibility', null, array( 'primitive' => 'string', 'specialized' => 'condition' ), array( 'transparent-children' ) ),
					$this->path( 'visibility.root_classes', 'root_classes', array( 'primitive' => 'array', 'specialized' => 'class' ), array( 'class-style-set' ) ),
					$this->path( 'styling', 'styling', array( 'primitive' => 'object', 'specialized' => 'group' ), array( 'group' ) ),
					$this->path( 'styling.rootClass', 'styling.rootClass', array( 'primitive' => 'array', 'specialized' => 'class' ), array( 'class-style-set' ) ),
					$this->path( 'items', 'items', array( 'primitive' => 'array', 'specialized' => 'repeater' ), array( 'repeater' ) ),
					$this->path( 'items.label', 'items[].label', array( 'primitive' => 'string' ), array( 'string' ) ),
					$this->path( 'items.item_classes', 'items[].item_classes', array( 'primitive' => 'array', 'specialized' => 'class' ), array( 'class-style-set' ) ),
				),
				'slots'               => array( 'default', 'actions' ),
				'class_property_paths' => array( 'root_classes', 'styling.rootClass', 'items[].item_classes' ),
				'recipe_ids'          => array( 'component.feature-card', 'component.feature-card.advanced' ),
			),
			$result->to_array()
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function provider_kind(): array {
		return array(
			'in-memory provider' => array( 'memory' ),
			'accepted provider'  => array( 'accepted' ),
		);
	}

	public function test_schema_contracts_are_pending_until_support_is_explicitly_declared(): void {
		$catalog = $this->catalog();
		$result  = ComponentContractLookup::from_provider(
			InMemoryComponentContractCatalogProvider::from_catalog( $catalog )
		)->for_key( 'FeatureCard' );

		self::assertSame( 'pending', $result->status()->value );
		self::assertSame( array(), $result->recipe_ids() );
		self::assertSame( 'pending', $catalog->contract( 'FeatureCard' )->status()->value );
	}

	/**
	 * @dataProvider invalid_recipe_ids
	 * @param array<int, string> $recipe_ids Recipe IDs to validate.
	 */
	public function test_component_metadata_rejects_invalid_or_duplicate_recipe_ids( array $recipe_ids, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		ComponentContractMetadata::pending( ...$recipe_ids );
	}

	/**
	 * @return array<string, array{array<int, string>, string}>
	 */
	public function invalid_recipe_ids(): array {
		return array(
			'empty'          => array( array( '' ), 'exact stable ID' ),
			'whitespace'     => array( array( ' component.card' ), 'exact stable ID' ),
			'uppercase'      => array( array( 'Component.Card' ), 'exact stable ID' ),
			'path'           => array( array( 'recipes/card.php' ), 'exact stable ID' ),
			'duplicate'      => array( array( 'component.card', 'component.card' ), 'duplicate recipe ID' ),
			'numeric prefix' => array( array( '1.component' ), 'exact stable ID' ),
		);
	}

	public function test_supported_metadata_requires_at_least_one_valid_recipe(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Supported component metadata requires at least one recipe ID.' );

		ComponentContractMetadata::supported( '' );
	}

	/**
	 * @dataProvider malformed_accepted_metadata
	 * @param mixed $value Malformed accepted field value.
	 */
	public function test_accepted_catalog_rejects_malformed_status_or_recipe_metadata( string $field, mixed $value, string $message ): void {
		$record = $this->catalog( ComponentContractMetadata::supported( 'component.feature-card' ) )->to_array();
		$record['components'][0][ $field ] = $value;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		AcceptedComponentContractCatalogProvider::from_array( $record );
	}

	/**
	 * @return array<string, array{string, mixed, string}>
	 */
	public function malformed_accepted_metadata(): array {
		return array(
			'unknown status'       => array( 'status', 'internal', 'status must be supported or pending' ),
			'non-string status'    => array( 'status', 1, 'status must be supported or pending' ),
			'recipe scalar'        => array( 'recipe_ids', 'component.feature-card', 'recipe_ids must be a list' ),
			'invalid recipe entry' => array( 'recipe_ids', array( 'FeatureCard' ), 'exact stable ID' ),
			'supported no recipes' => array( 'recipe_ids', array(), 'requires at least one recipe ID' ),
		);
	}

	public function test_unknown_component_key_fails_without_fuzzy_or_case_insensitive_lookup(): void {
		$lookup = ComponentContractLookup::from_provider(
			InMemoryComponentContractCatalogProvider::from_catalog( $this->catalog() )
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no component key "featurecard"' );

		$lookup->for_key( 'featurecard' );
	}

	public function test_lookup_results_are_defensive_and_do_not_mutate_the_catalog(): void {
		$catalog = $this->catalog( ComponentContractMetadata::supported( 'component.feature-card' ) );
		$lookup  = ComponentContractLookup::from_provider(
			InMemoryComponentContractCatalogProvider::from_catalog( $catalog )
		);
		$before  = $catalog->to_array();
		$result  = $lookup->for_key( 'FeatureCard' );

		$record                              = $result->to_array();
		$record['property_paths'][0]['type'] = array( 'primitive' => 'invented' );
		$record['slots'][]                    = 'invented';
		$recipes                             = $result->recipe_ids();
		$recipes[]                           = 'invented.recipe';

		self::assertSame( $before, $catalog->to_array() );
		self::assertSame( 'string', $result->to_array()['property_paths'][0]['type']['primitive'] );
		self::assertSame( array( 'default', 'actions' ), $result->slots() );
		self::assertSame( array( 'component.feature-card' ), $result->recipe_ids() );
	}

	private function catalog( ?ComponentContractMetadata $metadata = null ): ComponentContractCatalog {
		return ComponentContractCatalog::from_contracts(
			ComponentContract::from_schema(
				'FeatureCard',
				array(
					$this->property( 'Title', 'title', 'string' ),
					$this->property(
						'Visibility',
						'visibility',
						'string',
						'condition',
						array( $this->property( 'Root classes', 'root_classes', 'array', 'class' ) )
					),
					$this->property(
						'Styling',
						'styling',
						'object',
						'group',
						array( $this->property( 'Root Class', 'rootClass', 'array', 'class' ) )
					),
					$this->property(
						'Items',
						'items',
						'array',
						'repeater',
						array(
							$this->property( 'Label', 'label', 'string' ),
							$this->property( 'Item classes', 'item_classes', 'array', 'class' ),
						)
					),
				),
				array( 'default', 'actions' ),
				$metadata
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>>|null $children Child definitions.
	 * @return array<string, mixed>
	 */
	private function property(
		string $name,
		string $key,
		string $primitive,
		?string $specialized = null,
		?array $children = null
	): array {
		$type = array( 'primitive' => $primitive );
		if ( null !== $specialized ) {
			$type['specialized'] = $specialized;
		}

		$property = array( 'name' => $name, 'key' => $key, 'type' => $type );
		if ( null !== $children ) {
			$property['properties'] = $children;
		}

		return $property;
	}

	/**
	 * @param array<string, string> $type Exact primitive/specialized pair.
	 * @param array<int, string>    $value_kinds Allowed semantic value kinds.
	 * @return array<string, mixed>
	 */
	private function path( string $declaration_path, ?string $value_path, array $type, array $value_kinds ): array {
		return array(
			'declaration_path' => $declaration_path,
			'value_path'       => $value_path,
			'type'             => $type,
			'value_kinds'      => $value_kinds,
			'status'           => 'supported',
		);
	}
}
