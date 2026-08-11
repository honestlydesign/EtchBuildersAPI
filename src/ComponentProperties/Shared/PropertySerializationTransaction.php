<?php
/**
 * Root-scoped component property serialization transaction.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\ComponentProperties\Shared;

use Closure;
use LogicException;
use Throwable;

/**
 * Defers mutable validation-state commits until a whole property tree succeeds.
 *
 * @internal
 */
final class PropertySerializationTransaction {

	private static int $depth = 0;

	/**
	 * Latest staged commit per owning builder.
	 *
	 * @var array<int, Closure(): void>
	 */
	private static array $commits = array();

	/**
	 * Run one serialization operation inside the current or a new root transaction.
	 *
	 * @template TResult
	 * @param callable(): TResult $operation Serialization operation.
	 * @return TResult
	 */
	public static function run( callable $operation ): mixed {
		$is_root = 0 === self::$depth;
		if ( $is_root ) {
			self::$commits = array();
		}

		++self::$depth;

		try {
			$result = $operation();

			if ( $is_root ) {
				self::commit_staged();
			}

			return $result;
		} catch ( Throwable $throwable ) {
			if ( $is_root ) {
				self::$commits = array();
			}

			throw $throwable;
		} finally {
			--self::$depth;

			if ( $is_root ) {
				self::$commits = array();
			}
		}
	}

	/**
	 * Stage the latest state commit for one builder.
	 *
	 * @param object          $owner Owner used for de-duplication.
	 * @param Closure(): void $commit Non-throwing state commit.
	 */
	public static function stage( object $owner, Closure $commit ): void {
		if ( 0 === self::$depth ) {
			throw new LogicException( 'Property serialization state must be staged inside a transaction.' );
		}

		self::$commits[ spl_object_id( $owner ) ] = $commit;
	}

	/**
	 * Apply all staged non-throwing commits after root success.
	 */
	private static function commit_staged(): void {
		foreach ( self::$commits as $commit ) {
			$commit();
		}
	}
}
