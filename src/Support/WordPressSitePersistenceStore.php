<?php
/**
 * WordPress option adapter for compiled Site persistence records.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\CompiledSiteEntity;
use HonestlyDesign\EtchBuilders\CompiledSiteEntityType;
use HonestlyDesign\EtchBuilders\CompiledSiteResource;
use HonestlyDesign\EtchBuilders\CompiledSiteResourceType;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStoreInterface;
use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\SitePersistenceRecord;
use Throwable;

/**
 * Contains all WordPress function calls for the initial persistence seam.
 *
 * Records use one option per stable identity. This keeps unrelated records
 * independent and lets WordPress atomically claim a new identity with
 * add_option().
 */
final class WordPressSitePersistenceStore implements SitePersistenceStoreInterface {

	private const OPTION_PREFIX = 'etch_builders_site_record_';

	public function find( string $identity ): ?SitePersistenceRecord {
		$stored = \get_option( $this->option_name( $identity ), null );

		if ( ! is_array( $stored ) || (string) ( $stored['identity'] ?? '' ) !== $identity ) {
			return null;
		}

		return $this->record_from_storage( $stored );
	}

	public function create( SitePersistenceRecord $record ): RegistrationResult {
		$added = \add_option( $this->option_name( $record->identity() ), $record->to_array(), '', false );
		if ( $added ) {
			return RegistrationResult::success();
		}

		if ( null !== $this->find( $record->identity() ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'A Site record with this identity already exists.' );
		}

		return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_UPDATE_FAILED', 'WordPress could not create the compiled Site persistence record.' );
	}

	public function update( SitePersistenceRecord $record ): RegistrationResult {
		$updated = \update_option( $this->option_name( $record->identity() ), $record->to_array(), false );
		if ( $updated ) {
			return RegistrationResult::success();
		}

		$current = $this->find( $record->identity() );
		if ( null !== $current && $current->fingerprint() === $record->fingerprint() ) {
			return RegistrationResult::success();
		}

		return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_UPDATE_FAILED', 'WordPress could not update the compiled Site persistence record.' );
	}

	private function option_name( string $identity ): string {
		return self::OPTION_PREFIX . hash( 'sha256', $identity );
	}

	/**
	 * Rehydrate only through the typed compiled value objects.
	 *
	 * @param array<string, mixed> $stored
	 */
	private function record_from_storage( array $stored ): ?SitePersistenceRecord {
		$identity = (string) ( $stored['identity'] ?? '' );
		$payload  = $stored['payload'] ?? null;
		$owned    = (bool) ( $stored['owned'] ?? false );
		$kind     = (string) ( $stored['kind'] ?? '' );

		if ( ! is_array( $payload ) ) {
			return null;
		}

		try {
			$entity_type = CompiledSiteEntityType::tryFrom( $kind );
			if ( null !== $entity_type ) {
				return SitePersistenceRecord::from_entity( CompiledSiteEntity::new( $entity_type, $identity, $payload ), $owned );
			}

			$resource_type = CompiledSiteResourceType::tryFrom( $kind );
			if ( null !== $resource_type ) {
				return SitePersistenceRecord::from_resource( CompiledSiteResource::new( $resource_type, $identity, $payload ), $owned );
			}
		} catch ( Throwable ) {
			return null;
		}

		return null;
	}
}
