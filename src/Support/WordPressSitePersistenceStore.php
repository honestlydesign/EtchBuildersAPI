<?php
/**
 * WordPress adapter for compiled Site persistence records.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Support;

use HonestlyDesign\EtchBuilders\CompiledSiteEntity;
use HonestlyDesign\EtchBuilders\CompiledSiteEntityType;
use HonestlyDesign\EtchBuilders\CompiledSiteOwnership;
use HonestlyDesign\EtchBuilders\CompiledSitePlan;
use HonestlyDesign\EtchBuilders\CompiledSiteResource;
use HonestlyDesign\EtchBuilders\CompiledSiteResourceType;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceApplyLockInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceNativeRetirementInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceRecordAdoptionInterface;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceResourceStoreInterface;
use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\SiteHomePolicy;
use HonestlyDesign\EtchBuilders\SiteHomePolicyMode;
use HonestlyDesign\EtchBuilders\SitePersistenceRecord;
use Throwable;

/**
 * Contains all WordPress function calls for compiled Site persistence.
 *
 * Each compiled section is dispatched to one explicit native handler. The
 * adapter keeps builder ownership and snapshots beside native WordPress data
 * so native records remain inspectable by Etch and other WordPress tooling.
 */
final class WordPressSitePersistenceStore implements SitePersistenceApplyLockInterface, SitePersistenceNativeRetirementInterface, SitePersistenceRecordAdoptionInterface, SitePersistenceResourceStoreInterface {

	private const OPTION_PREFIX = 'etch_builders_site_record_';

	private const CLAIM_PREFIX = 'etch_builders_site_claim_';

	private const APPLY_LOCK_OPTION = 'etch_builders_site_apply_lock';

	/**
	 * Claims abandoned by a crashed apply become stealable after this window.
	 * Applies are serialized per site, so an older claim cannot be live.
	 */
	private const CLAIM_TTL_SECONDS = 900;

	private const BLOCK_POST_TYPE = 'wp_block';

	private const COMPONENT_SLUG_PREFIX = 'omide-component-';

	private const PATTERN_SLUG_PREFIX = 'omide-pattern-';

	private const COMPONENT_KEY_META = 'etch_component_html_key';

	private const COMPONENT_PROPERTIES_META = 'etch_component_properties';

	private const PATTERN_KEY_META = 'oh_my_id_etch_pattern_key';

	private const PATTERN_SYNC_META = 'wp_pattern_sync_status';

	private const PATTERN_SYNC_STATUS = 'unsynced';

	private const PATTERN_CATEGORY_TAXONOMY = 'wp_pattern_category';

	private const OWNER_META = 'etch_builders_site_persistence_owner';

	private const OWNER_VALUE = 'honestlydesign/etch-builders';

	private const SNAPSHOT_META = 'etch_builders_site_persistence_record';

	private const NATIVE_FINGERPRINT_META = 'etch_builders_site_persistence_native_fingerprint';

	private const CONTENT_IDENTITY_META = 'etch_builders_site_persistence_identity';

	private const RESOURCE_RECORDS_OPTION = 'etch_builders_site_persistence_resources';

	private const ENTITY_RECORDS_OPTION = 'etch_builders_site_persistence_entities';

	private const HOME_POLICY_OPTION = 'etch_builders_site_home_policy';

	private const STYLES_OPTION = 'etch_styles';

	private const GLOBAL_STYLESHEETS_OPTION = 'etch_global_stylesheets';

	private const LOOPS_OPTION = 'etch_loops';

	private const BUILDER_STYLESHEET_HASHES_OPTION = 'oh_my_id_etch_builder_stylesheets';

	private const BUILDER_STYLESHEET_FRAGMENTS_OPTION = 'oh_my_id_etch_builder_stylesheet_fragments';

	public function find( string $identity ): ?SitePersistenceRecord {
		$kind = $this->native_kind( $identity );
		if ( null !== $kind && $this->is_native_post_kind( $kind ) ) {
			$posts = $this->find_native_posts( $identity, $kind );
			if ( array() === $posts ) {
				return null;
			}

			return $this->record_from_native_post( $identity, $kind, $posts[0], count( $posts ) > 1 );
		}
		if ( 'style' === $kind ) {
			return $this->find_style( $identity );
		}
		if ( 'asset' === $kind ) {
			return $this->find_asset( $identity );
		}
		if ( 'loop_preset' === $kind ) {
			return $this->find_loop_preset( $identity );
		}
		if ( 'home_policy' === $kind ) {
			return $this->find_home_policy();
		}
		if ( 'component_contract_catalog' === $kind ) {
			return null;
		}

		$stored = \get_option( $this->option_name( $identity ), null );

		if ( ! is_array( $stored ) || (string) ( $stored['identity'] ?? '' ) !== $identity ) {
			return null;
		}

		return $this->record_from_storage( $stored );
	}

	public function create( SitePersistenceRecord $record ): RegistrationResult {
		$kind = $this->native_kind( $record->identity() );
		if ( null !== $kind && $this->is_native_post_kind( $kind ) ) {
			return $this->persist_native( $record, $kind, false );
		}
		if ( 'style' === $kind ) {
			return $this->persist_style( $record, false );
		}
		if ( 'asset' === $kind ) {
			return $this->persist_asset( $record, false );
		}
		if ( 'loop_preset' === $kind ) {
			return $this->persist_loop_preset( $record, false );
		}
		if ( 'home_policy' === $kind ) {
			return $this->persist_home_policy( $record );
		}
		if ( 'component_contract_catalog' === $kind ) {
			return $this->unsupported( 'Component Contract Catalog has no WordPress runtime persistence handler.' );
		}

		$added = \add_option( $this->option_name( $record->identity() ), $record->to_array(), '', false );
		if ( $added ) {
			return RegistrationResult::success();
		}

		if ( null !== $this->find( $record->identity() ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'A Site record with this identity already exists.' );
		}

		return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_UPDATE_FAILED', 'WordPress could not create the compiled Site persistence record.' );
	}

	public function update( SitePersistenceRecord $record ): RegistrationResult {
		$kind = $this->native_kind( $record->identity() );
		if ( null !== $kind && $this->is_native_post_kind( $kind ) ) {
			return $this->persist_native( $record, $kind, true );
		}
		if ( 'style' === $kind ) {
			return $this->persist_style( $record, true );
		}
		if ( 'asset' === $kind ) {
			return $this->persist_asset( $record, true );
		}
		if ( 'loop_preset' === $kind ) {
			return $this->persist_loop_preset( $record, true );
		}
		if ( 'home_policy' === $kind ) {
			return $this->persist_home_policy( $record );
		}
		if ( 'component_contract_catalog' === $kind ) {
			return $this->unsupported( 'Component Contract Catalog has no WordPress runtime persistence handler.' );
		}

		$updated = \update_option( $this->option_name( $record->identity() ), $record->to_array(), false );
		if ( $updated ) {
			return RegistrationResult::success();
		}

		$current = $this->find( $record->identity() );
		if ( null !== $current && $current->fingerprint() === $record->fingerprint() ) {
			return RegistrationResult::success();
		}

		return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_UPDATE_FAILED', 'WordPress could not update the compiled Site persistence record.' );
	}

	/**
	 * Return only resources with a valid, explicit prior ownership ledger.
	 *
	 * @return array<int, SitePersistenceRecord>
	 */
	public function owned_resource_records(): array {
		$stored = \get_option( self::RESOURCE_RECORDS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$records = array();
		foreach ( $stored as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			$record = $this->record_from_storage( $value );
			if ( null !== $record
				&& $record->is_owned()
				&& array() !== $record->ownership()
				&& in_array( $record->kind(), array( CompiledSiteResourceType::STYLE->value, CompiledSiteResourceType::ASSET->value ), true )
			) {
				$records[] = $record;
			}
		}

		return $records;
	}

	public function delete_owned_resource( SitePersistenceRecord $record ): RegistrationResult {
		$stored = $this->stored_resource( $record->identity() );
		if ( null === $stored || ! $stored->is_owned() || array() === $stored->ownership() || $stored->fingerprint() !== $record->fingerprint() ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'The recorded resource ownership no longer matches the native resource snapshot.' );
		}

		$result = match ( $record->kind() ) {
			CompiledSiteResourceType::STYLE->value => $this->delete_style_resource( $record ),
			CompiledSiteResourceType::ASSET->value => $this->delete_stylesheet_resource( $record ),
			default => RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_UNSUPPORTED', 'The recorded resource kind cannot be reconciled.' ),
		};

		return $result;
	}

	public function migrate_legacy_ownership( CompiledSitePlan $plan ): RegistrationResult {
		$stored = \get_option( self::RESOURCE_RECORDS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$next = $stored;
		foreach ( array_merge( $plan->styles(), $plan->assets() ) as $resource ) {
			$ownership = array_values(
				array_filter(
					$plan->ownership(),
					static fn ( CompiledSiteOwnership $edge ): bool => $edge->resource_identity() === $resource->identity()
				)
			);
			if ( array() === $ownership ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_OWNERSHIP_MISSING', sprintf( 'Legacy resource "%s" has no explicit ownership edge.', $resource->identity() ) );
			}

			if ( CompiledSiteResourceType::STYLE === $resource->type() ) {
				$style_id = substr( $resource->identity(), strlen( 'style:' ) );
				$styles   = \get_option( self::STYLES_OPTION, array() );
				$native   = is_array( $styles ) ? ( $styles[ $style_id ] ?? null ) : null;
				if ( ! is_array( $native ) || $native !== $resource->payload() || ! $this->has_legacy_style_marker( $style_id, $resource->payload() ) ) {
					return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_LEGACY_OWNERSHIP_UNPROVEN', sprintf( 'Legacy style "%s" does not have an exact bounded ownership match.', $resource->identity() ) );
				}
			} elseif ( 'stylesheet' === (string) ( $resource->payload()['type'] ?? '' ) ) {
				if ( array() === $this->existing_stylesheet_source_keys( $resource, $ownership ) ) {
					return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_LEGACY_OWNERSHIP_UNPROVEN', sprintf( 'Legacy stylesheet asset "%s" does not have an exact bounded fragment match.', $resource->identity() ) );
				}
			} else {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_ASSET_UNSUPPORTED', sprintf( 'Legacy migration does not support asset "%s".', $resource->identity() ) );
			}

			$record       = SitePersistenceRecord::from_resource( $resource, true, $ownership );
			$previous     = isset( $next[ $record->identity() ] ) && is_array( $next[ $record->identity() ] ) ? $this->record_from_storage( $next[ $record->identity() ] ) : null;
			if ( null !== $previous && $previous->fingerprint() !== $record->fingerprint() ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', sprintf( 'A different ownership record already exists for "%s".', $record->identity() ) );
			}
			$next[ $record->identity() ] = $record->to_array();
		}

		if ( ! $this->update_option_if_changed( self::RESOURCE_RECORDS_OPTION, $stored, $next ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', 'WordPress could not record the explicitly migrated resource ownership.' );
		}

		return RegistrationResult::success();
	}

	private function persist_native( SitePersistenceRecord $record, string $kind, bool $update ): RegistrationResult {
		$capability = $this->preflight_native( $record, $kind );
		if ( ! $capability->is_success() ) {
			return $capability;
		}

		if ( ! $update ) {
			$claim_name = $this->claim_option_name( $record->identity() );
			if ( ! $this->acquire_claim( $claim_name ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'A concurrent Site persistence claim already exists for this identity.' );
			}

			$result = null;
			try {
				$result = $this->persist_native_create_claimed( $record, $kind );
			} catch ( Throwable $throwable ) {
				$result = RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_EXCEPTION', $throwable->getMessage() ?: 'WordPress could not persist the native Site entity.' );
			}

			if ( ! $this->release_claim( $claim_name ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', 'WordPress could not release the Site persistence claim.' );
			}

			return $result;
		}

		$posts = $this->find_native_posts( $record->identity(), $kind );
		if ( count( $posts ) > 1 ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'Multiple native Site entities claim this identity.' );
		}

		$existing = $posts[0] ?? null;
		if ( null === $existing ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_MISSING', 'The native Site entity to update does not exist.' );
		}
		$existing_values = get_object_vars( $existing );
		if ( 'page' === $kind && 'page' !== (string) ( $existing_values['post_type'] ?? '' ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'The native Site Page target has a different post type.' );
		}
		if ( 'post' === $kind && (string) ( $record->payload()['post_type'] ?? '' ) !== (string) ( $existing_values['post_type'] ?? '' ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'The native Site Post target has a different post type.' );
		}
		if ( self::OWNER_VALUE !== (string) \get_post_meta( $this->post_id( $existing ), self::OWNER_META, true ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'Existing native Site entity is not owned by this builder.' );
		}

		return $this->persist_native_post( $record, $kind, true, $this->post_id( $existing ) );
	}

	private function persist_native_create_claimed( SitePersistenceRecord $record, string $kind ): RegistrationResult {
		if ( in_array( $kind, array( 'page', 'post' ), true ) && array_key_exists( 'id', $record->payload() ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_MISSING', 'An id-targeted Site content entity must already exist before it can be updated.' );
		}

		$posts = $this->find_native_posts( $record->identity(), $kind );
		if ( count( $posts ) > 1 ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'Multiple native Site entities claim this identity.' );
		}
		if ( null !== ( $posts[0] ?? null ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'A native Site entity with this identity already exists.' );
		}

		return $this->persist_native_post( $record, $kind, false, 0 );
	}

	private function persist_native_post( SitePersistenceRecord $record, string $kind, bool $update, int $existing_id ): RegistrationResult {
		$post_data = $this->native_post_data( $record, $kind, $update );
		$post_id   = $update
			? \wp_update_post( array_merge( $post_data, array( 'ID' => $existing_id ) ), true )
			: \wp_insert_post( $post_data, true );
		$native_id = $this->normalize_native_post_id( $post_id );
		if ( $native_id instanceof RegistrationResult ) {
			return $native_id;
		}

		$metadata = $this->write_native_metadata( $native_id, $record, $kind );
		if ( ! $metadata->is_success() ) {
			if ( ! $update ) {
				$deleted = \wp_delete_post( $native_id, true );
				if ( ! is_object( $deleted ) ) {
					return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', 'WordPress could not roll back the partially persisted Site entity.' );
				}
			}

			return $metadata;
		}

		return RegistrationResult::success();
	}

	private function preflight_native( SitePersistenceRecord $record, string $kind ): RegistrationResult {
		if ( ! \function_exists( 'wp_insert_post' ) || ! \function_exists( 'wp_update_post' ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'WordPress post persistence functions are unavailable.' );
		}

		if ( 'pattern' === $kind ) {
			$categories = is_array( $record->payload()['categories'] ?? null ) ? $record->payload()['categories'] : array();
			if ( array() !== $this->term_values( $categories ) && ( ! \function_exists( 'taxonomy_exists' ) || ! \taxonomy_exists( self::PATTERN_CATEGORY_TAXONOMY ) ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_TAXONOMY_UNAVAILABLE', 'The native Pattern category taxonomy is unavailable.' );
			}
		}

		if ( in_array( $kind, array( 'page', 'post' ), true ) ) {
			if ( ! \function_exists( 'get_post' ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'WordPress post lookup is unavailable.' );
			}
			if ( 'post' === $kind ) {
				$post_type = (string) ( $record->payload()['post_type'] ?? '' );
				if ( '' === $post_type || ! \function_exists( 'post_type_exists' ) ) {
					return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'WordPress post-type capability is unavailable.' );
				}
				if ( ! \post_type_exists( $post_type ) ) {
					return RegistrationResult::error( 'ETCH_SITE_POST_TYPE_INVALID', sprintf( 'Post type "%s" is not registered.', $post_type ) );
				}
			}
		}

		if ( 'template' === $kind ) {
			if ( ! \function_exists( 'get_stylesheet' ) || '' === (string) \get_stylesheet() ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'The active WordPress theme capability is unavailable.' );
			}
			if ( ! \function_exists( 'taxonomy_exists' ) || ! \taxonomy_exists( 'wp_theme' ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_TAXONOMY_UNAVAILABLE', 'The native template theme taxonomy is unavailable.' );
			}
			if ( ! \function_exists( 'wp_set_object_terms' ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'WordPress template theme persistence is unavailable.' );
			}
		}

		return RegistrationResult::success();
	}

	private function normalize_native_post_id( mixed $post_id ): int|RegistrationResult {
		if ( $post_id instanceof \WP_Error ) {
			return RegistrationResult::error( (string) $post_id->get_error_code(), $post_id->get_error_message() );
		}
		if ( ! is_int( $post_id ) || $post_id < 1 ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_UPDATE_FAILED', 'WordPress could not persist the native Site entity.' );
		}

		return $post_id;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function native_post_data( SitePersistenceRecord $record, string $kind, bool $update ): array {
		$payload = $record->payload();
		$key     = $this->native_key( $record->identity(), $kind );
		$is_block = in_array( $kind, array( 'component', 'pattern' ), true );
		$post_type = match ( $kind ) {
			'page'     => 'page',
			'post'     => (string) ( $payload['post_type'] ?? '' ),
			'template' => 'wp_template',
			default    => self::BLOCK_POST_TYPE,
		};
		$title = array_key_exists( 'post_title', $payload )
			? (string) $payload['post_title']
			: (string) ( $payload['name'] ?? ( $is_block ? $key : ucwords( str_replace( '-', ' ', (string) ( $payload['slug'] ?? $key ) ) ) ) );
		if ( ! $is_block && $update && ! array_key_exists( 'post_title', $payload ) ) {
			$title = '';
		}

		$data = array(
			'post_type'    => $post_type,
			'post_content' => \wp_slash( (string) ( $payload['blocks'] ?? '' ) ),
		);
		if ( $is_block || ! $update || array_key_exists( 'post_status', $payload ) ) {
			$data['post_status'] = (string) ( $payload['post_status'] ?? 'publish' );
		}
		if ( $is_block || ! $update || array_key_exists( 'post_title', $payload ) ) {
			$data['post_title'] = \wp_slash( \sanitize_text_field( $title ) );
		}
		if ( $is_block || ! $update || array_key_exists( 'post_excerpt', $payload ) ) {
			$data['post_excerpt'] = \wp_slash( \sanitize_text_field( (string) ( $payload['post_excerpt'] ?? $payload['description'] ?? '' ) ) );
		}
		if ( $is_block || ! $update || array_key_exists( 'slug', $payload ) ) {
			$data['post_name'] = $is_block
				? $this->native_slug( $kind, $key )
				: \sanitize_title( (string) ( $payload['slug'] ?? $key ) );
		}

		return $data;
	}

	private function write_native_metadata( int $post_id, SitePersistenceRecord $record, string $kind ): RegistrationResult {
		$payload = $record->payload();
		$key     = $this->native_key( $record->identity(), $kind );

		$identity_meta = in_array( $kind, array( 'component', 'pattern' ), true )
			? $this->native_key_meta( $kind )
			: self::CONTENT_IDENTITY_META;
		$metadata = array(
			array( $identity_meta, 'component' === $kind || 'pattern' === $kind ? \sanitize_text_field( $key ) : $record->identity() ),
			array( self::OWNER_META, self::OWNER_VALUE ),
		);
		if ( 'component' === $kind ) {
			$metadata[] = array( self::COMPONENT_PROPERTIES_META, is_array( $payload['properties'] ?? null ) ? $payload['properties'] : array() );
		} elseif ( 'pattern' === $kind ) {
			$metadata[] = array( self::PATTERN_SYNC_META, self::PATTERN_SYNC_STATUS );
			$categories = is_array( $payload['categories'] ?? null ) ? $payload['categories'] : array();
			if ( array() !== $this->term_values( $categories ) && ! \taxonomy_exists( self::PATTERN_CATEGORY_TAXONOMY ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_TAXONOMY_UNAVAILABLE', 'The native Pattern category taxonomy is unavailable.' );
			}
		}

		foreach ( $metadata as $entry ) {
			if ( ! $this->update_native_meta( $post_id, $entry[0], $entry[1] ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_META_FAILED', sprintf( 'WordPress could not write native metadata "%s".', $entry[0] ) );
			}
		}

		if ( 'pattern' === $kind && \taxonomy_exists( self::PATTERN_CATEGORY_TAXONOMY ) ) {
			$categories = $this->term_values( is_array( $payload['categories'] ?? null ) ? $payload['categories'] : array() );
			$terms      = \wp_set_object_terms( $post_id, $categories, self::PATTERN_CATEGORY_TAXONOMY );
			if ( $terms instanceof \WP_Error ) {
				return RegistrationResult::error( (string) $terms->get_error_code(), $terms->get_error_message() );
			}
			if ( ! is_array( $terms ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_TAXONOMY_FAILED', 'WordPress could not synchronize native Pattern categories.' );
			}
		}

		if ( 'template' === $kind ) {
			$terms = \wp_set_object_terms( $post_id, array( (string) \get_stylesheet() ), 'wp_theme' );
			if ( $terms instanceof \WP_Error ) {
				return RegistrationResult::error( (string) $terms->get_error_code(), $terms->get_error_message() );
			}
			if ( ! is_array( $terms ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_TAXONOMY_FAILED', 'WordPress could not associate the native Template with the active theme.' );
			}
		}

		$native = $this->native_projection_record( $record, $kind );
		if ( ! $this->update_native_meta( $post_id, self::SNAPSHOT_META, $record->to_array() ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_META_FAILED', 'WordPress could not write the compiled Site persistence snapshot.' );
		}
		if ( ! $this->update_native_meta( $post_id, self::NATIVE_FINGERPRINT_META, $native->fingerprint() ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_META_FAILED', 'WordPress could not write the native Site fingerprint.' );
		}

		return RegistrationResult::success();
	}

	private function update_native_meta( int $post_id, string $key, mixed $value ): bool {
		$slashed = \wp_slash( $value );
		if ( \update_post_meta( $post_id, $key, $slashed ) ) {
			return true;
		}

		return \get_post_meta( $post_id, $key, true ) === $value;
	}

	private function native_projection_record( SitePersistenceRecord $record, string $kind ): SitePersistenceRecord {
		$type = match ( $kind ) {
			'component' => CompiledSiteEntityType::COMPONENT,
			'pattern'   => CompiledSiteEntityType::PATTERN,
			'page'      => CompiledSiteEntityType::PAGE,
			'post'      => CompiledSiteEntityType::POST,
			'template'  => CompiledSiteEntityType::TEMPLATE,
			default     => throw new \InvalidArgumentException( sprintf( 'Unsupported native post kind "%s".', $kind ) ),
		};
		$entity = CompiledSiteEntity::new( $type, $record->identity(), $this->native_payload_from_record( $record, $kind ) );

		return SitePersistenceRecord::from_entity( $entity );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function native_payload_from_record( SitePersistenceRecord $record, string $kind ): array {
		$payload = $record->payload();
		if ( in_array( $kind, array( 'component', 'pattern' ), true ) ) {
			$native = array(
				'post_status' => 'publish',
				'post_name'   => $this->native_slug( $kind, $this->native_key( $record->identity(), $kind ) ),
				'name'        => \sanitize_text_field( (string) ( $payload['name'] ?? $this->native_key( $record->identity(), $kind ) ) ),
				'description' => \sanitize_text_field( (string) ( $payload['description'] ?? '' ) ),
				'blocks'      => (string) ( $payload['blocks'] ?? '' ),
			);
		} else {
			$native = array( 'blocks' => (string) ( $payload['blocks'] ?? '' ) );
			if ( 'post' === $kind ) {
				$native['post_type'] = (string) ( $payload['post_type'] ?? '' );
			}
			if ( array_key_exists( 'id', $payload ) ) {
				$native['id'] = (int) $payload['id'];
			}
			if ( array_key_exists( 'slug', $payload ) ) {
				$native['slug'] = \sanitize_title( (string) $payload['slug'] );
			}
			if ( array_key_exists( 'post_title', $payload ) ) {
				$native['post_title'] = \sanitize_text_field( (string) $payload['post_title'] );
			}
			if ( array_key_exists( 'post_status', $payload ) ) {
				$native['post_status'] = (string) $payload['post_status'];
			}
			if ( array_key_exists( 'post_excerpt', $payload ) ) {
				$native['post_excerpt'] = \sanitize_text_field( (string) $payload['post_excerpt'] );
			}
		}

		if ( 'component' === $kind ) {
			$native['properties'] = is_array( $payload['properties'] ?? null ) ? $payload['properties'] : array();
		} elseif ( 'pattern' === $kind ) {
			$native['categories']   = $this->normalize_categories( is_array( $payload['categories'] ?? null ) ? $payload['categories'] : array() );
			$native['sync_status']  = self::PATTERN_SYNC_STATUS;
		}

		return $native;
	}

	private function find_native_posts( string $identity, string $kind ): array {
		if ( ! \function_exists( 'get_post' ) || ! \function_exists( 'get_posts' ) || ! \function_exists( 'get_page_by_path' ) ) {
			return array();
		}
		if ( 'template' === $kind && ! \function_exists( 'get_stylesheet' ) ) {
			return array();
		}

		if ( 'page' === $kind ) {
			if ( str_contains( $identity, ':id:' ) ) {
				$post = \get_post( (int) substr( $identity, strlen( 'page:id:' ) ) );
				return is_object( $post ) && 'page' === (string) ( get_object_vars( $post )['post_type'] ?? '' ) ? array( $post ) : array();
			}

			$slug = substr( $identity, strlen( 'page:slug:' ) );
			$post = \get_page_by_path( $slug, 'OBJECT', 'page' );

			return is_object( $post ) ? array( $post ) : array();
		}

		if ( 'post' === $kind ) {
			if ( str_contains( $identity, ':id:' ) ) {
				$post = \get_post( (int) substr( $identity, strlen( 'post:id:' ) ) );
				if ( ! is_object( $post ) ) {
					return array();
				}
				$post_type = (string) ( get_object_vars( $post )['post_type'] ?? '' );
				return '' !== $post_type && 'page' !== $post_type && 'wp_template' !== $post_type ? array( $post ) : array();
			}

			$parts     = explode( ':', substr( $identity, strlen( 'post:' ) ), 2 );
			$post_type = $parts[0] ?? '';
			$slug      = $parts[1] ?? '';
			$posts     = \get_posts(
				array(
					'post_type'      => $post_type,
					'name'           => $slug,
					'post_status'    => 'any',
					'posts_per_page' => -1,
				)
			);

			return array_values(
				array_filter(
					$posts,
					static fn ( mixed $post ): bool => is_object( $post ) && (string) ( get_object_vars( $post )['post_name'] ?? '' ) === $slug
				)
			);
		}

		if ( 'template' === $kind ) {
			$slug  = substr( $identity, strlen( 'template:slug:' ) );
			$theme = \get_stylesheet();
			if ( \function_exists( 'get_block_template' ) ) {
				$template = \get_block_template( $theme . '//' . $slug, 'wp_template' );
				if ( is_object( $template ) && isset( $template->wp_id ) ) {
					$post = \get_post( (int) $template->wp_id );
					return is_object( $post ) ? array( $post ) : array();
				}
			}

			$posts = \get_posts(
				array(
					'post_type'      => 'wp_template',
					'name'           => $slug,
					'post_status'    => 'any',
					'posts_per_page' => -1,
				)
			);
			if ( \function_exists( 'wp_get_object_terms' ) ) {
				$posts = array_values(
					array_filter(
						$posts,
						static function ( mixed $post ): bool {
							if ( ! is_object( $post ) ) {
								return false;
							}
							$terms = \wp_get_object_terms( (int) ( get_object_vars( $post )['ID'] ?? 0 ), 'wp_theme', array( 'fields' => 'slugs' ) );
							return is_array( $terms ) && in_array( (string) \get_stylesheet(), $terms, true );
						}
					)
				);
			}

			return array_values(
				array_filter(
					$posts,
					static fn ( mixed $post ): bool => is_object( $post ) && (string) ( get_object_vars( $post )['post_name'] ?? '' ) === $slug
				)
			);
		}

		$key   = $this->native_key( $identity, $kind );
		$posts = \get_posts(
			array(
				'post_type'        => self::BLOCK_POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'future', 'pending', 'private' ),
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_key'        => $this->native_key_meta( $kind ),
				'meta_value'      => $key,
			)
		);

		$matches = array_values(
			array_filter(
				$posts,
				static fn ( mixed $post ): bool => is_object( $post )
			)
		);
		if ( array() !== $matches ) {
			return $matches;
		}

		$fallback = \get_page_by_path( $this->native_slug( $kind, $key ), 'OBJECT', self::BLOCK_POST_TYPE );

		return is_object( $fallback ) ? array( $fallback ) : array();
	}

	private function record_from_native_post( string $identity, string $kind, object $post, bool $force_unowned = false ): ?SitePersistenceRecord {
		$post_id = $this->post_id( $post );
		if ( $post_id < 1 ) {
			return null;
		}

		$owned   = ! $force_unowned && self::OWNER_VALUE === (string) \get_post_meta( $post_id, self::OWNER_META, true );
		$snapshot = \get_post_meta( $post_id, self::SNAPSHOT_META, true );
		$stored   = $owned && is_array( $snapshot ) ? $this->record_from_storage( $snapshot ) : null;
		$native   = $this->native_record_from_post( $identity, $kind, $post, $owned, $stored );
		if ( null === $native ) {
			return null;
		}

		if ( $owned ) {
			$native_fingerprint = (string) \get_post_meta( $post_id, self::NATIVE_FINGERPRINT_META, true );
			if ( null !== $stored
				&& $stored->is_owned()
				&& $stored->identity() === $identity
				&& $stored->kind() === $this->entity_kind_value( $kind )
				&& $native_fingerprint === $native->fingerprint()
			) {
				return $stored;
			}
		}

		return $native;
	}

	private function native_record_from_post( string $identity, string $kind, object $post, bool $owned, ?SitePersistenceRecord $stored = null ): ?SitePersistenceRecord {
		$type = match ( $kind ) {
			'component' => CompiledSiteEntityType::COMPONENT,
			'pattern'   => CompiledSiteEntityType::PATTERN,
			'page'      => CompiledSiteEntityType::PAGE,
			'post'      => CompiledSiteEntityType::POST,
			'template'  => CompiledSiteEntityType::TEMPLATE,
			default     => null,
		};
		if ( null === $type ) {
			return null;
		}
		try {
			return SitePersistenceRecord::from_entity(
				CompiledSiteEntity::new( $type, $identity, $this->native_payload_from_post( $identity, $kind, $post, $stored?->payload() ) ),
				$owned,
				$stored?->ownership() ?? array()
			);
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function native_payload_from_post( string $identity, string $kind, object $post, ?array $expected_payload = null ): array {
		$post_values = get_object_vars( $post );
		$post_id     = (int) ( $post_values['ID'] ?? 0 );
		if ( in_array( $kind, array( 'component', 'pattern' ), true ) ) {
			$payload = array(
				'post_status' => (string) ( $post_values['post_status'] ?? '' ),
				'post_name'   => (string) ( $post_values['post_name'] ?? '' ),
				'name'        => (string) ( $post_values['post_title'] ?? '' ),
				'description' => (string) ( $post_values['post_excerpt'] ?? '' ),
				'blocks'      => (string) ( $post_values['post_content'] ?? '' ),
			);
		} else {
			$payload = array( 'blocks' => (string) ( $post_values['post_content'] ?? '' ) );
			if ( 'post' === $kind ) {
				$payload['post_type'] = (string) ( $post_values['post_type'] ?? '' );
			}
			if ( str_contains( $identity, ':id:' ) ) {
				$payload['id'] = $post_id;
			} else {
				$payload['slug'] = (string) ( $post_values['post_name'] ?? '' );
			}
			foreach ( array( 'post_title', 'post_status', 'post_excerpt' ) as $field ) {
				if ( null === $expected_payload || array_key_exists( $field, $expected_payload ) ) {
					$payload[ $field ] = (string) ( $post_values[ $field ] ?? '' );
				}
			}
		}

		if ( 'component' === $kind ) {
			$properties         = \get_post_meta( $post_id, self::COMPONENT_PROPERTIES_META, true );
			$payload['properties'] = is_array( $properties ) ? $properties : array();
		} elseif ( 'pattern' === $kind ) {
			$payload['categories']  = $this->native_categories( $post_id );
			$payload['sync_status'] = (string) \get_post_meta( $post_id, self::PATTERN_SYNC_META, true );
		}

		return $payload;
	}

	/**
	 * @return array<int, string>
	 */
	private function native_categories( int $post_id ): array {
		if ( ! \taxonomy_exists( self::PATTERN_CATEGORY_TAXONOMY ) ) {
			return array();
		}

		$terms = \get_the_terms( $post_id, self::PATTERN_CATEGORY_TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$categories = array();
		foreach ( $terms as $term ) {
			$term_values = get_object_vars( $term );
			if ( is_string( $term_values['slug'] ?? null ) ) {
				$categories[] = $term_values['slug'];
			}
		}

		return $this->normalize_categories( $categories );
	}

	/**
	 * Preserve incoming category names for WordPress term creation.
	 *
	 * @param array<int, mixed> $categories
	 * @return array<int, string>
	 */
	private function term_values( array $categories ): array {
		$values = array();
		foreach ( $categories as $category ) {
			if ( is_string( $category ) && '' !== trim( $category ) ) {
				$values[] = $category;
			}
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * @param array<int, mixed> $categories
	 * @return array<int, string>
	 */
	private function normalize_categories( array $categories ): array {
		$normalized = array();
		foreach ( $categories as $category ) {
			if ( ! is_string( $category ) ) {
				continue;
			}
			$slug = \sanitize_title( $category );
			if ( '' !== $slug ) {
				$normalized[ $slug ] = true;
			}
		}

		$normalized = array_keys( $normalized );
		sort( $normalized, SORT_STRING );

		return $normalized;
	}

	private function native_kind( string $identity ): ?string {
		foreach ( array( 'component', 'pattern', 'page', 'post', 'template', 'loop_preset', 'component_contract_catalog', 'style', 'asset' ) as $kind ) {
			if ( str_starts_with( $identity, $kind . ':' ) ) {
				return $kind;
			}
		}
		if ( 'home_policy:front_page' === $identity ) {
			return 'home_policy';
		}

		return null;
	}

	private function is_native_post_kind( string $kind ): bool {
		return in_array( $kind, array( 'component', 'pattern', 'page', 'post', 'template' ), true );
	}

	private function entity_kind_value( string $kind ): string {
		return match ( $kind ) {
			'component' => CompiledSiteEntityType::COMPONENT->value,
			'pattern'   => CompiledSiteEntityType::PATTERN->value,
			'page'      => CompiledSiteEntityType::PAGE->value,
			'post'      => CompiledSiteEntityType::POST->value,
			'template'  => CompiledSiteEntityType::TEMPLATE->value,
			default     => '',
		};
	}

	private function find_style( string $identity ): ?SitePersistenceRecord {
		$styles = \get_option( self::STYLES_OPTION, array() );
		if ( ! is_array( $styles ) ) {
			return null;
		}

		$style_id = substr( $identity, strlen( 'style:' ) );
		if ( ! array_key_exists( $style_id, $styles ) || ! is_array( $styles[ $style_id ] ) ) {
			return null;
		}

		$stored = $this->stored_resource( $identity );
		try {
			$resource = CompiledSiteResource::new( CompiledSiteResourceType::STYLE, $identity, $styles[ $style_id ] );
			$current  = SitePersistenceRecord::from_resource(
				$resource,
				$stored?->is_owned() ?? false,
				$stored?->ownership() ?? array()
			);
		} catch ( Throwable ) {
			return null;
		}

		return null !== $stored && $stored->is_owned() && $stored->fingerprint() === $current->fingerprint() ? $stored : $current;
	}

	private function persist_style( SitePersistenceRecord $record, bool $update ): RegistrationResult {
		if ( ! \function_exists( 'get_option' ) || ! \function_exists( 'update_option' ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'The native Etch style option capability is unavailable.' );
		}

		$styles = \get_option( self::STYLES_OPTION, array() );
		if ( ! is_array( $styles ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'The native Etch style option is not an array.' );
		}

		$style_id = substr( $record->identity(), strlen( 'style:' ) );
		if ( $update && ! array_key_exists( $style_id, $styles ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_MISSING', 'The native Etch style to update does not exist.' );
		}
		if ( ! $update && array_key_exists( $style_id, $styles ) ) {
			$stored = $this->stored_resource( $record->identity() );
			if ( null === $stored || ! $stored->is_owned() ) {
				if ( ! $this->native_style_is_builder_handoff( $styles[ $style_id ], $record->payload() ) ) {
					return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'Existing native Etch style is not owned by this builder.' );
				}
				// Adopt the Builder's own handoff-written style: the public
				// payload matches exactly and the entry carries the internal
				// authorship marker only Builder code can set.
			}
		}

		$next         = $styles;
		$next[ $style_id ] = $record->payload();
		if ( ! $this->update_option_if_changed( self::STYLES_OPTION, $styles, $next ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_UPDATE_FAILED', 'WordPress could not update the native Etch style option.' );
		}

		return $this->store_resource_snapshot( $record );
	}

	private function find_asset( string $identity ): ?SitePersistenceRecord {
		$stored = $this->stored_resource( $identity );
		if ( null === $stored || 'stylesheet' !== (string) ( $stored->payload()['type'] ?? '' ) ) {
			return null;
		}

		$stylesheets = \get_option( self::GLOBAL_STYLESHEETS_OPTION, array() );
		if ( ! is_array( $stylesheets ) ) {
			return null;
		}
		$stylesheet_id = (string) ( $stored->payload()['id'] ?? '' );
		$native        = $stylesheets[ $stylesheet_id ] ?? null;
		if ( ! is_array( $native ) ) {
			return null;
		}

		$records = $this->stored_resources();
		$expected = $this->aggregate_stylesheet_payload( $records, $stylesheet_id );
		if ( (string) ( $native['css'] ?? '' ) === (string) ( $expected['css'] ?? '' )
			&& (string) ( $native['name'] ?? '' ) === $stylesheet_id
		) {
			return $stored;
		}

		$current_payload            = $stored->payload();
		$current_payload['css']    = (string) ( $native['css'] ?? '' );
		try {
			$current_resource = CompiledSiteResource::new( CompiledSiteResourceType::ASSET, $identity, $current_payload );
			return SitePersistenceRecord::from_resource( $current_resource, $stored->is_owned(), $stored->ownership() );
		} catch ( Throwable ) {
			return $stored;
		}
	}

	/** {@inheritdoc} */
	public function adopt_unowned_record( SitePersistenceRecord $record ): bool {
		if ( ! str_starts_with( $record->identity(), 'style:' ) || ! \function_exists( 'get_option' ) ) {
			return false;
		}

		$styles   = \get_option( self::STYLES_OPTION, array() );
		$style_id = substr( $record->identity(), strlen( 'style:' ) );
		return is_array( $styles )
			&& array_key_exists( $style_id, $styles )
			&& $this->native_style_is_builder_handoff( $styles[ $style_id ], $record->payload() );
	}

	/**
	 * Whether one persisted native style entry is Builder-authored handoff
	 * state that matches the compiled record exactly and may be adopted.
	 *
	 * @param mixed               $existing Persisted etch_styles entry.
	 * @param array<string, mixed> $payload Compiled style payload.
	 */
	private function native_style_is_builder_handoff( mixed $existing, array $payload ): bool {
		if ( ! is_array( $existing ) || true !== ( $existing['overwrite_on_register'] ?? null ) ) {
			return false;
		}

		foreach ( array( 'selector', 'css', 'type' ) as $field ) {
			if ( (string) ( $existing[ $field ] ?? '' ) !== (string) ( $payload[ $field ] ?? '' ) ) {
				return false;
			}
		}

		return true;
	}

	private function persist_asset( SitePersistenceRecord $record, bool $update ): RegistrationResult {
		$payload = $record->payload();
		$type    = (string) ( $payload['type'] ?? '' );
		if ( 'javascript' === $type ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_ASSET_UNSUPPORTED', 'Etch has no native global JavaScript asset persistence contract for this compiled asset.' );
		}
		if ( 'stylesheet' !== $type ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_ASSET_INVALID', 'Compiled Site asset type is not supported by the native persistence handler.' );
		}
		if ( ! \function_exists( 'get_option' ) || ! \function_exists( 'update_option' ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'The native Etch global stylesheet capability is unavailable.' );
		}
		if ( array() === $record->ownership() ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_OWNERSHIP_MISSING', 'A compiled stylesheet asset requires its exact plan ownership edge.' );
		}

		$stylesheets = \get_option( self::GLOBAL_STYLESHEETS_OPTION, array() );
		if ( ! is_array( $stylesheets ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'The native Etch global stylesheet option is not an array.' );
		}
		$records = $this->stored_resources();
		if ( $update && ! isset( $records[ $record->identity() ] ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_MISSING', 'The compiled stylesheet asset snapshot to update does not exist.' );
		}
		if ( ! $update && isset( $records[ $record->identity() ] ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'A compiled stylesheet asset with this identity already exists.' );
		}

		$records[ $record->identity() ] = $record->to_array();
		$stylesheet_id = (string) ( $payload['id'] ?? '' );
		if ( '' === $stylesheet_id ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_ASSET_INVALID', 'Compiled stylesheet asset requires a native stylesheet id.' );
		}
		if ( ! $update && array_key_exists( $stylesheet_id, $stylesheets ) && ! $this->has_owned_stylesheet_record( $this->stored_resources(), $stylesheet_id ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'Existing native Etch global stylesheet is not owned by this builder.' );
		}

		$state = $this->build_stylesheet_state( $records, $stylesheets, $stylesheet_id );
		$failed = $this->update_options_atomically( array(
			array(
				'option' => self::BUILDER_STYLESHEET_FRAGMENTS_OPTION,
				'before' => \get_option( self::BUILDER_STYLESHEET_FRAGMENTS_OPTION, array() ),
				'after'  => $state['fragments'],
			),
			array(
				'option' => self::GLOBAL_STYLESHEETS_OPTION,
				'before' => $stylesheets,
				'after'  => $state['stylesheets'],
			),
			array(
				'option' => self::BUILDER_STYLESHEET_HASHES_OPTION,
				'before' => \get_option( self::BUILDER_STYLESHEET_HASHES_OPTION, array() ),
				'after'  => $state['hashes'],
			),
		) );
		if ( null !== $failed ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', sprintf( 'WordPress could not update "%s" for the compiled stylesheet; prior writes were rolled back.', $failed ) );
		}

		return $this->store_resource_snapshot( $record, $records );
	}

	private function delete_style_resource( SitePersistenceRecord $record ): RegistrationResult {
		$styles = \get_option( self::STYLES_OPTION, array() );
		if ( ! is_array( $styles ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'The native Etch style option is not an array.' );
		}

		$style_id = substr( $record->identity(), strlen( 'style:' ) );
		$next     = $styles;
		$native   = $styles[ $style_id ] ?? null;
		if ( is_array( $native ) && $this->payload_hash( $native ) === $this->payload_hash( $record->payload() ) ) {
			unset( $next[ $style_id ] );
		}

		if ( ! $this->update_option_if_changed( self::STYLES_OPTION, $styles, $next ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', 'WordPress could not remove the recorded native Etch style.' );
		}

		return $this->remove_resource_snapshot( $record->identity() );
	}

	private function delete_stylesheet_resource( SitePersistenceRecord $record ): RegistrationResult {
		$payload = $record->payload();
		$id      = (string) ( $payload['id'] ?? '' );
		if ( '' === $id ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_ASSET_INVALID', 'Recorded stylesheet asset has no native stylesheet id.' );
		}

		$current_fragments = \get_option( self::BUILDER_STYLESHEET_FRAGMENTS_OPTION, array() );
		$current_fragments = is_array( $current_fragments ) ? $current_fragments : array();
		$next_fragments    = $current_fragments;
		$current_sources   = is_array( $current_fragments[ $id ] ?? null ) ? $current_fragments[ $id ] : array();
		$fragment_drifted  = false;
		foreach ( $this->stylesheet_source_keys( $record ) as $source_key ) {
			$source = $current_sources[ $source_key ] ?? null;
			if ( ! is_array( $source )
				|| (string) ( $source['css'] ?? '' ) !== (string) ( $payload['css'] ?? '' )
				|| (string) ( $source['file_path'] ?? '' ) !== (string) ( $payload['path'] ?? '' )
			) {
				if ( array_key_exists( $source_key, $current_sources ) ) {
					$fragment_drifted = true;
				}
				continue;
			}

			unset( $next_fragments[ $id ][ $source_key ] );
		}
		if ( isset( $next_fragments[ $id ] ) && array() === $next_fragments[ $id ] ) {
			unset( $next_fragments[ $id ] );
		}

		$stylesheets = \get_option( self::GLOBAL_STYLESHEETS_OPTION, array() );
		$stylesheets = is_array( $stylesheets ) ? $stylesheets : array();
		$hashes      = \get_option( self::BUILDER_STYLESHEET_HASHES_OPTION, array() );
		$hashes      = is_array( $hashes ) ? $hashes : array();
		$current     = $stylesheets[ $id ] ?? null;
		$owned       = is_array( $current ) && isset( $hashes[ $id ] ) && $hashes[ $id ] === $this->payload_hash( $current );
		$next_stylesheets = $stylesheets;
		$next_hashes      = $hashes;

		if ( $fragment_drifted ) {
			unset( $next_hashes[ $id ] );
		} elseif ( isset( $next_fragments[ $id ] ) ) {
			if ( $owned ) {
				$next_stylesheets[ $id ] = array(
					'name' => $id,
					'css'  => $this->aggregate_fragment_css( $next_fragments[ $id ] ),
				);
				$next_hashes[ $id ] = $this->payload_hash( $next_stylesheets[ $id ] );
			} else {
				unset( $next_hashes[ $id ] );
			}
		} else {
			if ( $owned ) {
				unset( $next_stylesheets[ $id ] );
			}
			unset( $next_hashes[ $id ] );
		}

		$failed = $this->update_options_atomically( array(
			array(
				'option' => self::BUILDER_STYLESHEET_FRAGMENTS_OPTION,
				'before' => $current_fragments,
				'after'  => $next_fragments,
			),
			array(
				'option' => self::GLOBAL_STYLESHEETS_OPTION,
				'before' => $stylesheets,
				'after'  => $next_stylesheets,
			),
			array(
				'option' => self::BUILDER_STYLESHEET_HASHES_OPTION,
				'before' => $hashes,
				'after'  => $next_hashes,
			),
		) );
		if ( null !== $failed ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', sprintf( 'WordPress could not update "%s" during stylesheet orphan cleanup; prior writes were rolled back.', $failed ) );
		}

		return $this->remove_resource_snapshot( $record->identity() );
	}

	/**
	 * @param array<string, mixed> $stored
	 * @return array<int, string>
	 */
	private function asset_source_keys_from_storage( array $stored ): array {
		$payload = is_array( $stored['payload'] ?? null ) ? $stored['payload'] : array();
		$owners  = is_array( $stored['ownership'] ?? null ) ? $stored['ownership'] : array();
		$id      = (string) ( $payload['id'] ?? '' );
		$path    = (string) ( $payload['path'] ?? '' );
		if ( '' === $id ) {
			return array();
		}

		$keys = array();
		foreach ( $owners as $edge ) {
			if ( is_array( $edge ) && is_string( $edge['owner'] ?? null ) ) {
				$keys[] = $edge['owner'] . ':' . hash( 'sha256', $id ) . ':' . hash( 'sha256', $path );
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function stylesheet_source_keys( SitePersistenceRecord $record ): array {
		return $this->asset_source_keys_from_storage( $record->to_array() );
	}

	/**
	 * @param array<int, CompiledSiteOwnership> $ownership
	 * @return array<int, string>
	 */
	private function existing_stylesheet_source_keys( CompiledSiteResource $resource, array $ownership ): array {
		$payload = $resource->payload();
		$id      = (string) ( $payload['id'] ?? '' );
		$path    = (string) ( $payload['path'] ?? '' );
		$stored  = \get_option( self::BUILDER_STYLESHEET_FRAGMENTS_OPTION, array() );
		$sources = is_array( $stored ) && is_array( $stored[ $id ] ?? null ) ? $stored[ $id ] : array();
		$matches = array();
		foreach ( $ownership as $edge ) {
			$key = $edge->owner_identity() . ':' . hash( 'sha256', $id ) . ':' . hash( 'sha256', $path );
			$source = $sources[ $key ] ?? null;
			if ( is_array( $source )
				&& (string) ( $source['css'] ?? '' ) === (string) ( $payload['css'] ?? '' )
				&& (string) ( $source['file_path'] ?? '' ) === $path
			) {
				$matches[] = $key;
			}
		}

		return $matches;
	}

	private function has_legacy_style_marker( string $style_id, array $payload ): bool {
		$collection = (string) ( $payload['collection'] ?? '' );

		return 1 === preg_match( '/^(?:omide|clayo)-/', $style_id ) || str_starts_with( $collection, 'OhMyIDEtch' );
	}

	private function find_loop_preset( string $identity ): ?SitePersistenceRecord {
		$loops = \get_option( self::LOOPS_OPTION, array() );
		if ( ! is_array( $loops ) ) {
			return null;
		}
		$key = substr( $identity, strlen( 'loop_preset:' ) );
		$matches = array();
		foreach ( $loops as $option_key => $loop ) {
			if ( is_array( $loop ) && $key === (string) ( $loop['key'] ?? '' ) ) {
				$matches[ (string) $option_key ] = $loop;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return null;
		}

		$option_key     = (string) array_key_first( $matches );
		$native_payload = $matches[ $option_key ];
		$native_payload = $this->without_runtime_private_keys( $native_payload );
		$native_payload['id'] = $option_key;
		$stored = $this->stored_entity( $identity );
		try {
			$entity = CompiledSiteEntity::new( CompiledSiteEntityType::LOOP_PRESET, $identity, $native_payload );
			$current = SitePersistenceRecord::from_entity( $entity, $stored?->is_owned() ?? false, $stored?->ownership() ?? array() );
		} catch ( Throwable ) {
			return null;
		}

		return null !== $stored && $stored->is_owned() && $stored->fingerprint() === $current->fingerprint() ? $stored : $current;
	}

	/**
	 * Drop runtime-private top-level keys from a native Etch payload.
	 *
	 * The Etch runtime may add its own underscore-prefixed bookkeeping keys to
	 * persisted loop payloads; only the non-private contract keys participate
	 * in ownership fingerprints, so runtime upgrades cannot cause false drift.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function without_runtime_private_keys( array $payload ): array {
		foreach ( array_keys( $payload ) as $key ) {
			if ( is_string( $key ) && str_starts_with( $key, '_' ) ) {
				unset( $payload[ $key ] );
			}
		}

		return $payload;
	}

	private function persist_loop_preset( SitePersistenceRecord $record, bool $update ): RegistrationResult {
		if ( ! \function_exists( 'get_option' ) || ! \function_exists( 'update_option' ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'The native Etch loop option capability is unavailable.' );
		}

		$loops = \get_option( self::LOOPS_OPTION, array() );
		if ( ! is_array( $loops ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'The native Etch loop option is not an array.' );
		}
		$key        = substr( $record->identity(), strlen( 'loop_preset:' ) );
		$option_key = (string) ( $record->payload()['id'] ?? $key );
		$existing_keys = array();
		foreach ( $loops as $existing_key => $loop ) {
			if ( is_array( $loop ) && $key === (string) ( $loop['key'] ?? '' ) ) {
				$existing_keys[] = (string) $existing_key;
			}
		}
		if ( count( $existing_keys ) > 1 ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'Multiple native Etch loop presets claim this identity.' );
		}
		if ( $update && ! in_array( $option_key, $existing_keys, true ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_MISSING', 'The native Etch loop preset to update does not exist.' );
		}
		if ( ! $update && array() !== $existing_keys ) {
			$stored = $this->stored_entity( $record->identity() );
			if ( null === $stored || ! $stored->is_owned() ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'Existing native Etch loop preset is not owned by this builder.' );
			}
		}

		$native_payload = $record->payload();
		unset( $native_payload['id'] );
		$native_payload['_omide_builder_hash'] = $this->payload_hash( $native_payload );
		$next = $loops;
		$next[ $option_key ] = $native_payload;
		if ( ! $this->update_option_if_changed( self::LOOPS_OPTION, $loops, $next ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_UPDATE_FAILED', 'WordPress could not update the native Etch loop option.' );
		}

		return $this->store_entity_snapshot( $record );
	}

	private function find_home_policy(): ?SitePersistenceRecord {
		$stored = $this->record_from_storage( \get_option( self::HOME_POLICY_OPTION, array() ) );
		if ( null === $stored || ! $stored->is_owned() || 'home_policy' !== $stored->kind() ) {
			return null;
		}

		return $this->home_policy_matches_native( $stored->payload() ) ? $stored : null;
	}

	private function persist_home_policy( SitePersistenceRecord $record ): RegistrationResult {
		if ( ! \function_exists( 'get_option' ) || ! \function_exists( 'update_option' ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'WordPress reading-setting persistence is unavailable.' );
		}

		$payload = $record->payload();
		$mode    = (string) ( $payload['mode'] ?? '' );
		$values  = array( 'show_on_front' => 'posts', 'page_on_front' => 0 );
		if ( SiteHomePolicyMode::PAGE->value === $mode ) {
			$slug = (string) ( $payload['slug'] ?? '' );
			if ( ! \function_exists( 'get_page_by_path' ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'WordPress page lookup is unavailable for the home-page policy.' );
			}
			$page = \get_page_by_path( $slug, 'OBJECT', 'page' );
			$page_values = is_object( $page ) ? get_object_vars( $page ) : array();
			if ( ! is_object( $page ) || 'page' !== (string) ( $page_values['post_type'] ?? '' ) || (int) ( $page_values['ID'] ?? 0 ) < 1 ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_HOME_PAGE_MISSING', sprintf( 'Home-page policy target page "%s" does not exist.', $slug ) );
			}
			$values = array( 'show_on_front' => 'page', 'page_on_front' => (int) $page_values['ID'] );
		}
		if ( ! in_array( $mode, array( SiteHomePolicyMode::PAGE->value, SiteHomePolicyMode::LATEST_POSTS->value ), true ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_HOME_POLICY_INVALID', 'Compiled home-page policy mode is not supported.' );
		}

		foreach ( $values as $option => $value ) {
			$current = \get_option( $option, null );
			if ( $current === $value ) {
				continue;
			}
			if ( ! \update_option( $option, $value, false ) ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', sprintf( 'WordPress could not update reading option "%s".', $option ) );
			}
		}

		if ( ! $this->update_option_if_changed( self::HOME_POLICY_OPTION, \get_option( self::HOME_POLICY_OPTION, array() ), $record->to_array() ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', 'WordPress could not store the compiled home-page policy ownership snapshot.' );
		}

		return RegistrationResult::success();
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function home_policy_matches_native( array $payload ): bool {
		$mode = (string) ( $payload['mode'] ?? '' );
		if ( SiteHomePolicyMode::LATEST_POSTS->value === $mode ) {
			return 'posts' === (string) \get_option( 'show_on_front', 'posts' ) && 0 === (int) \get_option( 'page_on_front', 0 );
		}
		if ( SiteHomePolicyMode::PAGE->value !== $mode || ! isset( $payload['slug'] ) || ! \function_exists( 'get_page_by_path' ) ) {
			return false;
		}
		$page = \get_page_by_path( (string) $payload['slug'], 'OBJECT', 'page' );
		return 'page' === (string) \get_option( 'show_on_front', 'posts' )
			&& is_object( $page )
			&& (int) \get_option( 'page_on_front', 0 ) === (int) ( get_object_vars( $page )['ID'] ?? 0 );
	}

	private function stored_resource( string $identity ): ?SitePersistenceRecord {
		$stored = \get_option( self::RESOURCE_RECORDS_OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored[ $identity ] ) || ! is_array( $stored[ $identity ] ) ) {
			return null;
		}

		$record = $this->record_from_storage( $stored[ $identity ] );
		return null !== $record && in_array( $record->kind(), array( CompiledSiteResourceType::STYLE->value, CompiledSiteResourceType::ASSET->value ), true ) ? $record : null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function stored_resources(): array {
		$stored = \get_option( self::RESOURCE_RECORDS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$records = array();
		foreach ( $stored as $identity => $value ) {
			if ( ! is_string( $identity ) || ! is_array( $value ) ) {
				continue;
			}
			$record = $this->record_from_storage( $value );
			if ( null !== $record && in_array( $record->kind(), array( CompiledSiteResourceType::STYLE->value, CompiledSiteResourceType::ASSET->value ), true ) ) {
				$records[ $identity ] = $record->to_array();
			}
		}

		return $records;
	}

	private function stored_entity( string $identity ): ?SitePersistenceRecord {
		$stored = \get_option( self::ENTITY_RECORDS_OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored[ $identity ] ) || ! is_array( $stored[ $identity ] ) ) {
			return null;
		}

		return $this->record_from_storage( $stored[ $identity ] );
	}

	private function store_resource_snapshot( SitePersistenceRecord $record, ?array $records = null ): RegistrationResult {
		$records ??= \get_option( self::RESOURCE_RECORDS_OPTION, array() );
		if ( ! is_array( $records ) ) {
			$records = array();
		}
		$records[ $record->identity() ] = $record->to_array();
		$current = \get_option( self::RESOURCE_RECORDS_OPTION, array() );
		if ( ! $this->update_option_if_changed( self::RESOURCE_RECORDS_OPTION, is_array( $current ) ? $current : array(), $records ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', 'WordPress could not store the compiled resource ownership snapshot.' );
		}

		return RegistrationResult::success();
	}

	private function remove_resource_snapshot( string $identity ): RegistrationResult {
		$records = \get_option( self::RESOURCE_RECORDS_OPTION, array() );
		if ( ! is_array( $records ) || ! array_key_exists( $identity, $records ) ) {
			return RegistrationResult::success();
		}

		$next = $records;
		unset( $next[ $identity ] );
		if ( ! $this->update_option_if_changed( self::RESOURCE_RECORDS_OPTION, $records, $next ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', 'WordPress could not remove the compiled resource ownership snapshot.' );
		}

		return RegistrationResult::success();
	}

	private function store_entity_snapshot( SitePersistenceRecord $record ): RegistrationResult {
		$records = \get_option( self::ENTITY_RECORDS_OPTION, array() );
		if ( ! is_array( $records ) ) {
			$records = array();
		}
		$records[ $record->identity() ] = $record->to_array();
		$current = \get_option( self::ENTITY_RECORDS_OPTION, array() );
		if ( ! $this->update_option_if_changed( self::ENTITY_RECORDS_OPTION, is_array( $current ) ? $current : array(), $records ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', 'WordPress could not store the compiled entity ownership snapshot.' );
		}

		return RegistrationResult::success();
	}

	/**
	 * @param array<string, array<string, mixed>> $records
	 * @param array<string, mixed>                $current_stylesheets
	 * @return array{fragments: array<string, mixed>, stylesheets: array<string, mixed>, hashes: array<string, mixed>}
	 */
	private function build_stylesheet_state( array $records, array $current_stylesheets, string $touched_id ): array {
		$current_fragments = \get_option( self::BUILDER_STYLESHEET_FRAGMENTS_OPTION, array() );
		$current_fragments = is_array( $current_fragments ) ? $current_fragments : array();
		$owned_source_keys = array();

		foreach ( $records as $stored ) {
			if ( ! is_array( $stored ) || 'asset' !== (string) ( $stored['kind'] ?? '' ) || ! is_array( $stored['payload'] ?? null ) || 'stylesheet' !== (string) ( $stored['payload']['type'] ?? '' ) ) {
				continue;
			}
			$id = (string) ( $stored['payload']['id'] ?? '' );
			if ( '' === $id || $id !== $touched_id ) {
				continue;
			}
			foreach ( $this->asset_source_keys_from_storage( $stored ) as $source_key ) {
				$owned_source_keys[ $source_key ] = true;
			}
		}

		$fragments = $current_fragments;
		if ( ! isset( $fragments[ $touched_id ] ) || ! is_array( $fragments[ $touched_id ] ) ) {
			$fragments[ $touched_id ] = array();
		}
		foreach ( array_keys( $owned_source_keys ) as $source_key ) {
			unset( $fragments[ $touched_id ][ $source_key ] );
		}

		foreach ( $records as $stored ) {
			if ( ! is_array( $stored ) || 'asset' !== (string) ( $stored['kind'] ?? '' ) || ! is_array( $stored['payload'] ?? null ) || 'stylesheet' !== (string) ( $stored['payload']['type'] ?? '' ) ) {
				continue;
			}
			$payload = $stored['payload'];
			$id      = (string) ( $payload['id'] ?? '' );
			if ( '' === $id || $id !== $touched_id ) {
				continue;
			}
			foreach ( $this->asset_source_keys_from_storage( $stored ) as $source_key ) {
				$fragments[ $id ][ $source_key ] = array(
					'css'       => (string) ( $payload['css'] ?? '' ),
					'file_path' => (string) ( $payload['path'] ?? '' ),
				);
			}
		}

		$stylesheets = $current_stylesheets;
		$hashes      = \get_option( self::BUILDER_STYLESHEET_HASHES_OPTION, array() );
		$hashes      = is_array( $hashes ) ? $hashes : array();
		$css = $this->aggregate_fragment_css( is_array( $fragments[ $touched_id ] ?? null ) ? $fragments[ $touched_id ] : array() );
		$stylesheets[ $touched_id ] = array(
			'name' => $touched_id,
			'css'  => $css,
		);
		$hashes[ $touched_id ] = $this->payload_hash( $stylesheets[ $touched_id ] );

		return array(
			'fragments'   => $fragments,
			'stylesheets' => $stylesheets,
			'hashes'      => $hashes,
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $records
	 */
	private function aggregate_stylesheet_payload( array $records, string $stylesheet_id ): array {
		$state = $this->build_stylesheet_state( $records, (array) \get_option( self::GLOBAL_STYLESHEETS_OPTION, array() ), $stylesheet_id );
		return is_array( $state['stylesheets'][ $stylesheet_id ] ?? null ) ? $state['stylesheets'][ $stylesheet_id ] : array( 'name' => $stylesheet_id, 'css' => '' );
	}

	private function aggregate_fragment_css( array $sources ): string {
		$chunks = array();
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$css = rtrim( (string) ( $source['css'] ?? '' ) );
			if ( '' !== $css ) {
				$chunks[] = $css;
			}
		}

		return array() === $chunks ? '' : implode( "\n\n", $chunks ) . "\n";
	}

	private function has_owned_stylesheet_record( array $records, string $stylesheet_id ): bool {
		foreach ( $records as $stored ) {
			if ( ! is_array( $stored ) || false === (bool) ( $stored['owned'] ?? false ) || ! is_array( $stored['payload'] ?? null ) ) {
				continue;
			}
			if ( 'stylesheet' === (string) ( $stored['payload']['type'] ?? '' ) && $stylesheet_id === (string) ( $stored['payload']['id'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private function update_option_if_changed( string $option, mixed $current, mixed $next ): bool {
		if ( $current === $next ) {
			return true;
		}

		if ( ! \update_option( $option, $next, false ) ) {
			return \get_option( $option, null ) === $next;
		}

		return true;
	}

	/**
	 * Apply sequential option writes as one rollback-safe group: when a later
	 * write fails, every already-applied write is restored to its prior value.
	 *
	 * @param array<int, array{option: string, before: mixed, after: mixed}> $writes
	 * @return string|null The option whose write failed, or null when all writes succeeded.
	 */
	private function update_options_atomically( array $writes ): ?string {
		$applied = array();
		foreach ( $writes as $write ) {
			if ( ! $this->update_option_if_changed( $write['option'], $write['before'], $write['after'] ) ) {
				foreach ( array_reverse( $applied ) as $restore ) {
					\update_option( $restore['option'], $restore['before'], true );
				}

				return $write['option'];
			}

			if ( $write['before'] !== $write['after'] ) {
				$applied[] = $write;
			}
		}

		return null;
	}

	private function payload_hash( array $payload ): string {
		$encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
		return hash( 'sha256', false === $encoded ? serialize( $payload ) : $encoded );
	}

	private function unsupported( string $message ): RegistrationResult {
		return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_UNSUPPORTED', $message );
	}

	private function native_key_meta( string $kind ): string {
		return 'component' === $kind ? self::COMPONENT_KEY_META : self::PATTERN_KEY_META;
	}

	private function native_key( string $identity, string $kind ): string {
		return substr( $identity, strlen( $kind ) + 1 );
	}

	private function native_slug( string $kind, string $key ): string {
		$prefix = 'component' === $kind ? self::COMPONENT_SLUG_PREFIX : self::PATTERN_SLUG_PREFIX;

		return $prefix . \sanitize_key( $key );
	}

	private function post_id( object $post ): int {
		return (int) ( get_object_vars( $post )['ID'] ?? 0 );
	}

	private function option_name( string $identity ): string {
		return self::OPTION_PREFIX . hash( 'sha256', $identity );
	}

	/** {@inheritdoc} */
	public function acquire_site_apply_lock(): bool {
		return $this->acquire_claim( self::APPLY_LOCK_OPTION );
	}

	/** {@inheritdoc} */
	public function release_site_apply_lock(): bool {
		if ( ! \function_exists( 'delete_option' ) || ! \function_exists( 'get_option' ) ) {
			return false;
		}

		if ( \delete_option( self::APPLY_LOCK_OPTION ) ) {
			return true;
		}

		return null === \get_option( self::APPLY_LOCK_OPTION, null );
	}

	/**
	 * Retire stale Builder ownership of a native loop preset identity.
	 *
	 * A previous managed era may have left an owned entity ledger beside (or
	 * on top of) the native Etch record, which fails native verification with
	 * an ownership conflict. Retirement removes the stale ledger and any loop
	 * entry that still carries a self-verifying Builder stamp; a native entry
	 * without the stamp is kept untouched. Anything modified since the Builder
	 * wrote it fails closed and keeps the conflict for manual review.
	 */
	public function retire_owned_native_record( string $identity ): RegistrationResult {
		if ( ! str_starts_with( $identity, 'loop_preset:' ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_NATIVE_RETIREMENT_UNSUPPORTED', 'Native ownership retirement currently supports loop preset identities only.' );
		}
		if ( ! \function_exists( 'get_option' ) || ! \function_exists( 'update_option' ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_RUNTIME_UNAVAILABLE', 'The native Etch loop option capability is unavailable.' );
		}

		$stored = $this->stored_entity( $identity );
		if ( null === $stored || ! $stored->is_owned() ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_NATIVE_RETIREMENT_MISSING', 'No stale Builder ownership exists for this native identity.' );
		}

		$key  = substr( $identity, strlen( 'loop_preset:' ) );
		$loops = \get_option( self::LOOPS_OPTION, array() );
		$next  = is_array( $loops ) ? $loops : array();

		foreach ( $next as $option_key => $loop ) {
			if ( ! is_array( $loop ) || $key !== (string) ( $loop['key'] ?? '' ) ) {
				continue;
			}

			$stamp = $loop['_omide_builder_hash'] ?? null;
			if ( ! is_string( $stamp ) || '' === $stamp ) {
				continue;
			}

			$verified = $loop;
			unset( $verified['_omide_builder_hash'], $verified['id'] );
			$verified = $this->without_runtime_private_keys( $verified );
			if ( $this->payload_hash( $verified ) !== $stamp ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_NATIVE_RETIREMENT_DRIFT', 'The Builder-written loop entry changed after it was persisted; retirement requires manual review.' );
			}

			$ledger_payload = $stored->payload();
			unset( $ledger_payload['id'] );
			if ( $this->payload_hash( $this->without_runtime_private_keys( $ledger_payload ) ) !== $stamp ) {
				return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_NATIVE_RETIREMENT_DRIFT', 'The stale Builder ledger no longer matches the persisted loop entry; retirement requires manual review.' );
			}

			unset( $next[ (string) $option_key ] );
		}

		$ledger = \get_option( self::ENTITY_RECORDS_OPTION, array() );
		$ledger = is_array( $ledger ) ? $ledger : array();
		unset( $ledger[ $identity ] );

		$failed = $this->update_options_atomically( array(
			array(
				'option' => self::LOOPS_OPTION,
				'before' => $loops,
				'after'  => $next,
			),
			array(
				'option' => self::ENTITY_RECORDS_OPTION,
				'before' => \get_option( self::ENTITY_RECORDS_OPTION, array() ),
				'after'  => $ledger,
			),
		) );
		if ( null !== $failed ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_PARTIAL_WRITE', sprintf( 'WordPress could not update "%s" while retiring stale native ownership; prior writes were rolled back.', $failed ) );
		}

		return RegistrationResult::success();
	}

	private function claim_option_name( string $identity ): string {
		return self::CLAIM_PREFIX . hash( 'sha256', $identity );
	}

	/**
	 * Atomically claim one lock option, stealing claims abandoned by a crashed
	 * apply once they are older than the TTL. A claim without a timestamp is a
	 * leftover from a pre-2.0.1 crash: applies are serialized per site, so it
	 * cannot belong to a live process.
	 */
	private function acquire_claim( string $option ): bool {
		if ( ! \function_exists( 'add_option' ) || ! \function_exists( 'get_option' ) || ! \function_exists( 'delete_option' ) ) {
			return false;
		}

		if ( \add_option( $option, $this->claim_stamp(), '', false ) ) {
			return true;
		}

		if ( ! $this->claim_is_stale( \get_option( $option, null ) ) ) {
			return false;
		}

		if ( ! \delete_option( $option ) ) {
			return false;
		}

		return \add_option( $option, $this->claim_stamp(), '', false );
	}

	private function claim_stamp(): string {
		return self::OWNER_VALUE . ':' . time();
	}

	private function claim_is_stale( mixed $existing ): bool {
		if ( ! is_string( $existing ) ) {
			return false;
		}

		if ( self::OWNER_VALUE === $existing ) {
			return true;
		}

		$prefix = self::OWNER_VALUE . ':';
		if ( ! str_starts_with( $existing, $prefix ) ) {
			return false;
		}

		$claimed_at = (int) substr( $existing, strlen( $prefix ) );
		return $claimed_at > 0 && ( time() - $claimed_at ) > self::CLAIM_TTL_SECONDS;
	}

	private function release_claim( string $claim_name ): bool {
		if ( \delete_option( $claim_name ) ) {
			return true;
		}

		return null === \get_option( $claim_name, null );
	}

	/**
	 * Rehydrate only through the typed compiled value objects.
	 *
	 * @param array<string, mixed> $stored
	 */
	private function record_from_storage( array $stored ): ?SitePersistenceRecord {
		$identity = (string) ( $stored['identity'] ?? '' );
		$payload  = $stored['payload'] ?? null;
		$owned    = (bool) ( $stored['owned'] ?? false );
		$kind     = (string) ( $stored['kind'] ?? '' );
		$ownership_records = $stored['ownership'] ?? array();

		if ( ! is_array( $payload ) || ! is_array( $ownership_records ) ) {
			return null;
		}

		try {
			$ownership = array();
			foreach ( $ownership_records as $ownership_record ) {
				if ( ! is_array( $ownership_record ) ) {
					return null;
				}
				$ownership[] = CompiledSiteOwnership::new(
					(string) ( $ownership_record['owner'] ?? '' ),
					(string) ( $ownership_record['resource'] ?? '' ),
					(string) ( $ownership_record['role'] ?? '' )
				);
			}

			if ( 'home_policy' === $kind ) {
				return SitePersistenceRecord::from_serialized( $identity, $kind, $payload, $owned, $ownership );
			}

			$entity_type = CompiledSiteEntityType::tryFrom( $kind );
			if ( null !== $entity_type ) {
				return SitePersistenceRecord::from_entity( CompiledSiteEntity::new( $entity_type, $identity, $payload ), $owned, $ownership );
			}

			$resource_type = CompiledSiteResourceType::tryFrom( $kind );
			if ( null !== $resource_type ) {
				return SitePersistenceRecord::from_resource( CompiledSiteResource::new( $resource_type, $identity, $payload ), $owned, $ownership );
			}
		} catch ( Throwable ) {
			return null;
		}

		return null;
	}
}
