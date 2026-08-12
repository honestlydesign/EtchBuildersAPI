<?php
/**
 * Resource kinds represented in a Compiled Site Plan.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Distinguishes compiled styles from compiled assets.
 */
enum CompiledSiteResourceType: string {

	case STYLE = 'style';

	case ASSET = 'asset';
}
