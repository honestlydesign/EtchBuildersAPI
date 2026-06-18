<?php
/**
 * Default ModeProviderInterface implementation: production-like (never dev).
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\Contracts\ModeProviderInterface;

/**
 * Reports non-dev mode unless overridden by a concrete adapter.
 */
final class NullMode implements ModeProviderInterface {

	/**
	 * {@inheritdoc}
	 */
	public function is_dev_mode(): bool {
		return false;
	}
}
