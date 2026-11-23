<?php
/**
 * Entity-owned parsed Etch styles.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Support\EditorStyleDuplicates;
use InvalidArgumentException;
use Throwable;

/**
 * Owns co-located CSS for one Site Entity and issues exact class references.
 *
 * Styles are written to Etch's `default` collection, where the Etch editor
 * looks classes up, so pasting, moving, or editing an element links the
 * code-owned style instead of creating an empty copy. Ownership is never read
 * from the collection: within a request the Style registry records the entity
 * as owner, and across requests the compiled resource ledger records the
 * style as Builder-owned. Records still in a legacy
 * `OhMyIDEtch:entity:<entityId>` collection (Builders 2.1.0 and earlier) keep
 * naming their entity until the next apply moves them to `default`.
 */
final class EntityStyleSet {

	private const COLLECTION = 'default';

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
	 * @authoring-contract-version 1.0
	 */
	public static function from_file( string $entity_id, string $file_path ): self {
		$entity_id = self::validate_entity_id( $entity_id );
		$references = array();
		$style_state = Style::snapshot_state();

		try {
			$parsed_styles = StylesParser::new( $file_path )->get_all();
			// Styles this entity already registered in this request stay its own on reload.
			$reloaded = array_fill_keys( Style::registered_ids_for_owner( $entity_id ), true );
			Style::forget_registered_owner( $entity_id );

			foreach ( $parsed_styles as $style ) {
				$record   = $style->to_array();
				$selector = isset( $record['selector'] ) && is_string( $record['selector'] )
					? StylesParserRuleScanner::normalize_selector_key( $record['selector'] )
					: '';
				$style_id = isset( $record['id'] ) && is_string( $record['id'] ) ? $record['id'] : '';
				self::assert_style_owner_available( $style_id, $entity_id, isset( $reloaded[ $style_id ] ) );

				$style_id = $style
					->collection( self::COLLECTION )
					->owner( $entity_id )
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
	 * @authoring-contract-version 1.0
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
	 *
	 * A request-local style must already belong to this entity. A persisted
	 * style must be Builder-written: recorded in the compiled resource ledger
	 * for the same selector, still in this entity's legacy collection, or
	 * registered by this entity earlier in the request. A style in another
	 * entity's legacy collection, or one no Builders-owned state records (a
	 * person's style), is refused.
	 */
	private static function assert_style_owner_available( string $style_id, string $entity_id, bool $reloaded ): void {
		if ( array_key_exists( $style_id, Style::registered_styles() ) ) {
			$owner = Style::registered_owner( $style_id );
			if ( $entity_id !== $owner ) {
				$collection = Style::registered_styles()[ $style_id ]['collection'];
				throw new InvalidArgumentException(
					sprintf(
						'Style ID "%s" is request-local-owned by %s and cannot be adopted by entity "%s".',
						$style_id,
						null !== $owner ? sprintf( 'entity "%s"', $owner ) : sprintf( 'collection "%s"', $collection ),
						$entity_id
					)
				);
			}
		}

		$persisted = Environment::storage()->get( 'etch_styles', array() );
		if ( ! is_array( $persisted ) || ! array_key_exists( $style_id, $persisted ) ) {
			return;
		}

		$record = $persisted[ $style_id ];
		if ( ! is_array( $record ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Style ID "%s" has malformed persisted ownership and cannot be adopted by entity "%s".', $style_id, $entity_id )
			);
		}

		$legacy_owner = EditorStyleDuplicates::legacy_entity_owner( $record );
		if ( null !== $legacy_owner ) {
			if ( $entity_id !== $legacy_owner ) {
				throw new InvalidArgumentException(
					sprintf(
						'Style ID "%s" is persisted-owned by entity "%s" (collection "%s") and cannot be adopted by entity "%s".',
						$style_id,
						$legacy_owner,
						(string) $record['collection'],
						$entity_id
					)
				);
			}

			return;
		}

		$ledger = EditorStyleDuplicates::ledger_selectors(
			Environment::storage()->get( EditorStyleDuplicates::RESOURCES_OPTION, array() )
		);
		if ( $reloaded || EditorStyleDuplicates::is_recorded( $ledger, $style_id, $record ) ) {
			return;
		}

		throw new InvalidArgumentException(
			sprintf(
				'Style ID "%s" is persisted-owned by collection "%s" and is not a recorded code-owned style, so it cannot be adopted by entity "%s".',
				$style_id,
				isset( $record['collection'] ) && is_string( $record['collection'] ) ? $record['collection'] : '(unowned)',
				$entity_id
			)
		);
	}
}
