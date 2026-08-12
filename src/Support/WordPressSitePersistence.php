<?php
/**
 * WordPress compiled Site persistence adapter.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStoreInterface;
use HonestlyDesign\EtchBuilders\SitePersistence;

/**
 * Uses the shared compiled-plan engine and the isolated WordPress store.
 */
final class WordPressSitePersistence extends SitePersistence {

	public function __construct( ?SitePersistenceStoreInterface $store = null ) {
		parent::__construct( $store ?? new WordPressSitePersistenceStore() );
	}
}
