<?php
/**
 * Pure in-memory compiled Site persistence adapter.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStoreInterface;
use HonestlyDesign\EtchBuilders\SitePersistence;

/**
 * Applies compiled plans without WordPress.
 */
final class InMemorySitePersistence extends SitePersistence {

	public function __construct( ?SitePersistenceStoreInterface $store = null ) {
		parent::__construct( $store ?? new InMemorySitePersistenceStore() );
	}
}
