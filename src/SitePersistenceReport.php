<?php
/**
 * Immutable report from one compiled Site persistence apply.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Carries ordered entity outcomes and any diagnostics that blocked apply.
 */
final class SitePersistenceReport {

	/**
	 * @param array<int, SitePersistenceResult>   $results
	 * @param array<int, CompiledSiteDiagnostic>  $blocking_diagnostics
	 */
	private function __construct(
		private readonly array $results,
		private readonly array $blocking_diagnostics
	) {
	}

	/**
	 * @param array<int, SitePersistenceResult>  $results
	 * @param array<int, CompiledSiteDiagnostic> $blocking_diagnostics
	 */
	public static function new( array $results = array(), array $blocking_diagnostics = array() ): self {
		return new self( array_values( $results ), array_values( $blocking_diagnostics ) );
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
	 * @return array{results: array<int, array{identity: string, outcome: string, code: string, message: string}>, blocking_diagnostics: array<int, array{code: string, severity: string, message: string, identity: string|null}>}
	 */
	public function to_array(): array {
		return array(
			'results'              => array_map( static fn ( SitePersistenceResult $result ): array => $result->to_array(), $this->results ),
			'blocking_diagnostics' => array_map( static fn ( CompiledSiteDiagnostic $diagnostic ): array => $diagnostic->to_array(), $this->blocking_diagnostics ),
		);
	}
}
