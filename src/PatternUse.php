<?php
/**
 * Typed use of one registered Pattern inside another Site Entity.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Captures a structural Pattern dependency without copying serialized markup.
 *
 * The typed block snapshot is detached when the use is created. Later
 * mutation of the source Pattern cannot silently change the consuming entity;
 * a later compiler can resolve the recorded key/dependency and produce its
 * deterministic plan.
 */
final class PatternUse {

	private function __construct(
		private readonly string $pattern_key,
		private readonly BlockSequence $sequence
	) {
	}

	/**
	 * Create a use of one Pattern that is registered in the Site Definition.
	 *
	 * Pattern uses require a typed block sequence so composition remains
	 * structural. Raw serialized pattern markup is intentionally rejected.
	 *
	 * @throws InvalidArgumentException When the Pattern has no typed blocks.
	 */
	public static function registered( Pattern $pattern ): self {
		$key      = trim( $pattern->get_key() );
		$sequence = $pattern->get_block_sequence();

		if ( '' === $key ) {
			throw new InvalidArgumentException( 'PatternUse requires a Pattern with a non-empty key.' );
		}

		if ( null === $sequence || $sequence->is_empty() ) {
			throw new InvalidArgumentException(
				'PatternUse requires a typed Pattern block sequence; serialized pattern markup cannot be copied into another entity.'
			);
		}

		return new self( $key, $sequence->copy() );
	}

	/**
	 * Return the registered Pattern key captured by this dependency.
	 */
	public function pattern_key(): string {
		return $this->pattern_key;
	}

	/**
	 * Return a detached structural snapshot for expansion.
	 */
	public function sequence(): BlockSequence {
		return $this->sequence->copy();
	}

	/**
	 * Return a deterministic dependency record, not Etch wire markup.
	 *
	 * @return array{type: string, pattern_key: string}
	 */
	public function to_array(): array {
		return array(
			'type'        => 'registered_pattern_use',
			'pattern_key' => $this->pattern_key,
		);
	}
}
