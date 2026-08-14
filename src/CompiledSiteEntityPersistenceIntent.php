<?php
/**
 * Persistence intent carried by one compiled Site entity.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Distinguishes Builder-managed entities from exact native prerequisites.
 */
enum CompiledSiteEntityPersistenceIntent: string {

	case MANAGED = 'managed';

	case VERIFY_NATIVE = 'verify_native';
}
