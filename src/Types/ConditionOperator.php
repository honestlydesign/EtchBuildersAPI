<?php
/**
 * Type-safe builder for nested condition expressions.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Types;

use InvalidArgumentException;

/**
 * Builds nested condition objects for etch/condition blocks.
 *
 * Pattern:
 *   ConditionOperator::truthy('slots.default.empty')
 *     ->and(ConditionOperator::truthy('props.label'))
 *
 *   ConditionOperator::new('props.value', '===', 10)
 */
final class ConditionOperator {
	/**
	 * Supported environment.current values.
	 *
	 * @var array<int, string>
	 */
	private const ENVIRONMENTS = array( 'etch', 'gutenberg', 'frontend' );

	/**
	 * Supported environment.context values.
	 *
	 * @var array<int, string>
	 */
	private const ENVIRONMENT_CONTEXTS = array( 'componentEditor' );

	/**
	 * Left hand side of condition.
	 *
	 * @var string|int|float|bool|null|ConditionOperator
	 */
	private string|int|float|bool|null|ConditionOperator $left_hand = null;

	/**
	 * Comparison operator.
	 *
	 * @var string
	 */
	private string $operator = '';

	/**
	 * Right hand side of condition.
	 *
	 * @var string|int|float|bool|null|ConditionOperator
	 */
	private string|int|float|bool|null|ConditionOperator $right_hand = null;

	/**
	 * Private constructor.
	 */
	private function __construct() {
	}

	/**
	 * Create a new condition with explicit operator.
	 *
	 * @param string|int|float|bool|null|ConditionOperator $left_hand  Left side of condition.
	 * @param string                                       $operator   Operator (isTruthy, isFalsy, ===, !==, ==, !=, <, >, <=, >=, &&, ||).
	 * @param string|int|float|bool|null|ConditionOperator $right_hand Right side of condition (null for unary operators).
	 * @return self
	 */
	public static function new(
		string|int|float|bool|null|ConditionOperator $left_hand,
		string $operator,
		string|int|float|bool|null|ConditionOperator $right_hand = null
	): self {
		$instance             = new self();
		$instance->left_hand  = $left_hand;
		$instance->operator   = $operator;
		$instance->right_hand = $right_hand;
		return $instance;
	}

	/**
	 * Create an isTruthy condition.
	 *
	 * @param string $operand The operand to check.
	 * @return self
	 */
	public static function truthy( string $operand ): self {
		return self::new( $operand, 'isTruthy', null );
	}

	/**
	 * Create an isFalsy condition.
	 *
	 * @param string $operand The operand to check.
	 * @return self
	 */
	public static function falsy( string $operand ): self {
		return self::new( $operand, 'isFalsy', null );
	}

	/**
	 * Create an environment.current equality condition.
	 *
	 * @param string $environment Environment name.
	 * @return self
	 * @throws InvalidArgumentException When the environment is unsupported.
	 */
	public static function is_in_environment( string $environment ): self {
		if ( ! in_array( $environment, self::ENVIRONMENTS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			$allowed_environments = implode( ', ', self::ENVIRONMENTS );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( sprintf( 'ConditionOperator::is_in_environment() expects one of: %s.', $allowed_environments ) );
		}

		return self::equals( 'environment.current', $environment );
	}

	/**
	 * Create an environment.context equality condition.
	 *
	 * @param string $context Environment context name.
	 * @return self
	 * @throws InvalidArgumentException When the context is unsupported.
	 */
	public static function is_in_environment_context( string $context ): self {
		if ( ! in_array( $context, self::ENVIRONMENT_CONTEXTS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			$allowed_contexts = implode( ', ', self::ENVIRONMENT_CONTEXTS );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( sprintf( 'ConditionOperator::is_in_environment_context() expects one of: %s.', $allowed_contexts ) );
		}

		return self::equals( 'environment.context', $context );
	}

	/**
	 * Create an environment.current === "etch" condition.
	 *
	 * @return self
	 */
	public static function is_in_etch(): self {
		return self::is_in_environment( 'etch' );
	}

	/**
	 * Create an environment.current === "gutenberg" condition.
	 *
	 * @return self
	 */
	public static function is_in_gutenberg(): self {
		return self::is_in_environment( 'gutenberg' );
	}

	/**
	 * Create an environment.current === "frontend" condition.
	 *
	 * @return self
	 */
	public static function is_in_frontend(): self {
		return self::is_in_environment( 'frontend' );
	}

	/**
	 * Create an environment.context === "componentEditor" condition.
	 *
	 * @return self
	 */
	public static function is_in_component_editor(): self {
		return self::is_in_environment_context( 'componentEditor' );
	}

	/**
	 * Create an environment.current check for Etch or frontend rendering.
	 *
	 * @return self
	 */
	public static function is_in_etch_or_frontend(): self {
		return self::is_in_etch()->or( self::is_in_frontend() );
	}

	/**
	 * Create a truthy condition for an empty slot.
	 *
	 * @param string $slot_name Slot name.
	 * @return self
	 * @throws InvalidArgumentException When the slot name is empty.
	 */
	public static function is_empty_slot( string $slot_name ): self {
		return self::truthy( self::build_slot_empty_operand( $slot_name ) );
	}

	/**
	 * Create a falsy condition for a filled slot.
	 *
	 * @param string $slot_name Slot name.
	 * @return self
	 * @throws InvalidArgumentException When the slot name is empty.
	 */
	public static function is_filled_slot( string $slot_name ): self {
		return self::falsy( self::build_slot_empty_operand( $slot_name ) );
	}

	/**
	 * Create a truthy condition for an empty default slot.
	 *
	 * @return self
	 */
	public static function is_default_slot_empty(): self {
		return self::is_empty_slot( 'default' );
	}

	/**
	 * Create a falsy condition for a filled default slot.
	 *
	 * @return self
	 */
	public static function is_default_slot_filled(): self {
		return self::is_filled_slot( 'default' );
	}

	/**
	 * Create an equality condition (===).
	 *
	 * @param string|int|float|bool|null $left  Left side.
	 * @param string|int|float|bool|null $right Right side.
	 * @return self
	 */
	public static function equals( string|int|float|bool|null $left, string|int|float|bool|null $right ): self {
		return self::new( $left, '===', $right );
	}

	/**
	 * Create an inequality condition (!==).
	 *
	 * @param string|int|float|bool|null $left  Left side.
	 * @param string|int|float|bool|null $right Right side.
	 * @return self
	 */
	public static function not_equals( string|int|float|bool|null $left, string|int|float|bool|null $right ): self {
		return self::new( $left, '!==', $right );
	}

	/**
	 * Create a greater than condition (>).
	 *
	 * @param string|int|float|bool|null $left  Left side.
	 * @param string|int|float|bool|null $right Right side.
	 * @return self
	 */
	public static function greater_than( string|int|float|bool|null $left, string|int|float|bool|null $right ): self {
		return self::new( $left, '>', $right );
	}

	/**
	 * Create a less than condition (<).
	 *
	 * @param string|int|float|bool|null $left  Left side.
	 * @param string|int|float|bool|null $right Right side.
	 * @return self
	 */
	public static function less_than( string|int|float|bool|null $left, string|int|float|bool|null $right ): self {
		return self::new( $left, '<', $right );
	}

	/**
	 * Create a greater than or equal condition (>=).
	 *
	 * @param string|int|float|bool|null $left  Left side.
	 * @param string|int|float|bool|null $right Right side.
	 * @return self
	 */
	public static function greater_or_equal( string|int|float|bool|null $left, string|int|float|bool|null $right ): self {
		return self::new( $left, '>=', $right );
	}

	/**
	 * Create a less than or equal condition (<=).
	 *
	 * @param string|int|float|bool|null $left  Left side.
	 * @param string|int|float|bool|null $right Right side.
	 * @return self
	 */
	public static function less_or_equal( string|int|float|bool|null $left, string|int|float|bool|null $right ): self {
		return self::new( $left, '<=', $right );
	}

	/**
	 * Combine with another condition using AND.
	 *
	 * @param ConditionOperator $condition The condition to AND with.
	 * @return self New combined condition.
	 */
	public function and( ConditionOperator $condition ): self {
		return self::new( $this, '&&', $condition );
	}

	/**
	 * Combine with another condition using OR.
	 *
	 * @param ConditionOperator $condition The condition to OR with.
	 * @return self New combined condition.
	 */
	public function or( ConditionOperator $condition ): self {
		return self::new( $this, '||', $condition );
	}

	/**
	 * Convert to array for JSON serialization.
	 *
	 * @return array<string, mixed> Nested condition object.
	 */
	public function to_array(): array {
		return array(
			'leftHand'  => $this->serialize_value( $this->left_hand ),
			'operator'  => $this->operator,
			'rightHand' => $this->serialize_value( $this->right_hand ),
		);
	}

	/**
	 * Generate condition string representation.
	 *
	 * @return string Human-readable condition string.
	 */
	public function to_string(): string {
		return $this->build_string( $this );
	}

	/**
	 * Recursively build condition string.
	 *
	 * @param ConditionOperator|string|int|float|bool|null $value The value to serialize.
	 * @return string Condition string segment.
	 */
	private function build_string( ConditionOperator|string|int|float|bool|null $value ): string {
		if ( $value instanceof ConditionOperator ) {
			$left_str  = $this->build_string( $value->left_hand );
			$right_str = $this->build_string( $value->right_hand );

			// Unary operators.
			if ( 'isTruthy' === $value->operator ) {
				return $left_str;
			}
			if ( 'isFalsy' === $value->operator ) {
				return "! {$left_str}";
			}

			// Binary operators.
			if ( '&&' === $value->operator || '||' === $value->operator ) {
				return "{$left_str} {$value->operator} {$right_str}";
			}

			return "{$left_str} {$value->operator} {$right_str}";
		}

		// Primitive values.
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		// String values: quote unless they are property references.
		if ( is_string( $value ) && ! $this->is_property_reference( $value ) ) {
			return $this->quote_string_literal( $value );
		}

		return (string) $value;
	}

	/**
	 * Check if a string is a property reference.
	 *
	 * @param string $value The value to check.
	 * @return bool True if the value is a property reference.
	 */
	private function is_property_reference( string $value ): bool {
		$prefixes = array( 'props.', 'item.', 'slots.', 'state.', 'context.', 'environment.' );

		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $value, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the slot empty operand path.
	 *
	 * @param string $slot_name Slot name.
	 * @return string
	 * @throws InvalidArgumentException When the slot name is empty.
	 */
	private static function build_slot_empty_operand( string $slot_name ): string {
		$slot_name = trim( $slot_name );

		if ( '' === $slot_name ) {
			throw new InvalidArgumentException( 'ConditionOperator slot helper expects a non-empty slot name.' );
		}

		return 'slots.' . $slot_name . '.empty';
	}

	/**
	 * Serialize a value for the condition object.
	 *
	 * @param ConditionOperator|string|int|float|bool|null $value The value to serialize.
	 * @return mixed Serialized value.
	 */
	private function serialize_value( ConditionOperator|string|int|float|bool|null $value ): mixed {
		if ( $value instanceof ConditionOperator ) {
			return $value->to_array();
		}

		if ( is_string( $value ) && ! $this->is_property_reference( $value ) ) {
			return $this->quote_string_literal( $value );
		}

		return $value;
	}

	/**
	 * Quote and escape a string literal for Etch condition payloads.
	 *
	 * @param string $value String literal value.
	 * @return string
	 */
	private function quote_string_literal( string $value ): string {
		return '"' . str_replace(
			array( '\\', '"' ),
			array( '\\\\', '\\"' ),
			$value
		) . '"';
	}

	/**
	 * Get the operator.
	 *
	 * @return string
	 */
	public function get_operator(): string {
		return $this->operator;
	}
}
