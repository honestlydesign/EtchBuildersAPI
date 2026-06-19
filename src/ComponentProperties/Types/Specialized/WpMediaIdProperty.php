<?php
/**
 * WordPress Media ID property builder.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Types\Specialized;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\BaseProperty;
use HonestlyDesign\EtchBuilders\ComponentProperties\Shared\PropertyPrimitive;

/**
 * Fluent builder for Etch WordPress Media ID properties (specialized string).
 *
 * Example:
 *   WpMediaIdProperty::new('Featured Image')
 *     ->key('featuredImage')
 *     ->default(0)
 *     ->to_array();
 */
final class WpMediaIdProperty extends BaseProperty {

	/**
	 * Create a new WordPress Media ID property builder.
	 *
	 * @param string $name Human-readable property name.
	 */
	public static function new( string $name ): self {
		return new self( $name );
	}

	/**
	 * Set the default media ID value.
	 *
	 * @param mixed $value Default media ID.
	 * @throws InvalidArgumentException When value is not an integer.
	 */
	public function default( mixed $value ): self {
		if ( ! is_int( $value ) ) {
			throw new InvalidArgumentException( 'WordPress Media ID property default must be an integer.' );
		}
		$this->default_value = (string) $value;
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
	 * Returns the WordPress Media ID specialized discriminator.
	 */
	protected function get_specialized(): string {
		return 'wpMediaId';
	}

	/**
	 * Builds WordPress Media ID-only payload fields.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_additional_payload(): array {
		return array();
	}
}
