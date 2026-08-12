<?php
/**
 * Component authoring contract support status.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentContracts;

/**
 * Whether one observed component contract is admitted for agent authoring.
 */
enum ComponentContractStatus: string {

	case SUPPORTED = 'supported';

	case PENDING = 'pending';
}
