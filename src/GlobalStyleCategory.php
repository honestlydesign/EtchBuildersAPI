<?php
/**
 * Supported categories for checked global stylesheet fragments.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Declares why CSS belongs in a global stylesheet rather than an entity style.
 */
enum GlobalStyleCategory: string {

	case TOKENS = 'tokens';

	case FRAMEWORK = 'framework';

	case UTILITY = 'utility';

	case FONT = 'font';

	case BASE = 'base';

	case PORTAL = 'portal';
}
