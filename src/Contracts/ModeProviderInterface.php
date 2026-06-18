<?php
/**
 * Runtime mode contract abstracting Flag::is_dev_mode().
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

/**
 * Tells the builders whether they're running in a development context.
 */
interface ModeProviderInterface {

	/**
	 * Whether the runtime is in development mode.
	 */
	public function is_dev_mode(): bool;
}
