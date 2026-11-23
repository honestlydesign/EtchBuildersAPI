<?php
/**
 * Shared compiled-plan persistence engine.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceApplyLockInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceEditorStyleDuplicateInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceHandOverInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceNativeClaimInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceNativeOrphanInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceRecordAdoptionInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceNativeRetirementInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStylesheetAdoptionInterface;
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

	private const ORPHAN = 'ETCH_SITE_PERSISTENCE_ORPHAN';

	private const ORPHAN_KEPT = 'ETCH_SITE_PERSISTENCE_ORPHAN_KEPT';

	private const ORPHAN_RETIRED = 'ETCH_SITE_PERSISTENCE_ORPHAN_RETIRED';

	/** Native kinds whose orphans can be retired by moving them to draft. */
	private const RETIRABLE_KINDS = array( 'page', 'post' );

	public function __construct( private readonly SitePersistenceStoreInterface $store ) {
	}

	/**
	 * Apply entities first, then styles, then assets in plan order.
	 */
	public function apply( CompiledSitePlan $plan ): SitePersistenceReport {
		$validated = $this->validate_plan( $plan );
		if ( array() !== $validated['diagnostics'] ) {
			return SitePersistenceReport::new( blocking_diagnostics: $validated['diagnostics'] );
		}

		/** @var array<int, array{record: SitePersistenceRecord, intent: CompiledSiteEntityPersistenceIntent, adopt?: bool, reclaim?: bool}> $records */
		$records = array();
		foreach ( $validated['entities'] as $entity ) {
			$records[] = array(
				'record'  => SitePersistenceRecord::from_entity( $entity ),
				'intent'  => $entity->persistence_intent(),
				'adopt'   => $entity->adopts_existing(),
				'reclaim' => $entity->reclaims_from_editors(),
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
		$mutates = false;
		foreach ( $records as $entry ) {
			if ( CompiledSiteEntityPersistenceIntent::VERIFY_NATIVE !== $entry['intent'] ) {
				$mutates = true;
				break;
			}
		}

		$lock = $mutates && $this->store instanceof SitePersistenceApplyLockInterface ? $this->store : null;
		if ( null !== $lock && ! $lock->acquire_site_apply_lock() ) {
			return SitePersistenceReport::new( array(), array( self::lock_refusal() ) );
		}

		try {
			$forgotten = $this->forget_other_complete_plan( $plan, $mutates );
			if ( null !== $forgotten ) {
				return SitePersistenceReport::new( array( $forgotten ) );
			}

			$this->adopt_unowned_stylesheet_assets( $records );

			foreach ( $records as $entry ) {
				$record = $entry['record'];
				$results[] = CompiledSiteEntityPersistenceIntent::VERIFY_NATIVE === $entry['intent']
					? $this->verify_native_record( $record )
					: $this->apply_record( $record, $entry['intent'], $entry['adopt'] ?? false, $entry['reclaim'] ?? false );
			}

			$duplicates = $this->adopt_editor_style_duplicates( $records, $results );
			$results    = array_merge( $results, $duplicates->results() );
			$orphans    = $plan->is_partial() ? array() : $this->orphan_entities( $plan );

			if ( ! $plan->is_partial() && $this->all_results_succeeded( $results ) && $this->store instanceof SitePersistenceResourceStoreInterface ) {
				// A plan of verified native dependencies only writes nothing
				// itself; it takes the lock only when there is something to clean.
				$this->cleanup_orphan_resources( $plan, $results, $orphans, null === $lock );
			}

			if ( ! $plan->is_partial() && self::has_content_entities( $plan ) && $this->all_results_succeeded( $results ) && $this->store instanceof SitePersistenceNativeOrphanInterface ) {
				try {
					$recorded = $this->store->record_complete_plan_digest( self::plan_digest( $plan ) );
					if ( ! $recorded->is_success() ) {
						$results[] = self::registration_failure( 'site:complete-plan', $recorded, 'The applied plan could not be recorded.' );
					}
				} catch ( Throwable $throwable ) {
					$results[] = self::failure( 'site:complete-plan', $throwable );
				}
			}

			return SitePersistenceReport::new( $results, array(), array_merge( $duplicates->notices(), $this->orphan_notices( $orphans ) ) );
		} finally {
			$lock?->release_site_apply_lock();
		}
	}

	/**
	 * Describe what needs a person's attention for this plan, without writing.
	 *
	 * Returns a report with notices only, for verification tooling: Etch
	 * editor duplicates of code-owned styles (adoptable ones and ones a person
	 * filled with other CSS) and, for a complete plan, Builder-owned entities
	 * the plan no longer contains.
	 */
	public function diagnose( CompiledSitePlan $plan ): SitePersistenceReport {
		$notices = array();

		if ( $this->store instanceof SitePersistenceEditorStyleDuplicateInterface ) {
			$styles = array_map(
				fn ( CompiledSiteResource $style ): SitePersistenceRecord => SitePersistenceRecord::from_resource( $style, true, $this->ownership_for( $plan, $style->identity() ) ),
				$plan->styles()
			);
			if ( array() !== $styles ) {
				try {
					$notices = $this->store->inspect_editor_style_duplicates( ...$styles )->notices();
				} catch ( Throwable $throwable ) {
					$notices[] = CompiledSiteDiagnostic::new( 'ETCH_SITE_PERSISTENCE_FAILED', CompiledSiteDiagnosticSeverity::WARNING, $throwable->getMessage() ?: 'Editor style duplicates could not be inspected.' );
				}
			}
		}

		if ( ! $plan->is_partial() ) {
			$notices = array_merge( $notices, $this->orphan_notices( $this->orphan_entities( $plan ) ) );
		}

		return SitePersistenceReport::new( array(), array(), $notices );
	}

	/**
	 * Report Builder-owned content this complete plan no longer contains.
	 *
	 * Read-only. apply() includes the same notices in its report. A partial
	 * plan cannot tell removed content from content another stage owns, so
	 * it is refused with a blocking diagnostic.
	 */
	public function orphans( CompiledSitePlan $plan ): SitePersistenceReport {
		if ( $plan->is_partial() ) {
			return SitePersistenceReport::new( array(), array( self::partial_plan_refusal() ) );
		}

		return SitePersistenceReport::new( array(), array(), $this->orphan_notices( $this->orphan_entities( $plan ) ) );
	}

	/**
	 * Explicitly retire selected Builder-owned pages and posts removed from code.
	 *
	 * Retirement moves the content to draft, the most reversible native
	 * action: drafts are never purged automatically (trash is, after
	 * EMPTY_TRASH_DAYS), they keep their ID, slug, revisions, and metadata,
	 * and they leave the public site. When the content returns to code, the
	 * next apply restores its previous status.
	 *
	 * The operation fails closed. It only touches the identities passed in,
	 * each of which must still be an orphan of the plan, and it refuses
	 * unless the plan is the complete plan that the last fully successful
	 * apply() wrote: an empty plan, a stage plan, a plan not applied yet, or
	 * a stale plan never retires anything. Components, patterns, templates,
	 * handed-over content, translations, protected pages (front page, posts
	 * page, privacy policy, shop pages, filtered IDs), and pages with live
	 * child pages are never retired.
	 *
	 * @param array<int, string> $identities Orphan identities from a prior orphans() report.
	 */
	public function retire_orphans( CompiledSitePlan $plan, array $identities ): SitePersistenceReport {
		$validated = $this->validate_plan( $plan );
		if ( array() !== $validated['diagnostics'] ) {
			return SitePersistenceReport::new( array(), $validated['diagnostics'] );
		}
		if ( $plan->is_partial() ) {
			return SitePersistenceReport::new( array(), array( self::partial_plan_refusal() ) );
		}

		if ( ! self::has_content_entities( $plan ) ) {
			return SitePersistenceReport::new( array(), array(
				CompiledSiteDiagnostic::new( 'ETCH_SITE_PERSISTENCE_ORPHANS_NO_CONTENT', CompiledSiteDiagnosticSeverity::ERROR, 'The plan contains no pages, posts, or templates, so it cannot be the complete Site plan; nothing was retired.' ),
			) );
		}

		$selected = array();
		foreach ( $identities as $identity ) {
			if ( is_string( $identity ) && '' !== trim( $identity ) ) {
				$selected[ trim( $identity ) ] = true;
			}
		}
		if ( array() === $selected ) {
			return SitePersistenceReport::new( array(), array(
				CompiledSiteDiagnostic::new( 'ETCH_SITE_PERSISTENCE_ORPHANS_NONE_SELECTED', CompiledSiteDiagnosticSeverity::ERROR, 'Pass the identities to retire, taken from an orphans() report; nothing was retired.' ),
			) );
		}

		if ( ! $this->store instanceof SitePersistenceNativeOrphanInterface ) {
			return SitePersistenceReport::new( array(), array(
				CompiledSiteDiagnostic::new( 'ETCH_SITE_PERSISTENCE_ORPHANS_UNSUPPORTED', CompiledSiteDiagnosticSeverity::ERROR, 'The configured Site persistence store cannot list or retire Builder-owned content.' ),
			) );
		}

		$lock = $this->store instanceof SitePersistenceApplyLockInterface ? $this->store : null;
		if ( null !== $lock && ! $lock->acquire_site_apply_lock() ) {
			return SitePersistenceReport::new( array(), array( self::lock_refusal() ) );
		}

		try {
			$digest = self::plan_digest( $plan );
			try {
				$applied = $this->store->complete_plan_digest();
				$current = null !== $applied && $this->store->complete_plan_is_current();
			} catch ( Throwable ) {
				$applied = null;
				$current = false;
			}
			if ( null === $applied || ! hash_equals( $applied, $digest ) ) {
				return SitePersistenceReport::new( array(), array(
					CompiledSiteDiagnostic::new( 'ETCH_SITE_PERSISTENCE_ORPHANS_PLAN_NOT_APPLIED', CompiledSiteDiagnosticSeverity::ERROR, 'This plan is not the complete plan the last successful apply wrote. Apply it successfully first; nothing was retired.' ),
				) );
			}
			if ( ! $current ) {
				return SitePersistenceReport::new( array(), array(
					CompiledSiteDiagnostic::new( 'ETCH_SITE_PERSISTENCE_ORPHANS_PLAN_STALE', CompiledSiteDiagnosticSeverity::ERROR, 'The site changed after this plan was last applied (another package version, or content and ownership records written outside that apply). Apply the plan again first; nothing was retired.' ),
				) );
			}

			$orphans = array();
			foreach ( $this->orphan_entities( $plan ) as $orphan ) {
				if ( $orphan instanceof CompiledSiteDiagnostic ) {
					return SitePersistenceReport::new( array(), array( $orphan ) );
				}
				$orphans[ $orphan['identity'] ] = $orphan;
			}

			$results = array();
			foreach ( array_keys( $selected ) as $identity ) {
				$orphan = $orphans[ $identity ] ?? null;
				if ( null === $orphan ) {
					$results[] = SitePersistenceResult::new( $identity, SitePersistenceOutcome::CONFLICT, 'ETCH_SITE_PERSISTENCE_ORPHAN_NOT_FOUND', sprintf( '"%s" is not Builder-owned content missing from this plan; it was not retired.', $identity ) );
					continue;
				}

				$obstacle = self::retirement_obstacle( $orphan );
				if ( null !== $obstacle ) {
					$results[] = SitePersistenceResult::new( $identity, SitePersistenceOutcome::SKIPPED, self::ORPHAN_KEPT, sprintf( '"%s" was not retired because %s.', $identity, $obstacle ) );
					continue;
				}

				try {
					$retired = $this->store->retire_owned_native_entity( $identity );
				} catch ( Throwable $throwable ) {
					$results[] = self::failure( $identity, $throwable );
					continue;
				}

				$results[] = match ( true ) {
					$retired->is_success()                                              => SitePersistenceResult::new( $identity, SitePersistenceOutcome::UPDATED, self::ORPHAN_RETIRED, 'Builder-owned content removed from code was moved to draft; adding it back to code restores its status.' ),
					'ETCH_SITE_PERSISTENCE_ORPHAN_PROTECTED' === $retired->get_error_code() => SitePersistenceResult::new( $identity, SitePersistenceOutcome::SKIPPED, self::ORPHAN_KEPT, $retired->get_error_message() ),
					default                                                             => self::registration_failure( $identity, $retired, 'The orphaned content could not be moved to draft.' ),
				};
			}

			if ( array() !== array_filter( $results, static fn ( SitePersistenceResult $result ): bool => self::ORPHAN_RETIRED === $result->code() ) ) {
				// Retiring changed code-owned content; the plan still describes the site, so record that.
				try {
					$recorded = $this->store->record_complete_plan_digest( $digest );
					if ( ! $recorded->is_success() ) {
						$results[] = self::registration_failure( 'site:complete-plan', $recorded, 'The applied plan could not be recorded after retiring.' );
					}
				} catch ( Throwable $throwable ) {
					$results[] = self::failure( 'site:complete-plan', $throwable );
				}
			}

			return SitePersistenceReport::new( $results );
		} finally {
			$lock?->release_site_apply_lock();
		}
	}

	/**
	 * Explicitly migrate a finite compiled resource list into recorded ownership.
	 *
	 * This operation is intentionally separate from apply(): legacy prefixes and
	 * collections can authorize migration only when the caller supplies the
	 * exact current plan and the native record matches it. It runs under the
	 * site apply lock, like apply().
	 */
	public function migrate_legacy_ownership( CompiledSitePlan $plan ): RegistrationResult {
		$validated = $this->validate_plan( $plan );
		if ( array() !== $validated['diagnostics'] ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_BLOCKED', $validated['diagnostics'][0]->message() );
		}

		if ( ! $this->store instanceof SitePersistenceResourceStoreInterface ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_LEGACY_MIGRATION_UNSUPPORTED', 'The configured Site persistence store does not support explicit legacy ownership migration.' );
		}

		$lock = $this->store instanceof SitePersistenceApplyLockInterface ? $this->store : null;
		if ( null !== $lock && ! $lock->acquire_site_apply_lock() ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_APPLY_LOCKED', self::lock_refusal()->message() );
		}

		try {
			return $this->store->migrate_legacy_ownership( $plan );
		} finally {
			$lock?->release_site_apply_lock();
		}
	}

	/**
	 * Run the blocking checks every writing operation shares: compiler
	 * errors, dependency ordering, and ownership edges.
	 *
	 * @return array{entities: array<int, CompiledSiteEntity>, diagnostics: array<int, CompiledSiteDiagnostic>}
	 */
	private function validate_plan( CompiledSitePlan $plan ): array {
		$blocking = array_values(
			array_filter(
				$plan->diagnostics(),
				static fn ( CompiledSiteDiagnostic $diagnostic ): bool => CompiledSiteDiagnosticSeverity::ERROR === $diagnostic->severity()
			)
		);
		if ( array() !== $blocking ) {
			return array( 'entities' => array(), 'diagnostics' => $blocking );
		}

		$ordered = $this->order_entities( $plan );
		if ( array() !== $ordered['diagnostics'] ) {
			return $ordered;
		}

		$ownership_diagnostics = $this->validate_ownership( $plan );
		if ( array() !== $ownership_diagnostics ) {
			return array( 'entities' => array(), 'diagnostics' => $ownership_diagnostics );
		}

		return $ordered;
	}

	/**
	 * Clear the recorded complete plan before a different plan writes.
	 *
	 * retire_orphans() trusts the recorded digest to describe the site. A
	 * later plan that fails halfway, or a partial stage, changes the site
	 * without proving a complete state, so the old digest must not survive
	 * it. Nothing has been written when this fails, so the apply stops.
	 */
	private function forget_other_complete_plan( CompiledSitePlan $plan, bool $writes ): ?SitePersistenceResult {
		if ( ! $writes || ! $this->store instanceof SitePersistenceNativeOrphanInterface ) {
			return null;
		}

		try {
			$recorded = $this->store->complete_plan_digest();
			if ( null === $recorded || self::plan_digest( $plan ) === $recorded ) {
				return null;
			}
			$forgotten = $this->store->forget_complete_plan_digest();
		} catch ( Throwable $throwable ) {
			return self::failure( 'site:complete-plan', $throwable );
		}

		return $forgotten->is_success()
			? null
			: self::registration_failure( 'site:complete-plan', $forgotten, 'The previously applied plan could not be cleared.' );
	}

	private static function has_content_entities( CompiledSitePlan $plan ): bool {
		foreach ( $plan->entities() as $entity ) {
			if ( in_array( $entity->type(), array( CompiledSiteEntityType::PAGE, CompiledSiteEntityType::POST, CompiledSiteEntityType::TEMPLATE ), true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function plan_digest( CompiledSitePlan $plan ): string {
		return hash( 'sha256', (string) json_encode( $plan->to_array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE ) );
	}

	private static function lock_refusal(): CompiledSiteDiagnostic {
		return CompiledSiteDiagnostic::new( 'ETCH_SITE_PERSISTENCE_APPLY_LOCKED', CompiledSiteDiagnosticSeverity::ERROR, 'Another Site apply still holds the site-wide persistence lock.' );
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

	/**
	 * Adopt unowned native global stylesheets before the per-record writes.
	 *
	 * One native stylesheet aggregates fragments from several plan records,
	 * so unowned entries can only be proven Builder-authored with every
	 * stylesheet record of the plan at hand. Stores without the capability
	 * keep the fail-closed per-record conflict behavior.
	 *
	 * @param array<int, array{record: SitePersistenceRecord, intent: CompiledSiteEntityPersistenceIntent}> $entries
	 */
	private function adopt_unowned_stylesheet_assets( array $entries ): void {
		if ( ! $this->store instanceof SitePersistenceStylesheetAdoptionInterface ) {
			return;
		}

		$records = array();
		foreach ( $entries as $entry ) {
			$record = $entry['record'];
			if ( CompiledSiteResourceType::ASSET->value === $record->kind()
				&& 'stylesheet' === (string) ( $record->payload()['type'] ?? '' ) ) {
				$records[] = $record;
			}
		}

		if ( array() !== $records ) {
			$this->store->adopt_unowned_stylesheet_records( ...$records );
		}
	}

	/**
	 * Heal Etch editor duplicates of the styles this apply persisted.
	 *
	 * Runs after the per-record writes so only styles that are now owned by
	 * this plan can be mirrored.
	 *
	 * @param array<int, array{record: SitePersistenceRecord, intent: CompiledSiteEntityPersistenceIntent}> $entries
	 * @param array<int, SitePersistenceResult>                                                             $results
	 */
	private function adopt_editor_style_duplicates( array $entries, array $results ): SitePersistenceReport {
		if ( ! $this->store instanceof SitePersistenceEditorStyleDuplicateInterface ) {
			return SitePersistenceReport::new();
		}

		$styles = array();
		foreach ( $entries as $index => $entry ) {
			$record = $entry['record'];
			if ( CompiledSiteResourceType::STYLE->value === $record->kind() && ( $results[ $index ] ?? null )?->is_success() ) {
				$styles[] = $record;
			}
		}

		if ( array() === $styles ) {
			return SitePersistenceReport::new();
		}

		try {
			return $this->store->adopt_editor_style_duplicates( ...$styles );
		} catch ( Throwable $throwable ) {
			return SitePersistenceReport::new( array( self::failure( 'style:editor-duplicates', $throwable ) ) );
		}
	}

	private function apply_record(
		SitePersistenceRecord $record,
		CompiledSiteEntityPersistenceIntent $intent = CompiledSiteEntityPersistenceIntent::MANAGED,
		bool $adopt = false,
		bool $reclaim = false
	): SitePersistenceResult {
		if ( CompiledSiteEntityType::COMPONENT_CONTRACT_CATALOG->value === $record->kind() ) {
			return SitePersistenceResult::new(
				$record->identity(),
				SitePersistenceOutcome::FAILED,
				'ETCH_SITE_PERSISTENCE_CATALOG_NOT_RUNTIME',
				'Component Contract Catalog is a build-time contract and has no WordPress runtime record.'
			);
		}

		$hand_over = CompiledSiteEntityPersistenceIntent::CREATE_ONLY === $intent;
		$hand_overs = $this->store instanceof SitePersistenceHandOverInterface ? $this->store : null;

		try {
			$current = $this->store->find( $record->identity() );
		} catch ( Throwable $throwable ) {
			return self::failure( $record->identity(), $throwable );
		}

		if ( null === $current ) {
			try {
				$was_handed_over = null !== $hand_overs && $hand_overs->was_handed_over( $record->identity() );
				if ( $was_handed_over && $reclaim ) {
					$forgotten = $hand_overs->forget_hand_over( $record->identity() );
					if ( ! $forgotten->is_success() ) {
						return self::registration_failure( $record->identity(), $forgotten, 'The hand-over could not be forgotten.' );
					}
					$was_handed_over = false;
				}
			} catch ( Throwable $throwable ) {
				return self::failure( $record->identity(), $throwable );
			}
			if ( $was_handed_over && ! $hand_over ) {
				// Code manages this content again but it is gone; reporting it as
				// unchanged would let verification read the code page as current.
				return SitePersistenceResult::new(
					$record->identity(),
					SitePersistenceOutcome::CONFLICT,
					'ETCH_SITE_PERSISTENCE_HANDED_OVER_RECLAIM_REQUIRED',
					'Code manages this content again, but it was handed over to editors and they renamed, trashed, or deleted it. Add reclaim_from_editors() to create it from code again, or keep hand_over_after_create().'
				);
			}
			if ( $was_handed_over ) {
				// Editors renamed, trashed, or deleted content they own; never recreate it behind their back.
				return SitePersistenceResult::new(
					$record->identity(),
					SitePersistenceOutcome::UNCHANGED,
					'ETCH_SITE_PERSISTENCE_HANDED_OVER_MISSING',
					'Content handed over to editors no longer exists under this identity (an editor renamed, trashed, or deleted it), so it is not created again. Add reclaim_from_editors() to create it from code again.'
				);
			}

			$created = $this->write( $record, false );

			return $hand_over && $created->is_success() ? $this->mark_handed_over( $record, $created ) : $created;
		}

		if ( ! $current->is_owned() ) {
			try {
				$handed_over = null !== $hand_overs && $hand_overs->is_handed_over( $record->identity() );
			} catch ( Throwable $throwable ) {
				return self::failure( $record->identity(), $throwable );
			}
			if ( $handed_over ) {
				return $this->apply_handed_over_record( $record, $hand_over, $reclaim );
			}

			if ( $adopt ) {
				return $this->adopt_native_record( $record, $hand_over );
			}

			if ( $this->store instanceof SitePersistenceRecordAdoptionInterface && $this->store->adopt_unowned_record( $record ) ) {
				return $this->write( $record, false );
			}

			return SitePersistenceResult::new(
				$record->identity(),
				SitePersistenceOutcome::CONFLICT,
				'ETCH_SITE_PERSISTENCE_CONFLICT',
				'Existing Site record is not owned by this builder.'
			);
		}

		if ( $hand_over ) {
			$restored = $this->restore_retired( $record );
			if ( $restored instanceof SitePersistenceResult ) {
				return $restored;
			}

			return $this->mark_handed_over(
				$record,
				SitePersistenceResult::new(
					$record->identity(),
					$restored ? SitePersistenceOutcome::UPDATED : SitePersistenceOutcome::UNCHANGED,
					$restored ? 'ETCH_SITE_PERSISTENCE_RESTORED' : 'ETCH_SITE_PERSISTENCE_HANDED_OVER',
					$restored
						? 'Content that was retired after leaving the code is back in code; its previous status was restored and it was handed over to editors.'
						: 'Content was handed over to editors after it was created; it is not written again.'
				)
			);
		}

		if ( $current->fingerprint() === $record->fingerprint() && $current->kind() === $record->kind() ) {
			$written = SitePersistenceResult::new(
				$record->identity(),
				SitePersistenceOutcome::UNCHANGED,
				'ETCH_SITE_PERSISTENCE_UNCHANGED',
				'Compiled Site record is already current.'
			);
		} else {
			$written = $this->write( $record, true );
			if ( ! $written->is_success() ) {
				return $written;
			}
		}

		// Restore a retired status only once the content write succeeded.
		$restored = $this->restore_retired( $record );
		if ( $restored instanceof SitePersistenceResult ) {
			return $restored;
		}

		return $restored
			? SitePersistenceResult::new( $record->identity(), SitePersistenceOutcome::UPDATED, 'ETCH_SITE_PERSISTENCE_RESTORED', 'Content that was retired after leaving the code is back in code; its previous status was restored.' )
			: $written;
	}

	/**
	 * Apply a record whose native content an earlier apply handed over to editors.
	 *
	 * Handed-over content stays with editors while the code keeps handing it
	 * over. When the code manages it again, editor changes are only
	 * overwritten after an explicit reclaim; otherwise the apply reports a
	 * conflict and writes nothing.
	 */
	private function apply_handed_over_record( SitePersistenceRecord $record, bool $hand_over, bool $reclaim ): SitePersistenceResult {
		if ( $hand_over ) {
			return SitePersistenceResult::new(
				$record->identity(),
				SitePersistenceOutcome::UNCHANGED,
				'ETCH_SITE_PERSISTENCE_HANDED_OVER',
				'Content was handed over to editors after it was created; it is not written again.'
			);
		}

		if ( ! $reclaim || ! $this->store instanceof SitePersistenceHandOverInterface ) {
			return SitePersistenceResult::new(
				$record->identity(),
				SitePersistenceOutcome::CONFLICT,
				'ETCH_SITE_PERSISTENCE_HANDED_OVER_RECLAIM_REQUIRED',
				'This content was handed over to editors, and the code no longer hands it over. Nothing was written. Add reclaim_from_editors() to replace the editors\' version with the code version (WordPress keeps a revision), or hand_over_after_create() to leave it with editors.'
			);
		}

		try {
			$released = $this->store->mark_handed_over( $record->identity(), false );
		} catch ( Throwable $throwable ) {
			return self::failure( $record->identity(), $throwable );
		}
		if ( ! $released->is_success() ) {
			return self::registration_failure( $record->identity(), $released, 'The hand-over could not be ended.' );
		}

		$written = $this->write( $record, true );
		if ( ! $written->is_success() ) {
			try {
				$this->store->mark_handed_over( $record->identity(), true );
			} catch ( Throwable ) {
				// The failed write is reported either way.
			}

			return $written;
		}

		return SitePersistenceResult::new( $record->identity(), SitePersistenceOutcome::UPDATED, 'ETCH_SITE_PERSISTENCE_RECLAIMED', 'Content handed over to editors was reclaimed by code and replaced with the compiled version.' );
	}

	/**
	 * Restore a retired record's status; a result on failure, else whether it was restored.
	 */
	private function restore_retired( SitePersistenceRecord $record ): bool|SitePersistenceResult {
		if ( ! $this->store instanceof SitePersistenceNativeOrphanInterface ) {
			return false;
		}

		try {
			$restored = $this->store->restore_retired_native_entity( $record );
		} catch ( Throwable $throwable ) {
			return self::failure( $record->identity(), $throwable );
		}
		if ( null === $restored ) {
			return false;
		}

		return $restored->is_success()
			? true
			: self::registration_failure( $record->identity(), $restored, 'Retired content could not be restored.' );
	}

	/**
	 * Claim one existing unowned native record the entity explicitly adopts.
	 *
	 * Managed content is then written from code. Handed-over content keeps
	 * its WordPress content and only gains the ownership and hand-over
	 * markers. A failed follow-up write releases the claim and restores the
	 * content the claim found, so a half-adopted record is never treated as
	 * Builder-owned later and the person's content is not lost.
	 */
	private function adopt_native_record( SitePersistenceRecord $record, bool $hand_over ): SitePersistenceResult {
		if ( ! $this->store instanceof SitePersistenceNativeClaimInterface ) {
			return SitePersistenceResult::new(
				$record->identity(),
				SitePersistenceOutcome::CONFLICT,
				'ETCH_SITE_PERSISTENCE_ADOPTION_UNSUPPORTED',
				'Existing Site record is not owned by this builder, and the configured store cannot adopt it.'
			);
		}

		try {
			$claim = $this->store->claim_unowned_native_record( $record, ! $hand_over );
		} catch ( Throwable $throwable ) {
			return self::failure( $record->identity(), $throwable );
		}
		if ( ! $claim->is_success() ) {
			return SitePersistenceResult::new(
				$record->identity(),
				SitePersistenceOutcome::CONFLICT,
				$claim->get_error_code() ?: 'ETCH_SITE_PERSISTENCE_CONFLICT',
				$claim->get_error_message() ?: 'Existing Site record could not be adopted.'
			);
		}

		$written = $hand_over
			? $this->mark_handed_over( $record, SitePersistenceResult::new( $record->identity(), SitePersistenceOutcome::UPDATED, 'ETCH_SITE_PERSISTENCE_ADOPTED', 'Existing content was adopted and handed over to editors; its content was kept.' ) )
			: $this->write( $record, true );

		if ( ! $written->is_success() ) {
			try {
				$released = $this->store->release_native_claim( $record );
			} catch ( Throwable $throwable ) {
				return self::failure( $record->identity(), $throwable );
			}
			if ( ! $released->is_success() ) {
				return self::registration_failure( $record->identity(), $released, 'A failed adoption could not be undone.' );
			}

			return $written;
		}

		return $hand_over
			? $written
			: SitePersistenceResult::new( $record->identity(), SitePersistenceOutcome::UPDATED, 'ETCH_SITE_PERSISTENCE_ADOPTED', 'Existing content was adopted and replaced with the compiled version; the earlier version stays in the WordPress revision history.' );
	}

	/**
	 * Record the hand-over on the native record, keeping the given result on success.
	 */
	private function mark_handed_over( SitePersistenceRecord $record, SitePersistenceResult $result ): SitePersistenceResult {
		if ( ! $this->store instanceof SitePersistenceHandOverInterface ) {
			return $result;
		}

		try {
			$marked = $this->store->mark_handed_over( $record->identity(), true );
		} catch ( Throwable $throwable ) {
			return self::failure( $record->identity(), $throwable );
		}

		return $marked->is_success()
			? $result
			: self::registration_failure( $record->identity(), $marked, 'The hand-over marker could not be written.' );
	}

	private static function registration_failure( string $identity, RegistrationResult $result, string $fallback ): SitePersistenceResult {
		return SitePersistenceResult::new(
			$identity,
			SitePersistenceOutcome::FAILED,
			$result->get_error_code() ?: 'ETCH_SITE_PERSISTENCE_FAILED',
			$result->get_error_message() ?: $fallback
		);
	}

	/**
	 * List owned native content the complete plan no longer contains.
	 *
	 * Content an ID-targeted plan entity points at is not an orphan, even
	 * while its recorded identity still uses an older slug identity.
	 *
	 * @return array<int, array{identity: string, kind: string, post_id: int, status: string, handed_over: bool, retired: bool, protected: bool}|CompiledSiteDiagnostic>
	 */
	private function orphan_entities( CompiledSitePlan $plan ): array {
		if ( ! $this->store instanceof SitePersistenceNativeOrphanInterface ) {
			return array();
		}

		try {
			$owned = $this->store->owned_native_entities();
		} catch ( Throwable $throwable ) {
			return array( CompiledSiteDiagnostic::new( 'ETCH_SITE_PERSISTENCE_ORPHANS_UNAVAILABLE', CompiledSiteDiagnosticSeverity::WARNING, $throwable->getMessage() ?: 'Builder-owned content could not be listed.' ) );
		}

		$active     = array();
		$active_ids = array();
		foreach ( $plan->entities() as $entity ) {
			$active[ $entity->identity() ] = true;
			if ( 1 === preg_match( '/^(?:page|post):id:(\d+)$/D', $entity->identity(), $match ) ) {
				$active_ids[ (int) $match[1] ] = true;
			}
		}

		$orphans = array_values(
			array_filter(
				$owned,
				static fn ( array $entity ): bool => ! isset( $active[ $entity['identity'] ] ) && ! isset( $active_ids[ $entity['post_id'] ] )
			)
		);
		usort( $orphans, static fn ( array $left, array $right ): int => $left['identity'] <=> $right['identity'] );

		return $orphans;
	}

	/**
	 * @param array<int, array{identity: string, kind: string, post_id: int, status: string, handed_over: bool, retired: bool, protected: bool}|CompiledSiteDiagnostic> $orphans
	 * @return array<int, CompiledSiteDiagnostic>
	 */
	private function orphan_notices( array $orphans ): array {
		$notices = array();
		foreach ( $orphans as $orphan ) {
			if ( $orphan instanceof CompiledSiteDiagnostic ) {
				$notices[] = $orphan;
				continue;
			}

			$identity = $orphan['identity'];
			if ( $orphan['retired'] ) {
				$notices[] = CompiledSiteDiagnostic::new( self::ORPHAN_RETIRED, CompiledSiteDiagnosticSeverity::INFO, sprintf( '"%s" was removed from code and is retired as a draft. Add it back to code to restore it, or delete it in WordPress.', $identity ), $identity );
				continue;
			}
			if ( $orphan['handed_over'] ) {
				$notices[] = CompiledSiteDiagnostic::new( self::ORPHAN_KEPT, CompiledSiteDiagnosticSeverity::INFO, sprintf( '"%s" was handed over to editors and is no longer in code; it stays as it is.', $identity ), $identity );
				continue;
			}

			$obstacle  = self::retirement_obstacle( $orphan );
			$notices[] = CompiledSiteDiagnostic::new(
				self::ORPHAN,
				CompiledSiteDiagnosticSeverity::WARNING,
				sprintf(
					'"%s" is Builder-owned but no longer in the Site Definition. It stays %s and keeps its styles. %s',
					$identity,
					'' !== $orphan['status'] ? $orphan['status'] : 'as it is',
					null === $obstacle
						? 'Pass this identity to SitePersistence::retire_orphans() with the applied complete plan to move it to draft, add it back to code, or delete it in WordPress.'
						: sprintf( 'It is not retired automatically because %s; add it back to code or delete it in WordPress.', $obstacle )
				),
				$identity
			);
		}

		return $notices;
	}

	/**
	 * Why an orphan cannot be moved to draft, or null when it may be.
	 *
	 * @param array{identity: string, kind: string, post_id: int, status: string, handed_over: bool, retired: bool, protected: bool} $orphan
	 */
	private static function retirement_obstacle( array $orphan ): ?string {
		return match ( true ) {
			$orphan['handed_over']                                    => 'it was handed over to editors',
			$orphan['retired']                                        => 'it is already retired',
			'template' === $orphan['kind']                            => 'WordPress keeps rendering draft templates',
			! in_array( $orphan['kind'], self::RETIRABLE_KINDS, true ) => 'other content may still use it',
			$orphan['protected']                                      => 'WordPress or a plugin uses it as a special page (front page, posts page, privacy policy, shop page, or a filtered ID)',
			'draft' === $orphan['status']                             => 'it is already a draft',
			default                                                   => null,
		};
	}

	private static function partial_plan_refusal(): CompiledSiteDiagnostic {
		return CompiledSiteDiagnostic::new(
			'ETCH_SITE_PERSISTENCE_ORPHANS_PARTIAL_PLAN',
			CompiledSiteDiagnosticSeverity::ERROR,
			'Orphans can only be detected against the complete Site plan; a partial plan cannot tell removed content from content another stage owns.'
		);
	}

	/**
	 * Verify one exact external runtime prerequisite without writing or claiming it.
	 *
	 * A stale Builder-owned ledger from a previous managed era is retired
	 * automatically when the store supports it, so projects migrate from
	 * managed presets to native ones without manual SQL. Foreign modifications
	 * keep the conflict fail-closed.
	 */
	private function verify_native_record( SitePersistenceRecord $expected ): SitePersistenceResult {
		try {
			$observed = $this->store->find( $expected->identity() );
		} catch ( Throwable $throwable ) {
			return self::failure( $expected->identity(), $throwable );
		}

		if ( null === $observed || $observed->is_owned() ) {
			if ( $this->store instanceof SitePersistenceNativeRetirementInterface ) {
				$retired = $this->store->retire_owned_native_record( $expected->identity() );
				if ( $retired->is_success() ) {
					try {
						$observed = $this->store->find( $expected->identity() );
					} catch ( Throwable $throwable ) {
						return self::failure( $expected->identity(), $throwable );
					}
				}
			}
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
	 * Styles and assets whose owner is Builder-owned content that left the
	 * plan but still exists in WordPress are kept, so that content keeps
	 * rendering; they are cleaned once the content is trashed or deleted.
	 *
	 * @param array<int, SitePersistenceResult>                                                                                        $results
	 * @param array<int, array{identity: string, kind: string, post_id: int, status: string, handed_over: bool, retired: bool, protected: bool}|CompiledSiteDiagnostic> $orphans
	 */
	private function cleanup_orphan_resources( CompiledSitePlan $plan, array &$results, array $orphans = array(), bool $needs_lock = false ): void {
		if ( ! $this->store instanceof SitePersistenceResourceStoreInterface ) {
			return;
		}

		$deletable = $this->deletable_orphan_resources( $plan, $orphans );
		if ( array() === $deletable ) {
			return;
		}

		$lock = $needs_lock && $this->store instanceof SitePersistenceApplyLockInterface ? $this->store : null;
		if ( null !== $lock && ! $lock->acquire_site_apply_lock() ) {
			$results[] = SitePersistenceResult::new( 'site:cleanup', SitePersistenceOutcome::FAILED, 'ETCH_SITE_PERSISTENCE_APPLY_LOCKED', 'Another Site apply still holds the site-wide persistence lock, so resources removed from code were not cleaned up.' );

			return;
		}

		try {
			if ( null !== $lock ) {
				// Another apply may have changed the ledger or the live content
				// before this one got the lock; decide again from what is stored now.
				$deletable = $this->deletable_orphan_resources( $plan, $this->orphan_entities( $plan ) );
			}
			$this->delete_orphan_resources( $deletable, $results );
		} finally {
			$lock?->release_site_apply_lock();
		}
	}

	/**
	 * Recorded resources absent from the plan whose owner is not live content.
	 *
	 * @param array<int, array{identity: string, kind: string, post_id: int, status: string, handed_over: bool, retired: bool, protected: bool}|CompiledSiteDiagnostic> $orphans
	 *
	 * @return array<int, SitePersistenceRecord>
	 */
	private function deletable_orphan_resources( CompiledSitePlan $plan, array $orphans ): array {
		if ( ! $this->store instanceof SitePersistenceResourceStoreInterface ) {
			return array();
		}

		$active = array_fill_keys(
			array_map(
				static fn ( CompiledSiteResource $resource ): string => $resource->identity(),
				array_merge( $plan->styles(), $plan->assets() )
			),
			true
		);
		$live_owners = array();
		foreach ( $orphans as $orphan ) {
			if ( $orphan instanceof CompiledSiteDiagnostic ) {
				// Without the list of live content, keeping every resource is the safe choice.
				return array();
			}
			$live_owners[ $orphan['identity'] ] = true;
		}
		$records = $this->store->owned_resource_records();
		usort( $records, static fn ( SitePersistenceRecord $left, SitePersistenceRecord $right ): int => $left->identity() <=> $right->identity() );

		$deletable = array();
		foreach ( $records as $record ) {
			if ( isset( $active[ $record->identity() ] ) ) {
				continue;
			}
			foreach ( $record->ownership() as $edge ) {
				if ( isset( $live_owners[ $edge->owner_identity() ] ) ) {
					continue 2;
				}
			}
			$deletable[] = $record;
		}

		return $deletable;
	}

	/**
	 * @param array<int, SitePersistenceRecord>  $records
	 * @param array<int, SitePersistenceResult> $results
	 */
	private function delete_orphan_resources( array $records, array &$results ): void {
		if ( ! $this->store instanceof SitePersistenceResourceStoreInterface ) {
			return;
		}

		foreach ( $records as $record ) {
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
