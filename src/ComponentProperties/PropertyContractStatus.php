<?php
/**
 * Property contract support status.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentProperties;

/**
 * Whether an audited Etch property pair belongs to the supported authoring surface.
 */
enum PropertyContractStatus: string {

	case SUPPORTED = 'supported';

	case PENDING = 'pending';
}
