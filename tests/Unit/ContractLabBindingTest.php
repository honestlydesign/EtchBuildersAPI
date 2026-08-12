<?php
/**
 * Safe LocalWP Contract Lab binding tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit;

use HonestlyDesign\EtchBuilders\ContractLabBinding;
use HonestlyDesign\EtchBuilders\ContractLabBindingVerifier;
use HonestlyDesign\EtchBuilders\ContractLabDeterministicSettings;
use HonestlyDesign\EtchBuilders\ContractLabEnvironmentConstraints;
use HonestlyDesign\EtchBuilders\ContractLabManifest;
use HonestlyDesign\EtchBuilders\ContractLabMarker;
use HonestlyDesign\EtchBuilders\ContractLabSchema;
use HonestlyDesign\EtchBuilders\ContractLabSiteState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that local identifiers are only consistency inputs and that the
 * in-WordPress marker is required for any verified binding resolution.
 */
final class ContractLabBindingTest extends TestCase {

	public function test_binding_and_marker_are_versioned_canonical_and_credential_free(): void {
		$binding = $this->binding();
		$marker  = $this->marker();

		self::assertSame(
			array(
				'binding_version' => '1',
				'lab_id'          => 'etch-builders-contract-lab',
				'site_id'         => 'jTAefG5iA',
				'site_name'       => 'etch-builders-contract-lab',
				'site_url'        => 'http://etch-builders-contract-lab.local',
				'web_root'        => '/tmp/contract-lab/app/public',
				'marker_id'       => 'marker-123',
			),
			$binding->to_array()
		);
		self::assertSame(
			array(
				'marker_version' => '1',
				'lab_id'         => 'etch-builders-contract-lab',
				'marker_id'      => 'marker-123',
				'site_id'        => 'jTAefG5iA',
				'wordpress_root' => 'wp',
			),
			$marker->to_array()
		);
		self::assertSame( $binding->to_array(), ContractLabBinding::from_array( $binding->to_array() )->to_array() );
		self::assertSame( $marker->to_array(), ContractLabMarker::from_array( $marker->to_array() )->to_array() );
	}

	/**
	 * @dataProvider invalid_binding_provider
	 * @param callable(): mixed $factory
	 */
	public function test_binding_rejects_unknown_versions_credentials_and_unsafe_locators( callable $factory, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );
		$factory();
	}

	/**
	 * @return array<string, array{callable(): mixed, string}>
	 */
	public function invalid_binding_provider(): array {
		$binding = $this->binding()->to_array();

		$unknown_version              = $binding;
		$unknown_version['binding_version'] = '9';
		$path_traversal               = $binding;
		$path_traversal['web_root']   = '/tmp/contract-lab/../other';
		$credentials                  = $binding;
		$credentials['credentials']   = 'password=secret';
		$unsafe_url                   = $binding;
		$unsafe_url['site_url']       = 'https://user:password@etch-builders-contract-lab.local';

		return array(
			'unknown binding version' => array(
				fn (): ContractLabBinding => ContractLabBinding::from_array( $unknown_version ),
				'Unknown Contract Lab binding version',
			),
			'path traversal' => array(
				fn (): ContractLabBinding => ContractLabBinding::from_array( $path_traversal ),
				'web root must be an absolute normalized path',
			),
			'credentials are not a binding field' => array(
				fn (): ContractLabBinding => ContractLabBinding::from_array( $credentials ),
				'must contain exactly its canonical fields',
			),
			'url credentials' => array(
				fn (): ContractLabBinding => ContractLabBinding::from_array( $unsafe_url ),
				'site URL must not contain credentials',
			),
		);
	}

	public function test_unknown_marker_version_fails_before_marker_identity_is_interpreted(): void {
		$record                   = $this->marker()->to_array();
		$record['marker_version'] = '9';
		$record['site_id']        = array( 'copied' );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown Contract Lab marker version' );
		ContractLabMarker::from_array( $record );
	}

	public function test_verified_resolution_requires_marker_and_reports_all_owned_targets(): void {
		$resolution = ContractLabBindingVerifier::resolve(
			$this->binding(),
			$this->site_state( $this->marker() ),
			$this->manifest()
		);

		self::assertSame( 'verified', $resolution->status() );
		self::assertSame( 'jTAefG5iA', $resolution->site_id() );
		self::assertSame(
			array(
				array( 'kind' => 'wordpress-site', 'identity' => 'jTAefG5iA' ),
				array( 'kind' => 'marker-option', 'identity' => 'etch_builders_contract_lab_marker' ),
				array( 'kind' => 'fixture-namespace', 'identity' => 'etch-builders-contract-lab' ),
			),
			$resolution->mutable_targets()
		);
		self::assertSame( $resolution->to_array(), $resolution->report() );
	}

	/**
	 * @dataProvider failed_resolution_provider
	 * @param callable(): ContractLabSiteState $state_factory
	 */
	public function test_binding_resolution_fails_closed_for_marker_site_environment_and_plugin_drift( callable $state_factory, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );
		ContractLabBindingVerifier::resolve( $this->binding(), $state_factory(), $this->manifest() );
	}

	/**
	 * @return array<string, array{callable(): ContractLabSiteState, string}>
	 */
	public function failed_resolution_provider(): array {
		return array(
			'missing marker' => array(
				fn (): ContractLabSiteState => $this->site_state( null ),
				'Contract Lab marker is missing',
			),
			'marker copied to wrong site' => array(
				fn (): ContractLabSiteState => $this->site_state( ContractLabMarker::new( 'marker-123', 'other-site', 'wp' ) ),
				'marker site ID does not match the resolved Local site',
			),
			'local identity drift' => array(
				fn (): ContractLabSiteState => $this->site_state( $this->marker(), 'other-site' ),
				'Local site ID does not match the binding',
			),
			'web root drift' => array(
				fn (): ContractLabSiteState => $this->site_state( $this->marker(), 'jTAefG5iA', '/tmp/other/app/public' ),
				'web root does not match the binding',
			),
			'multisite' => array(
				fn (): ContractLabSiteState => $this->site_state( $this->marker(), 'jTAefG5iA', null, null, false ),
				'Contract Lab requires a single-site WordPress installation',
			),
			'production environment' => array(
				fn (): ContractLabSiteState => $this->site_state( $this->marker(), 'jTAefG5iA', null, 'production' ),
				'WordPress environment must be local or development',
			),
			'missing Etch' => array(
				fn (): ContractLabSiteState => $this->site_state( $this->marker(), 'jTAefG5iA', null, null, true, array( 'etch-theme', 'contract-probe-plugin' ) ),
				'required plugin "etch" is missing or inactive',
			),
		);
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

	private function marker(): ContractLabMarker {
		return ContractLabMarker::new( 'marker-123', 'jTAefG5iA', 'wp' );
	}

	/**
	 * @param string|null              $site_id
	 * @param string|null              $web_root
	 * @param string|null              $environment_type
	 * @param bool                     $single_site
	 * @param array<int, string>|null  $active_plugins
	 */
	private function site_state(
		?ContractLabMarker $marker,
		?string $site_id = null,
		?string $web_root = null,
		?string $environment_type = null,
		bool $single_site = true,
		?array $active_plugins = null
	): ContractLabSiteState {
		return ContractLabSiteState::new(
			$site_id ?? 'jTAefG5iA',
			'etch-builders-contract-lab',
			'http://etch-builders-contract-lab.local',
			$web_root ?? '/tmp/contract-lab/app/public',
			'wp',
			$environment_type ?? 'local',
			$single_site,
			$active_plugins ?? array( 'etch', 'etch-theme', 'contract-probe-plugin' ),
			$marker
		);
	}

	private function manifest(): ContractLabManifest {
		return ContractLabManifest::new(
			ContractLabEnvironmentConstraints::new( '>=6.6 <7.0', '>=8.1 <8.5', '>=6.0' ),
			ContractLabDeterministicSettings::new( 'en_US', 'UTC', '/%postname%/', 'etch', false ),
			array(
				\HonestlyDesign\EtchBuilders\ContractLabProfile::required( 'base', array( 'etch', 'etch-theme', 'contract-probe-plugin' ) ),
			),
			ContractLabSchema::probe( '1.0', array( 'probe_id' ) ),
			ContractLabSchema::observation( '1.0', array( 'run_id' ) )
		);
	}
}
