<?php
/**
 * Generated readable Authoring reference material tests.
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
use HonestlyDesign\EtchBuilders\AuthoringContractCatalog;
use HonestlyDesign\EtchBuilders\AuthoringContractCatalogGenerator;
use HonestlyDesign\EtchBuilders\AuthoringReferenceMaterial;
use HonestlyDesign\EtchBuilders\AuthoringReferenceMaterialGenerator;
use HonestlyDesign\EtchBuilders\AuthoringReferenceMethodology;
use HonestlyDesign\EtchBuilders\CoreAuthoringRecipeCatalog;
use HonestlyDesign\EtchBuilders\CoreCompositeAuthoringRecipeCatalog;
use HonestlyDesign\EtchBuilders\CoreNegativeAuthoringRecipeCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies generated guidance stays a projection of executable authority.
 */
final class AuthoringReferenceMaterialTest extends TestCase {

	public function test_generation_is_deterministic_and_contains_versioned_facts_links_and_recipe_snippets(): void {
		$fixtures = $this->fixtures();
		$methodology = $this->methodology();
		$first = AuthoringReferenceMaterialGenerator::generate( $fixtures[0], $fixtures[1], $fixtures[2], $fixtures[3], $methodology );
		$second = AuthoringReferenceMaterialGenerator::generate( $fixtures[0], $fixtures[1], $fixtures[2], $fixtures[3], $methodology );
		$record = $first->to_array();

		self::assertSame( $record, $second->to_array() );
		self::assertSame( $record, AuthoringReferenceMaterial::from_array( $record )->to_array() );
		self::assertSame(
			array(
				'reference_schema_version',
				'catalog_schema_version',
				'catalog_contract_version',
				'package_version',
				'source_digest',
				'recipe_schema_version',
				'recipe_versions',
				'recipe_digest',
				'component_digest',
			),
			array_keys( $record['metadata'] )
		);
		self::assertSame( '1', $record['metadata']['reference_schema_version'] );
		self::assertSame( '1', $record['metadata']['catalog_schema_version'] );
		self::assertSame( '1.0', $record['metadata']['catalog_contract_version'] );
		self::assertSame( '1.1.8-dev', $record['metadata']['package_version'] );
		self::assertSame( array( '1.0' ), $record['metadata']['recipe_versions'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $record['metadata']['source_digest'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $record['metadata']['recipe_digest'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $record['metadata']['component_digest'] );

		self::assertSame( 'site.component.definition', $record['capabilities'][0]['id'] );
		self::assertSame( ReferenceMaterialContractFixture::class, $record['capabilities'][0]['interfaces'][0]['class'] );
		self::assertSame( 'component', $record['capabilities'][0]['interfaces'][0]['method'] );
		self::assertSame( 'recipe.site.component', $record['capabilities'][0]['recipe_ids'][0] );
		self::assertSame( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID', $record['capabilities'][0]['diagnostic_ids'][0] );
		self::assertStringContainsString( 'recipe.site.component', $record['recipes'][0]['snippet'] );
		self::assertSame( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID', $record['diagnostics'][0]['code'] );
		self::assertStringContainsString( 'Reference Material', $first->markdown() );
		self::assertStringContainsString( 'ReferenceMaterialContractFixture::component', $first->markdown() );
		self::assertStringContainsString( '[recipe.site.component](#recipe-recipe-site-component)', $first->markdown() );
		self::assertSame( 0, substr_count( $first->markdown(), '\\n' ) );
		self::assertStringNotContainsString( '/Users/', $first->markdown() );

		foreach ( $record['links'] as $link ) {
			self::assertStringContainsString( 'id="' . substr( $link['target'], 1 ) . '"', $first->markdown() );
		}
	}

	public function test_verify_rejects_manual_changes_to_generated_interface_facts_or_markdown(): void {
		$fixtures = $this->fixtures();
		$methodology = $this->methodology();
		$projection = AuthoringReferenceMaterialGenerator::generate( $fixtures[0], $fixtures[1], $fixtures[2], $fixtures[3], $methodology )->to_array();
		$projection['capabilities'][0]['interfaces'][0]['method'] = 'invented';

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'does not match generated source/catalog facts' );
		AuthoringReferenceMaterialGenerator::verify( $projection, $fixtures[0], $fixtures[1], $fixtures[2], $fixtures[3], $methodology );
	}

	public function test_methodology_is_routing_only_and_rejects_hand_authored_api_snippets(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'methodology must not contain API syntax' );

		AuthoringReferenceMethodology::new(
			'Invalid guidance',
			array(
				array( 'heading' => 'Route', 'body' => 'Use `ReferenceMaterialContractFixture::component()`.' ),
			)
		);
	}

	public function test_generation_rejects_recipe_capability_ids_not_in_the_contract_catalog(): void {
		$fixtures = $this->fixtures();
		$capabilities = $fixtures[0];
		$contract = $this->contract_catalog( array( 'site.component.definition' ) );
		$fixtures[0] = $contract;

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'recipe capability ID "site.page.definition"' );
		AuthoringReferenceMaterialGenerator::generate( $fixtures[0], $fixtures[1], $fixtures[2], $fixtures[3], $this->methodology() );
	}

	private function methodology(): AuthoringReferenceMethodology {
		return AuthoringReferenceMethodology::new(
			'Reference Material',
			array(
				array( 'heading' => 'Discover', 'body' => 'Identify the authoring intent and query the local versioned catalog.' ),
				array( 'heading' => 'Verify', 'body' => 'Follow the executable recipe and repair failures using the exact diagnostic.' ),
			)
		);
	}

	/**
	 * @return array{AuthoringContractCatalog, \HonestlyDesign\EtchBuilders\AuthoringRecipeCatalog, \HonestlyDesign\EtchBuilders\AuthoringNegativeRecipeCatalog, \HonestlyDesign\EtchBuilders\AuthoringCompositeRecipeCatalog}
	 */
	private function fixtures(): array {
		return array(
			$this->contract_catalog(),
			CoreAuthoringRecipeCatalog::new(),
			CoreNegativeAuthoringRecipeCatalog::new(),
			CoreCompositeAuthoringRecipeCatalog::new(),
		);
	}

	/**
	 * @param array<int, string>|null $only_capabilities
	 */
	private function contract_catalog( ?array $only_capabilities = null ): AuthoringContractCatalog {
		$recipe_catalog = CoreAuthoringRecipeCatalog::new();
		$negative_catalog = CoreNegativeAuthoringRecipeCatalog::new();
		$composite_catalog = CoreCompositeAuthoringRecipeCatalog::new();
		$recipe_ids = array_merge(
			array_map( static fn ( array $recipe ): string => $recipe['id'], $recipe_catalog->to_array()['recipes'] ),
			array_map( static fn ( array $recipe ): string => $recipe['id'], $negative_catalog->to_array()['recipes'] ),
			array_map( static fn ( array $recipe ): string => $recipe['id'], $composite_catalog->to_array()['recipes'] )
		);
		$diagnostic_ids = array_map(
			static fn ( array $recipe ): string => $recipe['expected_outcome']['diagnostic']['code'],
			$negative_catalog->to_array()['recipes']
		);
		$capability_ids = $only_capabilities ?? array(
			'site.component.definition',
			'site.pattern.definition',
			'site.page.definition',
			'site.template.definition',
			'site.style.ownership',
			'site.javascript.file',
			'site.entity.definition',
			'site.post.definition',
			'site.dynamic.loop',
			'site.component.instance',
			'site.component.slot',
			'site.style.class-reference',
			'site.checked-raw-fragment',
			'site.ome.composition',
			'site.woo.composition',
		);
		$references = AuthoringCapabilityReferenceIndex::new(
			$recipe_ids,
			$diagnostic_ids,
			array( 'evidence.component' )
		);
		$capabilities = array();
		foreach ( $capability_ids as $capability_id ) {
			$capabilities[] = 'site.component.definition' === $capability_id
				? AuthoringCapability::supported(
					$capability_id,
					array(),
					array( 'recipe.site.component' ),
					array( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID' ),
					array( 'evidence.component' )
				)
				: AuthoringCapability::pending( $capability_id, 'Awaiting the remaining evidence.' );
		}

		return AuthoringContractCatalogGenerator::generate(
			AuthoringCapabilityCatalog::from_declarations( $references, ...$capabilities ),
			AuthoringCapabilitySourceCatalog::from_declarations(
				AuthoringCapabilitySourceDeclaration::for_capability(
					'site.component.definition',
					AuthoringCapabilitySourceSymbol::method( ReferenceMaterialContractFixture::class, 'component' )
				)
			),
			'1.1.8-dev'
		);
	}
}

final class ReferenceMaterialContractFixture {

	/**
	 * @authoring-contract-version 1.0
	 */
	public static function component( string $key = 'Hero' ): string {
		return $key;
	}
}
