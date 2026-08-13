<?php
/**
 * Execution provenance for one Contract Lab observation adapter.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Describes the concrete adapter boundary that produced an observation.
 *
 * Semantic observations remain usable as pure values for unit tests, but the
 * maintainer gate only admits results stamped by the corresponding executable
 * probe runner. The stamp records the exact target and ordered operations so a
 * copied semantic payload cannot masquerade as a live run.
 */
final class ContractLabExecutionProvenance {

	public const VERSION = '2';

	private function __construct(
		private readonly string $source,
		private readonly string $target,
		/** @var array<int, string> */
		private readonly array $steps,
		private readonly string $payload_digest,
		private readonly string $receipt_id
	) {
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function from_frontend_probe( ContractLabFrontendProbe $runner, string $fixture_id, array $payload ): self {
		ContractLabManifestSafety::assert_stable_id( $fixture_id, 'Contract Lab frontend execution target' );

		return self::issue( 'frontend-http-probe', $fixture_id, array( 'http-get', 'stylesheet-get' ), $payload );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function from_browser_runner( ContractLabBrowserSentinelRunner $runner, ContractLabBrowserSentinel $sentinel, array $payload ): self {
		return self::issue( 'browser-sentinel-runner', $sentinel->logical_id(), array( 'capture', 'save', 'reload' ), $payload );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public static function from_javascript_runner( ContractLabJavascriptMarkerRunner $runner, ContractLabJavascriptMarker $marker, array $payload ): self {
		return self::issue( 'javascript-marker-runner', $marker->logical_id(), array( 'read-marker' ), $payload );
	}

	public function source(): string {
		return $this->source;
	}

	public function target(): string {
		return $this->target;
	}

	/**
	 * @return array<int, string>
	 */
	public function steps(): array {
		return $this->steps;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function matches_frontend( string $fixture_id, array $payload ): bool {
		ContractLabManifestSafety::assert_stable_id( $fixture_id, 'Contract Lab frontend execution target' );

		return $this->matches( 'frontend-http-probe', $fixture_id, array( 'http-get', 'stylesheet-get' ), $payload );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function matches_browser( ContractLabBrowserSentinel $sentinel, array $payload ): bool {
		return $this->matches( 'browser-sentinel-runner', $sentinel->logical_id(), array( 'capture', 'save', 'reload' ), $payload );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function matches_javascript( ContractLabJavascriptMarker $marker, array $payload ): bool {
		return $this->matches( 'javascript-marker-runner', $marker->logical_id(), array( 'read-marker' ), $payload );
	}

	/**
	 * @return array{provenance_version: string, source: string, target: string, steps: array<int, string>, payload_digest: string, receipt_id: string}
	 */
	public function to_array(): array {
		return array(
			'provenance_version' => self::VERSION,
			'source'             => $this->source,
			'target'             => $this->target,
			'steps'              => $this->steps,
			'payload_digest'     => $this->payload_digest,
			'receipt_id'         => $this->receipt_id,
		);
	}

	/**
	 * @param array<int, string>   $steps
	 * @param array<string, mixed> $payload
	 */
	private static function issue( string $source, string $target, array $steps, array $payload ): self {
		return new self( $source, $target, $steps, self::payload_digest( $payload ), bin2hex( random_bytes( 16 ) ) );
	}

	/**
	 * @param array<int, string>   $steps
	 * @param array<string, mixed> $payload
	 */
	private function matches( string $source, string $target, array $steps, array $payload ): bool {
		return $this->has_valid_receipt()
			&& $this->source === $source
			&& $this->target === $target
			&& $this->steps === $steps
			&& $this->payload_digest === self::payload_digest( $payload );
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function payload_digest( array $payload ): string {
		$json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );

		return hash( 'sha256', $json );
	}

	private function has_valid_receipt(): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/D', $this->receipt_id );
	}
}
