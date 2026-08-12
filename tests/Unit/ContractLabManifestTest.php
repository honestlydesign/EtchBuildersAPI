<?php
/**
 * Versioned Contract Lab manifest tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabDeterministicSettings;
use HonestlyDesign\EtchBuilders\ContractLabEnvironmentConstraints;
use HonestlyDesign\EtchBuilders\ContractLabManifest;
use HonestlyDesign\EtchBuilders\ContractLabProfile;
use HonestlyDesign\EtchBuilders\ContractLabSchema;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the immutable machine-readable contract boundary for the Lab.
 */
final class ContractLabManifestTest extends TestCase {

	public function test_current_manifest_is_canonical_versioned_and_preserves_ordered_profiles_and_schemas(): void {
		$manifest = $this->manifest();
		$record   = $manifest->to_array();

		self::assertSame( $record, ContractLabManifest::from_array( $record )->to_array() );
		self::assertSame( '1', $record['manifest_version'] );
		self::assertSame(
			array( 'base', 'ome', 'woo' ),
			array_column( $record['profiles'], 'id' )
		);
		self::assertSame( array( true, false, false ), array_column( $record['profiles'], 'required' ) );
		self::assertSame( array( 'probe_id', 'layer', 'fixture_id' ), $record['probe_schema']['required_fields'] );
		self::assertSame( array( 'run_id', 'environment', 'profiles', 'observations' ), $record['observation_schema']['required_fields'] );
		self::assertSame( '>=8.1 <8.5', $manifest->environment()->php_constraint() );
		self::assertFalse( $manifest->settings()->cache_enabled() );
		self::assertSame( 'oh-my-etch', $manifest->profiles()[1]->plugin_prerequisites()[0] );
	}

	/**
	 * @dataProvider unknown_version_provider
	 */
	public function test_unknown_manifest_probe_and_observation_versions_fail_before_accepting_the_projection( string $field, string $message ): void {
		$record = $this->manifest()->to_array();
		if ( 'manifest_version' === $field ) {
			$record[ $field ] = '9';
		} else {
			/** @var array<string, mixed> $schema */
			$schema            = $record[ $field ];
			$schema['version'] = '9.0';
			$record[ $field ]   = $schema;
		}

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );
		ContractLabManifest::from_array( $record );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function unknown_version_provider(): array {
		return array(
			'manifest version'     => array( 'manifest_version', 'Unknown Contract Lab manifest version' ),
			'probe schema version' => array( 'probe_schema', 'Unknown Contract Lab probe schema version' ),
			'observation schema version' => array( 'observation_schema', 'Unknown Contract Lab observation schema version' ),
		);
	}

	public function test_profiles_require_explicit_order_and_at_least_one_required_profile(): void {
		$base = ContractLabProfile::required( 'base', array( 'etch', 'etch-theme', 'contract-probe-plugin' ) );
		$optional = ContractLabProfile::optional( 'ome', array( 'oh-my-etch' ) );
		$manifest = ContractLabManifest::new(
			ContractLabEnvironmentConstraints::new( '>=6.6 <7.0', '>=8.1 <8.5', '>=6.0' ),
			ContractLabDeterministicSettings::new( 'en_US', 'UTC', '/%postname%/', 'etch', false ),
			array( $base, $optional ),
			ContractLabSchema::probe( '1.0', array( 'probe_id' ) ),
			ContractLabSchema::observation( '1.0', array( 'run_id' ) )
		);

		self::assertSame( array( $base, $optional ), $manifest->profiles() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'at least one required profile' );
		ContractLabManifest::new(
			$manifest->environment(),
			$manifest->settings(),
			array( $optional ),
			$manifest->probe_schema(),
			$manifest->observation_schema()
		);
	}

	/**
	 * @dataProvider forbidden_content_provider
	 * @param callable(): mixed $factory
	 */
	public function test_manifest_rejects_secrets_licenses_site_paths_and_non_machine_constraints( callable $factory, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );
		$factory();
	}

	/**
	 * @return array<string, array{callable(): mixed, string}>
	 */
	public function forbidden_content_provider(): array {
		return array(
			'bad version constraint' => array(
				fn (): ContractLabEnvironmentConstraints => ContractLabEnvironmentConstraints::new( '>=6.6; DROP TABLE', '>=8.1 <8.5', '>=6.0' ),
				'machine-checkable version constraint',
			),
			'site path' => array(
				fn (): ContractLabEnvironmentConstraints => ContractLabEnvironmentConstraints::new( '>=6.6 <7.0', '>=8.1 <8.5', '>=6.0', true, true, '/Users/woji/site' ),
				'WordPress root must be a stable token',
			),
			'license payload' => array(
				fn (): ContractLabProfile => ContractLabProfile::optional( 'woo', array( 'woocommerce', 'license-file' ) ),
				'forbidden license or secret content',
			),
			'secret schema field' => array(
				fn (): ContractLabSchema => ContractLabSchema::probe( '1.0', array( 'probe_id', 'api_token' ) ),
				'forbidden license or secret content',
			),
			'path-like theme' => array(
				fn (): ContractLabDeterministicSettings => ContractLabDeterministicSettings::new( 'en_US', 'UTC', '/%postname%/', '/tmp/theme', false ),
				'theme must be a stable token',
			),
		);
	}

	public function test_unknown_profile_shape_and_duplicate_fields_fail_closed(): void {
		$record = $this->manifest()->to_array();
		$record['profiles'][1]['id'] = 'base';

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'duplicate profile ID' );
		ContractLabManifest::from_array( $record );
	}

	private function manifest(): ContractLabManifest {
		return ContractLabManifest::new(
			ContractLabEnvironmentConstraints::new(
				'>=6.6 <7.0',
				'>=8.1 <8.5',
				'>=6.0',
				true,
				true,
				'wp'
			),
			ContractLabDeterministicSettings::new( 'en_US', 'UTC', '/%postname%/', 'etch', false ),
			array(
				ContractLabProfile::required( 'base', array( 'etch', 'etch-theme', 'contract-probe-plugin' ) ),
				ContractLabProfile::optional( 'ome', array( 'oh-my-etch' ) ),
				ContractLabProfile::optional( 'woo', array( 'woocommerce' ) ),
			),
			ContractLabSchema::probe( '1.0', array( 'probe_id', 'layer', 'fixture_id' ) ),
			ContractLabSchema::observation( '1.0', array( 'run_id', 'environment', 'profiles', 'observations' ) )
		);
	}
}
