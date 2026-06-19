<?php
/**
 * Script configuration for Etch blocks.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders\Types;

use InvalidArgumentException;

/**
 * Script data structure containing id and code.
 *
 * Matches TypeScript: { id: string; code: string; }
 */
final class Script {

	/**
	 * Script identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * JavaScript code content.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Constructor.
	 *
	 * @param string $id   Script identifier.
	 * @param string $code JavaScript code.
	 * @throws InvalidArgumentException When id or code is empty.
	 */
	private function __construct( string $id, string $code ) {
		$id   = trim( $id );
		$code = trim( $code );

		if ( '' === $id ) {
			throw new InvalidArgumentException( 'Script id cannot be empty.' );
		}

		$this->id   = $id;
		$this->code = $code;
	}

	/**
	 * Create a new Script instance.
	 *
	 * @param string $id   Script identifier.
	 * @param string $code JavaScript code.
	 * @throws InvalidArgumentException When id or code is empty.
	 */
	public static function new( string $id, string $code ): self {
		return new self( $id, $code );
	}

	/**
	 * Create from array (legacy support).
	 *
	 * @param array<string, mixed> $config Script configuration.
	 * @throws InvalidArgumentException When required keys missing.
	 */
	public static function from_array( array $config ): self {
		if ( ! array_key_exists( 'id', $config ) || ! is_string( $config['id'] ) ) {
			throw new InvalidArgumentException( 'Script requires "id" key with string value.' );
		}

		if ( ! array_key_exists( 'code', $config ) || ! is_string( $config['code'] ) ) {
			throw new InvalidArgumentException( 'Script requires "code" key with string value.' );
		}

		return new self( $config['id'], $config['code'] );
	}

	/**
	 * Get the script id.
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get the script code.
	 */
	public function get_code(): string {
		return $this->code;
	}

	/**
	 * Convert to array for block attributes.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'id'   => $this->id,
			'code' => $this->code,
		);
	}
}
