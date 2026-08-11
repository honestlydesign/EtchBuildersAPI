<?php
/**
 * Machine-checkable class-style migration failure.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Exceptions;

use HonestlyDesign\EtchBuilders\ClassStyleDiagnostic;
use InvalidArgumentException;

/**
 * Keeps validation failures backwards-compatible while exposing a stable code.
 */
final class ClassStyleDiagnosticException extends InvalidArgumentException {

	/**
	 * Stable diagnostic code.
	 */
	private string $diagnostic_code;

	/**
	 * Constructor.
	 *
	 * @param string $diagnostic_code Stable diagnostic code.
	 * @param string $problem Concrete invalid input or state.
	 * @param string $migration Prescriptive safe replacement.
	 */
	public function __construct( string $diagnostic_code, string $problem, string $migration ) {
		$this->diagnostic_code = $diagnostic_code;

		parent::__construct( ClassStyleDiagnostic::format( $diagnostic_code, $problem, $migration ) );
	}

	/**
	 * Return the stable machine-checkable diagnostic code.
	 */
	public function diagnostic_code(): string {
		return $this->diagnostic_code;
	}
}
