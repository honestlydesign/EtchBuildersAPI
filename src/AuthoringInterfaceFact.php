<?php
/**
 * Reflection-derived facts for one selected public method.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionType;

/**
 * Generated interface facts. The source symbol selection is the only curated input.
 */
final class AuthoringInterfaceFact {

	/**
	 * @param array<int, array<string, mixed>> $parameters
	 */
	private function __construct(
		private readonly string $class_name,
		private readonly string $method_name,
		private readonly string $visibility,
		private readonly bool $static,
		private readonly array $parameters,
		private readonly ?string $return_type,
		private readonly bool $return_allows_null,
		private readonly bool $deprecated,
		private readonly ?string $deprecation_reason,
		private readonly string $contract_version,
		private readonly ?string $source_file
	) {
	}

	/**
	 * Derive all interface facts from the selected reflection method and its source docblock.
	 */
	public static function from_reflection( ReflectionMethod $method, AuthoringCapabilitySourceSymbol $symbol ): self {
		if ( ! $method->isPublic() ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source symbol "%s" is not public.', $symbol->identity() ) );
		}

		$declaring_class = $method->getDeclaringClass()->getName();
		if ( $declaring_class !== $symbol->class_name() ) {
			throw new InvalidArgumentException(
				sprintf( 'Authoring source symbol "%s" must be declared by the selected class, not "%s".', $symbol->identity(), $declaring_class )
			);
		}

		$source_file = $method->getFileName();
		if ( false === $source_file ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source symbol "%s" has no readable source file.', $symbol->identity() ) );
		}

		$parameters = array();
		foreach ( $method->getParameters() as $position => $parameter ) {
			$parameters[] = self::parameter_record( $parameter, $position );
		}

		$return_type = $method->getReturnType();
		$doc_comment = $method->getDocComment();
		$contract_version = self::required_doc_tag( false === $doc_comment ? '' : $doc_comment, 'authoring-contract-version', $symbol );
		$doc_comment = false === $doc_comment ? '' : $doc_comment;
		$deprecation = self::optional_doc_tag( $doc_comment, 'deprecated' );
		$deprecated  = self::has_doc_tag( $doc_comment, 'deprecated' );

		return new self(
			$symbol->class_name(),
			$symbol->method_name(),
			'public',
			$method->isStatic(),
			$parameters,
			self::type_name( $return_type ),
			null === $return_type || $return_type->allowsNull(),
			$deprecated,
			$deprecation,
			$contract_version,
			$source_file
		);
	}

	/**
	 * Rehydrate a generated projection. Authority still requires Generator::verify().
	 *
	 * @param array<string, mixed> $record
	 */
	public static function from_array( array $record ): self {
		AcyclicArrayGuard::assert_acyclic( $record );
		$keys = array_keys( $record );
		sort( $keys );
		if ( array( 'class', 'contract_version', 'deprecated', 'deprecation_reason', 'method', 'parameters', 'return_allows_null', 'return_type', 'static', 'visibility' ) !== $keys ) {
			throw new InvalidArgumentException( 'Authoring interface fact must contain exactly its generated fields.' );
		}

		$class_name         = $record['class'];
		$method_name        = $record['method'];
		$visibility         = $record['visibility'];
		$static             = $record['static'];
		$parameters         = $record['parameters'];
		$return_type        = $record['return_type'];
		$return_allows_null = $record['return_allows_null'];
		$deprecated         = $record['deprecated'];
		$deprecation_reason = $record['deprecation_reason'];
		$contract_version   = $record['contract_version'];

		if ( ! is_string( $class_name ) || ! is_string( $method_name ) || ! is_string( $visibility ) || ! is_bool( $static ) || ! is_array( $parameters ) || ! array_is_list( $parameters ) || ( null !== $return_type && ! is_string( $return_type ) ) || ! is_bool( $return_allows_null ) || ! is_bool( $deprecated ) || ( null !== $deprecation_reason && ! is_string( $deprecation_reason ) ) || ! is_string( $contract_version ) ) {
			throw new InvalidArgumentException( 'Authoring interface fact has invalid field shapes.' );
		}

		AuthoringCapabilitySourceSymbol::method( $class_name, $method_name );
		if ( 'public' !== $visibility || '' === trim( $contract_version ) || ( ! $deprecated && null !== $deprecation_reason ) || ( $deprecated && null !== $deprecation_reason && '' === trim( $deprecation_reason ) ) ) {
			throw new InvalidArgumentException( 'Authoring interface fact has invalid generated metadata.' );
		}

		$normalized_parameters = array();
		foreach ( $parameters as $position => $parameter ) {
			if ( ! is_array( $parameter ) ) {
				throw new InvalidArgumentException( 'Authoring interface fact parameters must be object records.' );
			}

			$normalized_parameters[] = self::normalize_parameter( $parameter, $position );
		}

		$fact = new self(
			$class_name,
			$method_name,
			$visibility,
			$static,
			$normalized_parameters,
			$return_type,
			$return_allows_null,
			$deprecated,
			$deprecation_reason,
			$contract_version,
			null
		);

		if ( $fact->to_array() !== $record ) {
			throw new InvalidArgumentException( 'Authoring interface fact must be a canonical generated projection.' );
		}

		return $fact;
	}

	public function class_name(): string {
		return $this->class_name;
	}

	public function method_name(): string {
		return $this->method_name;
	}

	public function contract_version(): string {
		return $this->contract_version;
	}

	public function source_file(): ?string {
		return $this->source_file;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'class'               => $this->class_name,
			'method'              => $this->method_name,
			'visibility'          => $this->visibility,
			'static'              => $this->static,
			'parameters'          => $this->parameters,
			'return_type'         => $this->return_type,
			'return_allows_null'  => $this->return_allows_null,
			'deprecated'          => $this->deprecated,
			'deprecation_reason'  => $this->deprecation_reason,
			'contract_version'    => $this->contract_version,
		);
	}

	private static function type_name( ?ReflectionType $type ): ?string {
		return null === $type ? null : (string) $type;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function parameter_record( ReflectionParameter $parameter, int $position ): array {
		$has_default     = $parameter->isDefaultValueAvailable();
		$default         = null;
		$default_constant = null;
		if ( $has_default ) {
			if ( $parameter->isDefaultValueConstant() ) {
				$default_constant = $parameter->getDefaultValueConstantName();
				if ( null === $default_constant || '' === $default_constant ) {
					throw new InvalidArgumentException( sprintf( 'Parameter "%s" has an unnamed default constant.', $parameter->getName() ) );
				}
			} else {
				$default = self::copy_default( $parameter->getDefaultValue(), $parameter->getName() );
			}
		}

		return array(
			'name'             => $parameter->getName(),
			'position'         => $position,
			'type'             => self::type_name( $parameter->getType() ),
			'allows_null'      => $parameter->allowsNull(),
			'optional'         => $parameter->isOptional(),
			'variadic'         => $parameter->isVariadic(),
			'has_default'      => $has_default,
			'default'          => $default,
			'default_constant' => $default_constant,
		);
	}

	/**
	 * @return mixed
	 */
	private static function copy_default( mixed $value, string $parameter_name ): mixed {
		if ( is_array( $value ) ) {
			return ImmutableArray::copy( $value, sprintf( 'Parameter "%s" has an unsupported default value.', $parameter_name ) );
		}

		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}

		throw new InvalidArgumentException( sprintf( 'Parameter "%s" has an unsupported default value.', $parameter_name ) );
	}

	/**
	 * @param array<string, mixed> $parameter
	 * @return array<string, mixed>
	 */
	private static function normalize_parameter( array $parameter, int $position ): array {
		$keys = array_keys( $parameter );
		sort( $keys );
		if ( array( 'allows_null', 'default', 'default_constant', 'has_default', 'name', 'optional', 'position', 'type', 'variadic' ) !== $keys ) {
			throw new InvalidArgumentException( 'Authoring interface parameter must contain exactly its generated fields.' );
		}

		$name             = $parameter['name'];
		$record_position  = $parameter['position'];
		$type             = $parameter['type'];
		$allows_null      = $parameter['allows_null'];
		$optional         = $parameter['optional'];
		$variadic         = $parameter['variadic'];
		$has_default      = $parameter['has_default'];
		$default          = $parameter['default'];
		$default_constant = $parameter['default_constant'];

		if ( ! is_string( $name ) || ! is_int( $record_position ) || $record_position !== $position || ( null !== $type && ! is_string( $type ) ) || ! is_bool( $allows_null ) || ! is_bool( $optional ) || ! is_bool( $variadic ) || ! is_bool( $has_default ) || ( null !== $default_constant && ! is_string( $default_constant ) ) ) {
			throw new InvalidArgumentException( 'Authoring interface parameter has invalid field shapes.' );
		}

		if ( ! $has_default && ( null !== $default || null !== $default_constant ) ) {
			throw new InvalidArgumentException( 'Authoring interface parameter has a default without has_default.' );
		}

		if ( null !== $default_constant && '' === trim( $default_constant ) ) {
			throw new InvalidArgumentException( 'Authoring interface parameter has an empty default constant.' );
		}

		if ( is_array( $default ) ) {
			$default = ImmutableArray::copy( $default, 'Authoring interface parameter default must be scalar or an array.' );
		} elseif ( ! is_string( $default ) && ! is_int( $default ) && ! is_float( $default ) && ! is_bool( $default ) && null !== $default ) {
			throw new InvalidArgumentException( 'Authoring interface parameter default must be scalar or an array.' );
		}

		return array(
			'name'             => $name,
			'position'         => $record_position,
			'type'             => $type,
			'allows_null'      => $allows_null,
			'optional'         => $optional,
			'variadic'         => $variadic,
			'has_default'      => $has_default,
			'default'          => $default,
			'default_constant' => $default_constant,
		);
	}

	private static function required_doc_tag( string $doc_comment, string $tag, AuthoringCapabilitySourceSymbol $symbol ): string {
		$value = self::optional_doc_tag( $doc_comment, $tag );
		if ( null === $value || '' === trim( $value ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source symbol "%s" is missing @%s.', $symbol->identity(), $tag ) );
		}

		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value ) ) {
			throw new InvalidArgumentException( sprintf( 'Authoring source symbol "%s" has an invalid @%s value.', $symbol->identity(), $tag ) );
		}

		return $value;
	}

	private static function optional_doc_tag( string $doc_comment, string $tag ): ?string {
		$pattern = sprintf( '/^[ \\t]*\\*[ \\t]*@%s(?:[ \\t]+(.*?))?[ \\t]*$/mi', preg_quote( $tag, '/' ) );
		if ( 1 !== preg_match( $pattern, $doc_comment, $matches ) ) {
			return null;
		}

		$value = isset( $matches[1] ) ? trim( $matches[1] ) : '';
		return '' === $value ? null : $value;
	}

	private static function has_doc_tag( string $doc_comment, string $tag ): bool {
		$pattern = sprintf( '/^[ \\t]*\\*[ \\t]*@%s(?:[ \\t]+.*)?[ \\t]*$/mi', preg_quote( $tag, '/' ) );
		return 1 === preg_match( $pattern, $doc_comment );
	}
}
