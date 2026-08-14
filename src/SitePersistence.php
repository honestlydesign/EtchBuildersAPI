<?php
/**
 * Shared compiled-plan persistence engine.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceResourceStoreInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStoreInterface;
use Throwable;

/**
 * Applies normalized plan records through one store contract.
 */
class SitePersistence implements SitePersistenceInterface {

	private const DEPENDENCY_INVALID = 'ETCH_SITE_PERSISTENCE_DEPENDENCY_INVALID';

	private const DEPENDENCY_CYCLE = 'ETCH_SITE_PERSISTENCE_DEPENDENCY_CYCLE';

	private const OWNERSHIP_INVALID = 'ETCH_SITE_PERSISTENCE_OWNERSHIP_INVALID';

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

		$ownership_diagnostics = $this->validate_ownership( $plan );
		if ( array() !== $ownership_diagnostics ) {
			return SitePersistenceReport::new( blocking_diagnostics: $ownership_diagnostics );
		}

		/** @var array<int, array{record: SitePersistenceRecord, intent: CompiledSiteEntityPersistenceIntent}> $records */
		$records = array();
		foreach ( $ordered['entities'] as $entity ) {
			$records[] = array(
				'record' => SitePersistenceRecord::from_entity( $entity ),
				'intent' => $entity->persistence_intent(),
			);
		}
		foreach ( $plan->styles() as $style ) {
			$records[] = array(
				'record' => SitePersistenceRecord::from_resource( $style, true, $this->ownership_for( $plan, $style->identity() ) ),
				'intent' => CompiledSiteEntityPersistenceIntent::MANAGED,
			);
		}
		foreach ( $plan->assets() as $asset ) {
			$records[] = array(
				'record' => SitePersistenceRecord::from_resource( $asset, true, $this->ownership_for( $plan, $asset->identity() ) ),
				'intent' => CompiledSiteEntityPersistenceIntent::MANAGED,
			);
		}
		if ( $plan->has_home_page_policy() && SiteHomePolicyMode::NONE !== $plan->home_page_policy()->mode() ) {
			$records[] = array(
				'record' => SitePersistenceRecord::from_home_policy( $plan->home_page_policy() ),
				'intent' => CompiledSiteEntityPersistenceIntent::MANAGED,
			);
		}

		$results = array();
		foreach ( $records as $entry ) {
			$record = $entry['record'];
			$results[] = CompiledSiteEntityPersistenceIntent::VERIFY_NATIVE === $entry['intent']
				? $this->verify_native_record( $record )
				: $this->apply_record( $record );
		}

		if ( $this->all_results_succeeded( $results ) && $this->store instanceof SitePersistenceResourceStoreInterface ) {
			$this->cleanup_orphan_resources( $plan, $results );
		}

		return SitePersistenceReport::new( $results );
	}

	/**
	 * Explicitly migrate a finite compiled resource list into recorded ownership.
	 *
	 * This operation is intentionally separate from apply(): legacy prefixes and
	 * collections can authorize migration only when the caller supplies the
	 * exact current plan and the native record matches it.
	 */
	public function migrate_legacy_ownership( CompiledSitePlan $plan ): RegistrationResult {
		$blocking = array_values(
			array_filter(
				$plan->diagnostics(),
				static fn ( CompiledSiteDiagnostic $diagnostic ): bool => CompiledSiteDiagnosticSeverity::ERROR === $diagnostic->severity()
			)
		);
		if ( array() !== $blocking ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_BLOCKED', $blocking[0]->message() );
		}

		$ordered = $this->order_entities( $plan );
		if ( array() !== $ordered['diagnostics'] ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_BLOCKED', $ordered['diagnostics'][0]->message() );
		}

		$ownership_diagnostics = $this->validate_ownership( $plan );
		if ( array() !== $ownership_diagnostics ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_BLOCKED', $ownership_diagnostics[0]->message() );
		}

		if ( ! $this->store instanceof SitePersistenceResourceStoreInterface ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_LEGACY_MIGRATION_UNSUPPORTED', 'The configured Site persistence store does not support explicit legacy ownership migration.' );
		}

		return $this->store->migrate_legacy_ownership( $plan );
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
		if ( CompiledSiteEntityType::COMPONENT_CONTRACT_CATALOG->value === $record->kind() ) {
			return SitePersistenceResult::new(
				$record->identity(),
				SitePersistenceOutcome::FAILED,
				'ETCH_SITE_PERSISTENCE_CATALOG_NOT_RUNTIME',
				'Component Contract Catalog is a build-time contract and has no WordPress runtime record.'
			);
		}

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

	/**
	 * Verify one exact external runtime prerequisite without writing or claiming it.
	 */
	private function verify_native_record( SitePersistenceRecord $expected ): SitePersistenceResult {
		try {
			$observed = $this->store->find( $expected->identity() );
		} catch ( Throwable $throwable ) {
			return self::failure( $expected->identity(), $throwable );
		}

		if ( null === $observed ) {
			return SitePersistenceResult::new(
				$expected->identity(),
				SitePersistenceOutcome::CONFLICT,
				'ETCH_SITE_PERSISTENCE_NATIVE_MISSING',
				'Exact native Site dependency is missing or ambiguous.'
			);
		}

		if ( $observed->is_owned() ) {
			return SitePersistenceResult::new(
				$expected->identity(),
				SitePersistenceOutcome::CONFLICT,
				'ETCH_SITE_PERSISTENCE_NATIVE_OWNERSHIP',
				'Native Site dependency is unexpectedly Builder-owned.'
			);
		}

		if ( $observed->kind() !== $expected->kind() || $observed->fingerprint() !== $expected->fingerprint() ) {
			return SitePersistenceResult::new(
				$expected->identity(),
				SitePersistenceOutcome::CONFLICT,
				'ETCH_SITE_PERSISTENCE_NATIVE_DRIFT',
				'Native Site dependency does not match the exact compiled contract.'
			);
		}

		return SitePersistenceResult::new(
			$expected->identity(),
			SitePersistenceOutcome::UNCHANGED,
			'ETCH_SITE_PERSISTENCE_NATIVE_VERIFIED',
			'Exact native Site dependency is available and remains externally owned.'
		);
	}

	private function write( SitePersistenceRecord $record, bool $update ): SitePersistenceResult {
		try {
			$result = $update ? $this->store->update( $record ) : $this->store->create( $record );
		} catch ( Throwable $throwable ) {
			return self::failure( $record->identity(), $throwable );
		}

		if ( ! $result->is_success() ) {
			$outcome = match ( $result->get_error_code() ) {
				'ETCH_SITE_PERSISTENCE_CONFLICT' => SitePersistenceOutcome::CONFLICT,
				'ETCH_SITE_PERSISTENCE_SKIPPED'  => SitePersistenceOutcome::SKIPPED,
				default                          => SitePersistenceOutcome::FAILED,
			};

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

	/**
	 * Whether all active-plan results succeeded before cleanup is allowed.
	 *
	 * @param array<int, SitePersistenceResult> $results
	 */
	private function all_results_succeeded( array $results ): bool {
		foreach ( $results as $result ) {
			if ( ! $result->is_success() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Clean exact previously recorded resources that are absent from this
	 * complete replacement plan, reporting only cleanup failures.
	 *
	 * @param array<int, SitePersistenceResult> $results
	 */
	private function cleanup_orphan_resources( CompiledSitePlan $plan, array &$results ): void {
		if ( ! $this->store instanceof SitePersistenceResourceStoreInterface ) {
			return;
		}

		$active = array_fill_keys(
			array_map(
				static fn ( CompiledSiteResource $resource ): string => $resource->identity(),
				array_merge( $plan->styles(), $plan->assets() )
			),
			true
		);
		$records = $this->store->owned_resource_records();
		usort( $records, static fn ( SitePersistenceRecord $left, SitePersistenceRecord $right ): int => $left->identity() <=> $right->identity() );

		foreach ( $records as $record ) {
			if ( isset( $active[ $record->identity() ] ) ) {
				continue;
			}

			try {
				$deletion = $this->store->delete_owned_resource( $record );
			} catch ( Throwable $throwable ) {
				$results[] = self::failure( $record->identity(), $throwable );
				continue;
			}

			if ( ! $deletion->is_success() ) {
				$results[] = SitePersistenceResult::new(
					$record->identity(),
					SitePersistenceOutcome::FAILED,
					$deletion->get_error_code() ?: 'ETCH_SITE_PERSISTENCE_ORPHAN_DELETE_FAILED',
					$deletion->get_error_message() ?: 'The recorded orphan could not be deleted.'
				);
				continue;
			}

		}
	}

	private static function failure( string $identity, Throwable $throwable ): SitePersistenceResult {
		return SitePersistenceResult::new(
			$identity,
			SitePersistenceOutcome::FAILED,
			'ETCH_SITE_PERSISTENCE_FAILED',
			$throwable->getMessage() ?: 'Compiled Site record could not be persisted.'
		);
	}

	/**
	 * Select the ownership edges that belong to one persisted resource.
	 *
	 * @return array<int, CompiledSiteOwnership>
	 */
	private function ownership_for( CompiledSitePlan $plan, string $resource_identity ): array {
		return array_values(
			array_filter(
				$plan->ownership(),
				static fn ( CompiledSiteOwnership $edge ): bool => $edge->resource_identity() === $resource_identity
			)
		);
	}

	/**
	 * Validate ownership edges before any handler is called.
	 *
	 * @return array<int, CompiledSiteDiagnostic>
	 */
	private function validate_ownership( CompiledSitePlan $plan ): array {
		$entities = array_fill_keys( $plan->resolved_identities(), true );
		$entities['site:root'] = true;
		$resources = array();
		foreach ( array_merge( $plan->styles(), $plan->assets() ) as $resource ) {
			$resources[ $resource->identity() ] = true;
		}

		$diagnostics = array();
		foreach ( $plan->ownership() as $edge ) {
			if ( ! isset( $entities[ $edge->owner_identity() ] ) || ! isset( $resources[ $edge->resource_identity() ] ) ) {
				$diagnostics[] = CompiledSiteDiagnostic::new(
					self::OWNERSHIP_INVALID,
					CompiledSiteDiagnosticSeverity::ERROR,
					sprintf( 'Compiled Site ownership edge "%s" -> "%s" does not resolve to a plan owner and resource.', $edge->owner_identity(), $edge->resource_identity() ),
					$edge->resource_identity()
				);
			}
		}

		return $diagnostics;
	}
}
