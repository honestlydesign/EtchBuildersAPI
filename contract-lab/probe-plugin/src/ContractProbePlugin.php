<?php
/**
 * Maintainer-only Contract Probe Plugin implementation.
 *
 * @package EtchBuildersContractProbe
 */

declare( strict_types=1 );

namespace EtchBuildersContractProbe;

/**
 * Exposes only the versioned normalized probe envelope.
 *
 * This scaffold deliberately does not inspect private Etch classes, copy Etch
 * code, or return arbitrary WordPress payloads. Later probes add observations
 * behind this same authorization and version boundary.
 */
final class ContractProbePlugin {

	public const REST_NAMESPACE = 'etch-builders-contract-lab/v1';

	public const REST_ROUTE = '/observe';

	public const PROBE_VERSION = '1.0';

	public const OBSERVATION_SCHEMA_VERSION = '1.0';

	public const MARKER_OPTION = 'etch_builders_contract_lab_marker';

	public const MARKER_VERSION = '1';

	public const LAB_ID = 'etch-builders-contract-lab';

	private const STYLES_OPTION = 'etch_styles';

	private const COMPONENT_KEY_META = 'etch_component_html_key';

	private const COMPONENT_PROPERTIES_META = 'etch_component_properties';

	private function __construct() {
	}

	/**
	 * Register only through WordPress' public REST hook.
	 */
	public static function register(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		}
	}

	/**
	 * Register the single normalized observation endpoint.
	 */
	public static function register_route(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'observe' ),
				'permission_callback' => array( self::class, 'authorize' ),
			)
		);
	}

	/**
	 * Require a local/development site, the marker, a logged-in user, and the
	 * maintainer capability before any observation callback can run.
	 */
	public static function authorize( mixed $request ): bool|object {
		unset( $request );
		if ( ! function_exists( 'wp_get_environment_type' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return self::error( 'environment', 'Contract Probe is available only on local or development WordPress.' );
		}
		if ( ! function_exists( 'is_multisite' ) || is_multisite() ) {
			return self::error( 'multisite', 'Contract Probe requires a single-site WordPress installation.' );
		}
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return self::error( 'authentication', 'Contract Probe requires an authenticated WordPress user.' );
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return self::error( 'capability', 'Contract Probe requires the manage_options capability.' );
		}
		if ( ! function_exists( 'get_option' ) ) {
			return self::error( 'marker', 'Contract Probe cannot verify the Contract Lab marker.' );
		}
		$marker = get_option( self::MARKER_OPTION, null );
		if ( ! is_array( $marker ) || ( $marker['marker_version'] ?? null ) !== self::MARKER_VERSION || ( $marker['lab_id'] ?? null ) !== self::LAB_ID ) {
			return self::error( 'marker', 'Contract Probe requires the matching Contract Lab marker.' );
		}

		return true;
	}

	/**
	 * Return the normalized public-surface envelope after authorization and
	 * version checks.
	 */
	public static function observe( mixed $request ): object {
		$probe_version       = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'probe_version' ) : null;
		$observation_version = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( 'observation_schema_version' ) : null;
		if ( ! self::supports_versions( $probe_version, $observation_version ) ) {
			return self::error( 'schema', 'Contract Probe rejects unknown probe or observation schema versions.' );
		}

		try {
			$style_ids      = self::request_list( $request, 'style_ids' );
			$component_keys = self::request_list( $request, 'component_keys' );
			$document_slugs = self::request_list( $request, 'document_slugs' );
			$observations   = self::collect_observations( $style_ids, $component_keys, $document_slugs );
		} catch ( \Throwable $error ) {
			return self::error( 'observation', $error->getMessage() ?: 'Contract Probe could not produce a normalized observation.', 422 );
		}

		$status = 'inconclusive' === ( $observations['etch_runtime_resolution']['status'] ?? null ) ? 'inconclusive' : 'observed';
		if ( class_exists( '\WP_REST_Response' ) ) {
			return new \WP_REST_Response(
				array(
					'probe_version'                => self::PROBE_VERSION,
					'observation_schema_version'   => self::OBSERVATION_SCHEMA_VERSION,
					'status'                       => $status,
					'observations'                 => $observations,
				),
				'inconclusive' === $status ? 503 : 200
			);
		}

		return (object) array(
			'probe_version'              => self::PROBE_VERSION,
			'observation_schema_version' => self::OBSERVATION_SCHEMA_VERSION,
			'status'                     => $status,
			'observations'               => $observations,
		);
	}

	/**
	 * Observe only the requested Builder-owned persistence facts and public
	 * runtime style resolutions. WordPress IDs are used internally for lookup
	 * and never cross the normalized response boundary.
	 *
	 * @param array<int, mixed> $style_ids
	 * @param array<int, mixed> $component_keys
	 * @param array<int, mixed> $document_slugs
	 * @return array{persistence_handoff: array<string, mixed>, etch_runtime_resolution: array<string, mixed>}
	 */
	public static function collect_observations( array $style_ids, array $component_keys, array $document_slugs = array() ): array {
		$style_ids      = self::normalize_list( $style_ids, 'style IDs', '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D' );
		$component_keys = self::normalize_list( $component_keys, 'component keys', '/^[A-Za-z][A-Za-z0-9_-]*$/D' );
		$document_slugs = self::normalize_list( $document_slugs, 'document slugs', '/^[a-z0-9][a-z0-9_-]*$/D' );
		if ( array() === $style_ids && array() === $component_keys && array() === $document_slugs ) {
			throw new \InvalidArgumentException( 'Contract Probe requires at least one explicit style, component, or document target.' );
		}
		foreach ( array( 'get_option', 'get_posts', 'get_post_meta', 'parse_blocks' ) as $function ) {
			if ( ! function_exists( $function ) ) {
				throw new \RuntimeException( sprintf( 'Contract Probe public WordPress surface "%s" is unavailable.', $function ) );
			}
		}

		$stored_styles = get_option( self::STYLES_OPTION, array() );
		if ( ! is_array( $stored_styles ) ) {
			throw new \RuntimeException( 'Contract Probe Etch style persistence is unavailable.' );
		}

		$styles = array();
		foreach ( $style_ids as $style_id ) {
			$style = $stored_styles[ $style_id ] ?? null;
			if ( ! is_array( $style ) || ! is_string( $style['type'] ?? null ) || ! is_string( $style['selector'] ?? null ) ) {
				throw new \RuntimeException( sprintf( 'Contract Probe could not observe exact style ID "%s" from the Builder persistence handoff.', $style_id ) );
			}
			self::assert_style_shape( $style_id, $style['type'], $style['selector'] );
			$styles[] = array(
				'opaque_id' => $style_id,
				'type'      => $style['type'],
				'selector'  => $style['selector'],
			);
		}

		$component_posts = self::component_posts( $component_keys );
		$components      = array();
		$component_ids   = array();
		foreach ( $component_posts as $component_key => $post ) {
			$values = get_object_vars( $post );
			$post_id = (int) ( $values['ID'] ?? 0 );
			if ( $post_id < 1 ) {
				throw new \RuntimeException( sprintf( 'Contract Probe component "%s" has no usable public WordPress identity.', $component_key ) );
			}
			$properties = get_post_meta( $post_id, self::COMPONENT_PROPERTIES_META, true );
			if ( ! is_array( $properties ) || ! array_is_list( $properties ) ) {
				throw new \RuntimeException( sprintf( 'Contract Probe component "%s" has malformed public property schema.', $component_key ) );
			}
			$content = (string) ( $values['post_content'] ?? '' );
			$parsed  = parse_blocks( $content );
			$slots   = self::slot_names( $parsed );
			$components[] = array(
				'component_key' => $component_key,
				'properties'    => $properties,
				'slots'         => $slots,
				'instances'     => array(),
			);
			$component_ids[ $post_id ] = $component_key;
		}

		if ( array() !== $document_slugs ) {
			$instances = self::document_instances( $document_slugs, $component_ids );
			foreach ( $components as &$component ) {
				$component['instances'] = $instances[ $component['component_key'] ] ?? array();
			}
			unset( $component );
		}

		$runtime = self::runtime_style_observation( $styles, count( $component_keys ) );

		return array(
			'persistence_handoff' => array(
				'observation_version' => '1',
				'source'             => 'builder_handoff',
				'styles'             => $styles,
				'components'         => $components,
			),
			'etch_runtime_resolution' => $runtime,
		);
	}

	/**
	 * Keep unknown versions fail-closed without requiring WordPress in unit
	 * tests or in package-side contract tooling.
	 */
	public static function supports_versions( mixed $probe_version, mixed $observation_version ): bool {
		return is_string( $probe_version ) && self::PROBE_VERSION === $probe_version && is_string( $observation_version ) && self::OBSERVATION_SCHEMA_VERSION === $observation_version;
	}

	/**
	 * Document the only files owned by this plugin scaffold.
	 *
	 * @return array<int, string>
	 */
	public static function owned_files(): array {
		return array( 'contract-probe-plugin.php', 'src/ContractProbePlugin.php', 'README.md' );
	}

	/**
	 * @return array<int, string>
	 */
	private static function request_list( mixed $request, string $key ): array {
		$value = is_object( $request ) && method_exists( $request, 'get_param' ) ? $request->get_param( $key ) : array();
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			throw new \InvalidArgumentException( sprintf( 'Contract Probe request field "%s" must be an ordered list.', $key ) );
		}

		return $value;
	}

	/**
	 * @param array<int, mixed> $values
	 * @return array<int, string>
	 */
	private static function normalize_list( array $values, string $label, string $pattern ): array {
		if ( ! array_is_list( $values ) ) {
			throw new \InvalidArgumentException( sprintf( 'Contract Probe %s must be an ordered list.', $label ) );
		}
		$normalized = array();
		$seen       = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || trim( $value ) !== $value || 1 !== preg_match( $pattern, $value ) || isset( $seen[ $value ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Contract Probe %s must contain unique stable identifiers.', $label ) );
			}
			$seen[ $value ] = true;
			$normalized[]   = $value;
		}

		return $normalized;
	}

	private static function assert_style_shape( string $opaque_id, string $type, string $selector ): void {
		if ( '' === $type || trim( $type ) !== $type || 1 !== preg_match( '/^[a-z][a-z0-9_-]*$/D', $type ) || '' === $selector || trim( $selector ) !== $selector || 1 === preg_match( '/[\x00-\x1F\x7F]/', $selector ) ) {
			throw new \RuntimeException( sprintf( 'Contract Probe style "%s" has a malformed public selector or type.', $opaque_id ) );
		}
		if ( 'class' === $type && ( 1 !== preg_match( '/^\.([A-Za-z_][A-Za-z0-9_-]*)$/D', $selector ) || $opaque_id === $selector || $opaque_id === substr( $selector, 1 ) ) ) {
			throw new \RuntimeException( sprintf( 'Contract Probe style "%s" does not keep opaque identity separate from its class selector.', $opaque_id ) );
		}
	}

	/**
	 * @param array<int, string> $component_keys
	 * @return array<string, object>
	 */
	private static function component_posts( array $component_keys ): array {
		$posts = array();
		foreach ( $component_keys as $component_key ) {
			$matches = get_posts(
				array(
					'post_type'      => 'wp_block',
					'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
					'posts_per_page' => -1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => self::COMPONENT_KEY_META,
					'meta_value'     => $component_key,
				)
			);
			$matches = is_array( $matches ) ? array_values( array_filter( $matches, 'is_object' ) ) : array();
			if ( 1 !== count( $matches ) ) {
				throw new \RuntimeException( sprintf( 'Contract Probe requires exactly one public component definition for "%s".', $component_key ) );
			}
			$posts[ $component_key ] = $matches[0];
		}

		return $posts;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, string>
	 */
	private static function slot_names( array $blocks ): array {
		$slots = array();
		$seen  = array();
		self::collect_slot_names( $blocks, $slots, $seen );

		return $slots;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, string>               $slots
	 * @param array<string, true>              $seen
	 */
	private static function collect_slot_names( array $blocks, array &$slots, array &$seen ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				throw new \RuntimeException( 'Contract Probe received a malformed public WordPress block tree.' );
			}
			if ( 'etch/slot-placeholder' === ( $block['blockName'] ?? null ) ) {
				$name = $block['attrs']['name'] ?? null;
				if ( ! is_string( $name ) || '' === $name || trim( $name ) !== $name || isset( $seen[ $name ] ) ) {
					throw new \RuntimeException( 'Contract Probe received a malformed or duplicate exact component slot.' );
				}
				$seen[ $name ] = true;
				$slots[]       = $name;
			}
			$children = $block['innerBlocks'] ?? array();
			if ( ! is_array( $children ) ) {
				throw new \RuntimeException( 'Contract Probe received a malformed public WordPress inner block tree.' );
			}
			self::collect_slot_names( $children, $slots, $seen );
		}
	}

	/**
	 * @param array<int, string> $document_slugs
	 * @param array<int, string> $component_ids
	 * @return array<string, array<int, array{attributes: array<string, mixed>, slots: array<int, array{name: string, blocks: array<int, string>}>}>>
	 */
	private static function document_instances( array $document_slugs, array $component_ids ): array {
		$instances = array();
		foreach ( $document_slugs as $slug ) {
			$posts = get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'name'           => $slug,
				)
			);
			if ( ! is_array( $posts ) || 1 !== count( $posts ) || ! is_object( $posts[0] ) ) {
				throw new \RuntimeException( sprintf( 'Contract Probe requires exactly one document for "%s".', $slug ) );
			}
			$post_values = get_object_vars( $posts[0] );
			$blocks      = parse_blocks( (string) ( $post_values['post_content'] ?? '' ) );
			self::collect_component_instances( $blocks, $component_ids, $instances );
		}

		return $instances;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, string>               $component_ids
	 * @param array<string, array<int, array{attributes: array<string, mixed>, slots: array<int, array{name: string, blocks: array<int, string>}>}>> $instances
	 */
	private static function collect_component_instances( array $blocks, array $component_ids, array &$instances ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				throw new \RuntimeException( 'Contract Probe received a malformed component instance block.' );
			}
			if ( 'etch/component' === ( $block['blockName'] ?? null ) ) {
				$attrs = $block['attrs'] ?? null;
				$ref   = is_array( $attrs ) ? $attrs['ref'] ?? null : null;
				if ( ! is_int( $ref ) && ! ( is_string( $ref ) && ctype_digit( $ref ) ) ) {
					throw new \RuntimeException( 'Contract Probe component instance has no public component reference.' );
				}
				$key = $component_ids[ (int) $ref ] ?? null;
				if ( null === $key ) {
					throw new \RuntimeException( 'Contract Probe component instance reference does not resolve to a requested component.' );
				}
				$property_attributes = is_array( $attrs ) ? $attrs : array();
				unset( $property_attributes['ref'], $property_attributes['attributes'] );
				$slots = array();
				foreach ( $block['innerBlocks'] ?? array() as $child ) {
					if ( ! is_array( $child ) || 'etch/slot-content' !== ( $child['blockName'] ?? null ) ) {
						continue;
					}
					$name = $child['attrs']['name'] ?? null;
					if ( ! is_string( $name ) || '' === $name || trim( $name ) !== $name ) {
						throw new \RuntimeException( 'Contract Probe component instance has a malformed exact slot assignment.' );
					}
					$child_blocks = array();
					foreach ( $child['innerBlocks'] ?? array() as $slot_child ) {
						if ( is_array( $slot_child ) && is_string( $slot_child['blockName'] ?? null ) ) {
							$child_blocks[] = $slot_child['blockName'];
						}
					}
					$slots[] = array( 'name' => $name, 'blocks' => $child_blocks );
				}
				$instances[ $key ][] = array( 'attributes' => $property_attributes, 'slots' => $slots );
			}
			$children = $block['innerBlocks'] ?? array();
			if ( is_array( $children ) ) {
				self::collect_component_instances( $children, $component_ids, $instances );
			}
		}
	}

	/**
	 * @param array<int, array{opaque_id: string, type: string, selector: string}> $styles
	 * @return array<string, mixed>
	 */
	private static function runtime_style_observation( array $styles, int $requested_component_count ): array {
		if ( $requested_component_count > 0 ) {
			return array(
				'observation_version' => '1',
				'source'             => 'etch_runtime_resolution',
				'status'             => 'inconclusive',
				'styles'             => array(),
				'components'         => array(),
				'reason'             => 'Etch public component resolution surface is unavailable; component runtime evidence cannot be claimed.',
			);
		}

		$style_class = implode( '\\', array( 'Etch', 'Blocks', 'Global', 'StylesRegister' ) );
		if ( ! class_exists( $style_class ) || ! method_exists( $style_class, 'get_style_by_id' ) ) {
			return array(
				'observation_version' => '1',
				'source'             => 'etch_runtime_resolution',
				'status'             => 'inconclusive',
				'styles'             => array(),
				'components'         => array(),
				'reason'             => 'Etch public StylesRegister resolution surface is unavailable.',
			);
		}

		$resolved = array();
		foreach ( $styles as $style ) {
			$runtime_style = $style_class::get_style_by_id( $style['opaque_id'] );
			if ( ! is_array( $runtime_style ) || (string) ( $runtime_style['selector'] ?? '' ) !== $style['selector'] ) {
				throw new \RuntimeException( sprintf( 'Etch public runtime did not resolve style ID "%s" to the Builder selector.', $style['opaque_id'] ) );
			}
			$resolved[] = array( 'opaque_id' => $style['opaque_id'], 'selector' => $runtime_style['selector'] );
		}

		return array(
			'observation_version' => '1',
			'source'             => 'etch_runtime_resolution',
			'status'             => 'observed',
			'styles'             => $resolved,
			'components'         => array(),
		);
	}

	private static function error( string $code, string $message, int $status = 403 ): object {
		if ( class_exists( '\WP_Error' ) ) {
			return new \WP_Error( 'etch_contract_probe_' . $code, $message, array( 'status' => $status ) );
		}

		return (object) array(
			'code'    => 'etch_contract_probe_' . $code,
			'message' => $message,
		);
	}
}
