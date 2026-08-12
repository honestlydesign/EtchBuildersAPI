<?php
/**
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\AuthoringCapability;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityCatalog;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityEvidence;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityEvidenceCatalog;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityEvidenceMap;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityEvidenceRequirement;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityEvidenceRequirementCatalog;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityReferenceIndex;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuthoringCapabilityEvidenceMapTest extends TestCase {

	public function test_admitted_capability_requires_positive_negative_recipe_and_optional_runtime_evidence(): void {
		$capabilities = $this->capabilities(
			AuthoringCapability::supported(
				'site.page',
				array(),
				array( 'recipe.page' ),
				array( 'ETCH_PAGE_INVALID' ),
				array( 'evidence.page.positive', 'evidence.page.negative', 'evidence.page.recipe' )
			)
		);
		$requirements = AuthoringCapabilityEvidenceRequirementCatalog::from_declarations(
			AuthoringCapabilityEvidenceRequirement::for_capability( 'site.page', false )
		);
		$evidence = AuthoringCapabilityEvidenceMap::from_catalogs(
			$capabilities,
			$requirements,
			$this->evidence( false )
		);

		self::assertSame( array(), $evidence->missing_evidence_kinds( 'site.page' ) );
		self::assertSame( array( 'positive', 'negative', 'recipe' ), $evidence->assessment_for( 'site.page' )['required_evidence_kinds'] );
		self::assertSame( $evidence->to_array(), AuthoringCapabilityEvidenceMap::from_array( $evidence->to_array(), $capabilities )->to_array() );
	}

	public function test_runtime_requirement_is_explicit_and_missing_runtime_blocks_admission(): void {
		$capabilities = $this->capabilities(
			AuthoringCapability::supported(
				'site.page',
				array(),
				array( 'recipe.page' ),
				array( 'ETCH_PAGE_INVALID' ),
				array( 'evidence.page.positive', 'evidence.page.negative', 'evidence.page.recipe' )
			)
		);
		$requirements = AuthoringCapabilityEvidenceRequirementCatalog::from_declarations(
			AuthoringCapabilityEvidenceRequirement::for_capability( 'site.page', true )
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing runtime evidence' );
		AuthoringCapabilityEvidenceMap::from_catalogs( $capabilities, $requirements, $this->evidence( false ) );
	}

	public function test_pending_capability_exposes_exact_missing_evidence_without_becoming_supported(): void {
		$capabilities = $this->capabilities(
			AuthoringCapability::pending(
				'site.page',
				'Awaiting the remaining executable evidence.'
			)
		);
		$requirements = AuthoringCapabilityEvidenceRequirementCatalog::from_declarations(
			AuthoringCapabilityEvidenceRequirement::for_capability( 'site.page', true )
		);

		$evidence = AuthoringCapabilityEvidenceMap::from_catalogs(
			$capabilities,
			$requirements,
			AuthoringCapabilityEvidenceCatalog::empty()
		);

		self::assertSame( array( 'positive', 'negative', 'recipe', 'runtime' ), $evidence->missing_evidence_kinds( 'site.page' ) );
		self::assertSame( 'pending', $evidence->assessment_for( 'site.page' )['status'] );
		self::assertSame( 'Awaiting the remaining executable evidence.', $evidence->assessment_for( 'site.page' )['status_reason'] );
	}

	public function test_pending_capability_with_no_missing_evidence_is_rejected_as_contradictory(): void {
		$capabilities = $this->capabilities(
			AuthoringCapability::pending(
				'site.page',
				'Awaiting promotion review.',
				array(),
				array( 'recipe.page' ),
				array( 'ETCH_PAGE_INVALID' ),
				array( 'evidence.page.positive', 'evidence.page.negative', 'evidence.page.recipe' )
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'has no missing evidence' );
		AuthoringCapabilityEvidenceMap::from_catalogs(
			$capabilities,
			AuthoringCapabilityEvidenceRequirementCatalog::from_declarations( AuthoringCapabilityEvidenceRequirement::for_capability( 'site.page', false ) ),
			$this->evidence( false )
		);
	}

	public function test_stale_or_orphan_evidence_references_are_rejected(): void {
		$capabilities = $this->capabilities(
			AuthoringCapability::pending(
				'site.page',
				'Awaiting evidence.',
				array(),
				array(),
				array(),
				array( 'evidence.page.missing' )
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'references missing evidence ID' );
		AuthoringCapabilityEvidenceMap::from_catalogs(
			$capabilities,
			AuthoringCapabilityEvidenceRequirementCatalog::from_declarations( AuthoringCapabilityEvidenceRequirement::for_capability( 'site.page' ) ),
			AuthoringCapabilityEvidenceCatalog::empty()
		);
	}

	public function test_unreferenced_evidence_is_rejected_as_orphan(): void {
		$capabilities = $this->capabilities( AuthoringCapability::pending( 'site.page', 'Awaiting evidence.' ) );
		$orphan = AuthoringCapabilityEvidenceCatalogFixture::single( 'evidence.page.orphan' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'orphan evidence' );
		AuthoringCapabilityEvidenceMap::from_catalogs(
			$capabilities,
			AuthoringCapabilityEvidenceRequirementCatalog::from_declarations( AuthoringCapabilityEvidenceRequirement::for_capability( 'site.page' ) ),
			$orphan
		);
	}

	public function test_conflicting_kind_and_requirement_are_rejected(): void {
		$capabilities = $this->capabilities(
			AuthoringCapability::pending(
				'site.page',
				'Awaiting runtime proof.',
				array(),
				array(),
				array(),
				array( 'evidence.page.runtime' )
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not required' );
		AuthoringCapabilityEvidenceMap::from_catalogs(
			$capabilities,
			AuthoringCapabilityEvidenceRequirementCatalog::from_declarations( AuthoringCapabilityEvidenceRequirement::for_capability( 'site.page', false ) ),
			AuthoringCapabilityEvidenceCatalogFixture::runtime( 'evidence.page.runtime' )
		);
	}

	public function test_evidence_ownership_contradictions_are_rejected(): void {
		$capabilities = $this->capabilities(
			AuthoringCapability::pending(
				'site.page',
				'Awaiting evidence.',
				array(),
				array(),
				array(),
				array( 'evidence.page.positive' )
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'contradicts capability ownership' );
		AuthoringCapabilityEvidenceMap::from_catalogs(
			$capabilities,
			AuthoringCapabilityEvidenceRequirementCatalog::from_declarations( AuthoringCapabilityEvidenceRequirement::for_capability( 'site.page' ) ),
			AuthoringCapabilityEvidenceCatalog::from_declarations(
				AuthoringCapabilityEvidence::positive( 'evidence.page.positive', 'site.other', 'recipe.page.positive' )
			)
		);
	}

	public function test_stale_assessment_projection_is_rejected(): void {
		$capabilities = $this->capabilities( AuthoringCapability::pending( 'site.page', 'Awaiting evidence.' ) );
		$map          = AuthoringCapabilityEvidenceMap::from_catalogs(
			$capabilities,
			AuthoringCapabilityEvidenceRequirementCatalog::from_declarations( AuthoringCapabilityEvidenceRequirement::for_capability( 'site.page' ) ),
			AuthoringCapabilityEvidenceCatalog::empty()
		);
		$projection = $map->to_array();
		$projection['assessments'][0]['missing_evidence_kinds'] = array( 'runtime' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'stale or contradictory' );
		AuthoringCapabilityEvidenceMap::from_array( $projection, $capabilities );
	}

	public function test_duplicate_executable_evidence_and_path_like_ids_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate executable evidence' );
		AuthoringCapabilityEvidenceCatalogFixture::duplicate_executable();
	}

	public function test_path_like_executable_ids_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'stable executable ID' );
		AuthoringCapabilityEvidence::positive( 'evidence.page.path', 'site.page', '/tmp/page.php' );
	}

	private function capabilities( AuthoringCapability $capability ): AuthoringCapabilityCatalog {
		$evidence_ids = $capability->evidence_ids();
		$references   = AuthoringCapabilityReferenceIndex::new(
			array( 'recipe.page' ),
			array( 'ETCH_PAGE_INVALID' ),
			$evidence_ids
		);

		return AuthoringCapabilityCatalog::from_declarations( $references, $capability );
	}

	private function evidence( bool $runtime ): AuthoringCapabilityEvidenceCatalog {
		$evidence = array(
			AuthoringCapabilityEvidence::positive( 'evidence.page.positive', 'site.page', 'recipe.page.positive' ),
			AuthoringCapabilityEvidence::negative( 'evidence.page.negative', 'site.page', 'fixture.page.negative' ),
			AuthoringCapabilityEvidence::recipe( 'evidence.page.recipe', 'site.page', 'recipe.page' ),
		);
		if ( $runtime ) {
			$evidence[] = AuthoringCapabilityEvidence::runtime( 'evidence.page.runtime', 'site.page', 'probe.page' );
		}

		return AuthoringCapabilityEvidenceCatalog::from_declarations( ...$evidence );
	}
}

/**
 * Small test-only construction helpers keep the tests focused on map behavior.
 */
final class AuthoringCapabilityEvidenceCatalogFixture {

	public static function single( string $id ): AuthoringCapabilityEvidenceCatalog {
		return AuthoringCapabilityEvidenceCatalog::from_declarations(
			AuthoringCapabilityEvidence::positive( $id, 'site.page', 'recipe.page.positive' )
		);
	}

	public static function runtime( string $id ): AuthoringCapabilityEvidenceCatalog {
		return AuthoringCapabilityEvidenceCatalog::from_declarations(
			AuthoringCapabilityEvidence::runtime( $id, 'site.page', 'probe.page' )
		);
	}

	public static function duplicate_executable(): AuthoringCapabilityEvidenceCatalog {
		return AuthoringCapabilityEvidenceCatalog::from_declarations(
			AuthoringCapabilityEvidence::positive( 'evidence.page.one', 'site.page', 'recipe.page.duplicate' ),
			AuthoringCapabilityEvidence::positive( 'evidence.page.two', 'site.page', 'recipe.page.duplicate' )
		);
	}
}
