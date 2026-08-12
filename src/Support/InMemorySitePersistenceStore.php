<?php
/**
 * In-memory store for compiled Site persistence.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\CompiledSitePlan;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceResourceStoreInterface;
use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\SitePersistenceRecord;

/**
 * Deterministic store used by pure tests and local adapters.
 */
final class InMemorySitePersistenceStore implements SitePersistenceResourceStoreInterface {

	/** @var array<string, SitePersistenceRecord> */
	private array $records = array();

	public function find( string $identity ): ?SitePersistenceRecord {
		return $this->records[ $identity ] ?? null;
	}

	public function create( SitePersistenceRecord $record ): RegistrationResult {
		if ( isset( $this->records[ $record->identity() ] ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'A Site record with this identity already exists.' );
		}

		$this->records[ $record->identity() ] = $record;

		return RegistrationResult::success();
	}

	public function update( SitePersistenceRecord $record ): RegistrationResult {
		if ( ! isset( $this->records[ $record->identity() ] ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_MISSING', 'The Site record to update does not exist.' );
		}

		$this->records[ $record->identity() ] = $record;

		return RegistrationResult::success();
	}

	/**
	 * @return array<int, SitePersistenceRecord>
	 */
	public function owned_resource_records(): array {
		return array_values(
			array_filter(
				$this->records,
				static fn ( SitePersistenceRecord $record ): bool => $record->is_owned()
					&& in_array( $record->kind(), array( 'style', 'asset' ), true )
					&& array() !== $record->ownership()
			)
		);
	}

	public function delete_owned_resource( SitePersistenceRecord $record ): RegistrationResult {
		$current = $this->records[ $record->identity() ] ?? null;
		if ( null === $current || ! $current->is_owned() || $current->fingerprint() !== $record->fingerprint() ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'The recorded resource ownership no longer matches the in-memory record.' );
		}

		unset( $this->records[ $record->identity() ] );

		return RegistrationResult::success();
	}

	public function migrate_legacy_ownership( CompiledSitePlan $plan ): RegistrationResult {
		return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_LEGACY_MIGRATION_UNSUPPORTED', 'In-memory persistence has no legacy native records to migrate.' );
	}

	/**
	 * Seed an existing record to model an external or prior owner.
	 */
	public function seed( SitePersistenceRecord $record ): void {
		$this->records[ $record->identity() ] = $record;
	}
}
