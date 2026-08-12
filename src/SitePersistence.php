<?php
/**
 * Shared compiled-plan persistence engine.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStoreInterface;
use Throwable;

/**
 * Applies normalized plan records through one store contract.
 */
class SitePersistence implements SitePersistenceInterface {

	private const DEPENDENCY_INVALID = 'ETCH_SITE_PERSISTENCE_DEPENDENCY_INVALID';

	private const DEPENDENCY_CYCLE = 'ETCH_SITE_PERSISTENCE_DEPENDENCY_CYCLE';

	public function __construct( private readonly SitePersistenceStoreInterface $store ) {
	}

	/**
	 * Apply entities first, then styles, then assets in plan order.
	 */
	public function apply( CompiledSitePlan $plan ): SitePersistenceReport {
		$blocking = array_values(
			array_filter(
				$plan->diagnostics(),
				static fn ( CompiledSiteDiagnostic $diagnostic ): bool => CompiledSiteDiagnosticSeverity::ERROR === $diagnostic->severity()
			)
		);

		if ( array() !== $blocking ) {
			return SitePersistenceReport::new( blocking_diagnostics: $blocking );
		}

		$ordered = $this->order_entities( $plan );
		if ( array() !== $ordered['diagnostics'] ) {
			return SitePersistenceReport::new( blocking_diagnostics: $ordered['diagnostics'] );
		}

		$records = array();
		foreach ( $ordered['entities'] as $entity ) {
			$records[] = SitePersistenceRecord::from_entity( $entity );
		}
		foreach ( $plan->styles() as $style ) {
			$records[] = SitePersistenceRecord::from_resource( $style );
		}
		foreach ( $plan->assets() as $asset ) {
			$records[] = SitePersistenceRecord::from_resource( $asset );
		}

		$results = array();
		foreach ( $records as $record ) {
			$results[] = $this->apply_record( $record );
		}

		return SitePersistenceReport::new( $results );
	}

	/**
	 * Order all compiled entities by their dependency edges while preserving
	 * plan order as the deterministic tie-breaker.
	 *
	 * @return array{entities: array<int, CompiledSiteEntity>, diagnostics: array<int, CompiledSiteDiagnostic>}
	 */
	private function order_entities( CompiledSitePlan $plan ): array {
		$entities      = $plan->entities();
		$by_identity   = array();
		$positions     = array();
		$indegree      = array();
		$outgoing      = array();

		foreach ( $entities as $position => $entity ) {
			$identity               = $entity->identity();
			$by_identity[ $identity ] = $entity;
			$positions[ $identity ]  = $position;
			$indegree[ $identity ]   = 0;
		}

		foreach ( $plan->dependencies() as $dependency ) {
			$consumer_identity   = $dependency->consumer_identity();
			$dependency_identity = $dependency->dependency_identity();

			if ( ! isset( $by_identity[ $consumer_identity ] ) || ! isset( $by_identity[ $dependency_identity ] ) ) {
				return array(
					'entities'    => array(),
					'diagnostics' => array(
						CompiledSiteDiagnostic::new(
							self::DEPENDENCY_INVALID,
							CompiledSiteDiagnosticSeverity::ERROR,
							sprintf( 'Compiled Site dependency "%s" -> "%s" does not resolve to two plan entities.', $consumer_identity, $dependency_identity ),
							$consumer_identity
						)
					),
				);
			}

			if ( 'pattern' === $dependency->kind() && CompiledSiteEntityType::PATTERN !== $by_identity[ $dependency_identity ]->type() ) {
				return array(
					'entities'    => array(),
					'diagnostics' => array(
						CompiledSiteDiagnostic::new(
							self::DEPENDENCY_INVALID,
							CompiledSiteDiagnosticSeverity::ERROR,
							sprintf( 'Pattern dependency "%s" does not resolve to a Pattern entity.', $dependency_identity ),
							$consumer_identity
						)
					),
				);
			}

			if ( $consumer_identity === $dependency_identity ) {
				return array(
					'entities'    => array(),
					'diagnostics' => array(
						CompiledSiteDiagnostic::new(
							self::DEPENDENCY_CYCLE,
							CompiledSiteDiagnosticSeverity::ERROR,
							'Compiled Site dependency graph contains a self-cycle.',
							$consumer_identity
						)
					),
				);
			}

			if ( isset( $outgoing[ $dependency_identity ][ $consumer_identity ] ) ) {
				continue;
			}

			$outgoing[ $dependency_identity ][ $consumer_identity ] = true;
			++$indegree[ $consumer_identity ];
		}

		$ready = array_values(
			array_filter(
				array_keys( $indegree ),
				static function ( string $identity ) use ( $indegree ): bool {
					return 0 === $indegree[ $identity ];
				}
			)
		);
		$this->sort_entity_identities( $ready, $positions );

		$ordered = array();
		while ( array() !== $ready ) {
			$identity  = array_shift( $ready );
			$ordered[] = $by_identity[ $identity ];

			$consumers = array_keys( $outgoing[ $identity ] ?? array() );
			$this->sort_entity_identities( $consumers, $positions );
			foreach ( $consumers as $consumer ) {
				--$indegree[ $consumer ];
				if ( 0 === $indegree[ $consumer ] ) {
					$ready[] = $consumer;
				}
			}
			$this->sort_entity_identities( $ready, $positions );
		}

		if ( count( $ordered ) !== count( $entities ) ) {
			$remaining = array_keys(
				array_filter(
					$indegree,
					static fn ( int $degree ): bool => $degree > 0
				)
			);
			$this->sort_entity_identities( $remaining, $positions );

			return array(
				'entities'    => array(),
				'diagnostics' => array(
					CompiledSiteDiagnostic::new(
						self::DEPENDENCY_CYCLE,
						CompiledSiteDiagnosticSeverity::ERROR,
						'Compiled Site dependency graph contains a cycle.',
						$remaining[0] ?? null
					)
				),
			);
		}

		return array(
			'entities'    => $ordered,
			'diagnostics' => array(),
		);
	}

	/**
	 * @param array<int, string> $identities
	 * @param array<string, int> $positions
	 */
	private function sort_entity_identities( array &$identities, array $positions ): void {
		usort(
			$identities,
			static fn ( string $left, string $right ): int => $positions[ $left ] <=> $positions[ $right ]
		);
	}

	private function apply_record( SitePersistenceRecord $record ): SitePersistenceResult {
		try {
			$current = $this->store->find( $record->identity() );
		} catch ( Throwable $throwable ) {
			return self::failure( $record->identity(), $throwable );
		}

		if ( null === $current ) {
			return $this->write( $record, false );
		}

		if ( ! $current->is_owned() ) {
			return SitePersistenceResult::new(
				$record->identity(),
				SitePersistenceOutcome::CONFLICT,
				'ETCH_SITE_PERSISTENCE_CONFLICT',
				'Existing Site record is not owned by this builder.'
			);
		}

		if ( $current->fingerprint() === $record->fingerprint() && $current->kind() === $record->kind() ) {
			return SitePersistenceResult::new(
				$record->identity(),
				SitePersistenceOutcome::UNCHANGED,
				'ETCH_SITE_PERSISTENCE_UNCHANGED',
				'Compiled Site record is already current.'
			);
		}

		return $this->write( $record, true );
	}

	private function write( SitePersistenceRecord $record, bool $update ): SitePersistenceResult {
		try {
			$result = $update ? $this->store->update( $record ) : $this->store->create( $record );
		} catch ( Throwable $throwable ) {
			return self::failure( $record->identity(), $throwable );
		}

		if ( ! $result->is_success() ) {
			$outcome = 'ETCH_SITE_PERSISTENCE_CONFLICT' === $result->get_error_code()
				? SitePersistenceOutcome::CONFLICT
				: SitePersistenceOutcome::FAILED;

			return SitePersistenceResult::new(
				$record->identity(),
				$outcome,
				$result->get_error_code() ?: 'ETCH_SITE_PERSISTENCE_FAILED',
				$result->get_error_message() ?: 'Compiled Site record could not be persisted.'
			);
		}

		return SitePersistenceResult::new(
			$record->identity(),
			$update ? SitePersistenceOutcome::UPDATED : SitePersistenceOutcome::CREATED,
			$update ? 'ETCH_SITE_PERSISTENCE_UPDATED' : 'ETCH_SITE_PERSISTENCE_CREATED',
			$update ? 'Compiled Site record was updated.' : 'Compiled Site record was created.'
		);
	}

	private static function failure( string $identity, Throwable $throwable ): SitePersistenceResult {
		return SitePersistenceResult::new(
			$identity,
			SitePersistenceOutcome::FAILED,
			'ETCH_SITE_PERSISTENCE_FAILED',
			$throwable->getMessage() ?: 'Compiled Site record could not be persisted.'
		);
	}
}
