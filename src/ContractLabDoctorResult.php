<?php
/**
 * Classified Contract Lab doctor result.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Keeps environment failures distinct from contract incompatibilities.
 */
final class ContractLabDoctorResult {

	/**
	 * @param array<int, array{category: string, code: string, message: string}> $findings
	 */
	private function __construct(
		private readonly string $status,
		private readonly array $findings
	) {
	}

	/**
	 * @param array<int, array{category: string, code: string, message: string}> $findings
	 */
	public static function from_findings( array $findings ): self {
		if ( ! array_is_list( $findings ) ) {
			throw new InvalidArgumentException( 'Contract Lab doctor findings must be an ordered list.' );
		}
		$has_environment = false;
		$has_contract    = false;
		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) || array( 'category', 'code', 'message' ) !== self::sorted_keys( $finding ) || ! is_string( $finding['category'] ) || ! is_string( $finding['code'] ) || ! is_string( $finding['message'] ) || '' === trim( $finding['code'] ) || '' === trim( $finding['message'] ) || ! in_array( $finding['category'], array( 'environment', 'contract' ), true ) ) {
				throw new InvalidArgumentException( 'Contract Lab doctor findings must contain category, code, and message.' );
			}
			$has_environment = $has_environment || 'environment' === $finding['category'];
			$has_contract    = $has_contract || 'contract' === $finding['category'];
		}

		$status = $has_environment ? 'environment_failure' : ( $has_contract ? 'contract_incompatibility' : 'ready' );

		return new self( $status, $findings );
	}

	public function status(): string {
		return $this->status;
	}

	/**
	 * @return array<int, array{category: string, code: string, message: string}>
	 */
	public function findings(): array {
		return ImmutableArray::copy( $this->findings, 'Contract Lab doctor findings must contain scalar values.' );
	}

	/**
	 * @return array{status: string, findings: array<int, array{category: string, code: string, message: string}>}
	 */
	public function to_array(): array {
		return array(
			'status'   => $this->status,
			'findings' => $this->findings(),
		);
	}

	/**
	 * @return array{status: string, findings: array<int, array{category: string, code: string, message: string}>}
	 */
	public function report(): array {
		return $this->to_array();
	}

	/**
	 * @param array<array-key, mixed> $record
	 * @return array<int, string>
	 */
	private static function sorted_keys( array $record ): array {
		$keys = array_keys( $record );
		sort( $keys );

		return $keys;
	}
}
