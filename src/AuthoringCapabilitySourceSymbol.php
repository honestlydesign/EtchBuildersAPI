<?php
/**
 * A source-owned symbol selected for one Authoring Capability.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use InvalidArgumentException;

/**
 * Names a class method without accepting any hand-authored signature facts.
 */
final class AuthoringCapabilitySourceSymbol {

	private const CLASS_PATTERN  = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D';
	private const METHOD_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

	private function __construct(
		private readonly string $class_name,
		private readonly string $method_name
	) {
	}

	/**
	 * Select one class method as the source of generated interface facts.
	 */
	public static function method( string $class_name, string $method_name ): self {
		$class_name  = trim( $class_name );
		$method_name = trim( $method_name );

		if ( 1 !== preg_match( self::CLASS_PATTERN, $class_name ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source class "%s" must be a fully qualified class name.', $class_name ) );
		}

		if ( 1 !== preg_match( self::METHOD_PATTERN, $method_name ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source method "%s" must be a valid method name.', $method_name ) );
		}

		return new self( $class_name, $method_name );
	}

	/**
	 * Rehydrate a source symbol selection. Signature fields are intentionally rejected.
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'class', 'method' ) !== $keys ) {
			throw new InvalidArgumentException( 'Authoring source symbol must contain exactly class and method; signatures are source-derived.' );
		}

		if ( ! is_string( $record['class'] ) || ! is_string( $record['method'] ) ) {
			throw new InvalidArgumentException( 'Authoring source symbol class and method must be strings.' );
		}

		return self::method( $record['class'], $record['method'] );
	}

	public function class_name(): string {
		return $this->class_name;
	}

	public function method_name(): string {
		return $this->method_name;
	}

	/**
	 * @return array{class: string, method: string}
	 */
	public function to_array(): array {
		return array(
			'class'  => $this->class_name,
			'method' => $this->method_name,
		);
	}

	public function identity(): string {
		return $this->class_name . '::' . $this->method_name;
	}
}
