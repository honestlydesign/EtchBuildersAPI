<?php
/**
 * Hand-over state for content that code creates once and editors own afterwards.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Contracts;

use HonestlyDesign\EtchBuilders\RegistrationResult;

/**
 * Records that a Builder-created page, post, or template was handed over to
 * editors, and remembers the hand-over even after editors rename, trash, or
 * delete the content.
 *
 * A store must make handed-over records look unowned to anything that only
 * understands plain Builder ownership (the WordPress store uses a distinct
 * owner value), so an older package version reports a conflict instead of
 * overwriting editor content.
 */
interface SitePersistenceHandOverInterface {

	/**
	 * Whether the native record for this identity exists and is handed over to editors.
	 */
	public function is_handed_over( string $identity ): bool;

	/**
	 * Whether content with this identity was handed over at some point, even if it no longer exists.
	 */
	public function was_handed_over( string $identity ): bool;

	/**
	 * Hand the Builder-owned native record over to editors, or take it back.
	 */
	public function mark_handed_over( string $identity, bool $handed_over ): RegistrationResult;

	/**
	 * Forget a hand-over whose content no longer exists, so code may create it again.
	 */
	public function forget_hand_over( string $identity ): RegistrationResult;
}
