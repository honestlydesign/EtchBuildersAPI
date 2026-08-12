<?php
/**
 * Typed registry for one authored Etch site.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\ComponentContracts\ComponentContractCatalog;
use InvalidArgumentException;

/**
 * Collects every code-owned Site Entity before compilation or persistence.
 *
 * SiteDefinition is intentionally a no-write aggregate. It does not register
 * builders, resolve dependencies, expand patterns, or serialize Etch wire
 * payloads; those responsibilities belong to later compiler tickets.
 */
final class SiteDefinition {

	/**
	 * @var array<int, Component>
	 */
	private array $components = array();

	/**
	 * @var array<int, Pattern>
	 */
	private array $patterns = array();

	/**
	 * @var array<int, Page>
	 */
	private array $pages = array();

	/**
	 * @var array<int, Post>
	 */
	private array $posts = array();

	/**
	 * @var array<int, Template>
	 */
	private array $templates = array();

	/**
	 * @var array<int, LoopPreset|EntityStyleSet|ComponentContractCatalog>
	 */
	private array $supporting_definitions = array();

	/**
	 * @var array<int, StylesheetReference|JavascriptAsset>
	 */
	private array $global_assets = array();

	private SiteHomePolicy $home_page_policy;

	private function __construct() {
		$this->home_page_policy = SiteHomePolicy::none();
	}

	/**
	 * Create an empty Site Definition.
	 */
	public static function new(): self {
		return new self();
	}

	/**
	 * Add one explicitly keyed component.
	 */
	public function component( Component $component ): self {
		$key = trim( $component->get_key() );
		if ( '' === $key ) {
			throw new InvalidArgumentException( 'Site Definition component identity must be non-empty.' );
		}

		$this->add_unique( $this->components, 'component:' . $key, $component, 'component' );
		return $this;
	}

	/**
	 * Add one explicitly keyed pattern.
	 */
	public function pattern( Pattern $pattern ): self {
		$key = trim( $pattern->get_key() );
		if ( '' === $key ) {
			throw new InvalidArgumentException( 'Site Definition pattern identity must be non-empty.' );
		}

		$this->add_unique( $this->patterns, 'pattern:' . $key, $pattern, 'pattern' );
		return $this;
	}

	/**
	 * Add one page identified by its configured slug or post ID.
	 */
	public function page( Page $page ): self {
		$identity = $this->page_identity( $page );
		$this->add_unique( $this->pages, $identity, $page, 'page' );
		return $this;
	}

	/**
	 * Add one post identified by its configured slug/type or post ID.
	 */
	public function post( Post $post ): self {
		$identity = $this->post_identity( $post );
		$this->add_unique( $this->posts, $identity, $post, 'post' );
		return $this;
	}

	/**
	 * Add one active-theme template identified by slug.
	 */
	public function template( Template $template ): self {
		$slug = trim( (string) $template->get_slug() );
		if ( '' === $slug ) {
			throw new InvalidArgumentException( 'Site Definition template requires slug().' );
		}

		$this->add_unique( $this->templates, 'template:slug:' . $slug, $template, 'template' );
		return $this;
	}

	/**
	 * Add one known supporting definition.
	 *
	 * @param LoopPreset|EntityStyleSet|ComponentContractCatalog $definition Supporting value.
	 */
	public function supporting( LoopPreset|EntityStyleSet|ComponentContractCatalog $definition ): self {
		$identity = $this->supporting_identity( $definition );
		$this->add_unique( $this->supporting_definitions, $identity, $definition, 'supporting definition' );
		return $this;
	}

	/**
	 * Add one global stylesheet or file-based JavaScript asset.
	 */
	public function global_asset( StylesheetReference|JavascriptAsset $asset ): self {
		$identity = $this->global_asset_identity( $asset );

		$this->add_unique( $this->global_assets, $identity, $asset, 'global asset' );
		return $this;
	}

	/**
	 * Set the explicit front-page policy.
	 */
	public function home_page( SiteHomePolicy $policy ): self {
		$this->home_page_policy = $policy;
		return $this;
	}

	/**
	 * @return array<int, Component>
	 */
	public function components(): array {
		$this->assert_registry_integrity();
		return $this->components;
	}

	/**
	 * @return array<int, Pattern>
	 */
	public function patterns(): array {
		$this->assert_registry_integrity();
		return $this->patterns;
	}

	/**
	 * @return array<int, Page>
	 */
	public function pages(): array {
		$this->assert_registry_integrity();
		return $this->pages;
	}

	/**
	 * @return array<int, Post>
	 */
	public function posts(): array {
		$this->assert_registry_integrity();
		return $this->posts;
	}

	/**
	 * @return array<int, Template>
	 */
	public function templates(): array {
		$this->assert_registry_integrity();
		return $this->templates;
	}

	/**
	 * @return array<int, LoopPreset|EntityStyleSet|ComponentContractCatalog>
	 */
	public function supporting_definitions(): array {
		$this->assert_registry_integrity();
		return $this->supporting_definitions;
	}

	/**
	 * @return array<int, StylesheetReference|JavascriptAsset>
	 */
	public function global_assets(): array {
		$this->assert_registry_integrity();
		return $this->global_assets;
	}

	/**
	 * Return the selected front-page policy.
	 */
	public function home_page_policy(): SiteHomePolicy {
		return $this->home_page_policy;
	}

	/**
	 * Compile identities and dependencies without performing WordPress writes.
	 */
	public function compile( ?\HonestlyDesign\EtchBuilders\Contracts\SiteRuntimeCapabilitiesInterface $runtime = null ): CompiledSitePlan {
		return SiteCompiler::compile( $this, $runtime );
	}

	/**
	 * Return a deterministic registry projection, not an Etch wire payload.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$this->assert_registry_integrity();

		$supporting = array();
		foreach ( $this->supporting_definitions as $definition ) {
			$supporting[] = $this->supporting_record( $definition );
		}

		$assets = array();
		foreach ( $this->global_assets as $asset ) {
			$assets[] = $asset instanceof StylesheetReference
				? array( 'type' => 'stylesheet', 'id' => $asset->id() )
				: $asset->to_array();
		}

		return array(
			'components'             => array_map(
				static fn ( Component $component ): string => $component->get_key(),
				$this->components
			),
			'patterns'               => array_map(
				static fn ( Pattern $pattern ): string => $pattern->get_key(),
				$this->patterns
			),
			'pages'                  => array_map( fn ( Page $page ): string => $this->page_identity( $page ), $this->pages ),
			'posts'                  => array_map( fn ( Post $post ): string => $this->post_identity( $post ), $this->posts ),
			'templates'              => array_map(
				fn ( Template $template ): string => 'template:slug:' . (string) $template->get_slug(),
				$this->templates
			),
			'supporting_definitions' => $supporting,
			'global_assets'          => $assets,
			'home_page'              => $this->home_page_policy->to_array(),
		);
	}

	/**
	 * Add an identity only once while preserving insertion order.
	 *
	 * @param array<int, object> $registry Registry lane.
	 * @param object             $value    Typed value.
	 * @param string                $label    Human-readable lane label.
	 */
	private function add_unique( array &$registry, string $identity, object $value, string $label ): void {
		$this->assert_collection_integrity( $registry, $label );
		foreach ( $registry as $existing ) {
			if ( $identity === $this->identity_of( $existing ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Site Definition has duplicate %s identity "%s".', $label, $identity )
				);
			}
		}

		$registry[] = $value;
	}

	private function page_identity( Page $page ): string {
		if ( null !== $page->get_id() && null !== $page->get_slug() ) {
			throw new InvalidArgumentException( 'Site Definition page cannot use both slug() and id().' );
		}

		if ( null !== $page->get_id() ) {
			return 'page:id:' . (string) $page->get_id();
		}

		$slug = trim( (string) $page->get_slug() );
		if ( '' === $slug ) {
			throw new InvalidArgumentException( 'Site Definition page requires slug() or id().' );
		}

		return 'page:slug:' . $slug;
	}

	private function post_identity( Post $post ): string {
		if ( null !== $post->get_id() && null !== $post->get_slug() ) {
			throw new InvalidArgumentException( 'Site Definition post cannot use id() with slug().' );
		}

		if ( null !== $post->get_id() ) {
			if ( null === $post->get_post_type() ) {
				throw new InvalidArgumentException( 'Site Definition post with id() requires post_type().' );
			}

			return 'post:id:' . (string) $post->get_id();
		}

		$post_type = trim( (string) $post->get_post_type() );
		$slug      = trim( (string) $post->get_slug() );
		if ( '' === $post_type || '' === $slug ) {
			throw new InvalidArgumentException( 'Site Definition post requires post_type() and slug(), or id().' );
		}

		return 'post:' . $post_type . ':' . $slug;
	}

	private function supporting_identity( LoopPreset|EntityStyleSet|ComponentContractCatalog $definition ): string {
		if ( $definition instanceof LoopPreset ) {
			return 'loop_preset:' . $definition->get_key();
		}

		if ( $definition instanceof EntityStyleSet ) {
			return 'entity_style_set:' . $definition->entity_id();
		}

		return 'component_contract_catalog';
	}

	/**
	 * Return the stable registry identity for any value accepted by one lane.
	 */
	private function identity_of( object $value ): string {
		if ( $value instanceof Component ) {
			return 'component:' . trim( $value->get_key() );
		}

		if ( $value instanceof Pattern ) {
			return 'pattern:' . trim( $value->get_key() );
		}

		if ( $value instanceof Page ) {
			return $this->page_identity( $value );
		}

		if ( $value instanceof Post ) {
			return $this->post_identity( $value );
		}

		if ( $value instanceof Template ) {
			$slug = trim( (string) $value->get_slug() );
			if ( '' === $slug ) {
				throw new InvalidArgumentException( 'Site Definition template requires slug().' );
			}

			return 'template:slug:' . $slug;
		}

		if ( $value instanceof LoopPreset ) {
			return 'loop_preset:' . $value->get_key();
		}

		if ( $value instanceof EntityStyleSet ) {
			return 'entity_style_set:' . $value->entity_id();
		}

		if ( $value instanceof ComponentContractCatalog ) {
			return 'component_contract_catalog';
		}

		if ( $value instanceof StylesheetReference ) {
			return 'stylesheet:' . $value->id() . ':' . hash( 'sha256', $value->file_path() );
		}

		if ( $value instanceof JavascriptAsset ) {
			return 'javascript:' . $value->id();
		}

		throw new InvalidArgumentException( 'Site Definition received an unsupported registry value.' );
	}

	/**
	 * Refuse duplicate identities after a caller mutates a retained builder.
	 *
	 * @param array<int, object> $registry Registry lane.
	 */
	private function assert_collection_integrity( array $registry, string $label ): void {
		$identities = array();
		foreach ( $registry as $value ) {
			$identity = $this->identity_of( $value );
			if ( isset( $identities[ $identity ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Site Definition has duplicate %s identity "%s".', $label, $identity )
				);
			}

			$identities[ $identity ] = true;
		}
	}

	private function assert_registry_integrity(): void {
		$this->assert_collection_integrity( $this->components, 'component' );
		$this->assert_collection_integrity( $this->patterns, 'pattern' );
		$this->assert_collection_integrity( $this->pages, 'page' );
		$this->assert_collection_integrity( $this->posts, 'post' );
		$this->assert_collection_integrity( $this->templates, 'template' );
		$this->assert_collection_integrity( $this->supporting_definitions, 'supporting definition' );
		$this->assert_collection_integrity( $this->global_assets, 'global asset' );
	}

	private function global_asset_identity( StylesheetReference|JavascriptAsset $asset ): string {
		return $this->identity_of( $asset );
	}

	/**
	 * @return array{type: string, key: string}
	 */
	private function supporting_record( LoopPreset|EntityStyleSet|ComponentContractCatalog $definition ): array {
		if ( $definition instanceof LoopPreset ) {
			return array( 'type' => 'loop_preset', 'key' => $definition->get_key() );
		}

		if ( $definition instanceof EntityStyleSet ) {
			return array( 'type' => 'entity_style_set', 'key' => $definition->entity_id() );
		}

		return array( 'type' => 'component_contract_catalog', 'key' => 'component-contract-catalog' );
	}
}
