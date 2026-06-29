<?php
/**
 * ComponentRegistrar tests.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders\Tests\Unit {

	use HonestlyDesign\EtchBuilders\Component;
	use HonestlyDesign\EtchBuilders\ComponentRegistrar;
	use HonestlyDesign\EtchBuilders\Contracts\ModeProviderInterface;
	use HonestlyDesign\EtchBuilders\Contracts\StorageInterface;
	use HonestlyDesign\EtchBuilders\Environment;
	use HonestlyDesign\EtchBuilders\Support\NullAssetRegistry;
	use HonestlyDesign\EtchBuilders\Support\NullComponentRefResolver;
	use HonestlyDesign\EtchBuilders\Support\NullStorage;
	use PHPUnit\Framework\TestCase;
	use WP_Error;

	/**
	 * Component used by registrar report tests.
	 */
	final class ComponentRegistrarDevOnlyComponent {
		public static function build(): Component {
			return Component::new( 'Dev Only Registrar Component', 'Skipped outside dev mode.' )
				->key( 'DevOnlyRegistrarComponent' )
				->dev_only()
				->blocks( '<!-- wp:paragraph --><p>Dev only component.</p><!-- /wp:paragraph -->' );
		}
	}

	/**
	 * ComponentRegistrar contract tests.
	 *
	 * @runTestsInSeparateProcesses
	 * @preserveGlobalState disabled
	 */
	final class ComponentRegistrarTest extends TestCase {

		public function test_register_skips_dev_only_components_without_persisting_in_prod_mode(): void {
			Environment::configure(
				new NullStorage(),
				new ComponentRegistrarMode( false ),
				new NullAssetRegistry(),
				new NullComponentRefResolver()
			);

			$result = ComponentRegistrar::new( array() )->register(
				Component::new( 'Dev Only Component', 'Skipped outside development mode.' )
					->key( 'DevOnlyComponent' )
					->dev_only()
					->blocks( '<!-- wp:paragraph --><p>Dev only.</p><!-- /wp:paragraph -->' )
			);

			self::assertSame( 0, $result );
			self::assertSame( 0, $GLOBALS['etch_builders_component_registrar_insert_calls'] );
		}

		public function test_register_components_does_not_report_dev_only_components_as_registered_in_prod_mode(): void {
			Environment::configure(
				new NullStorage(),
				new ComponentRegistrarMode( false ),
				new NullAssetRegistry(),
				new NullComponentRefResolver()
			);

			$report = ComponentRegistrar::new( array( ComponentRegistrarDevOnlyComponent::class ) )->register_components();

			self::assertSame( array(), $report['registered_keys'] );
			self::assertSame( array(), $report['failed'] );
			self::assertSame( 0, $GLOBALS['etch_builders_component_registrar_insert_calls'] );
		}

		public function test_register_maps_stylesheet_registration_result_errors_to_wp_error(): void {
			Environment::configure(
				new ComponentRegistrarFailingFragmentStorage(),
				new ComponentRegistrarMode( true ),
				new NullAssetRegistry(),
				new NullComponentRefResolver()
			);

			$result = ComponentRegistrar::new( array() )->register(
				Component::new( 'Stylesheet Failure Component', 'Reports stylesheet failures.' )
					->key( 'StylesheetFailureComponent' )
					->blocks( '<!-- wp:paragraph --><p>Stylesheet failure.</p><!-- /wp:paragraph -->' )
					->stylesheet( 'omide-component-registrar-test', __DIR__ . '/../fixtures/test-stylesheet.css' )
			);

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'stylesheet_fragments_update_failed', $result->get_error_code() );
			self::assertSame( 'Stylesheet fragment option could not be updated.', $result->get_error_message() );
			self::assertSame( 0, $GLOBALS['etch_builders_component_registrar_insert_calls'] );
		}

		protected function setUp(): void {
			parent::setUp();

			$this->install_wordpress_stubs();

			$GLOBALS['etch_builders_component_registrar_insert_calls'] = 0;
			$GLOBALS['etch_builders_component_registrar_next_post_id'] = 1;
			$GLOBALS['etch_builders_component_registrar_posts']        = array();
			$GLOBALS['etch_builders_component_registrar_meta']         = array();
		}

		protected function tearDown(): void {
			Environment::reset();

			parent::tearDown();
		}

		private function install_wordpress_stubs(): void {
			eval(
				 <<<'PHP'
namespace {
	if ( ! class_exists( 'WP_Error', false ) ) {
		final class WP_Error {
			private string $code;
			private string $message;

			public function __construct( string $code, string $message ) {
				$this->code = $code;
				$this->message = $message;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}
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
		function sanitize_text_field( mixed $value ): string {
			return trim( strip_tags( (string) $value ) );
		}
	}

	if ( ! function_exists( 'wp_slash' ) ) {
		function wp_slash( mixed $value ): mixed {
			return $value;
		}
	}

	if ( ! function_exists( 'get_page_by_path' ) ) {
		function get_page_by_path( string $slug, mixed $output = OBJECT, string $post_type = 'page' ): ?object {
			foreach ( $GLOBALS['etch_builders_component_registrar_posts'] ?? array() as $post ) {
				if ( $post->post_name === $slug && $post->post_type === $post_type ) {
					return $post;
				}
			}

			return null;
		}
	}

	if ( ! function_exists( 'get_post' ) ) {
		function get_post( int $post_id ): ?object {
			return $GLOBALS['etch_builders_component_registrar_posts'][ $post_id ] ?? null;
		}
	}

	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( array $args ): array {
			$component_key = $args['meta_query'][0]['value'] ?? null;
			$post_type = $args['post_type'] ?? null;
			$ids = array();

			foreach ( $GLOBALS['etch_builders_component_registrar_posts'] ?? array() as $post_id => $post ) {
				if ( null !== $post_type && $post->post_type !== $post_type ) {
					continue;
				}

				if ( null !== $component_key && ( $GLOBALS['etch_builders_component_registrar_meta'][ $post_id ]['etch_component_html_key'] ?? null ) !== $component_key ) {
					continue;
				}

				$ids[] = (int) $post_id;
			}

			return array_slice( $ids, 0, (int) ( $args['posts_per_page'] ?? count( $ids ) ) );
		}
	}

	if ( ! function_exists( 'wp_insert_post' ) ) {
		function wp_insert_post( array $post_data, bool $wp_error = false ): int {
			$GLOBALS['etch_builders_component_registrar_insert_calls'] = (int) ( $GLOBALS['etch_builders_component_registrar_insert_calls'] ?? 0 ) + 1;
			$post_id = (int) ( $GLOBALS['etch_builders_component_registrar_next_post_id'] ?? 1 );
			$GLOBALS['etch_builders_component_registrar_next_post_id'] = $post_id + 1;
			$post_data['ID'] = $post_id;
			$GLOBALS['etch_builders_component_registrar_posts'][ $post_id ] = (object) $post_data;

			return $post_id;
		}
	}

	if ( ! function_exists( 'wp_update_post' ) ) {
		function wp_update_post( array $post_data, bool $wp_error = false ): int {
			$post_id = (int) ( $post_data['ID'] ?? 0 );
			if ( $post_id <= 0 ) {
				return 0;
			}

			$existing = $GLOBALS['etch_builders_component_registrar_posts'][ $post_id ] ?? (object) array( 'ID' => $post_id );
			$GLOBALS['etch_builders_component_registrar_posts'][ $post_id ] = (object) array_merge( (array) $existing, $post_data );

			return $post_id;
		}
	}

	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( int $post_id, string $key, mixed $value ): bool {
			$GLOBALS['etch_builders_component_registrar_meta'][ $post_id ][ $key ] = $value;

			return true;
		}
	}

	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
			return $GLOBALS['etch_builders_component_registrar_meta'][ $post_id ][ $key ] ?? '';
		}
	}

	if ( ! function_exists( 'wp_delete_post' ) ) {
		function wp_delete_post( int $post_id, bool $force_delete = false ): ?object {
			$post = $GLOBALS['etch_builders_component_registrar_posts'][ $post_id ] ?? null;
			unset( $GLOBALS['etch_builders_component_registrar_posts'][ $post_id ] );

			return $post;
		}
	}
}
PHP
			);
		}
	}

	final class ComponentRegistrarMode implements ModeProviderInterface {
		public function __construct( private bool $is_dev_mode ) {
		}

		public function is_dev_mode(): bool {
			return $this->is_dev_mode;
		}
	}

	final class ComponentRegistrarFailingFragmentStorage implements StorageInterface {
		/**
		 * @var array<string, mixed>
		 */
		private array $values = array();

		public function get( string $key, mixed $default = null ): mixed {
			return array_key_exists( $key, $this->values ) ? $this->values[ $key ] : $default;
		}

		public function set( string $key, mixed $value ): bool {
			if ( 'oh_my_id_etch_builder_stylesheet_fragments' === $key ) {
				return false;
			}

			$this->values[ $key ] = $value;

			return true;
		}

		public function delete( string $key ): bool {
			unset( $this->values[ $key ] );

			return true;
		}
	}
}
