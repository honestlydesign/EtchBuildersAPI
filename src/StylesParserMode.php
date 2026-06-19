<?php
/**
 * StylesParser validation modes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Defines CSS structure rules applied by StylesValidator.
 */
enum StylesParserMode {

	case CLASS_PROP;
	case FIXED;
	case FLEXIBLE;
}
