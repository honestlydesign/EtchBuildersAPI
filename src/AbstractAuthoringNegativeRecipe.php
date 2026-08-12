<?php
/**
 * Shared execution mechanics for negative Authoring Capability recipes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Exceptions\ClassStyleDiagnosticException;
use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use Throwable;

/**
 * Executes invalid fixtures through the no-write compiler and catches typed
 * guard failures without allowing a negative fixture to leak static state.
 */
abstract class AbstractAuthoringNegativeRecipe implements AuthoringNegativeRecipeInterface {

	private const STORAGE_KEYS = array(
		'etch_styles',
		'etch_global_stylesheets',
		'etch_loops',
		'etch_components',
		'etch_patterns',
		'etch_pages',
		'etch_posts',
		'etch_templates',
	);

	abstract protected function build(): SiteDefinition;

	public function execute(): AuthoringNegativeRecipeResult {
		$storage_before = $this->storage_snapshot();
		$style_state    = Style::snapshot_state();
		$plan           = null;
		$actual         = null;

		try {
			$plan   = $this->build()->compile();
			$actual = self::diagnostic_from_plan( $plan );
		} catch ( Throwable $throwable ) {
			$actual = self::diagnostic_from_throwable( $throwable );
		} finally {
			Style::restore_state( $style_state );
		}

		$writes_detected = $storage_before !== $this->storage_snapshot();

		return AuthoringNegativeRecipeResult::from_execution(
			$this->id(),
			$this->version(),
			$this->expected_outcome(),
			$actual,
			$plan,
			$writes_detected
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	final public function to_array(): array {
		return array(
			'id'               => $this->id(),
			'version'          => $this->version(),
			'capability_ids'   => $this->capability_ids(),
			'prerequisite_ids' => $this->prerequisite_ids(),
			'inputs'           => $this->inputs(),
			'expected_outcome' => $this->expected_outcome()->to_array(),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function storage_snapshot(): array {
		$snapshot = array();
		foreach ( self::STORAGE_KEYS as $key ) {
			$snapshot[ $key ] = Environment::storage()->get( $key, null );
		}

		AcyclicArrayGuard::assert_acyclic( $snapshot );

		return $snapshot;
	}

	/**
	 * @return array{code: string, severity: string, message: string, identity: string|null}|null
	 */
	private static function diagnostic_from_plan( CompiledSitePlan $plan ): ?array {
		$diagnostics = $plan->diagnostics();
		if ( array() === $diagnostics ) {
			return null;
		}

		return $diagnostics[0]->to_array();
	}

	/**
	 * @return array{code: string, severity: string, message: string, identity: string|null}
	 */
	private static function diagnostic_from_throwable( Throwable $throwable ): array {
		if ( $throwable instanceof ClassStyleDiagnosticException ) {
			return array(
				'code'     => $throwable->diagnostic_code(),
				'severity' => CompiledSiteDiagnosticSeverity::ERROR->value,
				'message'  => $throwable->getMessage(),
				'identity' => null,
			);
		}

		return array(
			'code'     => 'ETCH_NEGATIVE_RECIPE_UNEXPECTED_EXCEPTION',
			'severity' => CompiledSiteDiagnosticSeverity::ERROR->value,
			'message'  => $throwable::class . ': ' . $throwable->getMessage(),
			'identity' => null,
		);
	}
}
