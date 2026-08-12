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

		$records = array();
		foreach ( $plan->entities() as $entity ) {
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
