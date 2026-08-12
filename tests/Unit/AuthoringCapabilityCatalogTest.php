<?php
/**
 * Curated Authoring Capability declaration tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\AuthoringCapability;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityCatalog;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityReferenceIndex;
use HonestlyDesign\EtchBuilders\AuthoringCapabilityStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the intent-level authoring contract model without exporting PHP methods.
 */
final class AuthoringCapabilityCatalogTest extends TestCase {

	public function test_pending_declaration_has_explicit_status_reason_and_stable_projection(): void {
		$capability = AuthoringCapability::pending(
			'site.component.definition',
			'Runtime schema and negative evidence are not admitted yet.',
			prerequisite_ids: array( 'site.entity.definition' ),
			diagnostic_ids: array( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID' ),
			evidence_ids: array( 'evidence.component.definition' )
		);

		self::assertSame( 'site.component.definition', $capability->id() );
		self::assertSame( AuthoringCapabilityStatus::PENDING, $capability->status() );
		self::assertTrue( $capability->status()->is_pending() );
		self::assertFalse( $capability->status()->is_supported() );
		self::assertSame( 'Runtime schema and negative evidence are not admitted yet.', $capability->status_reason() );
		self::assertSame(
			array(
				'id'                => 'site.component.definition',
				'status'            => 'pending',
				'prerequisite_ids'  => array( 'site.entity.definition' ),
				'recipe_ids'        => array(),
				'diagnostic_ids'    => array( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID' ),
				'evidence_ids'      => array( 'evidence.component.definition' ),
				'status_reason'     => 'Runtime schema and negative evidence are not admitted yet.',
			),
			$capability->to_array()
		);
	}

	public function test_supported_catalog_requires_known_references_and_round_trips_canonically(): void {
		$references = AuthoringCapabilityReferenceIndex::new(
			recipe_ids: array( 'recipe.site.component', 'recipe.site.page' ),
			diagnostic_ids: array( 'ETCH_SITE_COMPONENT_CONTRACT_INVALID', 'ETCH_SITE_PATTERN_MISSING' ),
			evidence_ids: array( 'evidence.component', 'evidence.page' )
		);
		$component = AuthoringCapability::pending( 'site.component.definition', 'Awaiting complete evidence.' );
		$page      = AuthoringCapability::supported(
			'site.page.definition',
			prerequisite_ids: array( 'site.component.definition' ),
			recipe_ids: array( 'recipe.site.page' ),
			diagnostic_ids: array( 'ETCH_SITE_PATTERN_MISSING' ),
			evidence_ids: array( 'evidence.page' )
		);

		$catalog = AuthoringCapabilityCatalog::from_declarations( $references, $component, $page );

		self::assertTrue( $catalog->has( 'site.page.definition' ) );
		self::assertSame( $page, $catalog->capability( 'site.page.definition' ) );
		self::assertSame( array( $component, $page ), $catalog->all() );
		self::assertSame( $catalog->to_array(), AuthoringCapabilityCatalog::from_array( $catalog->to_array(), $references )->to_array() );
	}

	public function test_checked_escape_pending_and_unsupported_states_are_explicit_and_fail_closed(): void {
		$references = AuthoringCapabilityReferenceIndex::new(
			recipe_ids: array( 'recipe.escape' ),
			diagnostic_ids: array( 'ETCH_ESCAPE_REVIEW' ),
			evidence_ids: array( 'evidence.escape' )
		);
		$escape = AuthoringCapability::checked_escape(
			'site.raw.fragment.escape',
			'Requires a trusted fragment and explicit review reason.',
			recipe_ids: array( 'recipe.escape' ),
			diagnostic_ids: array( 'ETCH_ESCAPE_REVIEW' ),
			evidence_ids: array( 'evidence.escape' )
		);
		$pending     = AuthoringCapability::pending( 'site.advanced.dynamic', 'Typed route is not admitted yet.' );
		$unsupported = AuthoringCapability::unsupported( 'site.legacy.raw', 'Legacy raw route is not an agent capability.' );
		$catalog     = AuthoringCapabilityCatalog::from_declarations( $references, $escape, $pending, $unsupported );

		self::assertTrue( $escape->status()->is_admitted() );
		self::assertTrue( $escape->status()->is_checked_escape() );
		self::assertTrue( $pending->status()->is_pending() );
		self::assertTrue( $unsupported->status()->is_unsupported() );
		self::assertFalse( $pending->status()->is_admitted() );
		self::assertFalse( $unsupported->status()->is_admitted() );
		self::assertSame( array( $escape, $pending, $unsupported ), $catalog->all() );
	}

	public function test_supported_capability_without_recipe_or_evidence_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Supported authoring capability requires at least one recipe, diagnostic, and evidence ID.' );

		AuthoringCapability::supported( 'site.component.definition' );
	}

	public function test_unknown_prerequisite_capability_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unknown prerequisite capability ID "site.missing"' );

		AuthoringCapabilityCatalog::from_declarations(
			AuthoringCapabilityReferenceIndex::empty(),
			AuthoringCapability::pending( 'site.component.definition', 'Awaiting evidence.', prerequisite_ids: array( 'site.missing' ) )
		);
	}

	public function test_unknown_evidence_reference_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'unknown evidence ID "evidence.missing"' );

		AuthoringCapabilityCatalog::from_declarations(
			AuthoringCapabilityReferenceIndex::empty(),
			AuthoringCapability::pending( 'site.component.definition', 'Awaiting evidence.', evidence_ids: array( 'evidence.missing' ) )
		);
	}

	public function test_projection_rejects_arbitrary_public_method_declarations(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Accepted authoring capability must contain exactly' );

		AuthoringCapabilityCatalog::from_array(
			array(
				'capabilities' => array(
					array(
						'id'               => 'site.component.definition',
						'status'           => 'pending',
						'prerequisite_ids' => array(),
						'recipe_ids'       => array(),
						'diagnostic_ids'   => array(),
						'evidence_ids'     => array(),
						'status_reason'    => 'Awaiting evidence.',
						'methods'          => array( 'register' ),
					)
				),
			)
		);
	}
}
