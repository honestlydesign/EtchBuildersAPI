<?php
/**
 * Maintainer-only composite frontend render probe.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\ContractLabFrontendHttpClientInterface;

/**
 * Fetches a small fixture set once and observes DOM, CSS, class, slot, loop,
 * and supported dynamic markers without introducing a browser harness.
 */
final class ContractLabFrontendProbe {

	private function __construct() {
	}

	/**
	 * @return array<int, ContractLabFrontendProbeResult>
	 */
	public static function run_all( ContractLabFrontendFixtureCatalog $catalog, ContractLabFrontendHttpClientInterface $client ): array {
		return array_map(
			static fn ( ContractLabFrontendFixture $fixture ): ContractLabFrontendProbeResult => self::run( $fixture, $client ),
			$catalog->all()
		);
	}

	public static function run( ContractLabFrontendFixture $fixture, ContractLabFrontendHttpClientInterface $client ): ContractLabFrontendProbeResult {
		$issuer = new self();
		try {
			$response = $client->get( $fixture->path() );
			if ( ! $response instanceof ContractLabFrontendHttpResponse ) {
				throw new ContractLabObservationException( 'malformed', 'Frontend HTTP adapter returned an invalid response.' );
			}
			if ( ! $response->is_successful() ) {
				throw new ContractLabObservationException( 'unavailable', sprintf( 'Frontend fixture HTTP request returned status %d.', $response->status() ) );
			}

			$dom          = ContractLabFrontendDomNormalizer::normalize( $response->body() );
			$stylesheets  = self::stylesheets( $response->body(), $client );
			$capabilities = array();
			$failures     = array();
			foreach ( $fixture->capabilities() as $capability ) {
				$marker = $fixture->marker( $capability );
				$found  = self::capability_is_observed( $capability, $marker, $dom, $stylesheets );
				$capabilities[] = array(
					'capability' => $capability,
					'marker'     => $marker,
					'status'     => $found ? 'observed' : 'failed',
				);
				if ( ! $found ) {
					$failures[] = $capability;
				}
			}
			$observation = ContractLabFrontendObservation::observed(
				$fixture->logical_id(),
				$fixture->path(),
				$response->status(),
				$dom,
				$stylesheets,
				$capabilities
			);

			$result = array() === $failures
				? ContractLabFrontendProbeResult::observed( $observation )
				: ContractLabFrontendProbeResult::failed( $observation, $failures, 'One or more capability markers were not observed.' );

			return $result->with_execution_provenance( ContractLabExecutionProvenance::from_frontend_probe( $issuer, $fixture->logical_id(), $result->to_array() ) );
		} catch ( ContractLabObservationException $error ) {
			$result = match ( $error->reason() ) {
				'unsupported' => ContractLabFrontendProbeResult::skipped( $fixture->logical_id(), $error->getMessage() ),
				'unavailable' => ContractLabFrontendProbeResult::inconclusive( $fixture->logical_id(), $error->getMessage() ),
				default      => ContractLabFrontendProbeResult::failed_without_observation( $fixture->logical_id(), $error->getMessage() ),
			};

			return $result->with_execution_provenance( ContractLabExecutionProvenance::from_frontend_probe( $issuer, $fixture->logical_id(), $result->to_array() ) );
		} catch ( \Throwable $error ) {
			$result = ContractLabFrontendProbeResult::inconclusive(
				$fixture->logical_id(),
				$error->getMessage() ?: 'Frontend HTTP transport failed before an observation was produced.'
			);

			return $result->with_execution_provenance( ContractLabExecutionProvenance::from_frontend_probe( $issuer, $fixture->logical_id(), $result->to_array() ) );
		}
	}

	/**
	 * Re-check an observation against the independent fixture contract.
	 *
	 * This is deliberately separate from run(): a caller cannot turn a forged
	 * capability list into green evidence merely by constructing an observation
	 * with all statuses set to "observed".
	 */
	public static function assert_observation( ContractLabFrontendFixture $fixture, ContractLabFrontendObservation $observation ): void {
		if ( $fixture->logical_id() !== $observation->fixture_id() || $fixture->path() !== $observation->fixture_path() ) {
			throw new ContractLabObservationException( 'failed', sprintf( 'Frontend observation does not identify fixture "%s" exactly.', $fixture->logical_id() ) );
		}
		if ( $observation->response_status() < 200 || $observation->response_status() > 299 ) {
			throw new ContractLabObservationException( 'failed', sprintf( 'Frontend fixture "%s" observation is not a successful HTTP response.', $fixture->logical_id() ) );
		}

		$expected_capabilities = array();
		foreach ( $fixture->capabilities() as $capability ) {
			$expected_capabilities[] = array(
				'capability' => $capability,
				'marker'     => $fixture->marker( $capability ),
				'status'     => 'observed',
			);
		}
		if ( $expected_capabilities !== $observation->capabilities() ) {
			throw new ContractLabObservationException( 'failed', sprintf( 'Frontend fixture "%s" capability evidence is not the canonical ordered marker set.', $fixture->logical_id() ) );
		}

		foreach ( $fixture->capabilities() as $capability ) {
			if ( ! self::capability_is_observed( $capability, $fixture->marker( $capability ), $observation->dom(), $observation->stylesheets() ) ) {
				throw new ContractLabObservationException( 'failed', sprintf( 'Frontend fixture "%s" marker for "%s" was not independently observed.', $fixture->logical_id(), $capability ) );
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function stylesheets( string $html, ContractLabFrontendHttpClientInterface $client ): array {
		$document = ContractLabFrontendDomNormalizer::parse_document( $html );
		$records  = array();
		foreach ( $document->getElementsByTagName( '*' ) as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}
			$name = strtolower( $element->tagName );
			if ( 'style' === $name ) {
				$records[] = array(
					'source' => 'inline',
					'path'   => null,
					'rules'  => ContractLabFrontendCssNormalizer::normalize( (string) $element->textContent ),
				);
				continue;
			}
			if ( 'link' !== $name || ! self::is_stylesheet_link( $element->getAttribute( 'rel' ) ) ) {
				continue;
			}
			$path = self::normalize_asset_path( $element->getAttribute( 'href' ) );
			$asset = $client->get( $path );
			if ( ! $asset->is_successful() ) {
				throw new ContractLabObservationException( 'unavailable', sprintf( 'Frontend stylesheet request returned status %d for %s.', $asset->status(), $path ) );
			}
			$records[] = array(
				'source' => 'linked',
				'path'   => $path,
				'rules'  => ContractLabFrontendCssNormalizer::normalize( $asset->body() ),
			);
		}

		return $records;
	}

	private static function is_stylesheet_link( string $rel ): bool {
		$tokens = preg_split( '/\s+/', strtolower( trim( $rel ) ) );

		return is_array( $tokens ) && in_array( 'stylesheet', $tokens, true );
	}

	private static function normalize_asset_path( string $href ): string {
		if ( '' === $href || trim( $href ) !== $href || preg_match( '/[[:cntrl:]]/', $href ) || str_contains( $href, chr( 92 ) ) ) {
			throw new ContractLabObservationException( 'unsupported', 'Frontend stylesheet link is not a safe HTTP path.' );
		}
		$parsed = parse_url( $href );
		if ( false === $parsed || isset( $parsed['scheme'] ) || isset( $parsed['host'] ) || isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed['fragment'] ) ) {
			throw new ContractLabObservationException( 'unsupported', 'Frontend stylesheet links must be same-origin root-relative paths.' );
		}
		$path = $parsed['path'] ?? null;
		if ( ! is_string( $path ) || ! str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) ) {
			throw new ContractLabObservationException( 'unsupported', 'Frontend stylesheet links must be same-origin root-relative paths.' );
		}
		$query = $parsed['query'] ?? null;
		$query = is_string( $query ) ? '?' . $query : '';

		return $path . $query;
	}

	/**
	 * @param array<int, array<string, mixed>> $dom
	 * @param array<int, array<string, mixed>> $stylesheets
	 */
	private static function capability_is_observed( string $capability, string $marker, array $dom, array $stylesheets ): bool {
		if ( 'stylesheet' === $capability ) {
			foreach ( $stylesheets as $stylesheet ) {
				if ( is_array( $stylesheet['rules'] ?? null ) && ContractLabFrontendCssNormalizer::contains_selector( $stylesheet['rules'], $marker ) ) {
					return true;
				}
			}

			return false;
		}
		if ( 'class' === $capability ) {
			return self::has_class_token( $dom, $marker );
		}
		$attribute = 'dom' === $capability ? 'data-contract-fixture' : 'data-contract-' . $capability;

		return self::has_attribute_value( $dom, $attribute, $marker );
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes
	 */
	private static function has_attribute_value( array $nodes, string $attribute, string $expected ): bool {
		foreach ( $nodes as $node ) {
			if ( 'element' === ( $node['type'] ?? null ) && ( $node['attributes'][ $attribute ] ?? null ) === $expected ) {
				return true;
			}
			$children = $node['children'] ?? array();
			if ( is_array( $children ) && self::has_attribute_value( $children, $attribute, $expected ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes
	 */
	private static function has_class_token( array $nodes, string $expected ): bool {
		foreach ( $nodes as $node ) {
			if ( 'element' === ( $node['type'] ?? null ) && is_string( $node['attributes']['class'] ?? null ) ) {
				$tokens = preg_split( '/\s+/', trim( $node['attributes']['class'] ) );
				if ( is_array( $tokens ) && in_array( $expected, $tokens, true ) ) {
					return true;
				}
			}
			$children = $node['children'] ?? array();
			if ( is_array( $children ) && self::has_class_token( $children, $expected ) ) {
				return true;
			}
		}

		return false;
	}
}
