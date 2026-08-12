<?php
/**
 * Contract Probe Plugin scaffold tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabBinding;
use HonestlyDesign\EtchBuilders\ContractLabSchema;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the maintainer-only plugin boundary without requiring WordPress.
 */
final class ContractProbePluginTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/contract-lab/probe-plugin/src/ContractProbePlugin.php';
	}

	public function test_plugin_has_one_owned_directory_and_public_versioned_route(): void {
		$class = '\\EtchBuildersContractProbe\\ContractProbePlugin';

		self::assertSame( 'etch-builders-contract-lab/v1', $class::REST_NAMESPACE );
		self::assertSame( '/observe', $class::REST_ROUTE );
		self::assertSame( ContractLabSchema::PROBE_VERSION, $class::PROBE_VERSION );
		self::assertSame( ContractLabSchema::OBSERVATION_VERSION, $class::OBSERVATION_SCHEMA_VERSION );
		self::assertSame( ContractLabBinding::LAB_ID, $class::LAB_ID );
		self::assertSame( ContractLabBinding::MARKER_OPTION, $class::MARKER_OPTION );
		self::assertSame(
			array( 'contract-probe-plugin.php', 'src/ContractProbePlugin.php', 'README.md' ),
			$class::owned_files()
		);
		self::assertFileExists( dirname( __DIR__, 2 ) . '/contract-lab/probe-plugin/contract-probe-plugin.php' );
		self::assertFileExists( dirname( __DIR__, 2 ) . '/contract-lab/probe-plugin/README.md' );
	}

	public function test_unknown_probe_and_observation_versions_fail_closed(): void {
		$class = '\\EtchBuildersContractProbe\\ContractProbePlugin';

		self::assertTrue( $class::supports_versions( '1.0', '1.0' ) );
		self::assertFalse( $class::supports_versions( '9.0', '1.0' ) );
		self::assertFalse( $class::supports_versions( '1.0', '9.0' ) );
		self::assertFalse( $class::supports_versions( array( '1.0' ), '1.0' ) );
	}

	public function test_plugin_source_contains_no_proprietary_implementation_or_broad_payload_surface(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/contract-lab/probe-plugin/src/ContractProbePlugin.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( 'register_rest_route', $source );
		self::assertStringContainsString( 'current_user_can', $source );
		self::assertStringContainsString( 'wp_get_environment_type', $source );
		self::assertStringNotContainsString( 'Etch\\', $source );
		self::assertStringNotContainsString( 'eval(', $source );
		self::assertStringNotContainsString( 'get_posts(', $source );
	}
}
