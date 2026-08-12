<?php
/**
 * Entity-owned parsed Etch styles.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use Throwable;

/**
 * Owns co-located CSS for one Site Entity and issues exact class references.
 */
final class EntityStyleSet {

	private const COLLECTION_PREFIX = 'OhMyIDEtch:entity:';

	/**
	 * @param array<string, ClassStyleReference> $class_references References keyed by exact selector.
	 */
	private function __construct(
		private readonly string $entity_id,
		private readonly array $class_references
	) {
	}

	/**
	 * Parse and register one entity-owned CSS file in the request-local Style registry.
	 */
	public static function from_file( string $entity_id, string $file_path ): self {
		$entity_id = self::validate_entity_id( $entity_id );
		$references = array();
		$collection = self::COLLECTION_PREFIX . $entity_id;
		$style_state = Style::snapshot_state();

		try {
			$parsed_styles = StylesParser::new( $file_path )->get_all();
			Style::forget_registered_collection( $collection );

			foreach ( $parsed_styles as $style ) {
				$record   = $style->to_array();
				$selector = isset( $record['selector'] ) && is_string( $record['selector'] )
					? StylesParserRuleScanner::normalize_selector_key( $record['selector'] )
					: '';
				$style_id = isset( $record['id'] ) && is_string( $record['id'] ) ? $record['id'] : '';
				self::assert_style_owner_available( $style_id, $collection );

				$style_id = $style
					->collection( $collection )
					->overwrite_on_register( true )
					->add();

				if ( null !== StylesParserRuleScanner::single_class_token( $selector ) ) {
					$references[ $selector ] = ClassStyleReference::registered( $style_id );
				}
			}
		} catch ( Throwable $throwable ) {
			Style::restore_state( $style_state );
			throw $throwable;
		}

		return new self( $entity_id, $references );
	}

	/**
	 * Return the exact stable Site Entity identity owning this set.
	 */
	public function entity_id(): string {
		return $this->entity_id;
	}

	/**
	 * Issue the opaque Class Style Reference for one exact simple class selector.
	 */
	public function class_reference( string $selector ): ClassStyleReference {
		if (
			$selector !== StylesParserRuleScanner::normalize_selector_key( $selector )
			|| null === StylesParserRuleScanner::single_class_token( $selector )
		) {
			throw new InvalidArgumentException(
				sprintf(
					'Entity class lookup requires an exact simple class selector such as ".hero__title"; got "%s". Do not pass a class name or opaque Class Style ID.',
					$selector
				)
			);
		}

		if ( ! isset( $this->class_references[ $selector ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Entity "%s" does not define class selector "%s" in its Entity Style Set.', $this->entity_id, $selector )
			);
		}

		return $this->class_references[ $selector ];
	}

	/**
	 * Return all exact selector-to-reference mappings in parser order.
	 *
	 * @return array<string, ClassStyleReference>
	 */
	public function class_references(): array {
		return $this->class_references;
	}

	/**
	 * Return the opaque style IDs owned by this entity set.
	 *
	 * @return array<int, string>
	 */
	public function style_ids(): array {
		return array_values(
			array_map(
				static fn ( ClassStyleReference $reference ): string => $reference->id(),
				array_values( $this->class_references )
			)
		);
	}

	/**
	 * Require a stable, explicit Site Entity type and key.
	 */
	private static function validate_entity_id( string $entity_id ): string {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]*:[A-Za-z0-9][A-Za-z0-9_.-]*\z/', $entity_id ) ) {
			throw new InvalidArgumentException(
				'Entity Style Set identity must be an exact stable type:key value such as "component:Hero" or "page:home".'
			);
		}

		return $entity_id;
	}

	/**
	 * Refuse implicit adoption of request-local or persisted styles.
	 */
	private static function assert_style_owner_available( string $style_id, string $expected_collection ): void {
		$registered = Style::registered_styles();
		if ( array_key_exists( $style_id, $registered ) ) {
			self::assert_record_owner( $style_id, $registered[ $style_id ], $expected_collection, 'request-local' );
		}

		$persisted = Environment::storage()->get( 'etch_styles', array() );
		if ( is_array( $persisted ) && array_key_exists( $style_id, $persisted ) ) {
			$record = $persisted[ $style_id ];
			if ( ! is_array( $record ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Style ID "%s" has malformed persisted ownership and cannot be adopted by "%s".', $style_id, $expected_collection )
				);
			}

			self::assert_record_owner( $style_id, $record, $expected_collection, 'persisted' );
		}
	}

	/**
	 * @param array<string, mixed> $record Effective style record.
	 */
	private static function assert_record_owner(
		string $style_id,
		array $record,
		string $expected_collection,
		string $source
	): void {
		$collection = isset( $record['collection'] ) && is_string( $record['collection'] )
			? $record['collection']
			: '(unowned)';

		if ( $expected_collection !== $collection ) {
			throw new InvalidArgumentException(
				sprintf(
					'Style ID "%s" is %s-owned by collection "%s" and cannot be adopted by "%s".',
					$style_id,
					$source,
					$collection,
					$expected_collection
				)
			);
		}
	}
}
