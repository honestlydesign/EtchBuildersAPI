<?php
/**
 * Entity kinds represented in a Compiled Site Plan.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Stable Site Entity categories emitted by the no-write compiler.
 */
enum CompiledSiteEntityType: string {

	case COMPONENT = 'component';

	case PATTERN = 'pattern';

	case PAGE = 'page';

	case POST = 'post';

	case TEMPLATE = 'template';

	case LOOP_PRESET = 'loop_preset';

	case COMPONENT_CONTRACT_CATALOG = 'component_contract_catalog';
}
