<?php
/**
 * One disposition for a legacy rendering-harness behavior.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Keeps the old test identity auditable without carrying its wp-env runtime.
 */
final class ContractLabHarnessCase {

	public const CASE_VERSION = '1';

	private const RETAINED_CONTRACT = 'retained-contract';

	private const RETIRED = 'retired';

	private function __construct(
		private readonly string $source_suite,
		private readonly string $source_test,
		private readonly string $disposition,
		private readonly ?string $outcome_id,
		private readonly ?string $retirement_reason
	) {
	}

	public static function retained_contract( string $source_suite, string $source_test, string $outcome_id ): self {
		self::assert_source_test( $source_suite, $source_test );
		ContractLabManifestSafety::assert_stable_id( $outcome_id, 'Contract Lab harness outcome ID' );

		return new self( $source_suite, $source_test, self::RETAINED_CONTRACT, $outcome_id, null );
	}

	public static function retired( string $source_suite, string $source_test, string $retirement_reason ): self {
		self::assert_source_test( $source_suite, $source_test );
		if ( '' === $retirement_reason || trim( $retirement_reason ) !== $retirement_reason || preg_match( '/[[:cntrl:]]/', $retirement_reason ) ) {
			throw new InvalidArgumentException( 'Retired Contract Lab harness cases require a safe non-empty reason.' );
		}

		return new self( $source_suite, $source_test, self::RETIRED, null, $retirement_reason );
	}

	/**
	 * Rehydrate one canonical legacy case.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$expected = array( 'case_version', 'disposition', 'outcome_id', 'retirement_reason', 'source_suite', 'source_test' );
		$actual   = array_keys( $record );
		sort( $expected );
		sort( $actual );
		if ( $expected !== $actual ) {
			throw new InvalidArgumentException( 'Contract Lab harness case has an unknown or missing field.' );
		}
		if ( self::CASE_VERSION !== ( $record['case_version'] ?? null ) || ! is_string( $record['source_suite'] ?? null ) || ! is_string( $record['source_test'] ?? null ) || ! is_string( $record['disposition'] ?? null ) || ( null !== $record['outcome_id'] && ! is_string( $record['outcome_id'] ) ) || ( null !== $record['retirement_reason'] && ! is_string( $record['retirement_reason'] ) ) ) {
			throw new InvalidArgumentException( 'Contract Lab harness case has an invalid field shape or version.' );
		}

		$case = match ( $record['disposition'] ) {
			self::RETAINED_CONTRACT => null !== $record['outcome_id'] && null === $record['retirement_reason']
				? self::retained_contract( $record['source_suite'], $record['source_test'], $record['outcome_id'] )
				: throw new InvalidArgumentException( 'Retained Contract Lab harness cases require an outcome and no retirement reason.' ),
			self::RETIRED => null === $record['outcome_id'] && null !== $record['retirement_reason']
				? self::retired( $record['source_suite'], $record['source_test'], $record['retirement_reason'] )
				: throw new InvalidArgumentException( 'Retired Contract Lab harness cases require a reason and no outcome.' ),
			default => throw new InvalidArgumentException( sprintf( 'Contract Lab harness disposition "%s" is unsupported.', $record['disposition'] ) ),
		};

		if ( $case->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Contract Lab harness case must be canonical.' );
		}

		return $case;
	}

	public function source_suite(): string {
		return $this->source_suite;
	}

	public function source_test(): string {
		return $this->source_test;
	}

	public function source_id(): string {
		return $this->source_suite . '::' . $this->source_test;
	}

	public function disposition(): string {
		return $this->disposition;
	}

	public function outcome_id(): ?string {
		return $this->outcome_id;
	}

	public function retirement_reason(): ?string {
		return $this->retirement_reason;
	}

	public function is_retained_contract(): bool {
		return self::RETAINED_CONTRACT === $this->disposition;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return ImmutableArray::copy(
			array(
				'case_version'      => self::CASE_VERSION,
				'source_suite'      => $this->source_suite,
				'source_test'       => $this->source_test,
				'disposition'       => $this->disposition,
				'outcome_id'        => $this->outcome_id,
				'retirement_reason' => $this->retirement_reason,
			),
			'Contract Lab harness cases must contain only persisted data.'
		);
	}

	private static function assert_source_test( string $source_suite, string $source_test ): void {
		if ( 1 !== preg_match( '/^[A-Z][A-Za-z0-9]+Test$/D', $source_suite ) ) {
			throw new InvalidArgumentException( 'Contract Lab harness source suite must be a test class identity.' );
		}
		if ( 1 !== preg_match( '/^test_[a-z0-9_]+$/D', $source_test ) ) {
			throw new InvalidArgumentException( 'Contract Lab harness source test must be a test method identity.' );
		}
	}
}
