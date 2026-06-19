<?php
/**
 * Result of a persistence operation, replacing WP_Error in the WP-free package.
 *
 * @package HonestlyDesign\EtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Simple success/error value object for register() flows.
 *
 * Consumers running under WordPress map this to WP_Error in their adapters.
 */
final class RegistrationResult {

	/**
	 * Whether the operation succeeded.
	 *
	 * @var bool
	 */
	private bool $success;

	/**
	 * Error code (empty on success).
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Error message (empty on success).
	 *
	 * @var string
	 */
	private string $error_message;

	/**
	 * Constructor.
	 *
	 * @param bool   $success       Success flag.
	 * @param string $error_code    Error code.
	 * @param string $error_message Error message.
	 */
	private function __construct( bool $success, string $error_code = '', string $error_message = '' ) {
		$this->success       = $success;
		$this->error_code    = $error_code;
		$this->error_message = $error_message;
	}

	/**
	 * Create a success result.
	 */
	public static function success(): self {
		return new self( true );
	}

	/**
	 * Create an error result.
	 *
	 * @param string $error_code    Error code.
	 * @param string $error_message Error message.
	 */
	public static function error( string $error_code, string $error_message ): self {
		return new self( false, $error_code, $error_message );
	}

	/**
	 * Whether the operation succeeded.
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Error code (empty on success).
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Error message (empty on success).
	 */
	public function get_error_message(): string {
		return $this->error_message;
	}
}
