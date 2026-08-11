<?php
/**
 * Stable class-style migration diagnostics.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Exceptions\ClassStyleDiagnosticException;

/**
 * Public code catalog and formatter for class-style migration failures.
 */
final class ClassStyleDiagnostic {

	public const UNKNOWN_ID = 'ETCH_CLASS_UNKNOWN_ID';

	public const NON_CLASS_STYLE = 'ETCH_CLASS_NON_CLASS_STYLE';

	public const CLASS_NAME_INPUT = 'ETCH_CLASS_NAME_INSTEAD_OF_ID';

	public const RUNTIME_TOKEN = 'ETCH_CLASS_RUNTIME_TOKEN';

	public const COMPOUND_SELECTOR = 'ETCH_CLASS_COMPOUND_SELECTOR';

	public const DESTRUCTIVE_LEGACY_CALL = 'ETCH_CLASS_DESTRUCTIVE_LEGACY_CALL';

	/**
	 * Prevent direct instantiation.
	 */
	private function __construct() {
	}

	/**
	 * Return the complete stable code catalog in declaration order.
	 *
	 * @return array<int, string>
	 */
	public static function codes(): array {
		return array(
			self::UNKNOWN_ID,
			self::NON_CLASS_STYLE,
			self::CLASS_NAME_INPUT,
			self::RUNTIME_TOKEN,
			self::COMPOUND_SELECTOR,
			self::DESTRUCTIVE_LEGACY_CALL,
		);
	}

	/**
	 * Build a typed migration failure.
	 *
	 * @param string $code Stable diagnostic code.
	 * @param string $problem Concrete invalid input or state.
	 * @param string $migration Prescriptive safe replacement.
	 */
	public static function failure( string $code, string $problem, string $migration ): ClassStyleDiagnosticException {
		return new ClassStyleDiagnosticException( $code, $problem, $migration );
	}

	/**
	 * Emit a backwards-compatible migration diagnostic.
	 *
	 * @param string $code Stable diagnostic code.
	 * @param string $problem Concrete deprecated input or call.
	 * @param string $migration Prescriptive safe replacement.
	 */
	public static function emit_deprecation( string $code, string $problem, string $migration ): void {
		trigger_error( self::format( $code, $problem, $migration ), E_USER_DEPRECATED );
	}

	/**
	 * Format one stable, prescriptive diagnostic message.
	 */
	public static function format( string $code, string $problem, string $migration ): string {
		if ( ! in_array( $code, self::codes(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown class-style diagnostic code: ' . $code );
		}

		return sprintf( '[%s] %s Migration: %s', $code, $problem, $migration );
	}
}
