<?php
/**
 * Checked component source expression.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentProperties;

use HonestlyDesign\EtchBuilders\EtchBlocks\ComponentPropValueEncoder;
use InvalidArgumentException;

/**
 * Retains a conservative source path and its declared result kind until schema validation.
 *
 * This value intentionally models only a standalone source lookup. It does not
 * model Etch templates, literals, calls, modifiers, operators, or runtime-token
 * classes, and it does not claim that a source will exist at render time. A
 * CLASS_STYLE_SET result must resolve at runtime to opaque Etch style IDs,
 * never selector or HTML class names.
 */
final class ComponentExpression {

	private function __construct(
		private readonly string $source_path,
		private readonly PropertyInstanceValueKind $expected_kind
	) {
	}

	/**
	 * Create one exact standalone source lookup.
	 */
	public static function source( string $source_path, PropertyInstanceValueKind $expected_kind ): self {
		if ( PropertyInstanceValueKind::TRANSPARENT_CHILDREN === $expected_kind ) {
			throw new InvalidArgumentException(
				'Component expression result kind "transparent-children" is declaration-only and cannot be persisted.'
			);
		}

		$is_simple_path = 1 === preg_match(
			'/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*$/D',
			$source_path
		);
		$is_literal      = in_array( strtolower( $source_path ), array( 'true', 'false', 'null', 'infinity' ), true );
		$is_runtime      = str_starts_with( $source_path, 'rt-' );

		if ( ! $is_simple_path || $is_literal || $is_runtime ) {
			throw new InvalidArgumentException(
				'Component expression source must be an exact simple dot-separated source path without braces, templates, literals, calls, modifiers, operators, brackets, quotes, escapes, runtime tokens, or surrounding whitespace.'
			);
		}

		return new self( $source_path, $expected_kind );
	}

	public function source_path(): string {
		return $this->source_path;
	}

	public function expected_kind(): PropertyInstanceValueKind {
		return $this->expected_kind;
	}

	/**
	 * Encode only after an exact Component Contract target accepts the declared kind.
	 */
	public function encode(): string {
		return ComponentPropValueEncoder::expression( $this->source_path );
	}
}
