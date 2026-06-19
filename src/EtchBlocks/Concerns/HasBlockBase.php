<?php
/**
 * Trait providing BlockBase methods for Etch block builders.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\EtchBlocks\Concerns;

use HonestlyDesign\EtchBuilders\Types\BlockBase;

/**
 * Provides common block base methods for fluent Etch block builders.
 *
 * Usage:
 *   class MyBlock implements EtchBlockBuilderInterface {
 *       use HasBlockBase;
 *
 *       private BlockBase $base;
 *
 *       private function __construct() {
 *           $this->base = BlockBase::new();
 *       }
 *   }
 *
 * Note: The consuming class MUST have a private BlockBase $base property.
 */
trait HasBlockBase {

	/**
	 * Set hidden state.
	 *
	 * @param bool $hidden Whether to hide the block.
	 * @return static
	 */
	public function hidden( bool $hidden = true ): static {
		$this->base->hidden( $hidden );
		return $this;
	}

	/**
	 * Add a script.
	 *
	 * @param string $id   Script identifier.
	 * @param string $code JavaScript code.
	 * @return static
	 */
	public function script( string $id, string $code ): static {
		$this->base->script( $id, $code );
		return $this;
	}

	/**
	 * Add an option.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Option value.
	 * @return static
	 */
	public function option( string $key, mixed $value ): static {
		$this->base->option( $key, $value );
		return $this;
	}
}
