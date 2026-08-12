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

	case SKIPPED = 'skipped';

	case FAILED = 'failed';

	/**
	 * Whether this outcome represents a successful application.
	 */
	public function is_success(): bool {
		return match ( $this ) {
			self::CREATED, self::UPDATED, self::UNCHANGED => true,
			self::CONFLICT, self::SKIPPED, self::FAILED => false,
		};
	}

	/**
	 * Whether the record was written or updated by this apply.
	 */
	public function is_applied(): bool {
		return match ( $this ) {
			self::CREATED, self::UPDATED => true,
			self::UNCHANGED, self::CONFLICT, self::SKIPPED, self::FAILED => false,
		};
	}

	/**
	 * Whether the compiled record already matched persisted state.
	 */
	public function is_unchanged(): bool {
		return self::UNCHANGED === $this;
	}

	/**
	 * Whether the compiled record could not replace an external record.
	 */
	public function is_conflicted(): bool {
		return self::CONFLICT === $this;
	}

	/**
	 * Whether the persistence adapter intentionally declined the record.
	 */
	public function is_skipped(): bool {
		return self::SKIPPED === $this;
	}

	/**
	 * Whether persistence failed for the compiled record.
	 */
	public function is_failed(): bool {
		return self::FAILED === $this;
	}
}
