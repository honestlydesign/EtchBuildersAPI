<?php
/**
 * Immutable execution envelope for one maintainer package gate.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use Throwable;

/**
 * Keeps the command, exact candidate identity, exit code, and output digest
 * together. Callers cannot declare a passed gate by supplying a status alone.
 */
final class ContractLabPackageGateEvidence {

	/** @var array<string, string> */
	private const COMMANDS = array(
		'package' => 'composer test',
		'source'  => 'composer phpstan',
		'catalog' => 'composer test -- --filter ContractLab',
		'recipe'  => 'composer test -- --filter Authoring',
	);

	private function __construct(
		private readonly string $gate_id,
		private readonly string $status,
		private readonly ContractLabPackageGateIdentity $identity,
		private readonly string $source_revision,
		private readonly string $artifact_fingerprint,
		private readonly string $command,
		private readonly ?int $exit_code,
		private readonly string $output_digest,
		private readonly string $summary
	) {
	}

	/**
	 * Execute the complete fixed package gate set against one verified
	 * checkout. The only production path that can create passed evidence is
	 * this real process runner; the envelope constructors remain private.
	 */
	public static function run( string $working_directory, string $artifact_directory ): ContractLabPackageGateSet {
		$identity = ContractLabPackageGateIdentity::inspect( $working_directory, $artifact_directory );
		$evidence = array();
		foreach ( array( 'package', 'source', 'catalog', 'recipe' ) as $gate_id ) {
			$evidence[] = self::run_one( $gate_id, $working_directory, $identity );
		}
		$identity->assert_unchanged();

		return ContractLabPackageGateSet::from_evidence( $evidence );
	}

	/**
	 * Build evidence from a completed process invocation.
	 */
	private static function from_execution(
		string $gate_id,
		ContractLabPackageGateIdentity $identity,
		string $command,
		int $exit_code,
		string $output,
		string $summary
	): self {
		self::assert_identity( $gate_id, $command, $summary );
		if ( $exit_code < 0 ) {
			throw new InvalidArgumentException( 'Contract Lab package gate process exit code must be zero or a positive failure code.' );
		}

		return new self(
			$gate_id,
			0 === $exit_code ? 'passed' : 'failed',
			$identity,
			$identity->source_revision(),
			$identity->artifact_fingerprint(),
			$command,
			$exit_code,
			hash( 'sha256', $output ),
			$summary
		);
	}

	/**
	 * Build explicit infrastructure-unavailable evidence without claiming a
	 * process ran.
	 */
	private static function inconclusive(
		string $gate_id,
		ContractLabPackageGateIdentity $identity,
		string $summary
	): self {
		self::assert_identity( $gate_id, self::command( $gate_id ), $summary );

		return new self(
			$gate_id,
			'inconclusive',
			$identity,
			$identity->source_revision(),
			$identity->artifact_fingerprint(),
			self::command( $gate_id ),
			null,
			hash( 'sha256', '' ),
			$summary
		);
	}

	public static function command( string $gate_id ): string {
		if ( ! isset( self::COMMANDS[ $gate_id ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown Contract Lab package gate "%s".', $gate_id ) );
		}

		return self::COMMANDS[ $gate_id ];
	}

	public function gate_id(): string {
		return $this->gate_id;
	}

	public function status(): string {
		return $this->status;
	}

	public function is_passed(): bool {
		return 'passed' === $this->status;
	}

	public function source_revision(): string {
		return $this->source_revision;
	}

	public function artifact_fingerprint(): string {
		return $this->artifact_fingerprint;
	}

	public function command_line(): string {
		return $this->command;
	}

	public function exit_code(): ?int {
		return $this->exit_code;
	}

	public function output_digest(): string {
		return $this->output_digest;
	}

	public function summary(): string {
		return $this->summary;
	}

	public function assert_identity_unchanged(): void {
		$this->identity->assert_unchanged();
	}

	/**
	 * @return array{gate_id: string, status: string, source_revision: string, artifact_fingerprint: string, command: string, exit_code: int|null, output_digest: string, summary: string, identity: array{source_identity: string, repository_clean: bool, artifact_identity: string}}
	 */
	public function to_array(): array {
		return array(
			'gate_id'             => $this->gate_id,
			'status'              => $this->status,
			'source_revision'     => $this->source_revision,
			'artifact_fingerprint' => $this->artifact_fingerprint,
			'command'             => $this->command,
			'exit_code'           => $this->exit_code,
			'output_digest'       => $this->output_digest,
			'summary'             => $this->summary,
			'identity'            => $this->identity->to_array(),
		);
	}

	private static function assert_identity( string $gate_id, string $command, string $summary ): void {
		if ( ! isset( self::COMMANDS[ $gate_id ] ) || self::COMMANDS[ $gate_id ] !== $command ) {
			throw new InvalidArgumentException( sprintf( 'Contract Lab package gate "%s" must use its canonical command.', $gate_id ) );
		}
		if ( '' === $summary || trim( $summary ) !== $summary || 1 === preg_match( '/[[:cntrl:]]/', $summary ) ) {
			throw new InvalidArgumentException( 'Contract Lab package gate summary must be a safe non-empty string.' );
		}
	}

	private static function run_one( string $gate_id, string $working_directory, ContractLabPackageGateIdentity $identity ): self {
		$command = self::command( $gate_id );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'redirect', 1 ),
		);

		try {
			$process = proc_open( $command, $descriptors, $pipes, $working_directory );
			if ( ! is_resource( $process ) ) {
				return self::inconclusive( $gate_id, $identity, sprintf( 'Unable to start the canonical %s gate command.', $gate_id ) );
			}

			fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );
			$exit_code = proc_close( $process );
			$identity->assert_unchanged();

			return self::from_execution(
				$gate_id,
				$identity,
				$command,
				$exit_code,
				false !== $output ? $output : '',
				sprintf( 'Canonical %s gate command exited with code %d.', $gate_id, $exit_code )
			);
		} catch ( Throwable $error ) {
			return self::inconclusive(
				$gate_id,
				$identity,
				sprintf( 'Canonical %s gate command could not be executed: %s', $gate_id, $error->getMessage() )
			);
		}
	}
}
