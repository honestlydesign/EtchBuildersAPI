<?php
/**
 * Hand-over marker for content that code creates once and editors own afterwards.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\RegistrationResult;

/**
 * Records, on the native record itself, that a Builder-created page, post, or
 * template was handed over to editors.
 *
 * The persistence engine never writes handed-over content. The marker keeps
 * the hand-over visible in WordPress and keeps handed-over records out of
 * orphan retirement. A managed apply of the same identity clears it.
 */
interface SitePersistenceHandOverInterface {

	/**
	 * Whether the owned native record for this identity is marked as handed over.
	 */
	public function is_handed_over( string $identity ): bool;

	/**
	 * Set or clear the hand-over marker on the owned native record.
	 */
	public function mark_handed_over( string $identity, bool $handed_over ): RegistrationResult;
}
