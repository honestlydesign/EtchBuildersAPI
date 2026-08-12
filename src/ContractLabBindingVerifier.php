<?php
/**
 * Read-only verifier for the Contract Lab binding boundary.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Requires both re-resolved LocalWP state and the in-WordPress marker.
 */
final class ContractLabBindingVerifier {

	private function __construct() {
	}

	/**
	 * Verify a binding without touching LocalWP or mutating WordPress.
	 */
	public static function resolve(
		ContractLabBinding $binding,
		ContractLabSiteState $site,
		ContractLabManifest $manifest
	): ContractLabBindingResolution {
		self::assert_same( $binding->site_id(), $site->site_id(), 'Contract Lab Local site ID does not match the binding.' );
		self::assert_same( $binding->site_name(), $site->site_name(), 'Contract Lab Local site name does not match the binding.' );
		self::assert_same( $binding->site_url(), $site->site_url(), 'Contract Lab site URL does not match the binding.' );
		self::assert_same( $binding->web_root(), $site->web_root(), 'Contract Lab web root does not match the binding.' );

		if ( ! $site->is_single_site() ) {
			throw new InvalidArgumentException( 'Contract Lab requires a single-site WordPress installation.' );
		}
		if ( ! in_array( $site->environment_type(), array( 'local', 'development' ), true ) ) {
			throw new InvalidArgumentException( 'WordPress environment must be local or development.' );
		}
		self::assert_same( $manifest->environment()->expected_wordpress_root(), $site->wordpress_root(), 'WordPress root does not match the manifest.' );

		$marker = $site->marker();
		if ( null === $marker ) {
			throw new InvalidArgumentException( 'Contract Lab marker is missing from the resolved WordPress site.' );
		}
		self::assert_same( ContractLabBinding::LAB_ID, $marker->to_array()['lab_id'], 'Contract Lab marker lab identity does not match.' );
		self::assert_same( $binding->marker_id(), $marker->marker_id(), 'Contract Lab marker ID does not match the binding.' );
		self::assert_same( $site->site_id(), $marker->site_id(), 'Contract Lab marker site ID does not match the resolved Local site.' );
		self::assert_same( $site->wordpress_root(), $marker->wordpress_root(), 'Contract Lab marker WordPress root does not match the resolved site.' );

		$base_profile = null;
		foreach ( $manifest->profiles() as $profile ) {
			if ( 'base' === $profile->id() ) {
				$base_profile = $profile;
				break;
			}
		}
		if ( null === $base_profile ) {
			throw new InvalidArgumentException( 'Contract Lab manifest has no base profile.' );
		}
		foreach ( $base_profile->plugin_prerequisites() as $plugin ) {
			if ( ! in_array( $plugin, $site->active_prerequisites(), true ) ) {
				throw new InvalidArgumentException( sprintf( 'Contract Lab required plugin "%s" is missing or inactive.', $plugin ) );
			}
		}

		return ContractLabBindingResolution::verified(
			$site->site_id(),
			$site->site_url(),
			$site->web_root(),
			$site->wordpress_root(),
			$marker->marker_id(),
			array(
				array( 'kind' => 'wordpress-site', 'identity' => $site->site_id() ),
				array( 'kind' => 'marker-option', 'identity' => ContractLabBinding::MARKER_OPTION ),
				array( 'kind' => 'fixture-namespace', 'identity' => ContractLabBinding::FIXTURE_NAMESPACE ),
			)
		);
	}

	private static function assert_same( string $expected, string $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new InvalidArgumentException( $message );
		}
	}
}
