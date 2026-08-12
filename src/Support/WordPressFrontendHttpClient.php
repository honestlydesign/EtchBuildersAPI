<?php
/**
 * WordPress HTTP adapter for maintainer-only Contract Lab frontend probes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\ContractLabFrontendHttpResponse;
use HonestlyDesign\EtchBuilders\ContractLabObservationException;
use HonestlyDesign\EtchBuilders\Contracts\ContractLabFrontendHttpClientInterface;

/**
 * Delegates transport to WordPress' public same-origin HTTP API.
 */
final class WordPressFrontendHttpClient implements ContractLabFrontendHttpClientInterface {

	public function get( string $path ): ContractLabFrontendHttpResponse {
		self::assert_path( $path );
		if ( ! function_exists( 'wp_get_environment_type' ) || ! in_array( \wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			throw new ContractLabObservationException( 'unsupported', 'Frontend HTTP probing is limited to local or development WordPress.' );
		}
		if ( function_exists( 'is_multisite' ) && \is_multisite() ) {
			throw new ContractLabObservationException( 'unsupported', 'Frontend HTTP probing requires a single-site WordPress installation.' );
		}
		foreach ( array( 'home_url', 'wp_safe_remote_get', 'is_wp_error', 'wp_remote_retrieve_response_code', 'wp_remote_retrieve_body' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				throw new ContractLabObservationException( 'unavailable', sprintf( 'WordPress public HTTP surface "%s" is unavailable.', $function ) );
			}
		}

		$url      = \home_url( $path );
		$response = \wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
			)
		);
		if ( \is_wp_error( $response ) ) {
			throw new ContractLabObservationException( 'unavailable', 'WordPress frontend HTTP transport could not fetch the fixture.' );
		}

		return ContractLabFrontendHttpResponse::new(
			(int) \wp_remote_retrieve_response_code( $response ),
			(string) \wp_remote_retrieve_body( $response )
		);
	}

	private static function assert_path( string $path ): void {
		if ( '' === $path || trim( $path ) !== $path || ! str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) || preg_match( '/[[:cntrl:]#]/', $path ) || str_contains( $path, chr( 92 ) ) ) {
			throw new ContractLabObservationException( 'malformed', 'WordPress frontend HTTP paths must be root-relative and credential-free.' );
		}
		$parsed = parse_url( $path );
		if ( ! is_array( $parsed ) || isset( $parsed['scheme'] ) || isset( $parsed['host'] ) || isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed['fragment'] ) ) {
			throw new ContractLabObservationException( 'malformed', 'WordPress frontend HTTP paths must not contain an origin, credentials, or fragment.' );
		}
	}
}
