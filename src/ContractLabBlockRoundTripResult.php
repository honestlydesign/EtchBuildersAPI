<?php
/**
 * Normalized result of one WordPress block wire round trip.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Retains only semantic trees, never raw markup or parser-only HTML fields.
 */
final class ContractLabBlockRoundTripResult {

	/**
	 * @param array<int, array<string, mixed>> $before
	 * @param array<int, array<string, mixed>> $after
	 */
	private function __construct(
		private readonly string $status,
		private readonly array $before,
		private readonly array $after
	) {
	}

	/**
	 * @param array<int, array<string, mixed>> $before
	 * @param array<int, array<string, mixed>> $after
	 */
	public static function compare( array $before, array $after ): self {
		return new self( $before === $after ? 'matched' : 'drift', $before, $after );
	}

	public function status(): string {
		return $this->status;
	}

	public function matches(): bool {
		return 'matched' === $this->status;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function before(): array {
		return $this->before;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function after(): array {
		return $this->after;
	}

	/**
	 * @return array{status: string, before: array<int, array<string, mixed>>, after: array<int, array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'status' => $this->status,
			'before' => $this->before,
			'after'  => $this->after,
		);
	}
}
