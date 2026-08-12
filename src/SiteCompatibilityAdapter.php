<?php
/**
 * Temporary compatibility bridge for compiled Site registration.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceInterface;
use HonestlyDesign\EtchBuilders\Contracts\SiteRuntimeCapabilitiesInterface;
use HonestlyDesign\EtchBuilders\Support\WordPressSitePersistence;

/**
 * Translates an existing registration entry point to the compiled-plan seam.
 *
 * This bridge accepts only a typed SiteDefinition, delegates compilation to
 * SiteDefinition::compile(), and delegates every write to the injected
 * SitePersistenceInterface. It does not reproduce entity ordering, validation,
 * or persistence rules. A definition carrying blocking diagnostics is passed
 * unchanged to the persistence boundary, which fails closed before any write.
 *
 * Remove this bridge after OhMyIDEtch#21 has migrated its SiteRegistrar to call
 * the compiled SiteDefinition and persistence boundaries directly.
 *
 * @internal
 */
final class SiteCompatibilityAdapter {

	public function __construct(
		private readonly SitePersistenceInterface $persistence,
		private readonly ?SiteRuntimeCapabilitiesInterface $runtime = null
	) {
	}

	/**
	 * Create a bridge with an explicit persistence adapter.
	 */
	public static function new(
		SitePersistenceInterface $persistence,
		?SiteRuntimeCapabilitiesInterface $runtime = null
	): self {
		return new self( $persistence, $runtime );
	}

	/**
	 * Create the WordPress bridge used by the current consumer entry point.
	 */
	public static function wordpress( ?SiteRuntimeCapabilitiesInterface $runtime = null ): self {
		return new self( new WordPressSitePersistence(), $runtime );
	}

	/**
	 * Compile and apply one typed Site Definition.
	 */
	public function register( SiteDefinition $definition ): SitePersistenceReport {
		return $this->persistence->apply( $definition->compile( $this->runtime ) );
	}
}
