<?php
/**
 * Read-only metadata seam consumed by the Site Compiler.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\BlockSequence;
use HonestlyDesign\EtchBuilders\RawFragment;
use HonestlyDesign\EtchBuilders\StylesheetReference;

/**
 * Exposes typed authoring metadata without exposing persistence or registrars.
 */
interface SiteEntityCompilerMetadataInterface extends ClassTokenMetadataProviderInterface {

	/**
	 * @return array<int, string> Style IDs declared by this entity builder.
	 */
	public function get_style_ids(): array;

	/**
	 * @return array<int, StylesheetReference> Stylesheet files owned by this entity.
	 */
	public function get_stylesheet_references(): array;

	/**
	 * Return the typed block tree when one exists.
	 */
	public function get_block_sequence(): ?BlockSequence;

	/**
	 * @return array<int, RawFragment> Checked raw fragments in the typed tree.
	 */
	public function get_raw_fragments(): array;
}
