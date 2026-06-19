<?php
/**
 * Loop property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

/**
 * Fluent builder for Etch loop properties (primitive string, specialized array).
 *
 * Example:
 *   LoopProperty::new('Posts')
 *     ->key('posts')
 *     ->default('{this.posts}')
 *     ->to_array();
 */
final class LoopProperty extends BaseProperty {

	/**
	 * Create a new loop property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default loop source value.
	 *
	 * @param mixed $value Default loop source.
	 * @throws InvalidArgumentException When value is not a string.
	 */
	public function default( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'Loop property default must be a string.' );
		}

		$this->default_value = $value;
		$this->has_default   = true;
		return $this;
	}

	/**
	 * Returns the string primitive discriminator.
	 */
	public function get_primitive(): PropertyPrimitive {
		return PropertyPrimitive::STRING;
	}

	/**
	 * Returns the loop specialized discriminator.
	 */
	protected function get_specialized(): string {
		return 'array';
	}

	/**
	 * Builds loop-only payload fields.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		return array();
	}
}
