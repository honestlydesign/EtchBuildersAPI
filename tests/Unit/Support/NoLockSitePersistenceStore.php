<?php
/**
 * A WordPress persistence store without the apply lock capability.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit\Support;

use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceNativeOrphanInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStoreInterface;
use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\SitePersistenceRecord;
use HonestlyDesign\EtchBuilders\Support\WordPressSitePersistenceStore;

/**
 * Forwards records and orphan bookkeeping to the WordPress store but offers
 * no lock, like a third-party store that never implemented one.
 */
final class NoLockSitePersistenceStore implements SitePersistenceStoreInterface, SitePersistenceNativeOrphanInterface {
	public function __construct( private readonly WordPressSitePersistenceStore $store ) {
	}

	public function find( string $identity ): ?SitePersistenceRecord {
		return $this->store->find( $identity );
	}

	public function create( SitePersistenceRecord $record ): RegistrationResult {
		return $this->store->create( $record );
	}

	public function update( SitePersistenceRecord $record ): RegistrationResult {
		return $this->store->update( $record );
	}

	public function owned_native_entities(): array {
		return $this->store->owned_native_entities();
	}

	public function retire_owned_native_entity( string $identity ): RegistrationResult {
		return $this->store->retire_owned_native_entity( $identity );
	}

	public function restore_retired_native_entity( SitePersistenceRecord $record ): ?RegistrationResult {
		return $this->store->restore_retired_native_entity( $record );
	}

	public function record_complete_plan_digest( string $digest ): RegistrationResult {
		return $this->store->record_complete_plan_digest( $digest );
	}

	public function complete_plan_is_current(): bool {
		return $this->store->complete_plan_is_current();
	}

	public function complete_plan_digest(): ?string {
		return $this->store->complete_plan_digest();
	}

	public function forget_complete_plan_digest(): RegistrationResult {
		return $this->store->forget_complete_plan_digest();
	}
}
