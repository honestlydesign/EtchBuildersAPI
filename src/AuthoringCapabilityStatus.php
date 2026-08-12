<?php
/**
 * Admission status for one curated Authoring Capability.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * States an intent-level capability can expose to the contract catalog.
 */
enum AuthoringCapabilityStatus: string {

	case SUPPORTED = 'supported';

	case CHECKED_ESCAPE = 'checked_escape';

	case PENDING = 'pending';

	case UNSUPPORTED = 'unsupported';

	/**
	 * Whether this status is part of the supported authoring route.
	 */
	public function is_supported(): bool {
		return self::SUPPORTED === $this;
	}

	/**
	 * Whether this status is an explicitly admitted escape hatch.
	 */
	public function is_checked_escape(): bool {
		return self::CHECKED_ESCAPE === $this;
	}

	/**
	 * Whether this intent is visible but not yet admitted.
	 */
	public function is_pending(): bool {
		return self::PENDING === $this;
	}

	/**
	 * Whether this intent must fail closed for agents.
	 */
	public function is_unsupported(): bool {
		return self::UNSUPPORTED === $this;
	}

	/**
	 * Whether the status has an admitted route an agent may use.
	 */
	public function is_admitted(): bool {
		return self::SUPPORTED === $this || self::CHECKED_ESCAPE === $this;
	}
}
