<?php
/**
 * Classified Contract Lab lock failure.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use RuntimeException;

/**
 * Allows callers to distinguish active contention from stale recovery.
 */
final class ContractLabLockException extends RuntimeException {

	public function __construct(
		private readonly string $reason,
		string $message
	) {
		parent::__construct( $message );
	}

	public function reason(): string {
		return $this->reason;
	}
}
