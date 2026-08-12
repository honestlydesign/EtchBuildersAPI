<?php
/**
 * Maintainer-only browser preservation sentinel runner.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\ContractLabBrowserSentinelClientInterface;

/**
 * Runs save/reload preservation checks without knowing product UI details.
 */
final class ContractLabBrowserSentinelRunner {

	private function __construct() {
	}

	/**
	 * @return array<int, ContractLabBrowserSentinelResult>
	 */
	public static function run_all( ContractLabBrowserSentinelCatalog $catalog, ContractLabBrowserSentinelClientInterface $client ): array {
		return array_map(
			static fn ( ContractLabBrowserSentinel $sentinel ): ContractLabBrowserSentinelResult => self::run( $sentinel, $client ),
			$catalog->all()
		);
	}

	public static function run( ContractLabBrowserSentinel $sentinel, ContractLabBrowserSentinelClientInterface $client ): ContractLabBrowserSentinelResult {
		$before = null;
		try {
			$before = self::observation( $client->capture( $sentinel ), 'capture' );
			$client->save( $sentinel );
			$after = self::observation( $client->reload( $sentinel ), 'reload' );

			return $before->to_array() === $after->to_array()
				? ContractLabBrowserSentinelResult::matched( $sentinel, $before, $after )
				: ContractLabBrowserSentinelResult::drift( $sentinel, $before, $after );
		} catch ( ContractLabObservationException $error ) {
			return match ( $error->reason() ) {
				'unsupported' => ContractLabBrowserSentinelResult::skipped( $sentinel, $before, $error->getMessage() ),
				'unavailable' => ContractLabBrowserSentinelResult::inconclusive( $sentinel, $before, $error->getMessage() ),
				default      => ContractLabBrowserSentinelResult::failed( $sentinel, $before, null, $error->getMessage() ),
			};
		} catch ( \Throwable $error ) {
			return ContractLabBrowserSentinelResult::inconclusive(
				$sentinel,
				$before,
				$error->getMessage() ?: 'Browser infrastructure failed before preservation could be observed.'
			);
		}
	}

	private static function observation( mixed $observation, string $phase ): ContractLabFrontendObservation {
		if ( ! $observation instanceof ContractLabFrontendObservation ) {
			throw new ContractLabObservationException( 'malformed', sprintf( 'Browser sentinel %s adapter returned an invalid frontend observation.', $phase ) );
		}

		return $observation;
	}
}
