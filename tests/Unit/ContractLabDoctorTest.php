<?php
/**
 * Contract Lab doctor tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabBinding;
use HonestlyDesign\EtchBuilders\ContractLabDeterministicSettings;
use HonestlyDesign\EtchBuilders\ContractLabDoctor;
use HonestlyDesign\EtchBuilders\ContractLabDoctorEvidence;
use HonestlyDesign\EtchBuilders\ContractLabEnvironmentConstraints;
use HonestlyDesign\EtchBuilders\ContractLabManifest;
use HonestlyDesign\EtchBuilders\ContractLabMarker;
use HonestlyDesign\EtchBuilders\ContractLabProfile;
use HonestlyDesign\EtchBuilders\ContractLabSchema;
use HonestlyDesign\EtchBuilders\ContractLabSiteState;
use PHPUnit\Framework\TestCase;

/**
 * Ensures preflight is read-only and separates environment from contract drift.
 */
final class ContractLabDoctorTest extends TestCase {

	public function test_matching_environment_and_contract_are_ready_without_writing(): void {
		$binding_path = sys_get_temp_dir() . '/contract-lab-doctor-' . uniqid( '', true ) . '/binding.json';
		$result       = ContractLabDoctor::inspect(
			$this->binding(),
			$this->site_state(),
			$this->manifest(),
			$this->evidence(),
			$this->fingerprint()
		);

		self::assertSame( 'ready', $result->status() );
		self::assertSame( array(), $result->findings() );
		self::assertSame( $result->to_array(), $result->report() );
		self::assertFileDoesNotExist( $binding_path );
	}

	public function test_environment_failure_is_not_reported_as_contract_incompatibility(): void {
		$evidence = ContractLabDoctorEvidence::new(
			'6.6.0',
			'7.4.0',
			'10.1.0',
			'1.5.1',
			$this->fingerprint(),
			'1.0',
			'1.0'
		);

		$result = ContractLabDoctor::inspect( $this->binding(), $this->site_state(), $this->manifest(), $evidence, $this->fingerprint() );

		self::assertSame( 'environment_failure', $result->status() );
		self::assertSame( array( 'environment' ), array_values( array_unique( array_column( $result->findings(), 'category' ) ) ) );
		self::assertStringContainsString( 'PHP version', $result->findings()[0]['message'] );
	}

	public function test_binding_or_site_failure_is_classified_as_environment_failure(): void {
		$result = ContractLabDoctor::inspect(
			$this->binding(),
			$this->site_state( null, false ),
			$this->manifest(),
			$this->evidence(),
			$this->fingerprint()
		);

		self::assertSame( 'environment_failure', $result->status() );
		self::assertSame( 'environment', $result->findings()[0]['category'] );
		self::assertStringContainsString( 'marker is missing', $result->findings()[0]['message'] );
	}

	public function test_fingerprint_and_probe_drift_are_contract_incompatibilities(): void {
		$evidence = ContractLabDoctorEvidence::new(
			'6.6.0',
			'8.2.29',
			'10.1.0',
			'1.5.1',
			str_repeat( 'b', 64 ),
			'9.0',
			'1.0'
		);

		$result = ContractLabDoctor::inspect( $this->binding(), $this->site_state(), $this->manifest(), $evidence, $this->fingerprint() );

		self::assertSame( 'contract_incompatibility', $result->status() );
		self::assertSame( array( 'contract' ), array_values( array_unique( array_column( $result->findings(), 'category' ) ) ) );
		self::assertCount( 2, $result->findings() );
		self::assertStringContainsString( 'fingerprint', $result->findings()[0]['message'] );
		self::assertStringContainsString( 'probe schema', $result->findings()[1]['message'] );
	}

	private function binding(): ContractLabBinding {
		return ContractLabBinding::new(
			'jTAefG5iA',
			'etch-builders-contract-lab',
			'http://etch-builders-contract-lab.local',
			'/tmp/contract-lab/app/public',
			'marker-123'
		);
	}

	private function site_state( ?ContractLabMarker $marker = null, bool $include_marker = true ): ContractLabSiteState {
		return ContractLabSiteState::new(
			'jTAefG5iA',
			'etch-builders-contract-lab',
			'http://etch-builders-contract-lab.local',
			'/tmp/contract-lab/app/public',
			'wp',
			'local',
			true,
			array( 'etch', 'etch-theme', 'contract-probe-plugin' ),
			$include_marker ? ( $marker ?? ContractLabMarker::new( 'marker-123', 'jTAefG5iA', 'wp' ) ) : null
		);
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

	private function evidence(): ContractLabDoctorEvidence {
		return ContractLabDoctorEvidence::new( '6.6.0', '8.2.29', '10.1.0', '1.5.1', $this->fingerprint(), '1.0', '1.0' );
	}

	private function fingerprint(): string {
		return str_repeat( 'a', 64 );
	}
}
