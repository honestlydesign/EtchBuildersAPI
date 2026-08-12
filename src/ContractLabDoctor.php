<?php
/**
 * Read-only Contract Lab environment and contract doctor.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Performs preflight only; lock acquisition belongs to the mutation runner.
 */
final class ContractLabDoctor {

	private function __construct() {
	}

	/**
	 * Inspect all available facts without acquiring a lock or writing state.
	 */
	public static function inspect(
		ContractLabBinding $binding,
		ContractLabSiteState $site,
		ContractLabManifest $manifest,
		ContractLabDoctorEvidence $evidence,
		string $expected_etch_fingerprint
	): ContractLabDoctorResult {
		ContractLabManifestSafety::assert_digest( $expected_etch_fingerprint, 'Expected Etch fingerprint' );
		/** @var array<int, array{category: string, code: string, message: string}> $findings */
		$findings = array();
		$binding_verified = true;
		try {
			ContractLabBindingVerifier::resolve( $binding, $site, $manifest );
		} catch ( InvalidArgumentException $error ) {
			$binding_verified = false;
			$findings[]       = array(
				'category' => 'environment',
				'code'     => 'binding',
				'message'  => $error->getMessage(),
			);
		}

		self::check_version( $findings, 'wordpress-version', 'WordPress version', $manifest->environment()->wordpress_constraint(), $evidence->wordpress_version() );
		self::check_version( $findings, 'php-version', 'PHP version', $manifest->environment()->php_constraint(), $evidence->php_version() );
		self::check_version( $findings, 'localwp-version', 'LocalWP version', $manifest->environment()->localwp_constraint(), $evidence->localwp_version() );

		if ( $binding_verified ) {
			foreach ( $manifest->profiles() as $profile ) {
				if ( ! $profile->is_required() || 'base' === $profile->id() ) {
					continue;
				}
				foreach ( $profile->plugin_prerequisites() as $prerequisite ) {
					if ( ! in_array( $prerequisite, $site->active_prerequisites(), true ) ) {
						$findings[] = array(
							'category' => 'environment',
							'code'     => 'profile-' . $profile->id(),
							'message'  => sprintf( 'Required Contract Lab profile "%s" prerequisite "%s" is missing or inactive.', $profile->id(), $prerequisite ),
						);
					}
				}
			}
		}

		if ( $expected_etch_fingerprint !== $evidence->etch_fingerprint() ) {
			$findings[] = array(
				'category' => 'contract',
				'code'     => 'etch-fingerprint',
				'message'  => 'Etch artifact fingerprint does not match the expected contract input.',
			);
		}
		if ( $manifest->probe_schema()->version() !== $evidence->probe_schema_version() ) {
			$findings[] = array(
				'category' => 'contract',
				'code'     => 'probe-schema',
				'message'  => 'Contract Lab probe schema version is incompatible with the manifest.',
			);
		}
		if ( $manifest->observation_schema()->version() !== $evidence->observation_schema_version() ) {
			$findings[] = array(
				'category' => 'contract',
				'code'     => 'observation-schema',
				'message'  => 'Contract Lab observation schema version is incompatible with the manifest.',
			);
		}

		return ContractLabDoctorResult::from_findings( $findings );
	}

	/**
	 * @param array<int, array{category: string, code: string, message: string}> $findings
	 */
	private static function check_version( array &$findings, string $code, string $label, string $constraint, string $version ): void {
		if ( ! ContractLabVersionConstraint::matches( $constraint, $version ) ) {
			$findings[] = array(
				'category' => 'environment',
				'code'     => $code,
				'message'  => sprintf( '%s %s does not satisfy manifest constraint %s.', $label, $version, $constraint ),
			);
		}
	}
}
