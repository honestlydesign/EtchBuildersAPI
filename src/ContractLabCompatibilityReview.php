<?php
/**
 * Audited maintainer review used for Contract Lab classification actions.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use DateTimeImmutable;
use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;

/**
 * Makes actor, time, rationale, and gate evidence explicit before a write.
 */
final class ContractLabCompatibilityReview {

	public const REVIEW_VERSION = '1';

	/** @var array<int, string> */
	private const CLASSIFICATIONS = array( 'green', 'yellow', 'red', 'inconclusive' );

	private function __construct( private readonly array $record ) {
	}

	/**
	 * Build a reviewed classification from explicit audit inputs.
	 *
	 * @param array<int, array<string, mixed>> $evidence
	 */
	public static function from_values(
		string $classification,
		string $reviewed_by,
		string $reviewed_at,
		string $rationale,
		array $evidence
	): self {
		return self::from_array(
			array(
				'review_version' => self::REVIEW_VERSION,
				'classification' => $classification,
				'reviewed_by'    => $reviewed_by,
				'reviewed_at'    => $reviewed_at,
				'rationale'      => $rationale,
				'evidence'       => $evidence,
			)
		);
	}

	/**
	 * Rehydrate one canonical reviewed decision.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		self::assert_exact_keys( $record, array( 'classification', 'evidence', 'rationale', 'review_version', 'reviewed_at', 'reviewed_by' ), 'Contract Lab compatibility review' );
		$strings = array( 'review_version', 'classification', 'reviewed_by', 'reviewed_at', 'rationale' );
		foreach ( $strings as $key ) {
			if ( ! is_string( $record[ $key ] ?? null ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab compatibility review field "%s" must be a string.', $key ) );
			}
		}
		if ( self::REVIEW_VERSION !== $record['review_version'] ) {
			throw new ContractLabObservationException( 'unsupported', 'Contract Lab compatibility review version is unsupported.' );
		}
		if ( ! in_array( $record['classification'], self::CLASSIFICATIONS, true ) ) {
			throw new ContractLabObservationException( 'unsupported', sprintf( 'Contract Lab compatibility review classification "%s" is unsupported.', $record['classification'] ) );
		}
		try {
			ContractLabManifestSafety::assert_stable_id( $record['reviewed_by'], 'Contract Lab compatibility reviewer' );
		} catch ( \InvalidArgumentException $error ) {
			throw new ContractLabObservationException( 'malformed', $error->getMessage() );
		}
		self::assert_timestamp( $record['reviewed_at'] );
		if ( '' === $record['rationale'] || trim( $record['rationale'] ) !== $record['rationale'] || preg_match( '/[[:cntrl:]]/', $record['rationale'] ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility review rationale must be a safe non-empty string.' );
		}
		$evidence = $record['evidence'] ?? null;
		if ( ! is_array( $evidence ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility review evidence must be an ordered list.' );
		}
		$evidence = self::normalize_evidence( $evidence );
		self::assert_classification_evidence( $record['classification'], $evidence );

		return new self(
			array(
				'review_version' => self::REVIEW_VERSION,
				'classification' => $record['classification'],
				'reviewed_by'    => $record['reviewed_by'],
				'reviewed_at'    => $record['reviewed_at'],
				'rationale'      => $record['rationale'],
				'evidence'       => $evidence,
			)
		);
	}

	public function classification(): string {
		return $this->record['classification'];
	}

	public function reviewed_by(): string {
		return $this->record['reviewed_by'];
	}

	public function reviewed_at(): string {
		return $this->record['reviewed_at'];
	}

	public function is_green(): bool {
		return 'green' === $this->classification();
	}

	public function is_yellow(): bool {
		return 'yellow' === $this->classification();
	}

	public function is_red(): bool {
		return 'red' === $this->classification();
	}

	public function is_inconclusive(): bool {
		return 'inconclusive' === $this->classification();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return ImmutableArray::copy( $this->record, 'Contract Lab compatibility review must contain only persisted data.' );
	}

	/**
	 * @param array<int, mixed> $evidence
	 * @return array<int, array{kind: string, status: string, summary: string}>
	 */
	private static function normalize_evidence( array $evidence ): array {
		if ( ! array_is_list( $evidence ) || array() === $evidence ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility review evidence must be a non-empty ordered list.' );
		}
		$normalized = array();
		$seen       = array();
		foreach ( $evidence as $item ) {
			if ( ! is_array( $item ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility review evidence entries must be records.' );
			}
			self::assert_exact_keys( $item, array( 'kind', 'status', 'summary' ), 'Contract Lab compatibility review evidence entry' );
			if ( ! is_string( $item['kind'] ?? null ) || ! is_string( $item['status'] ?? null ) || ! is_string( $item['summary'] ?? null ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility review evidence entries must contain strings.' );
			}
			try {
				ContractLabManifestSafety::assert_stable_token( $item['kind'], 'Contract Lab compatibility review evidence kind' );
			} catch ( \InvalidArgumentException $error ) {
				throw new ContractLabObservationException( 'malformed', $error->getMessage() );
			}
			if ( ! in_array( $item['status'], array( 'passed', 'failed', 'inconclusive' ), true ) ) {
				throw new ContractLabObservationException( 'unsupported', sprintf( 'Contract Lab compatibility review evidence status "%s" is unsupported.', $item['status'] ) );
			}
			if ( '' === $item['summary'] || trim( $item['summary'] ) !== $item['summary'] || preg_match( '/[[:cntrl:]]/', $item['summary'] ) ) {
				throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility review evidence summary must be a safe non-empty string.' );
			}
			if ( isset( $seen[ $item['kind'] ] ) ) {
				throw new ContractLabObservationException( 'malformed', sprintf( 'Contract Lab compatibility review evidence has duplicate kind "%s".', $item['kind'] ) );
			}
			$seen[ $item['kind'] ] = true;
			$normalized[] = array( 'kind' => $item['kind'], 'status' => $item['status'], 'summary' => $item['summary'] );
		}

		return $normalized;
	}

	/**
	 * @param array<int, array{kind: string, status: string, summary: string}> $evidence
	 */
	private static function assert_classification_evidence( string $classification, array $evidence ): void {
		$statuses = array_column( $evidence, 'status' );
		$passed   = in_array( 'passed', $statuses, true );
		$failed   = in_array( 'failed', $statuses, true );
		$unknown  = in_array( 'inconclusive', $statuses, true );
		if ( in_array( $classification, array( 'green', 'yellow' ), true ) && ( ! $passed || $failed || $unknown ) ) {
			throw new ContractLabObservationException( 'inconclusive', 'Green and yellow compatibility reviews require completely passed, non-inconclusive evidence.' );
		}
		if ( 'red' === $classification && ( ! $failed || $unknown ) ) {
			throw new ContractLabObservationException( 'malformed', 'Red compatibility reviews require failed contract evidence and no inconclusive infrastructure evidence.' );
		}
		if ( 'inconclusive' === $classification && ! $unknown ) {
			throw new ContractLabObservationException( 'inconclusive', 'Inconclusive compatibility reviews require explicit inconclusive evidence.' );
		}
	}

	private static function assert_timestamp( string $timestamp ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $timestamp ) ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility review time must be an ISO-8601 offset timestamp.' );
		}
		try {
			$parsed = new DateTimeImmutable( $timestamp );
		} catch ( \Exception $error ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility review time is invalid.' );
		}
		if ( ! str_ends_with( $timestamp, 'Z' ) && $parsed->format( 'Y-m-d\TH:i:sP' ) !== $timestamp ) {
			throw new ContractLabObservationException( 'malformed', 'Contract Lab compatibility review time is not canonical.' );
		}
	}

	/**
	 * @param array<array-key, mixed> $record
	 * @param array<int, string>      $expected
	 */
	private static function assert_exact_keys( array $record, array $expected, string $label ): void {
		$actual = array_keys( $record );
		$left   = $actual;
		$right  = $expected;
		sort( $left );
		sort( $right );
		if ( $left !== $right ) {
			throw new ContractLabObservationException( 'malformed', $label . ' has an unknown or missing field.' );
		}
	}
}
