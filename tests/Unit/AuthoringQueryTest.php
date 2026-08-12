<?php
/**
 * Authoring Query command tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\AuthoringCapability;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityCatalog;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityReferenceIndex;
use HonestlyDesign\EtchBuilders\AuthoringCapabilitySourceCatalog;
use HonestlyDesign\EtchBuilders\AuthoringCapabilitySourceDeclaration;
use HonestlyDesign\EtchBuilders\AuthoringCapabilitySourceSymbol;
use HonestlyDesign\EtchBuilders\AuthoringContractCatalogGenerator;
use HonestlyDesign\EtchBuilders\AuthoringQuery;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContract;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractMetadata;
use HonestlyDesign\EtchBuilders\CoreAuthoringRecipeCatalog;
use HonestlyDesign\EtchBuilders\CoreCompositeAuthoringRecipeCatalog;
use HonestlyDesign\EtchBuilders\CoreNegativeAuthoringRecipeCatalog;
use HonestlyDesign\EtchBuilders\Support\InMemoryComponentContractCatalogProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves that local agent queries expose only the versioned supported surface.
 */
final class AuthoringQueryTest extends TestCase {

	public function test_intent_query_returns_exact_versioned_interfaces_recipes_prerequisites_and_diagnostics(): void {
		$query = $this->query();
		$first = $query->help( 'site.component.definition' );
		$second = $query->help( 'site.component.definition' );

		self::assertSame( $first->to_array(), $second->to_array() );
		self::assertSame( $first->to_array(), json_decode( $first->to_json(), true, 512, JSON_THROW_ON_ERROR ) );
		self::assertSame( 'supported', $first->status() );
		self::assertSame(
			array(
				'command',
				'query',
				'status',
				'catalog',
				'intent',
				'capability',
				'interfaces',
				'prerequisites',
				'recipes',
				'diagnostics',
				'alternatives',
				'error',
			),
			array_keys( $first->to_array() )
		);
		self::assertSame( 'builder:help', $first->to_array()['command'] );
		self::assertSame( 'site.component.definition', $first->to_array()['intent'] );
		self::assertSame(
			array(
				'schema_version'   => '1',
				'contract_version' => '1.0',
				'package_version'  => '1.1.8-dev',
				'source_digest'    => $first->to_array()['catalog']['source_digest'],
			),
			$first->to_array()['catalog']
		);
		self::assertSame( QueryContractFixture::class, $first->to_array()['interfaces'][0]['class'] );
		self::assertSame( 'component', $first->to_array()['interfaces'][0]['method'] );
		self::assertSame( array( 'recipe.site.component' ), $first->to_array()['capability']['recipe_ids'] );
		self::assertSame( array( 'site.page.definition' ), $first->to_array()['prerequisites'] );
		self::assertSame( 'recipe.site.component', $first->to_array()['recipes'][0]['id'] );
		self::assertSame( 'positive', $first->to_array()['recipes'][0]['kind'] );
		self::assertSame( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID', $first->to_array()['diagnostics'][0]['code'] );
		self::assertSame( array(), $first->to_array()['alternatives'] );
		self::assertNull( $first->to_array()['error'] );
	}

	public function test_component_query_preserves_exact_nested_paths_slots_and_class_property_semantics(): void {
		$result = $this->query()->component( 'FeatureCard' );
		$component = $result->to_array()['component'];

		self::assertSame( 'supported', $result->status() );
		self::assertSame( 'FeatureCard', $component['component_key'] );
		self::assertSame( array( 'default', 'actions' ), $component['slots'] );
		self::assertSame(
			array( 'styling.rootClass', 'items[].itemClass' ),
			$component['class_property_paths']
		);
		self::assertSame(
			array( 'styling.rootClass', 'styling.rootClass' ),
			array(
				$component['property_paths'][2]['declaration_path'],
				$component['property_paths'][2]['value_path'],
			)
		);
		self::assertSame( 'items[].itemClass', $component['property_paths'][4]['value_path'] );
		self::assertSame( array( 'recipe.site.component' ), $component['recipe_ids'] );
		self::assertSame( array(), $result->to_array()['alternatives'] );
	}

	public function test_unknown_and_pending_queries_fail_closed_with_only_safe_catalog_alternatives(): void {
		$query = $this->query();

		$unknown_intent = $query->help( 'site.invented' )->to_array();
		self::assertSame( 'unknown', $unknown_intent['status'] );
		self::assertSame( 'AUTHORING_QUERY_UNKNOWN_INTENT', $unknown_intent['error']['code'] );
		self::assertSame( 'site.component.definition', $unknown_intent['alternatives'][0]['id'] );
		self::assertSame( 'builder:help --intent site.component.definition', $unknown_intent['alternatives'][0]['command'] );
		self::assertArrayNotHasKey( 'interfaces', $unknown_intent );

		$pending_intent = $query->help( 'site.pending' )->to_array();
		self::assertSame( 'pending', $pending_intent['status'] );
		self::assertSame( 'AUTHORING_QUERY_INTENT_PENDING', $pending_intent['error']['code'] );
		self::assertStringContainsString( 'runtime proof', $pending_intent['error']['message'] );
		self::assertSame( 'site.component.definition', $pending_intent['alternatives'][0]['id'] );
		self::assertArrayNotHasKey( 'interfaces', $pending_intent );

		$unknown_component = $query->component( 'featurecard' )->to_array();
		self::assertSame( 'unknown', $unknown_component['status'] );
		self::assertSame( 'AUTHORING_QUERY_UNKNOWN_COMPONENT', $unknown_component['error']['code'] );
		self::assertSame( 'FeatureCard', $unknown_component['alternatives'][0]['id'] );

		$pending_component = $query->component( 'PendingCard' )->to_array();
		self::assertSame( 'pending', $pending_component['status'] );
		self::assertSame( 'AUTHORING_QUERY_COMPONENT_PENDING', $pending_component['error']['code'] );
		self::assertArrayNotHasKey( 'component', $pending_component );

		$unknown_diagnostic = $query->explain( 'INVENTED_DIAGNOSTIC' )->to_array();
		self::assertSame( 'unknown', $unknown_diagnostic['status'] );
		self::assertSame( 'AUTHORING_QUERY_UNKNOWN_DIAGNOSTIC', $unknown_diagnostic['error']['code'] );
		self::assertSame( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID', $unknown_diagnostic['alternatives'][0]['id'] );
	}

	public function test_diagnostic_query_returns_exact_correction_and_linked_recipe(): void {
		$result = $this->query()->explain( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID' );
		$record = $result->to_array();

		self::assertSame( 'known', $result->status() );
		self::assertSame( 'builder:explain', $record['command'] );
		self::assertSame( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID', $record['diagnostic']['code'] );
		self::assertSame( 'error', $record['diagnostic']['severity'] );
		self::assertSame(
			'Use ComponentBlock::for_key() and an exact schema-derived path with a typed value.',
			$record['diagnostic']['alternatives'][0]
		);
		self::assertSame( array( 'recipe.negative.component-path' ), $record['diagnostic']['recipe_ids'] );
		self::assertSame( array( 'site.component.instance' ), $record['diagnostic']['capability_ids'] );
		self::assertSame( array(), $record['alternatives'] );
	}

	public function test_checked_escape_is_hidden_from_normal_help_but_available_through_explicit_escape_query(): void {
		$query = $this->query( true );

		$normal = $query->help( 'site.checked-raw-fragment' )->to_array();
		self::assertSame( 'checked_escape', $normal['status'] );
		self::assertSame( 'AUTHORING_QUERY_ESCAPE_REQUIRED', $normal['error']['code'] );
		self::assertSame( 'builder:escape --intent site.checked-raw-fragment', $normal['alternatives'][0]['command'] );

		$escape = $query->escape( 'site.checked-raw-fragment' )->to_array();
		self::assertSame( 'checked_escape', $escape['status'] );
		self::assertSame( 'site.checked-raw-fragment', $escape['intent'] );
		self::assertSame( 'recipe.negative.raw-fallback', $escape['recipes'][0]['id'] );
	}

	private function query( bool $include_escape = false ): AuthoringQuery {
		$recipe_ids = array(
			'recipe.site.component',
			'recipe.site.page',
			'recipe.negative.raw-fallback',
		);
		$diagnostic_ids = array(
			'ETCH_SITE_COMPONENT_CONTRACT_INVALID',
			'ETCH_SITE_ESCAPE_REVIEW',
		);
		$references = AuthoringCapabilityReferenceIndex::new(
			$recipe_ids,
			$diagnostic_ids,
			array( 'evidence.component', 'evidence.escape' )
		);
		$capabilities = array(
			AuthoringCapability::supported(
				'site.component.definition',
				array( 'site.page.definition' ),
				array( 'recipe.site.component' ),
				array( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID' ),
				array( 'evidence.component' )
			),
			AuthoringCapability::supported(
				'site.page.definition',
				array(),
				array( 'recipe.site.page' ),
				array( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID' ),
				array( 'evidence.component' )
			),
			AuthoringCapability::pending(
				'site.pending',
				'Requires runtime proof before admission; query requires exact runtime proof.',
			),
		);
		if ( $include_escape ) {
			$capabilities[] = AuthoringCapability::checked_escape(
				'site.checked-raw-fragment',
				'Only an explicit checked escape may use this route.',
				array(),
				array( 'recipe.negative.raw-fallback' ),
				array( 'ETCH_SITE_ESCAPE_REVIEW' ),
				array( 'evidence.escape' )
			);
		}

		$source_declarations = array(
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.component.definition',
				AuthoringCapabilitySourceSymbol::method( QueryContractFixture::class, 'component' )
			),
			AuthoringCapabilitySourceDeclaration::for_capability(
				'site.page.definition',
				AuthoringCapabilitySourceSymbol::method( QueryContractFixture::class, 'page' )
			),
		);
		if ( $include_escape ) {
			$source_declarations[] = AuthoringCapabilitySourceDeclaration::for_capability(
				'site.checked-raw-fragment',
				AuthoringCapabilitySourceSymbol::method( QueryContractFixture::class, 'escape' )
			);
		}

		$contract = AuthoringContractCatalogGenerator::generate(
			AuthoringCapabilityCatalog::from_declarations( $references, ...$capabilities ),
			AuthoringCapabilitySourceCatalog::from_declarations( ...$source_declarations ),
			'1.1.8-dev'
		);

		return AuthoringQuery::from_catalogs(
			$contract,
			CoreAuthoringRecipeCatalog::new(),
			CoreNegativeAuthoringRecipeCatalog::new(),
			CoreCompositeAuthoringRecipeCatalog::new(),
			InMemoryComponentContractCatalogProvider::from_catalog( $this->component_catalog() )
		);
	}

	private function component_catalog(): ComponentContractCatalog {
		return ComponentContractCatalog::from_contracts(
			ComponentContract::from_schema(
				'FeatureCard',
				array(
					$this->property( 'Title', 'title', 'string' ),
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
						array( $this->property( 'Item Class', 'itemClass', 'array', 'class' ) )
					),
				),
				array( 'default', 'actions' ),
				ComponentContractMetadata::supported( 'recipe.site.component' )
			),
			ComponentContract::from_schema(
				'PendingCard',
				array( $this->property( 'Title', 'title', 'string' ) ),
				array( 'default' )
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>>|null $children
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

		$property = array(
			'name' => $name,
			'key'  => $key,
			'type' => $type,
		);
		if ( null !== $children ) {
			$property['properties'] = $children;
		}

		return $property;
	}
}

final class QueryContractFixture {

	/**
	 * @authoring-contract-version 1.0
	 */
	public static function component(): string {
		return 'component';
	}

	/**
	 * @authoring-contract-version 1.0
	 */
	public static function page(): string {
		return 'page';
	}

	/**
	 * @authoring-contract-version 1.0
	 */
	public static function escape(): string {
		return 'escape';
	}
}
