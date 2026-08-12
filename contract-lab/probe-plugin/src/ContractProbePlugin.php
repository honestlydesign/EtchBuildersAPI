<?php
/**
 * Maintainer-only Contract Probe Plugin implementation.
 *
 * @package EtchBuildersContractProbe
 */

declare( strict_types=1 );

namespace EtchBuildersContractProbe;

/**
 * Exposes only the versioned normalized probe envelope.
 *
 * This scaffold deliberately does not inspect private Etch classes, copy Etch
 * code, or return arbitrary WordPress payloads. Later probes add observations
 * behind this same authorization and version boundary.
 */
final class ContractProbePlugin {

	public const REST_NAMESPACE = 'etch-builders-contract-lab/v1';

	public const REST_ROUTE = '/observe';

	public const PROBE_VERSION = '1.0';

	public const OBSERVATION_SCHEMA_VERSION = '1.0';

	public const MARKER_OPTION = 'etch_builders_contract_lab_marker';

	public const MARKER_VERSION = '1';

	public const LAB_ID = 'etch-builders-contract-lab';

	private function __construct() {
	}

	/**
	 * Register only through WordPress' public REST hook.
	 */
	public static function register(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		}
	}

	/**
	 * Register the single normalized observation endpoint.
	 */
	public static function register_route(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'observe' ),
				'permission_callback' => array( self::class, 'authorize' ),
			)
		);
	}

	/**
	 * Require a local/development site, the marker, a logged-in user, and the
	 * maintainer capability before any observation callback can run.
	 */
	public static function authorize( mixed $request ): bool|object {
		unset( $request );
		if ( ! function_exists( 'wp_get_environment_type' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return self::error( 'environment', 'Contract Probe is available only on local or development WordPress.' );
		}
		if ( ! function_exists( 'is_multisite' ) || is_multisite() ) {
			return self::error( 'multisite', 'Contract Probe requires a single-site WordPress installation.' );
		}
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return self::error( 'authentication', 'Contract Probe requires an authenticated WordPress user.' );
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return self::error( 'capability', 'Contract Probe requires the manage_options capability.' );
		}
		if ( ! function_exists( 'get_option' ) ) {
			return self::error( 'marker', 'Contract Probe cannot verify the Contract Lab marker.' );
		}
		$marker = get_option( self::MARKER_OPTION, null );
		if ( ! is_array( $marker ) || ( $marker['marker_version'] ?? null ) !== self::MARKER_VERSION || ( $marker['lab_id'] ?? null ) !== self::LAB_ID ) {
			return self::error( 'marker', 'Contract Probe requires the matching Contract Lab marker.' );
		}

		return true;
	}

	/**
	 * Return the scaffold envelope after authorization and version checks.
	 */
	public static function observe( mixed $request ): object {
		$probe_version       = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'probe_version' ) : null;
		$observation_version = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'observation_schema_version' ) : null;
		if ( ! self::supports_versions( $probe_version, $observation_version ) ) {
			return self::error( 'schema', 'Contract Probe rejects unknown probe or observation schema versions.' );
		}
		if ( class_exists( '\WP_REST_Response' ) ) {
			return new \WP_REST_Response(
				array(
					'probe_version'                => self::PROBE_VERSION,
					'observation_schema_version'   => self::OBSERVATION_SCHEMA_VERSION,
					'status'                       => 'scaffold',
					'observations'                 => array(),
				),
				200
			);
		}

		return (object) array(
			'probe_version'              => self::PROBE_VERSION,
			'observation_schema_version' => self::OBSERVATION_SCHEMA_VERSION,
			'status'                     => 'scaffold',
			'observations'               => array(),
		);
	}

	/**
	 * Keep unknown versions fail-closed without requiring WordPress in unit
	 * tests or in package-side contract tooling.
	 */
	public static function supports_versions( mixed $probe_version, mixed $observation_version ): bool {
		return is_string( $probe_version ) && self::PROBE_VERSION === $probe_version && is_string( $observation_version ) && self::OBSERVATION_SCHEMA_VERSION === $observation_version;
	}

	/**
	 * Document the only files owned by this plugin scaffold.
	 *
	 * @return array<int, string>
	 */
	public static function owned_files(): array {
		return array( 'contract-probe-plugin.php', 'src/ContractProbePlugin.php', 'README.md' );
	}

	private static function error( string $code, string $message ): object {
		if ( class_exists( '\WP_Error' ) ) {
			return new \WP_Error( 'etch_contract_probe_' . $code, $message, array( 'status' => 403 ) );
		}

		return (object) array(
			'code'    => 'etch_contract_probe_' . $code,
			'message' => $message,
		);
	}
}
