<?php
/**
 * Normalized observation of one composite Contract Lab frontend fixture.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\AcyclicArrayGuard;
use HonestlyDesign\EtchBuilders\Support\ImmutableArray;
use InvalidArgumentException;

/**
 * Stores semantic DOM/CSS output and capability outcomes, never raw HTML.
 */
final class ContractLabFrontendObservation {

	public const OBSERVATION_VERSION = '2';

	/**
	 * @param array<int, array<string, mixed>> $dom
	 * @param array<int, array<string, mixed>> $stylesheets
	 * @param array<int, array{capability: string, marker: string, status: string}> $capabilities
	 */
	private function __construct(
		private readonly string $fixture_id,
		private readonly string $fixture_path,
		private readonly int $response_status,
		private readonly array $dom,
		private readonly array $stylesheets,
		private readonly array $capabilities
	) {
	}

	/**
	 * @param array<int, array<string, mixed>> $dom
	 * @param array<int, array<string, mixed>> $stylesheets
	 * @param array<int, array{capability: string, marker: string, status: string}> $capabilities
	 */
	public static function observed(
		string $fixture_id,
		string $fixture_path,
		int $response_status,
		array $dom,
		array $stylesheets,
		array $capabilities
	): self {
		ContractLabManifestSafety::assert_stable_id( $fixture_id, 'Contract Lab frontend observation fixture identity' );
		if ( $response_status < 200 || $response_status > 299 ) {
			throw new InvalidArgumentException( 'Contract Lab frontend observations require a successful HTTP response.' );
		}
		AcyclicArrayGuard::assert_acyclic( $dom );
		AcyclicArrayGuard::assert_acyclic( $stylesheets );
		AcyclicArrayGuard::assert_acyclic( $capabilities );
		$dom          = ImmutableArray::copy( $dom, 'Contract Lab frontend DOM must contain only scalar, null, or nested array values.' );
		$stylesheets  = ImmutableArray::copy( $stylesheets, 'Contract Lab frontend stylesheets must contain only scalar, null, or nested array values.' );
		$capabilities = ImmutableArray::copy( $capabilities, 'Contract Lab frontend capabilities must contain only scalar, null, or nested array values.' );
		if ( ! array_is_list( $dom ) || ! array_is_list( $stylesheets ) || ! array_is_list( $capabilities ) || array() === $dom || array() === $capabilities ) {
			throw new InvalidArgumentException( 'Contract Lab frontend observation DOM and capabilities must be non-empty ordered lists.' );
		}
		$seen = array();
		foreach ( $capabilities as $capability ) {
			if ( ! is_array( $capability ) || array( 'capability', 'marker', 'status' ) !== array_keys( $capability ) || ! is_string( $capability['capability'] ?? null ) || ! is_string( $capability['marker'] ?? null ) || ! is_string( $capability['status'] ?? null ) || ! in_array( $capability['status'], array( 'observed', 'failed' ), true ) || isset( $seen[ $capability['capability'] ] ) ) {
				throw new InvalidArgumentException( 'Contract Lab frontend capability observations have an invalid ordered shape.' );
			}
			$seen[ $capability['capability'] ] = true;
		}

		return new self( $fixture_id, $fixture_path, $response_status, $dom, $stylesheets, $capabilities );
	}

	public function fixture_id(): string {
		return $this->fixture_id;
	}

	public function fixture_path(): string {
		return $this->fixture_path;
	}

	public function response_status(): int {
		return $this->response_status;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function dom(): array {
		return $this->dom;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function stylesheets(): array {
		return $this->stylesheets;
	}

	/**
	 * @return array<int, array{capability: string, marker: string, status: string}>
	 */
	public function capabilities(): array {
		return $this->capabilities;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'observation_version' => self::OBSERVATION_VERSION,
			'fixture_id'          => $this->fixture_id,
			'fixture_path'        => $this->fixture_path,
			'http_status'         => $this->response_status,
			'dom'                 => $this->dom,
			'stylesheets'         => $this->stylesheets,
			'capabilities'        => $this->capabilities,
		);
	}
}
