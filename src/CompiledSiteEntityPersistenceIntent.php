<?php
/**
 * Persistence intent carried by one compiled Site entity.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Distinguishes Builder-managed entities from exact native prerequisites and
 * from content that is created once and then handed over to editors.
 */
enum CompiledSiteEntityPersistenceIntent: string {

	/** Code owns the record: every apply writes the compiled content. */
	case MANAGED = 'managed';

	/** An exact native prerequisite that is verified but never written. */
	case VERIFY_NATIVE = 'verify_native';

	/**
	 * Created when missing, then left to editors: later applies never write
	 * the record again, whatever changed in code or in WordPress.
	 */
	case CREATE_ONLY = 'create_only';
}
