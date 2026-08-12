<?php
/**
 * Compatibility coverage for the pre-compiled registrar lane.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit {

	use HonestlyDesign\EtchBuilders\Component;
	use HonestlyDesign\EtchBuilders\ComponentRegistrar;
	use HonestlyDesign\EtchBuilders\Contracts\ModeProviderInterface;
	use HonestlyDesign\EtchBuilders\Environment;
	use HonestlyDesign\EtchBuilders\Pattern;
	use HonestlyDesign\EtchBuilders\PatternRegistrar;
	use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
	use HonestlyDesign\EtchBuilders\Support\NullComponentRefResolver;
	use HonestlyDesign\EtchBuilders\Support\NullStorage;
	use PHPUnit\Framework\TestCase;

	/**
	 * Proves the old component registrar still discovers definitions in order.
	 */
	final class LegacyRegistrarFirstComponent {

		/** @var array<int, string> */
		public static array $built = array();

		public static function build(): Component {
			self::$built[] = 'first';

			return Component::new( 'First legacy component', 'Compatibility fixture.' )
				->key( 'FirstLegacyComponent' )
				->blocks( '<!-- wp:paragraph --><p>First.</p><!-- /wp:paragraph -->' );
		}
	}

	/**
	 * Proves the old component registrar still discovers definitions in order.
	 */
	final class LegacyRegistrarSecondComponent {

		/** @var array<int, string> */
		public static array $built = array();

		public static function build(): Component {
			LegacyRegistrarFirstComponent::$built[] = 'second';

			return Component::new( 'Second legacy component', 'Compatibility fixture.' )
				->key( 'SecondLegacyComponent' )
				->blocks( '<!-- wp:paragraph --><p>Second.</p><!-- /wp:paragraph -->' );
		}
	}

	/**
	 * Pattern fixture for the legacy registrar definition list.
	 */
	final class LegacyRegistrarFirstPattern {

		public static function build(): Pattern {
			return Pattern::new( 'First legacy pattern', 'Compatibility fixture.' )
				->key( 'FirstLegacyPattern' )
				->blocks( '<!-- wp:paragraph --><p>First.</p><!-- /wp:paragraph -->' );
		}
	}

	/**
	 * Pattern fixture for the legacy registrar definition list.
	 */
	final class LegacyRegistrarSecondPattern {

		public static function build(): Pattern {
			return Pattern::new( 'Second legacy pattern', 'Compatibility fixture.' )
				->key( 'SecondLegacyPattern' )
				->blocks( '<!-- wp:paragraph --><p>Second.</p><!-- /wp:paragraph -->' );
		}
	}

	/**
	 * Enables the legacy DEV-only pattern registrar lane for this test.
	 */
	final class LegacyRegistrarDevMode implements ModeProviderInterface {

		public function is_dev_mode(): bool {
			return true;
		}
	}

	/**
	 * Keeps legacy registrar compatibility explicit without making it the plan
	 * persistence entry point.
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class LegacyRegistrarCompatibilityTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			ini_set( 'error_log', '/dev/null' );
			$this->install_wordpress_stubs();
			$GLOBALS['etch_builders_legacy_registrar_posts']  = array();
			$GLOBALS['etch_builders_legacy_registrar_meta']   = array();
			$GLOBALS['etch_builders_legacy_registrar_events'] = array();
			$GLOBALS['etch_builders_legacy_registrar_next_id'] = 1;
			Environment::configure( new NullStorage(), new LegacyRegistrarDevMode(), new NullAssetRegistry(), new NullComponentRefResolver() );
			LegacyRegistrarFirstComponent::$built = array();
		}

		protected function tearDown(): void {
			Environment::reset();
			parent::tearDown();
		}

		public function test_legacy_registrar_definition_order_remains_compatible_and_separate_from_compiled_apply(): void {
			$component_report = ComponentRegistrar::new(
				array( LegacyRegistrarFirstComponent::class, LegacyRegistrarSecondComponent::class )
			)->register_components();
			$pattern_report = PatternRegistrar::new(
				array(
					array(
						'class'              => LegacyRegistrarFirstPattern::class,
						'key'                => 'FirstLegacyPattern',
						'required_components' => array( 'FirstLegacyComponent' ),
					),
					array(
						'class'              => LegacyRegistrarSecondPattern::class,
						'key'                => 'SecondLegacyPattern',
						'required_components' => array( 'FirstLegacyComponent' ),
					),
				)
			)->register_patterns( $component_report['registered_keys'] );

			self::assertSame( array( 'first', 'second' ), LegacyRegistrarFirstComponent::$built );
			self::assertSame( array( 'FirstLegacyComponent', 'SecondLegacyComponent' ), $component_report['registered_keys'] );
			self::assertSame( array( 'FirstLegacyPattern', 'SecondLegacyPattern' ), $pattern_report['registered_keys'] );
			self::assertSame( array( 'component', 'component', 'pattern', 'pattern' ), $GLOBALS['etch_builders_legacy_registrar_events'] );
		}

		private function install_wordpress_stubs(): void {
			eval(
				<<<'PHP'
	namespace {
		if ( ! class_exists( 'WP_Error', false ) ) {
			final class WP_Error {
				public function __construct( private string $code, private string $message ) {}

				public function get_error_code(): string { return $this->code; }

				public function get_error_message(): string { return $this->message; }
			}
		}

		if ( ! defined( 'OBJECT' ) ) {
			define( 'OBJECT', 'OBJECT' );
		}

		if ( ! function_exists( 'sanitize_key' ) ) {
			function sanitize_key( string $key ): string {
				return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) ) ?? '';
			}
		}

		if ( ! function_exists( 'sanitize_text_field' ) ) {
			function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
		}

		if ( ! function_exists( 'wp_slash' ) ) {
			function wp_slash( mixed $value ): mixed { return $value; }
		}

		if ( ! function_exists( 'get_page_by_path' ) ) {
			function get_page_by_path( string $slug, mixed $output = OBJECT, string $post_type = 'page' ): ?object {
				foreach ( $GLOBALS['etch_builders_legacy_registrar_posts'] ?? array() as $post ) {
					if ( ( $post->post_name ?? '' ) === $slug && ( $post->post_type ?? '' ) === $post_type ) {
						return $post;
					}
				}

				return null;
			}
		}

		if ( ! function_exists( 'get_post' ) ) {
			function get_post( int $post_id ): ?object {
				return $GLOBALS['etch_builders_legacy_registrar_posts'][ $post_id ] ?? null;
			}
		}

		if ( ! function_exists( 'get_posts' ) ) {
			function get_posts( array $args ): array {
				$key   = $args['meta_query'][0]['key'] ?? null;
				$value = $args['meta_query'][0]['value'] ?? null;
				$ids   = array();

				foreach ( $GLOBALS['etch_builders_legacy_registrar_posts'] ?? array() as $post_id => $post ) {
					if ( null !== $key && ( $GLOBALS['etch_builders_legacy_registrar_meta'][ $post_id ][ $key ] ?? null ) !== $value ) {
						continue;
					}
					$ids[] = (int) $post_id;
				}

				return 'ids' === ( $args['fields'] ?? '' ) ? $ids : array_map( 'get_post', $ids );
			}
		}

		if ( ! function_exists( 'wp_insert_post' ) ) {
			function wp_insert_post( array $post_data, bool $wp_error = false ): int {
				$id = (int) $GLOBALS['etch_builders_legacy_registrar_next_id']++;
				$post_data['ID'] = $id;
				$GLOBALS['etch_builders_legacy_registrar_posts'][ $id ] = (object) $post_data;
				$GLOBALS['etch_builders_legacy_registrar_events'][] = str_starts_with( $post_data['post_name'], 'omide-component-' ) ? 'component' : 'pattern';

				return $id;
			}
		}

		if ( ! function_exists( 'update_post_meta' ) ) {
			function update_post_meta( int $post_id, string $key, mixed $value ): bool {
				$GLOBALS['etch_builders_legacy_registrar_meta'][ $post_id ][ $key ] = $value;

				return true;
			}
		}

		if ( ! function_exists( 'get_post_meta' ) ) {
			function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
				return $GLOBALS['etch_builders_legacy_registrar_meta'][ $post_id ][ $key ] ?? '';
			}
		}

		if ( ! function_exists( 'taxonomy_exists' ) ) {
			function taxonomy_exists( string $taxonomy ): bool { return false; }
		}

		if ( ! function_exists( 'wp_delete_post' ) ) {
			function wp_delete_post( int $post_id, bool $force_delete = false ): ?object {
				$post = $GLOBALS['etch_builders_legacy_registrar_posts'][ $post_id ] ?? null;
				unset( $GLOBALS['etch_builders_legacy_registrar_posts'][ $post_id ] );

				return $post;
			}
		}
}
PHP
			);
		}
	}
}
