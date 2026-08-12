<?php
/**
 * Supported front-page policies for a Site Definition.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Selects how a compiled site should choose its front page.
 */
enum SiteHomePolicyMode: string {

	case NONE = 'none';

	case PAGE = 'page';

	case LATEST_POSTS = 'latest_posts';
}
