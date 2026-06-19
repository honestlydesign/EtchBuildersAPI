<?php
/**
 * Component builder for Etch wp_block registrations.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare(strict_types=1);

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;
use HonestlyDesign\EtchBuilders\ComponentProperties\Contracts\ComponentPropertyInterface;
use HonestlyDesign\EtchBuilders\Environment;
use HonestlyDesign\EtchBuilders\Support\BlocksInputPathGuard;
use RuntimeException;

/**
 * Fluent builder for Etch components backed by wp_block posts.
 *
 * Example:
 *   Component::new('Accordion', 'Description...')
 *     ->key('Accordion')
 *     ->prop(StringProperty::new('Tag')->key('tag')->default('div'))
 *     ->blocks($markup)
 *     ->register();
 */
final class Component {
	/**
	 * Component display name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Unique component key saved in etch_component_html_key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Component description.
	 *
	 * @var string
	 */
	private string $description;

	/**
	 * Serialized Gutenberg HTML.
	 *
	 * @var string
	 */
	private string $blocks = '';

	/**
	 * Properties keyed by prop key.
	 *
	 * @var array<string, ComponentPropertyInterface>
	 */
	private array $properties = array();

	/**
	 * Global stylesheet references declared by this component.
	 *
	 * @var array<int, StylesheetReference>
	 */
	private array $stylesheet_references = array();

	/**
	 * Whether this component is dev-only.
	 *
	 * @var bool
	 */
	private bool $dev_only = false;

	/**
	 * Constructor.
	 *
	 * @param string $name        Component display name.
	 * @param string $description Component description.
	 * @throws InvalidArgumentException When name or description is invalid.
	 */
	private function __construct( string $name, string $description ) {
		$this->name        = $this->validate_name( $name );
		$this->description = $this->validate_description( $description );
		$this->key         = $this->derive_key( $name );
	}

	/**
	 * Create a new Component builder.
	 *
	 * @param string $name        Component display name.
	 * @param string $description Component description.
	 * @throws InvalidArgumentException When name or description is invalid.
	 */
	public static function new( string $name, string $description ): self {
		return new self( $name, $description );
	}

	/**
	 * Set the component key.
	 *
	 * @param string $key Component key (overrides auto-derived key).
	 */
	public function key( string $key ): self {
		$this->key = $this->validate_key( $key );
		return $this;
	}

	/**
	 * Mark this component as dev-only.
	 *
	 * Dev-only components are silently skipped during registration
	 * when not running in development mode.
	 *
	 * @param bool $dev_only Whether the component is dev-only.
	 */
	public function dev_only( bool $dev_only = true ): self {
		$this->dev_only = $dev_only;
		return $this;
	}

	/**
	 * Whether this component is dev-only.
	 */
	public function is_dev_only(): bool {
		return $this->dev_only;
	}

	/**
	 * Add a property.
	 *
	 * @param ComponentPropertyInterface $property Property builder instance.
	 */
	public function prop( ComponentPropertyInterface $property ): self {
		$this->properties[ $property->get_key() ] = $property;
		return $this;
	}

	/**
	 * Set the blocks markup.
	 *
	 * @param string $blocks Raw Gutenberg HTML or local file path.
	 * @throws RuntimeException When the local file cannot be read.
	 */
	public function blocks( string $blocks ): self {
		$this->blocks = $this->resolve_blocks_input( $blocks );
		return $this;
	}

	/**
	 * Gets the component name.
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Gets the component key.
	 */
	public function get_key(): string {
		return $this->key;
	}

	/**
	 * Gets the component description.
	 */
	public function get_description(): string {
		return $this->description;
	}

	/**
	 * Derives a CapitalCase key from a component name.
	 *
	 * "Accordion Item" → "AccordionItem"
	 * "my-component" → "MyComponent"
	 *
	 * @param string $name Component name.
	 */
	private function derive_key( string $name ): string {
		// Split on non-alphanumeric characters.
		$words = preg_split( '/[^A-Za-z0-9]+/', trim( $name ) );
		if ( false === $words || array() === $words ) {
			return 'Component';
		}

		// Filter out empty strings and convert each word to CapitalCase.
		$key_parts = array();
		foreach ( $words as $word ) {
			$word = trim( $word );
			if ( '' !== $word ) {
				$key_parts[] = ucfirst( strtolower( $word ) );
			}
		}

		if ( array() === $key_parts ) {
			return 'Component';
		}

		return implode( '', $key_parts );
	}

	/**
	 * Validates description.
	 *
	 * @param string $description Raw description value.
	 * @throws InvalidArgumentException When description is invalid.
	 */
	private function validate_description( string $description ): string {
		$description = trim( $description );
		if ( '' === $description ) {
			throw new InvalidArgumentException( 'Component "description" must be non-empty.' );
		}
		return $description;
	}

	/**
	 * Gets serialized Gutenberg blocks markup.
	 */
	public function get_blocks(): string {
		return $this->blocks;
	}

	/**
	 * Gets all component properties in Etch schema format.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_properties(): array {
		$properties = array();

		foreach ( $this->properties as $property ) {
			$properties[] = $property->to_array();
		}

		return $properties;
	}

	/**
	 * Add a style scoped to this component.
	 *
	 * Styles are editable (not readonly) by default, matching the Etch runtime
	 * contract: the `readonly` field on an etch_styles entry defaults to unset.
	 * Use Style::readonly(true) before passing to add_style() to mark a style
	 * non-editable in the Etch editor.
	 *
	 * @param Style $style Style builder instance.
	 * @return string Registered style id.
	 */
	public function add_style( Style $style ): string {
		return $style->add();
	}

	/**
	 * Add a class-prop default style scoped to this component.
	 *
	 * Class-prop default styles are mutable and single-register:
	 * they can be edited by users and are not overwritten on re-register.
	 *
	 * @param Style $style Style builder instance.
	 * @return string Registered style id.
	 */
	public function add_class_prop_style( Style $style ): string {
		return $style->add();
	}

	/**
	 * Attach a CSS file to an Etch global stylesheet.
	 *
	 * Multiple builders can target the same stylesheet ID; their CSS is stacked
	 * into the same Etch global stylesheet.
	 *
	 * @param string $id Stylesheet ID and display name.
	 * @param string $file_path CSS file path.
	 */
	public function stylesheet( string $id, string $file_path ): self {
		$this->stylesheet_references[] = StylesheetReference::new( $id, $file_path );

		return $this;
	}

	/**
	 * Register global stylesheet references declared by this component.
	 */
	public function register_stylesheets(): bool|RegistrationResult {
		return Stylesheet::register_references( 'component:' . $this->key, $this->stylesheet_references );
	}

	/**
	 * Register a CSS asset to be enqueued when this component renders.
	 *
	 * @param string $handle WordPress enqueue handle (must be unique).
	 * @param string $path   Path relative to assets/ folder (e.g., '/dist/carousel/swiper.css').
	 */
	public function enqueue_style( string $handle, string $path ): self {
		Environment::assets()->register( $this->key, 'styles', $handle, $path );
		return $this;
	}

	/**
	 * Register a JS asset to be enqueued when this component renders.
	 *
	 * Scripts are enqueued with defer strategy in the head.
	 *
	 * @param string $handle WordPress enqueue handle (must be unique).
	 * @param string $path   Path relative to assets/ folder (e.g., '/dist/carousel/swiper.js').
	 */
	public function enqueue_script( string $handle, string $path ): self {
		Environment::assets()->register( $this->key, 'scripts', $handle, $path );
		return $this;
	}

	/**
	 * Whether this component should be skipped during registration in the current mode.
	 *
	 * Returns true when the component is marked dev-only and the runtime is not
	 * in development mode. Concrete persistence is handled by the consumer's
	 * registrar (e.g. the WordPress starter's ComponentRegistrar).
	 */
	public function should_skip_registration(): bool {
		return $this->dev_only && ! Environment::mode()->is_dev_mode();
	}

	/**
	 * Validates and normalizes component name.
	 *
	 * @param mixed $raw_name Raw component name value.
	 * @throws InvalidArgumentException When name is invalid.
	 */
	private function validate_name( mixed $raw_name ): string {
		if ( ! is_string( $raw_name ) ) {
			throw new InvalidArgumentException( 'Component "name" is required and must be a string.' );
		}

		$name = trim( $raw_name );
		if ( '' === $name ) {
			throw new InvalidArgumentException( 'Component "name" must be non-empty.' );
		}

		return $name;
	}

	/**
	 * Validates and normalizes component key.
	 *
	 * @param mixed $raw_key Raw component key value.
	 * @throws InvalidArgumentException When key is invalid.
	 */
	private function validate_key( mixed $raw_key ): string {
		if ( ! is_string( $raw_key ) ) {
			throw new InvalidArgumentException( 'Component "key" is required and must be a string.' );
		}

		$key = trim( $raw_key );
		if ( '' === $key ) {
			throw new InvalidArgumentException( 'Component "key" must be non-empty.' );
		}

		if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]*$/', $key ) ) {
			throw new InvalidArgumentException( 'Component "key" must match /^[A-Za-z][A-Za-z0-9_-]*$/.' );
		}

		return $key;
	}

	/**
	 * Resolves blocks input as either raw HTML or a readable local file path.
	 *
	 * @param string $blocks_or_path Raw Gutenberg HTML or local file path.
	 * @throws RuntimeException When the local file cannot be read or is outside plugin directory.
	 */
	private function resolve_blocks_input( string $blocks_or_path ): string {
		if ( ! BlocksInputPathGuard::is_path_candidate( $blocks_or_path ) ) {
			return $blocks_or_path;
		}

		if ( is_readable( $blocks_or_path ) && is_file( $blocks_or_path ) ) {
			$real_path   = realpath( $blocks_or_path );
			$plugin_root = realpath( dirname( __DIR__, 2 ) );

			if ( false === $real_path || false === $plugin_root
				|| ! str_starts_with( $real_path, $plugin_root . DIRECTORY_SEPARATOR )
			) {
				throw new RuntimeException( 'Blocks file must be within the plugin directory.' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = file_get_contents( $real_path );
			if ( false === $contents ) {
				throw new RuntimeException( 'Unable to read blocks file.' );
			}
			return $contents;
		}

		return $blocks_or_path;
	}
}
