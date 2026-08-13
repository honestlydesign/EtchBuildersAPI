<?php
/**
 * Maintainer-only passive JavaScript marker runner.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\ContractLabJavascriptMarkerClientInterface;

/**
 * Reads exactly one marker from an already navigated browser flow.
 */
final class ContractLabJavascriptMarkerRunner {

	private function __construct() {
	}

	public static function run( ContractLabJavascriptMarker $marker, ContractLabJavascriptMarkerClientInterface $client ): ContractLabJavascriptMarkerResult {
		$issuer = new self();
		try {
			$observed_value = $client->read_marker( $marker );
			if ( null === $observed_value ) {
				$result = ContractLabJavascriptMarkerResult::failed( $marker, null, 'JavaScript marker missing from the existing browser flow.' );

				return $result->with_execution_provenance( ContractLabExecutionProvenance::from_javascript_runner( $issuer, $marker, $result->to_array() ) );
			}
			if ( preg_match( '/[[:cntrl:]]/', $observed_value ) ) {
				throw new ContractLabObservationException( 'malformed', 'JavaScript marker observation contains control characters.' );
			}
			if ( $observed_value !== $marker->expected_value() ) {
				$result = ContractLabJavascriptMarkerResult::failed( $marker, $observed_value, sprintf( 'JavaScript marker value "%s" does not equal the expected value.', $observed_value ) );

				return $result->with_execution_provenance( ContractLabExecutionProvenance::from_javascript_runner( $issuer, $marker, $result->to_array() ) );
			}

			$result = ContractLabJavascriptMarkerResult::observed( $marker, $observed_value );

			return $result->with_execution_provenance( ContractLabExecutionProvenance::from_javascript_runner( $issuer, $marker, $result->to_array() ) );
		} catch ( ContractLabObservationException $error ) {
			$result = match ( $error->reason() ) {
				'unsupported' => ContractLabJavascriptMarkerResult::skipped( $marker, $error->getMessage() ),
				'unavailable' => ContractLabJavascriptMarkerResult::inconclusive( $marker, $error->getMessage() ),
				default      => ContractLabJavascriptMarkerResult::failed( $marker, null, $error->getMessage() ),
			};

			return $result->with_execution_provenance( ContractLabExecutionProvenance::from_javascript_runner( $issuer, $marker, $result->to_array() ) );
		} catch ( \Throwable $error ) {
			$result = ContractLabJavascriptMarkerResult::inconclusive(
				$marker,
				$error->getMessage() ?: 'Browser infrastructure failed before the JavaScript marker could be read.'
			);

			return $result->with_execution_provenance( ContractLabExecutionProvenance::from_javascript_runner( $issuer, $marker, $result->to_array() ) );
		}
	}
}
