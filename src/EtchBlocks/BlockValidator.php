<?php
/**
 * Validation helpers for strict Etch block builders.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\EtchBlocks;

use InvalidArgumentException;

/**
 * Shared validation helpers for block-specific builders.
 */
final class BlockValidator {

	/**
	 * Base attribute keys shared by all blocks.
	 *
	 * @var array<int, string>
	 */
	private const BASE_ATTRIBUTE_KEYS = array( 'options', 'hidden', 'script' );

	/**
	 * Supported condition operators.
	 *
	 * @var array<int, string>
	 */
	private const CONDITION_OPERATORS = array( '===', '==', '!==', '!=', '<=', '>=', '<', '>', '||', '&&', 'isTruthy', 'isFalsy' );

	/**
	 * Prevent class instantiation.
	 */
	private function __construct() {}

	/**
	 * Validate config keys and extract shared base attributes.
	 *
	 * @param array<string, mixed> $config Builder config.
	 * @param array<int, string>   $specific_keys Block-specific keys.
	 * @param string               $builder_name Builder name for error messages.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException When config validation fails.
	 */
	public static function extract_base_attributes( array $config, array $specific_keys, string $builder_name ): array {
		self::assert_allowed_keys( $config, array_merge( self::BASE_ATTRIBUTE_KEYS, $specific_keys ), $builder_name );

		$attributes = array();

		if ( array_key_exists( 'options', $config ) ) {
			if ( ! is_array( $config['options'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $builder_name . ' expects "options" to be an array when provided.' );
			}

			$attributes['options'] = $config['options'];
		}

		if ( array_key_exists( 'hidden', $config ) ) {
			if ( ! is_bool( $config['hidden'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $builder_name . ' expects "hidden" to be a boolean when provided.' );
			}

			$attributes['hidden'] = $config['hidden'];
		}

		if ( array_key_exists( 'script', $config ) ) {
			if ( ! is_array( $config['script'] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $builder_name . ' expects "script" to be an object with "id" and "code" when provided.' );
			}

			$attributes['script'] = self::normalize_script( $config['script'], $builder_name );
		}

		return $attributes;
	}

	/**
	 * Normalize an Etch script object.
	 *
	 * @param array<string, mixed> $script Script config.
	 * @param string               $builder_name Builder name for error messages.
	 * @return array{id: string, code: string}
	 * @throws InvalidArgumentException When script validation fails.
	 */
	public static function normalize_script( array $script, string $builder_name ): array {
		if ( ! isset( $script['id'], $script['code'] ) || ! is_string( $script['id'] ) || ! is_string( $script['code'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $builder_name . ' expects "script" to include string "id" and string "code".' );
		}

		return array(
			'id'   => $script['id'],
			'code' => $script['code'],
		);
	}

	/**
	 * Normalize attributes map values.
	 *
	 * @param mixed  $value Value to normalize.
	 * @param string $field_name Field name for error messages.
	 * @param string $builder_name Builder name for error messages.
	 * @return array<string, string|null>
	 * @throws InvalidArgumentException When value validation fails.
	 */
	public static function normalize_attributes_map( mixed $value, string $field_name, string $builder_name ): array {
		if ( ! is_array( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $builder_name . ' expects "' . $field_name . '" to be an array.' );
		}

		$attributes = array();
		foreach ( $value as $attribute_name => $attribute_value ) {
			if ( ! is_string( $attribute_name ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $builder_name . ' expects "' . $field_name . '" to use string keys.' );
			}

			if ( null !== $attribute_value && ! is_string( $attribute_value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $builder_name . ' expects "' . $field_name . '" values to be string|null.' );
			}

			$attributes[ $attribute_name ] = $attribute_value;
		}

		return $attributes;
	}

	/**
	 * Normalize attributes map with mixed values.
	 *
	 * @param mixed  $value Value to normalize.
	 * @param string $field_name Field name for error messages.
	 * @param string $builder_name Builder name for error messages.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException When value validation fails.
	 */
	public static function normalize_mixed_map( mixed $value, string $field_name, string $builder_name ): array {
		if ( ! is_array( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $builder_name . ' expects "' . $field_name . '" to be an array.' );
		}

		$attributes = array();
		foreach ( $value as $attribute_name => $attribute_value ) {
			if ( ! is_string( $attribute_name ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $builder_name . ' expects "' . $field_name . '" to use string keys.' );
			}

			$attributes[ $attribute_name ] = $attribute_value;
		}

		return $attributes;
	}

	/**
	 * Normalize string arrays.
	 *
	 * @param mixed  $value Value to normalize.
	 * @param string $field_name Field name for error messages.
	 * @param string $builder_name Builder name for error messages.
	 * @return array<int, string>
	 * @throws InvalidArgumentException When value validation fails.
	 */
	public static function normalize_string_array( mixed $value, string $field_name, string $builder_name ): array {
		if ( ! is_array( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $builder_name . ' expects "' . $field_name . '" to be an array of strings.' );
		}

		$normalized = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $builder_name . ' expects "' . $field_name . '" to be an array of strings.' );
			}

			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * Normalize condition object recursively.
	 *
	 * @param mixed  $condition Raw condition value.
	 * @param string $builder_name Builder name for error messages.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException When condition validation fails.
	 */
	public static function normalize_condition( mixed $condition, string $builder_name ): array {
		if ( ! is_array( $condition ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $builder_name . ' expects "condition" to be an object.' );
		}

		if ( ! array_key_exists( 'leftHand', $condition ) || ! array_key_exists( 'operator', $condition ) || ! array_key_exists( 'rightHand', $condition ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $builder_name . ' expects "condition" to include "leftHand", "operator", and "rightHand".' );
		}

		if ( ! is_string( $condition['operator'] ) || ! in_array( $condition['operator'], self::CONDITION_OPERATORS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $builder_name . ' has invalid condition operator.' );
		}

		$operator   = $condition['operator'];
		$left_hand  = self::normalize_condition_operand( $condition['leftHand'], $builder_name );
		$right_hand = self::normalize_condition_right_hand( $condition['rightHand'], $operator, $builder_name );

		return array(
			'leftHand'  => $left_hand,
			'operator'  => $operator,
			'rightHand' => $right_hand,
		);
	}

	/**
	 * Normalize integer-like values.
	 *
	 * @param mixed  $value Value to normalize.
	 * @param string $field_name Field name for error messages.
	 * @param string $builder_name Builder name for error messages.
	 * @return int Normalized integer.
	 * @throws InvalidArgumentException When value is not a valid integer.
	 */
	public static function normalize_int( mixed $value, string $field_name, string $builder_name ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && '' !== trim( $value ) && ctype_digit( $value ) ) {
			return (int) $value;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new InvalidArgumentException( $builder_name . ' expects "' . $field_name . '" to be an integer.' );
	}

	/**
	 * Normalize strings with optional default.
	 *
	 * @param mixed  $value Value to normalize.
	 * @param string $field_name Field name for error messages.
	 * @param string $builder_name Builder name for error messages.
	 * @param string $fallback Default value when null.
	 * @return string Normalized string.
	 * @throws InvalidArgumentException When value is not a valid string.
	 */
	public static function normalize_string( mixed $value, string $field_name, string $builder_name, string $fallback = '' ): string {
		if ( null === $value ) {
			return $fallback;
		}

		if ( ! is_string( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $builder_name . ' expects "' . $field_name . '" to be a string.' );
		}

		return $value;
	}

	/**
	 * Validate allowed keys.
	 *
	 * @param array<string, mixed> $config Config to validate.
	 * @param array<int, string>   $allowed_keys Allowed config keys.
	 * @param string               $builder_name Builder name for error messages.
	 * @throws InvalidArgumentException When invalid keys are found.
	 */
	private static function assert_allowed_keys( array $config, array $allowed_keys, string $builder_name ): void {
		$allowed = array_fill_keys( $allowed_keys, true );

		foreach ( $config as $key => $_ ) {
			if ( ! is_string( $key ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $builder_name . ' expects config keys to be strings.' );
			}

			if ( ! isset( $allowed[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $builder_name . ' received unsupported config key: ' . $key );
			}
		}
	}

	/**
	 * Normalize condition operands recursively.
	 *
	 * @param mixed  $operand Operand to normalize.
	 * @param string $builder_name Builder name for error messages.
	 * @return array<string, mixed>|string|bool|int|float Normalized operand.
	 * @throws InvalidArgumentException When operand is invalid.
	 */
	private static function normalize_condition_operand( mixed $operand, string $builder_name ): array|string|bool|int|float {
		if ( is_array( $operand ) ) {
			return self::normalize_condition( $operand, $builder_name );
		}

		if ( is_string( $operand ) || is_bool( $operand ) || is_int( $operand ) || is_float( $operand ) ) {
			return $operand;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new InvalidArgumentException( $builder_name . ' expects condition operands to be string|bool|number|object.' );
	}

	/**
	 * Normalize condition right hand value based on operator type.
	 *
	 * @param mixed  $right_hand Right hand value.
	 * @param string $operator Operator type.
	 * @param string $builder_name Builder name for error messages.
	 * @return array<string, mixed>|string|bool|int|float|null Normalized right hand value.
	 * @throws InvalidArgumentException When right hand value is invalid.
	 */
	private static function normalize_condition_right_hand( mixed $right_hand, string $operator, string $builder_name ): array|string|bool|int|float|null {
		if ( 'isTruthy' === $operator || 'isFalsy' === $operator ) {
			if ( null !== $right_hand ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( $builder_name . ' expects "rightHand" to be null for unary operators.' );
			}

			return null;
		}

		if ( null === $right_hand ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $builder_name . ' expects non-null "rightHand" for operator ' . $operator . '.' );
		}

		return self::normalize_condition_operand( $right_hand, $builder_name );
	}
}
