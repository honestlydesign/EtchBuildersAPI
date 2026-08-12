<?php
/**
 * Outcomes returned by the compiled Site persistence seam.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Stable machine-readable persistence outcomes.
 */
enum SitePersistenceOutcome: string {

	case CREATED = 'created';

	case UPDATED = 'updated';

	case UNCHANGED = 'unchanged';

	case CONFLICT = 'conflict';

	case FAILED = 'failed';

	/**
	 * Whether this outcome represents a successful application.
	 */
	public function is_success(): bool {
		return match ( $this ) {
			self::CREATED, self::UPDATED, self::UNCHANGED => true,
			self::CONFLICT, self::FAILED => false,
		};
	}
}
