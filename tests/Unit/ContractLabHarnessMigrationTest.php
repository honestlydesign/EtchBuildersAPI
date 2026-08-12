<?php
/**
 * Legacy rendering harness migration tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinel;
use HonestlyDesign\EtchBuilders\ContractLabBrowserSentinelCatalog;
use HonestlyDesign\EtchBuilders\ContractLabDeterministicSettings;
use HonestlyDesign\EtchBuilders\ContractLabEnvironmentConstraints;
use HonestlyDesign\EtchBuilders\ContractLabFrontendFixture;
use HonestlyDesign\EtchBuilders\ContractLabFrontendFixtureCatalog;
use HonestlyDesign\EtchBuilders\ContractLabHarnessMigration;
use HonestlyDesign\EtchBuilders\ContractLabIntegrationOutcome;
use HonestlyDesign\EtchBuilders\ContractLabJavascriptMarker;
use HonestlyDesign\EtchBuilders\ContractLabManifest;
use HonestlyDesign\EtchBuilders\ContractLabProfile;
use HonestlyDesign\EtchBuilders\ContractLabSchema;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Proves that the old one-method rendering inventory has a bounded semantic
 * replacement in the maintainer-owned Contract Lab.
 */
final class ContractLabHarnessMigrationTest extends TestCase {

	public function test_current_main_inventory_maps_every_authoritative_test_once(): void {
		$migration = ContractLabHarnessMigration::current();
		$parity    = $migration->parity();

		self::assertSame( 'honestlydesign/etch-builders-rendering-tests', $migration->source_repository() );
		self::assertSame( '3f2eb0834df421169baf653f76218a1e4292719a', $migration->source_revision() );
		self::assertSame( 221, $migration->source_test_count() );
		self::assertSame( 221, $parity['inventoried_test_count'] );
		self::assertSame( 211, $parity['retained_contract_count'] );
		self::assertSame( 10, $parity['retired_count'] );
		self::assertSame( 9, $parity['outcome_count'] );
		self::assertSame( 'complete', $parity['status'] );
		self::assertSame( array( 'base' ), $migration->profile_ids() );
		$migration->assert_manifest_profiles( $this->manifest() );
		self::assertStringNotContainsString( 'wp-env', strtolower( (string) json_encode( $migration->to_array() ) ) );
	}

	public function test_inventory_requires_real_composite_fixture_sentinel_and_canonical_outcome_references(): void {
		$migration = ContractLabHarnessMigration::current();

		$migration->assert_contract_surface(
			$this->frontend_fixtures(),
			$this->browser_sentinels(),
			$this->outcomes(),
			ContractLabJavascriptMarker::marketing_reference()
		);

		self::assertSame( $migration->to_array(), ContractLabHarnessMigration::from_array( $migration->to_array() )->to_array() );
	}

	public function test_unknown_surface_reference_fails_closed(): void {
		$migration = ContractLabHarnessMigration::current();
		$outcomes  = $this->outcomes();
		$outcomes['frontend-core-composite'] = ContractLabIntegrationOutcome::from_array(
			array(
				'name'        => 'frontend-core-composite',
				'status'      => 'observed',
				'observation' => array( 'matched' => true ),
			)
		);
		$fixtures = ContractLabFrontendFixtureCatalog::new(
			array(
			ContractLabFrontendFixture::new( 'different-fixture', '/contract-fixtures/different', array( 'dom' => 'different-fixture' ) ),
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'marketing-home' );
		$migration->assert_contract_surface( $fixtures, $this->browser_sentinels(), $outcomes, ContractLabJavascriptMarker::marketing_reference() );
	}

	public function test_save_and_javascript_surfaces_require_bound_fixture_identity(): void {
		$migration = ContractLabHarnessMigration::current();
		$sentinels = ContractLabBrowserSentinelCatalog::new(
			array(
				ContractLabBrowserSentinel::new( 'document-preservation', 'document', 'missing-fixture', '/editor/documents', 'save-document' ),
				ContractLabBrowserSentinel::new( 'component-preservation', 'component', 'marketing-home', '/editor/components', 'save-component' ),
				ContractLabBrowserSentinel::new( 'pattern-preservation', 'pattern', 'marketing-home', '/editor/patterns', 'save-pattern' ),
				ContractLabBrowserSentinel::new( 'global-asset-preservation', 'global-asset', 'marketing-home', '/editor/assets', 'save-global-asset' ),
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing-fixture' );
		$migration->assert_contract_surface( $this->frontend_fixtures(), $sentinels, $this->outcomes(), ContractLabJavascriptMarker::marketing_reference() );
	}

	public function test_malformed_inventory_cannot_claim_complete_parity(): void {
		$record = ContractLabHarnessMigration::current()->to_array();
		array_pop( $record['cases'] );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'source test count' );
		ContractLabHarnessMigration::from_array( $record );
	}

	private function frontend_fixtures(): ContractLabFrontendFixtureCatalog {
		return ContractLabFrontendFixtureCatalog::new(
			array(
				ContractLabFrontendFixture::new(
					'marketing-home',
					'/contract-fixtures/marketing-home',
					array(
						'dom'        => 'marketing-home',
						'stylesheet' => '.marketing-card',
						'class'      => 'marketing-card',
						'slot'       => 'headline',
						'loop'       => 'item-1',
						'dynamic'    => 'title',
					)
				),
			)
		);
	}

	private function browser_sentinels(): ContractLabBrowserSentinelCatalog {
		return ContractLabBrowserSentinelCatalog::new(
			array(
				ContractLabBrowserSentinel::new( 'document-preservation', 'document', 'marketing-home', '/editor/documents', 'save-document' ),
				ContractLabBrowserSentinel::new( 'component-preservation', 'component', 'marketing-home', '/editor/components', 'save-component' ),
				ContractLabBrowserSentinel::new( 'pattern-preservation', 'pattern', 'marketing-home', '/editor/patterns', 'save-pattern' ),
				ContractLabBrowserSentinel::new( 'global-asset-preservation', 'global-asset', 'marketing-home', '/editor/assets', 'save-global-asset' ),
			)
		);
	}

	/**
	 * @return array<string, ContractLabIntegrationOutcome>
	 */
	private function outcomes(): array {
		$names = array(
			'runtime-shape-core',
			'block-wire-round-trip-core',
			'component-style-handoff',
			'frontend-core-composite',
			'browser-save-document',
			'browser-save-component',
			'browser-save-pattern',
			'browser-save-global-asset',
			'javascript-marketing-ready',
		);
		$outcomes = array();
		foreach ( $names as $name ) {
			$outcomes[ $name ] = ContractLabIntegrationOutcome::from_array(
				array(
					'name'        => $name,
					'status'      => 'observed',
					'observation' => array( 'matched' => true ),
				)
			);
		}

		return $outcomes;
	}

	private function manifest(): ContractLabManifest {
		return ContractLabManifest::new(
			ContractLabEnvironmentConstraints::new( '>=6.6 <7.0', '>=8.1 <8.5', '>=6.0' ),
			ContractLabDeterministicSettings::new( 'en_US', 'UTC', '/%postname%/', 'etch', false ),
			array( ContractLabProfile::required( 'base', array( 'etch', 'etch-theme', 'contract-probe-plugin' ) ) ),
			ContractLabSchema::probe( '1.0', array( 'probe_id' ) ),
			ContractLabSchema::observation( '1.0', array( 'run_id' ) )
		);
	}
}
