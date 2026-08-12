<?php
/**
 * Evidence classes used to admit an Authoring Capability.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Stable machine-readable evidence categories.
 */
enum AuthoringCapabilityEvidenceKind: string {

	case POSITIVE = 'positive';

	case NEGATIVE = 'negative';

	case RECIPE = 'recipe';

	case RUNTIME = 'runtime';
}
