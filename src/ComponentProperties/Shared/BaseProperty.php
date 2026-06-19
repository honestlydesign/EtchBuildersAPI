<?php
/**
 * Abstract base for fluent Etch component property builders.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Shared;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Contracts\ComponentPropertyInterface;

/**
 * Base class for all component property builders.
 *
 * Provides common functionality: name, key derivation, serialization.
 */
abstract class BaseProperty implements ComponentPropertyInterface {

	/**
	 * Human-readable property name.
	 *
	 * @var string
	 */
	protected string $name;

	/**
	 * Property key used in props.* expressions.
	 *
	 * @var string
	 */
	protected string $key;

	/**
	 * Optional editor key touched flag.
	 *
	 * @var bool|null
	 */
	protected ?bool $key_touched = null;

	/**
	 * Optional source identifier for inherited props.
	 *
	 * @var string|null
	 */
	protected ?string $prop_source_id = null;

	/**
	 * Optional property description for the editor.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * Default value for the property.
	 *
	 * @var mixed
	 */
	protected mixed $default_value = null;

	/**
	 * Whether a default value has been set.
	 *
	 * @var bool
	 */
	protected bool $has_default = false;

	/**
	 * Constructor.
	 *
	 * @param string $name Human-readable property name.
	 * @throws InvalidArgumentException When name is empty.
	 */
	protected function __construct( string $name ) {
		$name = trim( $name );

		if ( '' === $name ) {
			throw new InvalidArgumentException( 'Property name cannot be empty.' );
		}

		$this->name = $name;
		$this->key  = $this->derive_key_from_name( $name );
	}

	/**
	 * Set the human-readable property name.
	 *
	 * @param string $name Property display name.
	 * @throws InvalidArgumentException When name is empty.
	 */
	public function name( string $name ): static {
		$name = trim( $name );

		if ( '' === $name ) {
			throw new InvalidArgumentException( 'Property name cannot be empty.' );
		}

		$this->name = $name;
		return $this;
	}

	/**
	 * Set the property key.
	 *
	 * @param string $key Property key (must match /^[A-Za-z_][A-Za-z0-9_]*$/).
	 * @throws InvalidArgumentException When key format is invalid.
	 */
	public function key( string $key ): static {
		$key = trim( $key );

		if ( '' === $key ) {
			throw new InvalidArgumentException( 'Property key cannot be empty.' );
		}

		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $key ) ) {
			throw new InvalidArgumentException(
				'Property key must match /^[A-Za-z_][A-Za-z0-9_]*$/.'
			);
		}

		$this->key = $key;
		return $this;
	}

	/**
	 * Set the key touched flag.
	 *
	 * @param bool $touched Whether the key has been touched.
	 */
	public function key_touched( bool $touched ): static {
		$this->key_touched = $touched;
		return $this;
	}

	/**
	 * Set the source identifier for inherited props.
	 *
	 * @param string $id Source identifier.
	 */
	public function prop_source_id( string $id ): static {
		$this->prop_source_id = $id;
		return $this;
	}

	/**
	 * Set the property description.
	 *
	 * @param string $description Property description.
	 * @throws InvalidArgumentException When description is empty.
	 */
	public function description( string $description ): static {
		$description = trim( $description );

		if ( '' === $description ) {
			throw new InvalidArgumentException( 'Property description cannot be empty.' );
		}

		$this->description = $description;
		return $this;
	}

	/**
	 * Get the property display name.
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Get the property key.
	 */
	public function get_key(): string {
		return $this->key;
	}

	/**
	 * Returns the primitive type for the property.
	 */
	abstract public function get_primitive(): PropertyPrimitive;

	/**
	 * Get the optional specialized type discriminator.
	 *
	 * @return string|null
	 */
	protected function get_specialized(): ?string {
		return null;
	}

	/**
	 * Convert the property to Etch component schema array.
	 *
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException When key derivation fails.
	 */
	public function to_array(): array {
		$property = array(
			'name' => $this->name,
			'key'  => $this->key,
			'type' => $this->build_type_payload(),
		);

		if ( null !== $this->key_touched ) {
			$property['keyTouched'] = $this->key_touched;
		}

		if ( null !== $this->prop_source_id ) {
			$property['propSourceId'] = $this->prop_source_id;
		}

		if ( null !== $this->description ) {
			$property['description'] = $this->description;
		}

		if ( $this->has_default ) {
			$property['default'] = $this->default_value;
		}

		foreach ( $this->build_additional_payload() as $key => $value ) {
			$property[ $key ] = $value;
		}

		return $property;
	}

	/**
	 * Builds the Etch type payload.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_type_payload(): array {
		$type_payload = array(
			'primitive' => $this->get_primitive()->value,
		);

		$specialized = $this->get_specialized();
		if ( null !== $specialized ) {
			$type_payload['specialized'] = $specialized;
		}

		return $type_payload;
	}

	/**
	 * Adds subclass-specific payload fields.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function build_additional_payload(): array;

	/**
	 * Derives a valid camelCase key from a human-readable name.
	 *
	 * @param string $name Human-readable property name.
	 * @throws InvalidArgumentException When a valid key cannot be derived.
	 */
	private function derive_key_from_name( string $name ): string {
		$words = preg_split( '/[^A-Za-z0-9]+/', trim( $name ) );
		if ( false === $words || array() === $words ) {
			throw new InvalidArgumentException( 'Unable to derive a valid property key from name.' );
		}

		$words = array_values(
			array_filter(
				$words,
				static fn( string $word ): bool => '' !== $word
			)
		);

		if ( array() === $words ) {
			throw new InvalidArgumentException( 'Unable to derive a valid property key from name.' );
		}

		$first_word = strtolower( (string) array_shift( $words ) );
		$rest_words = array_map(
			static fn( string $word ): string => ucfirst( strtolower( $word ) ),
			$words
		);

		$derived = $first_word . implode( '', $rest_words );

		if ( is_numeric( $derived[0] ) ) {
			$derived = 'prop_' . $derived;
		}

		return $derived;
	}
}
