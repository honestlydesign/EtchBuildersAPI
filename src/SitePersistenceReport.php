<?php
/**
 * Immutable report from one compiled Site persistence apply.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Carries ordered entity outcomes, any diagnostics that blocked apply, and
 * non-blocking notices that need a person's attention.
 */
final class SitePersistenceReport {

	/**
	 * @param array<int, SitePersistenceResult>   $results
	 * @param array<int, CompiledSiteDiagnostic>  $blocking_diagnostics
	 * @param array<int, CompiledSiteDiagnostic>  $notices
	 */
	private function __construct(
		private readonly array $results,
		private readonly array $blocking_diagnostics,
		private readonly array $notices
	) {
	}

	/**
	 * @param array<int, SitePersistenceResult>  $results
	 * @param array<int, CompiledSiteDiagnostic> $blocking_diagnostics
	 * @param array<int, CompiledSiteDiagnostic> $notices Non-blocking findings, such as orphaned entities or editor-written style duplicates left untouched.
	 */
	public static function new( array $results = array(), array $blocking_diagnostics = array(), array $notices = array() ): self {
		foreach ( $notices as $notice ) {
			if ( ! $notice instanceof CompiledSiteDiagnostic ) {
				throw new \InvalidArgumentException( 'Site persistence notices must be CompiledSiteDiagnostic values.' );
			}
		}

		return new self( array_values( $results ), array_values( $blocking_diagnostics ), array_values( $notices ) );
	}

	/**
	 * Return a report with these results and notices appended.
	 *
	 * @param array<int, SitePersistenceResult>  $results
	 * @param array<int, CompiledSiteDiagnostic> $notices
	 */
	public function with( array $results = array(), array $notices = array() ): self {
		return self::new(
			array_merge( $this->results, $results ),
			$this->blocking_diagnostics,
			array_merge( $this->notices, $notices )
		);
	}

	/**
	 * @return array<int, SitePersistenceResult>
	 */
	public function results(): array {
		return $this->results;
	}

	/**
	 * @return array<int, CompiledSiteDiagnostic>
	 */
	public function blocking_diagnostics(): array {
		return $this->blocking_diagnostics;
	}

	/**
	 * @return array<int, SitePersistenceResult>
	 */
	public function applied_results(): array {
		return $this->filter_results( static fn ( SitePersistenceResult $result ): bool => $result->is_applied() );
	}

	/**
	 * @return array<int, SitePersistenceResult>
	 */
	public function unchanged_results(): array {
		return $this->filter_results( static fn ( SitePersistenceResult $result ): bool => $result->is_unchanged() );
	}

	/**
	 * @return array<int, SitePersistenceResult>
	 */
	public function conflicted_results(): array {
		return $this->filter_results( static fn ( SitePersistenceResult $result ): bool => $result->is_conflicted() );
	}

	/**
	 * @return array<int, SitePersistenceResult>
	 */
	public function skipped_results(): array {
		return $this->filter_results( static fn ( SitePersistenceResult $result ): bool => $result->is_skipped() );
	}

	/**
	 * @return array<int, SitePersistenceResult>
	 */
	public function failed_results(): array {
		return $this->filter_results( static fn ( SitePersistenceResult $result ): bool => $result->is_failed() );
	}

	/**
	 * Non-blocking findings that do not fail the apply but need review.
	 *
	 * @return array<int, CompiledSiteDiagnostic>
	 */
	public function notices(): array {
		return $this->notices;
	}

	public function was_blocked(): bool {
		return array() !== $this->blocking_diagnostics;
	}

	public function is_success(): bool {
		if ( $this->was_blocked() ) {
			return false;
		}

		foreach ( $this->results as $result ) {
			if ( ! $result->is_success() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param callable(SitePersistenceResult): bool $predicate
	 * @return array<int, SitePersistenceResult>
	 */
	private function filter_results( callable $predicate ): array {
		return array_values( array_filter( $this->results, $predicate ) );
	}

	/**
	 * The `notices` key is present only when the report carries notices.
	 *
	 * @return array{results: array<int, array{identity: string, outcome: string, code: string, message: string}>, blocking_diagnostics: array<int, array{code: string, severity: string, message: string, identity: string|null}>, notices?: array<int, array{code: string, severity: string, message: string, identity: string|null}>}
	 */
	public function to_array(): array {
		$projection = array(
			'results'              => array_map( static fn ( SitePersistenceResult $result ): array => $result->to_array(), $this->results ),
			'blocking_diagnostics' => array_map( static fn ( CompiledSiteDiagnostic $diagnostic ): array => $diagnostic->to_array(), $this->blocking_diagnostics ),
		);
		if ( array() !== $this->notices ) {
			$projection['notices'] = array_map( static fn ( CompiledSiteDiagnostic $diagnostic ): array => $diagnostic->to_array(), $this->notices );
		}

		return $projection;
	}
}
