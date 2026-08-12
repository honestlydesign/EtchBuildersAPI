<?php
/**
 * Immutable response returned by a Contract Lab frontend HTTP adapter.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Carries only the response facts needed by a maintainer-only probe.
 */
final class ContractLabFrontendHttpResponse {

	/**
	 * @param array<string, string|array<int, string>> $headers
	 */
	private function __construct(
		private readonly int $status,
		private readonly string $body,
		private readonly array $headers
	) {
	}

	/**
	 * @param array<string, string|array<int, string>> $headers
	 */
	public static function new( int $status, string $body, array $headers = array() ): self {
		if ( $status < 100 || $status > 599 ) {
			throw new InvalidArgumentException( 'Contract Lab frontend HTTP status must be between 100 and 599.' );
		}
		AcyclicArrayGuard::assert_acyclic( $headers );
		$headers = ImmutableArray::copy( $headers, 'Contract Lab frontend HTTP headers must contain only scalar values.' );
		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) || '' === trim( $name ) || preg_match( '/[[:cntrl:]]/', $name ) ) {
				throw new InvalidArgumentException( 'Contract Lab frontend HTTP header names must be safe strings.' );
			}
			if ( ! is_string( $value ) && ( ! is_array( $value ) || ! array_is_list( $value ) ) ) {
				throw new InvalidArgumentException( 'Contract Lab frontend HTTP header values must be strings or ordered string lists.' );
			}
			$values = is_array( $value ) ? $value : array( $value );
			foreach ( $values as $header_value ) {
				if ( ! is_string( $header_value ) || preg_match( '/[[:cntrl:]]/', $header_value ) ) {
					throw new InvalidArgumentException( 'Contract Lab frontend HTTP header values must be safe strings.' );
				}
			}
		}

		return new self( $status, $body, $headers );
	}

	public function status(): int {
		return $this->status;
	}

	public function body(): string {
		return $this->body;
	}

	public function is_successful(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Return a header without making transport header casing part of the contract.
	 */
	public function header( string $name ): ?string {
		foreach ( $this->headers as $header_name => $value ) {
			if ( strtolower( $header_name ) !== strtolower( $name ) ) {
				continue;
			}

			return is_array( $value ) ? implode( ', ', $value ) : $value;
		}

		return null;
	}

	/**
	 * @return array<string, string|array<int, string>>
	 */
	public function headers(): array {
		return $this->headers;
	}
}
