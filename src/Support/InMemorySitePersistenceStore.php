<?php
/**
 * In-memory store for compiled Site persistence.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStoreInterface;
use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\SitePersistenceRecord;

/**
 * Deterministic store used by pure tests and local adapters.
 */
final class InMemorySitePersistenceStore implements SitePersistenceStoreInterface {

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
	 * Seed an existing record to model an external or prior owner.
	 */
	public function seed( SitePersistenceRecord $record ): void {
		$this->records[ $record->identity() ] = $record;
	}
}
