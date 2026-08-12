<?php
/**
 * No-write Site Definition identity and dependency compiler.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\SiteRuntimeCapabilitiesInterface;
use HonestlyDesign\EtchBuilders\Contracts\SiteEntityCompilerMetadataInterface;
use HonestlyDesign\EtchBuilders\Support\WordPressSiteRuntimeCapabilities;
use InvalidArgumentException;
use Throwable;

/**
 * Compiles one complete no-write Site Definition into a deterministic plan.
 *
 * Every failure is represented as a stable plan diagnostic; the compiler never
 * performs a WordPress write or invokes a registrar.
 */
final class SiteCompiler {

	private const IDENTITY_INVALID = 'ETCH_SITE_IDENTITY_INVALID';

	private const PATTERN_MISSING = 'ETCH_SITE_PATTERN_MISSING';

	private const PATTERN_CYCLE = 'ETCH_SITE_PATTERN_CYCLE';

	private const POST_TYPE_INVALID = 'ETCH_SITE_POST_TYPE_INVALID';

	private const RUNTIME_UNAVAILABLE = 'ETCH_SITE_RUNTIME_UNAVAILABLE';

	private const SERIALIZATION_FAILED = 'ETCH_SITE_SERIALIZATION_FAILED';

	private const PROPERTY_INVALID = 'ETCH_SITE_PROPERTY_INVALID';

	private const COMPONENT_CONTRACT_INVALID = 'ETCH_SITE_COMPONENT_CONTRACT_INVALID';

	private const LOOP_INVALID = 'ETCH_SITE_LOOP_INVALID';

	private const STYLE_INVALID = 'ETCH_SITE_STYLE_INVALID';

	private const ASSET_INVALID = 'ETCH_SITE_ASSET_INVALID';

	private const ESCAPE_REVIEW = 'ETCH_SITE_ESCAPE_REVIEW';

	private function __construct() {
	}

	/**
	 * Compile one Site Definition without WordPress writes.
	 */
	public static function compile(
		SiteDefinition $definition,
		?SiteRuntimeCapabilitiesInterface $runtime = null
	): CompiledSitePlan {
		$runtime      ??= WordPressSiteRuntimeCapabilities::new();
		$entities      = array();
		$dependencies  = array();
		$styles        = array();
		$assets        = array();
		$ownership     = array();
		$diagnostics   = array();
		$pattern_graph = array();
		$pattern_keys  = array();
		$style_owners  = array();
		$asset_ids     = array();
		$loop_payloads = array();
		$loop_keys     = array();
		$site_assets   = self::read_lane( $definition, 'global_assets', $diagnostics );
		$supporting    = self::read_lane( $definition, 'supporting_definitions', $diagnostics );

		self::prepare_supporting_definitions( $supporting, $loop_payloads, $loop_keys, $style_owners, $diagnostics );

		$component_result = self::read_lane( $definition, 'components', $diagnostics );
		foreach ( $component_result as $component ) {
			self::compile_component( $component, $entities, $dependencies, $style_owners, $assets, $asset_ids, $ownership, $diagnostics, $loop_keys );
		}

		$pattern_result = self::read_lane( $definition, 'patterns', $diagnostics );
		foreach ( $pattern_result as $pattern ) {
			$identity = 'pattern:' . $pattern->get_key();
			$pattern_keys[ $pattern->get_key() ] = $identity;
			self::compile_pattern( $pattern, $entities, $dependencies, $pattern_graph, $style_owners, $assets, $asset_ids, $ownership, $diagnostics, $loop_keys );
		}

		$page_result = self::read_lane( $definition, 'pages', $diagnostics );
		foreach ( $page_result as $page ) {
			self::compile_page( $page, $entities, $dependencies, $style_owners, $assets, $asset_ids, $ownership, $diagnostics, $loop_keys );
		}

		$post_result = self::read_lane( $definition, 'posts', $diagnostics );
		foreach ( $post_result as $post ) {
			self::compile_post( $post, $entities, $dependencies, $style_owners, $assets, $asset_ids, $ownership, $diagnostics, $runtime, $loop_keys );
		}

		$template_result = self::read_lane( $definition, 'templates', $diagnostics );
		foreach ( $template_result as $template ) {
			self::compile_template( $template, $entities, $dependencies, $style_owners, $assets, $asset_ids, $ownership, $diagnostics, $loop_keys );
		}

		self::validate_dependencies( $dependencies, $pattern_keys, $diagnostics );
		self::validate_pattern_cycles( $pattern_graph, $diagnostics );
		self::compile_supporting_entities( $loop_payloads, $supporting, $entities, $diagnostics );
		self::compile_global_assets( $site_assets, $assets, $asset_ids, $ownership, $diagnostics );
		self::compile_styles( $style_owners, $styles, $ownership, $diagnostics );

		return CompiledSitePlan::from_sections(
			entities: $entities,
			dependencies: $dependencies,
			styles: $styles,
			assets: $assets,
			ownership: $ownership,
			diagnostics: $diagnostics
		);
	}

	/**
	 * Read one Site Definition lane while converting mutation drift to a plan diagnostic.
	 *
	 * @return array<int, mixed>
	 */
	private static function read_lane( SiteDefinition $definition, string $lane, array &$diagnostics ): array {
		try {
			return match ( $lane ) {
				'components' => $definition->components(),
				'patterns'   => $definition->patterns(),
				'pages'      => $definition->pages(),
				'posts'      => $definition->posts(),
				'templates'  => $definition->templates(),
				'supporting_definitions' => $definition->supporting_definitions(),
				'global_assets'         => $definition->global_assets(),
				default      => throw new InvalidArgumentException( 'Unsupported Site Definition compiler lane.' ),
			};
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::IDENTITY_INVALID, $throwable->getMessage() );
			return array();
		}
	}

	/**
	 * @param array<int, CompiledSiteEntity>      $entities
	 * @param array<int, CompiledSiteDependency>  $dependencies
	 * @param array<string, array<string, true>>  $style_owners
	 * @param array<int, CompiledSiteResource>    $assets
	 * @param array<string, true>                 $asset_ids
	 * @param array<int, CompiledSiteOwnership>  $ownership
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 * @param array<string, true>                 $loop_keys
	 */
	private static function compile_component(
		Component $component,
		array &$entities,
		array &$dependencies,
		array &$style_owners,
		array &$assets,
		array &$asset_ids,
		array &$ownership,
		array &$diagnostics,
		array $loop_keys
	): void {
		$identity = 'component:' . trim( $component->get_key() );
		try {
			$properties = self::component_properties( $component, $identity, $diagnostics );
			$blocks     = Javascript::inject_placeholders( $component->get_blocks() );
			$entities[] = CompiledSiteEntity::new(
				CompiledSiteEntityType::COMPONENT,
				$identity,
				array(
					'name'        => $component->get_name(),
					'description' => $component->get_description(),
					'blocks'      => $blocks,
					'properties'  => $properties,
				)
			);
			self::append_pattern_dependencies( $identity, $component->get_pattern_uses(), $dependencies );
			self::collect_entity_metadata( $identity, $component, $blocks, $dependencies, $style_owners, $assets, $asset_ids, $ownership, $diagnostics, $loop_keys, true );
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::SERIALIZATION_FAILED, $throwable->getMessage(), $identity );
		}
	}

	/**
	 * @param array<int, CompiledSiteEntity>       $entities
	 * @param array<int, CompiledSiteDependency>   $dependencies
	 * @param array<string, array<int, string>>    $pattern_graph
	 * @param array<string, array<string, true>>   $style_owners
	 * @param array<int, CompiledSiteResource>     $assets
	 * @param array<string, true>                  $asset_ids
	 * @param array<int, CompiledSiteOwnership>   $ownership
	 * @param array<int, CompiledSiteDiagnostic>  $diagnostics
	 * @param array<string, true>                  $loop_keys
	 */
	private static function compile_pattern(
		Pattern $pattern,
		array &$entities,
		array &$dependencies,
		array &$pattern_graph,
		array &$style_owners,
		array &$assets,
		array &$asset_ids,
		array &$ownership,
		array &$diagnostics,
		array $loop_keys
	): void {
		$identity = 'pattern:' . trim( $pattern->get_key() );
		$pattern_graph[ $identity ] = array();
		try {
			$blocks     = Javascript::inject_placeholders( $pattern->get_blocks() );
			$entities[] = CompiledSiteEntity::new(
				CompiledSiteEntityType::PATTERN,
				$identity,
				array(
					'name'        => $pattern->get_name(),
					'description' => $pattern->get_description(),
					'blocks'      => $blocks,
					'categories'  => $pattern->get_categories(),
				)
			);
			$uses = $pattern->get_pattern_uses();
			self::append_pattern_dependencies( $identity, $uses, $dependencies );
			foreach ( $uses as $use ) {
				$pattern_graph[ $identity ][] = 'pattern:' . $use->pattern_key();
			}
			self::collect_entity_metadata( $identity, $pattern, $blocks, $dependencies, $style_owners, $assets, $asset_ids, $ownership, $diagnostics, $loop_keys );
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::SERIALIZATION_FAILED, $throwable->getMessage(), $identity );
		}
	}

	/**
	 * @param array<int, CompiledSiteEntity>      $entities
	 * @param array<int, CompiledSiteDependency>  $dependencies
	 * @param array<string, array<string, true>>  $style_owners
	 * @param array<int, CompiledSiteResource>    $assets
	 * @param array<string, true>                 $asset_ids
	 * @param array<int, CompiledSiteOwnership>  $ownership
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 * @param array<string, true>                 $loop_keys
	 */
	private static function compile_page( Page $page, array &$entities, array &$dependencies, array &$style_owners, array &$assets, array &$asset_ids, array &$ownership, array &$diagnostics, array $loop_keys ): void {
		$identity = self::page_identity( $page );
		try {
			$blocks     = $page->get_blocks();
			$entities[] = CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, $identity, array( 'blocks' => $blocks ) );
			self::append_pattern_dependencies( $identity, $page->get_pattern_uses(), $dependencies );
			self::collect_entity_metadata( $identity, $page, $blocks, $dependencies, $style_owners, $assets, $asset_ids, $ownership, $diagnostics, $loop_keys );
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::SERIALIZATION_FAILED, $throwable->getMessage(), $identity );
		}
	}

	/**
	 * @param array<int, CompiledSiteEntity>      $entities
	 * @param array<int, CompiledSiteDependency>  $dependencies
	 * @param array<string, array<string, true>>  $style_owners
	 * @param array<int, CompiledSiteResource>    $assets
	 * @param array<string, true>                 $asset_ids
	 * @param array<int, CompiledSiteOwnership>  $ownership
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 * @param array<string, true>                 $loop_keys
	 */
	private static function compile_post( Post $post, array &$entities, array &$dependencies, array &$style_owners, array &$assets, array &$asset_ids, array &$ownership, array &$diagnostics, SiteRuntimeCapabilitiesInterface $runtime, array $loop_keys ): void {
		$identity  = self::post_identity( $post );
		$post_type = $post->get_post_type();
		if ( null === $post_type || '' === trim( $post_type ) ) {
			$diagnostics[] = self::error( self::POST_TYPE_INVALID, 'Post requires a configured post_type().', $identity );
			return;
		}
		$available = $runtime->post_type_exists( $post_type );
		if ( null === $available ) {
			$diagnostics[] = self::error( self::RUNTIME_UNAVAILABLE, 'Post-type capability is unavailable; compile under WordPress or provide a read-only runtime adapter.', $identity );
			return;
		}
		if ( ! $available ) {
			$diagnostics[] = self::error( self::POST_TYPE_INVALID, sprintf( 'Post type "%s" is not registered.', $post_type ), $identity );
			return;
		}
		try {
			$blocks     = $post->get_blocks();
			$entities[] = CompiledSiteEntity::new( CompiledSiteEntityType::POST, $identity, array( 'post_type' => $post_type, 'blocks' => $blocks ) );
			self::append_pattern_dependencies( $identity, $post->get_pattern_uses(), $dependencies );
			self::collect_entity_metadata( $identity, $post, $blocks, $dependencies, $style_owners, $assets, $asset_ids, $ownership, $diagnostics, $loop_keys );
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::SERIALIZATION_FAILED, $throwable->getMessage(), $identity );
		}
	}

	/**
	 * @param array<int, CompiledSiteEntity>      $entities
	 * @param array<int, CompiledSiteDependency>  $dependencies
	 * @param array<string, array<string, true>>  $style_owners
	 * @param array<int, CompiledSiteResource>    $assets
	 * @param array<string, true>                 $asset_ids
	 * @param array<int, CompiledSiteOwnership>  $ownership
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 * @param array<string, true>                 $loop_keys
	 */
	private static function compile_template( Template $template, array &$entities, array &$dependencies, array &$style_owners, array &$assets, array &$asset_ids, array &$ownership, array &$diagnostics, array $loop_keys ): void {
		$identity = 'template:slug:' . (string) $template->get_slug();
		try {
			$blocks     = $template->get_blocks();
			$entities[] = CompiledSiteEntity::new( CompiledSiteEntityType::TEMPLATE, $identity, array( 'slug' => $template->get_slug(), 'blocks' => $blocks ) );
			self::append_pattern_dependencies( $identity, $template->get_pattern_uses(), $dependencies );
			self::collect_entity_metadata( $identity, $template, $blocks, $dependencies, $style_owners, $assets, $asset_ids, $ownership, $diagnostics, $loop_keys );
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::SERIALIZATION_FAILED, $throwable->getMessage(), $identity );
		}
	}

	/**
	 * Serialize component definitions through the existing property transaction.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function component_properties( Component $component, string $identity, array &$diagnostics ): array {
		try {
			return $component->get_properties();
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::PROPERTY_INVALID, $throwable->getMessage(), $identity );
			return array();
		}
	}

	/**
	 * Collect the non-wire authoring metadata shared by every Site Entity.
	 *
	 * @param array<int, CompiledSiteDependency>  $dependencies
	 * @param array<string, array<string, true>>  $style_owners
	 * @param array<int, CompiledSiteResource>    $assets
	 * @param array<string, true>                 $asset_ids
	 * @param array<int, CompiledSiteOwnership>  $ownership
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 * @param array<string, true>                 $loop_keys
	 */
	private static function collect_entity_metadata(
		string $identity,
		SiteEntityCompilerMetadataInterface $builder,
		string $blocks,
		array &$dependencies,
		array &$style_owners,
		array &$assets,
		array &$asset_ids,
		array &$ownership,
		array &$diagnostics,
		array $loop_keys,
		bool $allow_component_root_slot_placeholder = false
	): void {
		foreach ( $builder->get_style_ids() as $style_id ) {
			if ( ! is_string( $style_id ) || '' === trim( $style_id ) ) {
				$diagnostics[] = self::error( self::STYLE_INVALID, 'Entity style IDs must be non-empty strings.', $identity );
				continue;
			}

			$style_owners[ $style_id ][ $identity ] = true;
		}

		foreach ( $builder->get_class_tokens() as $class_token ) {
			try {
				$class_token->assert_current();
			} catch ( Throwable $throwable ) {
				$diagnostics[] = self::error( self::STYLE_INVALID, $throwable->getMessage(), $identity );
				continue;
			}

			$reference = $class_token->style_reference();
			if ( null === $reference ) {
				continue;
			}

			$style_id = $reference->id();
			$style_owners[ $style_id ][ $identity ] = true;
			self::append_unique_ownership(
				$ownership,
				CompiledSiteOwnership::new( $identity, 'style:' . $style_id, 'presentation_class' )
			);
		}

		foreach ( $builder->get_stylesheet_references() as $reference ) {
			if ( ! $reference instanceof StylesheetReference ) {
				$diagnostics[] = self::error( self::ASSET_INVALID, 'Entity stylesheet references must use StylesheetReference values.', $identity );
				continue;
			}

			self::append_stylesheet_asset( $identity, $reference, $assets, $asset_ids, $ownership, $diagnostics );
		}

		$sequence = $builder->get_block_sequence();
		if ( null !== $sequence ) {
			self::validate_typed_blocks( $identity, $sequence->to_blocks(), $dependencies, $diagnostics, $loop_keys, 0, $allow_component_root_slot_placeholder );
		} elseif ( '' !== trim( $blocks ) ) {
			$diagnostics[] = self::warning(
				self::ESCAPE_REVIEW,
				'Entity uses serialized block markup instead of a typed BlockSequence; compiler checks are limited to the opaque wire payload.',
				$identity
			);
			self::validate_serialized_loops( $identity, $blocks, $dependencies, $diagnostics, $loop_keys );
		}

		if ( function_exists( 'parse_blocks' ) && '' !== trim( $blocks ) ) {
			try {
				$parsed = parse_blocks( $blocks );
				foreach ( BuilderPreviewStyleGuard::validate_component_class_props( $parsed ) as $message ) {
					$diagnostics[] = self::error( self::COMPONENT_CONTRACT_INVALID, $message, $identity );
				}
			} catch ( Throwable $throwable ) {
				$diagnostics[] = self::error( self::COMPONENT_CONTRACT_INVALID, $throwable->getMessage(), $identity );
			}
		}
	}

	/**
	 * Walk typed blocks and validate the contracts not already enforced by a
	 * concrete block builder at construction time.
	 *
	 * @param array<int, Block>                  $blocks
	 * @param array<int, CompiledSiteDependency> $dependencies
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 * @param array<string, true>                $loop_keys
	 */
	private static function validate_typed_blocks( string $identity, array $blocks, array &$dependencies, array &$diagnostics, array $loop_keys, int $depth = 0, bool $allow_component_root_slot_placeholder = false ): void {
		foreach ( $blocks as $block ) {
			if ( ! $block instanceof Block ) {
				$diagnostics[] = self::error( self::SERIALIZATION_FAILED, 'Typed block sequences may contain only Block values.', $identity );
				continue;
			}

			$attributes = $block->attributes();
			if ( 'etch/loop' === $block->name() ) {
				$loop_id = $attributes['loopId'] ?? null;
				if ( null !== $loop_id ) {
					if ( ! is_string( $loop_id ) || '' === trim( $loop_id ) || ! isset( $loop_keys[ $loop_id ] ) ) {
						$diagnostics[] = self::error( self::LOOP_INVALID, sprintf( 'Loop reference "%s" is not registered in the Site Definition.', is_scalar( $loop_id ) ? (string) $loop_id : gettype( $loop_id ) ), $identity );
					} else {
						self::append_unique_dependency( $dependencies, CompiledSiteDependency::loop( $identity, $loop_id ) );
					}
				}
			}

			$root_slot_placeholder_allowed = $allow_component_root_slot_placeholder
				&& 0 === $depth
				&& 'etch/slot-placeholder' === $block->name();
			if ( ( 'etch/slot-content' === $block->name() || 'etch/slot-placeholder' === $block->name() ) && 0 === $depth && ! $root_slot_placeholder_allowed ) {
				$diagnostics[] = self::error( self::COMPONENT_CONTRACT_INVALID, 'Slot boundary blocks must be emitted inside a component instance, not at the Site Entity root.', $identity );
			}

			if ( 'etch/component' === $block->name() ) {
				$ref = $attributes['ref'] ?? null;
				if ( ! is_int( $ref ) || $ref <= 0 ) {
					$diagnostics[] = self::error( self::COMPONENT_CONTRACT_INVALID, 'Component instance requires a positive numeric ref.', $identity );
				}
			}

			self::validate_typed_blocks( $identity, $block->children(), $dependencies, $diagnostics, $loop_keys, $depth + 1, $allow_component_root_slot_placeholder );
		}
	}

	/**
	 * Validate loop references retained by a legacy serialized markup boundary.
	 *
	 * This deliberately checks only the loop comment contract. The rest of a
	 * raw payload remains an explicit escape and receives ESCAPE_REVIEW above.
	 *
	 * @param array<int, CompiledSiteDependency> $dependencies
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 * @param array<string, true>                $loop_keys
	 */
	private static function validate_serialized_loops( string $identity, string $blocks, array &$dependencies, array &$diagnostics, array $loop_keys ): void {
		$matched = preg_match_all(
			'/<!--\\s+wp:etch\\/loop(?:\\s+(\\{.*?\\}))?\\s+(?:\\/-->|-->)/s',
			$blocks,
			$matches,
			PREG_SET_ORDER
		);
		if ( false === $matched || 0 === $matched ) {
			return;
		}

		foreach ( $matches as $match ) {
			$attributes_json = isset( $match[1] ) ? trim( $match[1] ) : '';
			$attributes      = '' === $attributes_json ? null : json_decode( $attributes_json, true );
			$loop_id         = is_array( $attributes ) ? ( $attributes['loopId'] ?? null ) : null;

			if ( ! is_string( $loop_id ) || '' === trim( $loop_id ) || ! isset( $loop_keys[ $loop_id ] ) ) {
				$diagnostics[] = self::error(
					self::LOOP_INVALID,
					sprintf( 'Loop reference "%s" is not registered in the Site Definition.', is_scalar( $loop_id ) ? (string) $loop_id : ( null === $loop_id ? 'missing' : gettype( $loop_id ) ) ),
					$identity
				);
				continue;
			}

			self::append_unique_dependency( $dependencies, CompiledSiteDependency::loop( $identity, $loop_id ) );
		}
	}

	/**
	 * Prepare supporting definitions before entity traversal so loop references
	 * can be validated without mutating LoopPreset's process-global registry.
	 *
	 * @param array<int, mixed>                 $supporting
	 * @param array<string, array<string,mixed>> $loop_payloads
	 * @param array<string, true>                $loop_keys
	 * @param array<string, array<string, true>> $style_owners
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	private static function prepare_supporting_definitions( array $supporting, array &$loop_payloads, array &$loop_keys, array &$style_owners, array &$diagnostics ): void {
		foreach ( $supporting as $definition ) {
			if ( $definition instanceof LoopPreset ) {
				$identity = 'loop_preset:' . $definition->get_key();
				try {
					$loop_payloads[ $definition->get_key() ] = $definition->to_array();
					$loop_keys[ $definition->get_key() ] = true;
				} catch ( Throwable $throwable ) {
					$diagnostics[] = self::error( self::LOOP_INVALID, $throwable->getMessage(), $identity );
				}
				continue;
			}

			if ( $definition instanceof EntityStyleSet ) {
				foreach ( $definition->style_ids() as $style_id ) {
					$style_owners[ $style_id ][ $definition->entity_id() ] = true;
				}
				continue;
			}

			if ( $definition instanceof ComponentContracts\ComponentContractCatalog ) {
				continue;
			}

			$diagnostics[] = self::error( self::SERIALIZATION_FAILED, 'Unsupported Site Definition supporting value.', null );
		}
	}

	/**
	 * Add loop and catalog supporting entities to the compiled entity section.
	 *
	 * @param array<string, array<string,mixed>> $loop_payloads
	 * @param array<int, mixed>                  $supporting
	 * @param array<int, CompiledSiteEntity>     $entities
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	private static function compile_supporting_entities( array $loop_payloads, array $supporting, array &$entities, array &$diagnostics ): void {
		foreach ( $loop_payloads as $key => $payload ) {
			try {
				$entities[] = CompiledSiteEntity::new( CompiledSiteEntityType::LOOP_PRESET, 'loop_preset:' . $key, $payload );
			} catch ( Throwable $throwable ) {
				$diagnostics[] = self::error( self::LOOP_INVALID, $throwable->getMessage(), 'loop_preset:' . $key );
			}
		}

		foreach ( $supporting as $definition ) {
			if ( ! $definition instanceof ComponentContracts\ComponentContractCatalog ) {
				continue;
			}

			try {
				$entities[] = CompiledSiteEntity::new(
					CompiledSiteEntityType::COMPONENT_CONTRACT_CATALOG,
					'component_contract_catalog:default',
					$definition->to_array()
				);
			} catch ( Throwable $throwable ) {
				$diagnostics[] = self::error( self::COMPONENT_CONTRACT_INVALID, $throwable->getMessage(), 'component_contract_catalog:default' );
			}
		}
	}

	/**
	 * Compile explicitly declared Site Definition assets.
	 *
	 * @param array<int, mixed>                 $site_assets
	 * @param array<int, CompiledSiteResource>  $assets
	 * @param array<string, true>               $asset_ids
	 * @param array<int, CompiledSiteOwnership> $ownership
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	private static function compile_global_assets( array $site_assets, array &$assets, array &$asset_ids, array &$ownership, array &$diagnostics ): void {
		foreach ( $site_assets as $asset ) {
			if ( $asset instanceof StylesheetReference ) {
				self::append_stylesheet_asset( 'site:root', $asset, $assets, $asset_ids, $ownership, $diagnostics );
				continue;
			}

			if ( $asset instanceof JavascriptAsset ) {
				$hash     = substr( hash( 'sha256', $asset->file_path() ), 0, 16 );
				$identity = 'asset:javascript:site:' . $asset->id() . ':' . $hash;
				if ( isset( $asset_ids[ $identity ] ) ) {
					continue;
				}
				try {
					$assets[] = CompiledSiteResource::new( CompiledSiteResourceType::ASSET, $identity, $asset->to_array() );
					$asset_ids[ $identity ] = true;
					self::append_unique_ownership( $ownership, CompiledSiteOwnership::new( 'site:root', $identity, 'global_asset' ) );
				} catch ( Throwable $throwable ) {
					$diagnostics[] = self::error( self::ASSET_INVALID, $throwable->getMessage(), 'site:root' );
				}
				continue;
			}

			$diagnostics[] = self::error( self::ASSET_INVALID, 'Site Definition assets must use StylesheetReference or JavascriptAsset values.', 'site:root' );
		}
	}

	/**
	 * Compile style records referenced by entities and Entity Style Sets.
	 *
	 * @param array<string, array<string, true>>  $style_owners
	 * @param array<int, CompiledSiteResource>    $styles
	 * @param array<int, CompiledSiteOwnership>  $ownership
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	private static function compile_styles( array $style_owners, array &$styles, array &$ownership, array &$diagnostics ): void {
		$registered = Style::registered_styles();
		foreach ( $style_owners as $style_id => $owners ) {
			if ( ! isset( $registered[ $style_id ] ) ) {
				$diagnostics[] = self::error( self::STYLE_INVALID, sprintf( 'Referenced style ID "%s" is not present in the request-local style registry.', $style_id ), null );
				continue;
			}

			$identity = 'style:' . $style_id;
			try {
				$styles[] = CompiledSiteResource::new( CompiledSiteResourceType::STYLE, $identity, $registered[ $style_id ] );
				foreach ( array_keys( $owners ) as $owner ) {
					self::append_unique_ownership( $ownership, CompiledSiteOwnership::new( $owner, $identity, 'style' ) );
				}
			} catch ( Throwable $throwable ) {
				$diagnostics[] = self::error( self::STYLE_INVALID, $throwable->getMessage(), $identity );
			}
		}
	}

	/**
	 * Add one file-based stylesheet resource and ownership edge.
	 */
	private static function append_stylesheet_asset( string $owner, StylesheetReference $reference, array &$assets, array &$asset_ids, array &$ownership, array &$diagnostics ): void {
		$hash     = substr( hash( 'sha256', $reference->css() ), 0, 16 );
		$identity = 'asset:stylesheet:' . $owner . ':' . $reference->id() . ':' . $hash;
		if ( isset( $asset_ids[ $identity ] ) ) {
			return;
		}

		try {
			$assets[] = CompiledSiteResource::new(
				CompiledSiteResourceType::ASSET,
				$identity,
				array(
					'type' => 'stylesheet',
					'id'   => $reference->id(),
					'path' => $reference->file_path(),
					'css'  => $reference->css(),
				)
			);
			$asset_ids[ $identity ] = true;
			self::append_unique_ownership( $ownership, CompiledSiteOwnership::new( $owner, $identity, 'stylesheet' ) );
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::ASSET_INVALID, $throwable->getMessage(), $owner );
		}
	}

	/**
	 * Keep ownership edges deterministic and unique before plan construction.
	 */
	private static function append_unique_ownership( array &$ownership, CompiledSiteOwnership $candidate ): void {
		foreach ( $ownership as $existing ) {
			if ( $existing->owner_identity() === $candidate->owner_identity()
				&& $existing->resource_identity() === $candidate->resource_identity()
				&& $existing->role() === $candidate->role()
			) {
				return;
			}
		}

		$ownership[] = $candidate;
	}

	/**
	 * Keep dependency edges deterministic and unique before plan construction.
	 */
	private static function append_unique_dependency( array &$dependencies, CompiledSiteDependency $candidate ): void {
		foreach ( $dependencies as $existing ) {
			if ( $existing->consumer_identity() === $candidate->consumer_identity()
				&& $existing->dependency_identity() === $candidate->dependency_identity()
				&& $existing->kind() === $candidate->kind()
			) {
				return;
			}
		}

		$dependencies[] = $candidate;
	}

	/**
	 * @param array<int, PatternUse>             $uses
	 * @param array<int, CompiledSiteDependency> $dependencies
	 */
	private static function append_pattern_dependencies( string $consumer_identity, array $uses, array &$dependencies ): void {
		$seen = array();
		foreach ( $uses as $use ) {
			$dependency = CompiledSiteDependency::pattern( $consumer_identity, $use );
			$key        = $dependency->consumer_identity() . '>' . $dependency->dependency_identity();
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ]   = true;
			$dependencies[] = $dependency;
		}
	}

	/**
	 * @param array<int, CompiledSiteDependency> $dependencies
	 * @param array<string, string>              $pattern_keys
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	private static function validate_dependencies( array $dependencies, array $pattern_keys, array &$diagnostics ): void {
		foreach ( $dependencies as $dependency ) {
			if ( 'pattern' !== $dependency->kind() ) {
				continue;
			}
			$key = substr( $dependency->dependency_identity(), strlen( 'pattern:' ) );
			if ( ! isset( $pattern_keys[ $key ] ) ) {
				$diagnostics[] = self::error(
					self::PATTERN_MISSING,
					sprintf( 'Pattern "%s" is used by "%s" but is not registered in the Site Definition.', $key, $dependency->consumer_identity() ),
					$dependency->consumer_identity()
				);
			}
		}
	}

	/**
	 * @param array<string, array<int, string>>   $graph
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	private static function validate_pattern_cycles( array $graph, array &$diagnostics ): void {
		$visited = array();
		$active  = array();
		foreach ( array_keys( $graph ) as $node ) {
			self::visit_pattern( $node, $graph, $visited, $active, $diagnostics, array() );
		}
	}

	/**
	 * @param array<string, array<int, string>>   $graph
	 * @param array<string, true>                 $visited
	 * @param array<string, true>                 $active
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 * @param array<int, string>                  $path
	 */
	private static function visit_pattern( string $node, array $graph, array &$visited, array &$active, array &$diagnostics, array $path ): void {
		if ( isset( $active[ $node ] ) ) {
			$cycle_path    = array_merge( $path, array( $node ) );
			$diagnostics[] = self::error( self::PATTERN_CYCLE, 'Pattern dependency cycle: ' . implode( ' -> ', $cycle_path ), $node );
			return;
		}
		if ( isset( $visited[ $node ] ) ) {
			return;
		}
		$active[ $node ] = true;
		foreach ( $graph[ $node ] ?? array() as $dependency ) {
			self::visit_pattern( $dependency, $graph, $visited, $active, $diagnostics, array_merge( $path, array( $node ) ) );
		}
		unset( $active[ $node ] );
		$visited[ $node ] = true;
	}

	private static function page_identity( Page $page ): string {
		if ( null !== $page->get_id() ) {
			return 'page:id:' . $page->get_id();
		}
		return 'page:slug:' . (string) $page->get_slug();
	}

	private static function post_identity( Post $post ): string {
		if ( null !== $post->get_id() ) {
			return 'post:id:' . $post->get_id();
		}
		return 'post:' . (string) $post->get_post_type() . ':' . (string) $post->get_slug();
	}

	private static function error( string $code, string $message, ?string $identity = null ): CompiledSiteDiagnostic {
		return CompiledSiteDiagnostic::new( $code, CompiledSiteDiagnosticSeverity::ERROR, $message, $identity );
	}

	private static function warning( string $code, string $message, ?string $identity = null ): CompiledSiteDiagnostic {
		return CompiledSiteDiagnostic::new( $code, CompiledSiteDiagnosticSeverity::WARNING, $message, $identity );
	}
}
