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
use HonestlyDesign\EtchBuilders\CompiledSiteResource;
use HonestlyDesign\EtchBuilders\CompiledSiteResourceType;
use HonestlyDesign\EtchBuilders\Contracts\SitePersistenceStoreInterface;
use HonestlyDesign\EtchBuilders\RegistrationResult;
use HonestlyDesign\EtchBuilders\SitePersistenceRecord;
use Throwable;

/**
 * Contains all WordPress function calls for compiled Site persistence.
 *
 * Components and Patterns use native wp_block records and their existing Etch
 * metadata. Other records retain the isolated option store until their
 * dedicated persistence handlers are introduced by later Wayfinder tickets.
 */
final class WordPressSitePersistenceStore implements SitePersistenceStoreInterface {

	private const OPTION_PREFIX = 'etch_builders_site_record_';

	private const CLAIM_PREFIX = 'etch_builders_site_claim_';

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

	public function find( string $identity ): ?SitePersistenceRecord {
		$kind = $this->native_kind( $identity );
		if ( null !== $kind ) {
			$posts = $this->find_native_posts( $identity, $kind );
			if ( array() === $posts ) {
				return null;
			}

			return $this->record_from_native_post( $identity, $kind, $posts[0], count( $posts ) > 1 );
		}

		$stored = \get_option( $this->option_name( $identity ), null );

		if ( ! is_array( $stored ) || (string) ( $stored['identity'] ?? '' ) !== $identity ) {
			return null;
		}

		return $this->record_from_storage( $stored );
	}

	public function create( SitePersistenceRecord $record ): RegistrationResult {
		$kind = $this->native_kind( $record->identity() );
		if ( null !== $kind ) {
			return $this->persist_native( $record, $kind, false );
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
		if ( null !== $kind ) {
			return $this->persist_native( $record, $kind, true );
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

	private function persist_native( SitePersistenceRecord $record, string $kind, bool $update ): RegistrationResult {
		if ( ! $update ) {
			$claim_name = $this->claim_option_name( $record->identity() );
			if ( ! \add_option( $claim_name, self::OWNER_VALUE, '', false ) ) {
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
		if ( self::OWNER_VALUE !== (string) \get_post_meta( $this->post_id( $existing ), self::OWNER_META, true ) ) {
			return RegistrationResult::error( 'ETCH_SITE_PERSISTENCE_CONFLICT', 'Existing native Site entity is not owned by this builder.' );
		}

		return $this->persist_native_post( $record, $kind, true, $this->post_id( $existing ) );
	}

	private function persist_native_create_claimed( SitePersistenceRecord $record, string $kind ): RegistrationResult {
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
		$post_data = $this->native_post_data( $record, $kind );
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
	private function native_post_data( SitePersistenceRecord $record, string $kind ): array {
		$payload = $record->payload();
		$key     = $this->native_key( $record->identity(), $kind );

		return array(
			'post_type'    => self::BLOCK_POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => \sanitize_text_field( (string) ( $payload['name'] ?? $key ) ),
			'post_name'    => $this->native_slug( $kind, $key ),
			'post_excerpt' => \sanitize_text_field( (string) ( $payload['description'] ?? '' ) ),
			'post_content' => \wp_slash( (string) ( $payload['blocks'] ?? '' ) ),
		);
	}

	private function write_native_metadata( int $post_id, SitePersistenceRecord $record, string $kind ): RegistrationResult {
		$payload = $record->payload();
		$key     = $this->native_key( $record->identity(), $kind );

		$metadata = array(
			array( $this->native_key_meta( $kind ), \sanitize_text_field( $key ) ),
			array( self::OWNER_META, self::OWNER_VALUE ),
		);
		if ( 'component' === $kind ) {
			$metadata[] = array( self::COMPONENT_PROPERTIES_META, is_array( $payload['properties'] ?? null ) ? $payload['properties'] : array() );
		} else {
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
		$type = 'component' === $kind ? CompiledSiteEntityType::COMPONENT : CompiledSiteEntityType::PATTERN;
		$entity = CompiledSiteEntity::new( $type, $record->identity(), $this->native_payload_from_record( $record, $kind ) );

		return SitePersistenceRecord::from_entity( $entity );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function native_payload_from_record( SitePersistenceRecord $record, string $kind ): array {
		$payload = $record->payload();
		$native  = array(
			'post_status' => 'publish',
			'post_name'   => $this->native_slug( $kind, $this->native_key( $record->identity(), $kind ) ),
			'name'        => \sanitize_text_field( (string) ( $payload['name'] ?? $this->native_key( $record->identity(), $kind ) ) ),
			'description' => \sanitize_text_field( (string) ( $payload['description'] ?? '' ) ),
			'blocks'      => (string) ( $payload['blocks'] ?? '' ),
		);

		if ( 'component' === $kind ) {
			$native['properties'] = is_array( $payload['properties'] ?? null ) ? $payload['properties'] : array();
		} else {
			$native['categories']   = $this->normalize_categories( is_array( $payload['categories'] ?? null ) ? $payload['categories'] : array() );
			$native['sync_status']  = self::PATTERN_SYNC_STATUS;
		}

		return $native;
	}

	private function find_native_posts( string $identity, string $kind ): array {
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

		$owned  = ! $force_unowned && self::OWNER_VALUE === (string) \get_post_meta( $post_id, self::OWNER_META, true );
		$native = $this->native_record_from_post( $identity, $kind, $post, $owned );
		if ( null === $native ) {
			return null;
		}

		if ( $owned ) {
			$snapshot = \get_post_meta( $post_id, self::SNAPSHOT_META, true );
			$stored   = is_array( $snapshot ) ? $this->record_from_storage( $snapshot ) : null;
			$native_fingerprint = (string) \get_post_meta( $post_id, self::NATIVE_FINGERPRINT_META, true );
			if ( null !== $stored
				&& $stored->is_owned()
				&& $stored->identity() === $identity
				&& $stored->kind() === ( 'component' === $kind ? CompiledSiteEntityType::COMPONENT->value : CompiledSiteEntityType::PATTERN->value )
				&& $native_fingerprint === $native->fingerprint()
			) {
				return $stored;
			}
		}

		return $native;
	}

	private function native_record_from_post( string $identity, string $kind, object $post, bool $owned ): ?SitePersistenceRecord {
		$type = 'component' === $kind ? CompiledSiteEntityType::COMPONENT : CompiledSiteEntityType::PATTERN;
		try {
			return SitePersistenceRecord::from_entity(
				CompiledSiteEntity::new( $type, $identity, $this->native_payload_from_post( $kind, $post ) ),
				$owned
			);
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function native_payload_from_post( string $kind, object $post ): array {
		$post_values = get_object_vars( $post );
		$post_id     = (int) ( $post_values['ID'] ?? 0 );
		$payload     = array(
			'post_status' => (string) ( $post_values['post_status'] ?? '' ),
			'post_name'   => (string) ( $post_values['post_name'] ?? '' ),
			'name'        => (string) ( $post_values['post_title'] ?? '' ),
			'description' => (string) ( $post_values['post_excerpt'] ?? '' ),
			'blocks'      => (string) ( $post_values['post_content'] ?? '' ),
		);

		if ( 'component' === $kind ) {
			$properties         = \get_post_meta( $post_id, self::COMPONENT_PROPERTIES_META, true );
			$payload['properties'] = is_array( $properties ) ? $properties : array();
		} else {
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
		foreach ( array( 'component', 'pattern' ) as $kind ) {
			if ( str_starts_with( $identity, $kind . ':' ) ) {
				return $kind;
			}
		}

		return null;
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

	private function claim_option_name( string $identity ): string {
		return self::CLAIM_PREFIX . hash( 'sha256', $identity );
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

		if ( ! is_array( $payload ) ) {
			return null;
		}

		try {
			$entity_type = CompiledSiteEntityType::tryFrom( $kind );
			if ( null !== $entity_type ) {
				return SitePersistenceRecord::from_entity( CompiledSiteEntity::new( $entity_type, $identity, $payload ), $owned );
			}

			$resource_type = CompiledSiteResourceType::tryFrom( $kind );
			if ( null !== $resource_type ) {
				return SitePersistenceRecord::from_resource( CompiledSiteResource::new( $resource_type, $identity, $payload ), $owned );
			}
		} catch ( Throwable ) {
			return null;
		}

		return null;
	}
}
