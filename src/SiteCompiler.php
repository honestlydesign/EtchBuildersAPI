<?php
/**
 * No-write Site Definition identity and dependency compiler.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\Contracts\SiteRuntimeCapabilitiesInterface;
use HonestlyDesign\EtchBuilders\Support\WordPressSiteRuntimeCapabilities;
use InvalidArgumentException;
use Throwable;

/**
 * Compiles entity identities and Pattern Use dependencies into a plan.
 *
 * This first compiler phase intentionally stops before schema-backed props,
 * slots, loops, styles, escapes, and persistence. Every failure is represented
 * as a stable plan diagnostic; the compiler never performs a WordPress write.
 */
final class SiteCompiler {

	private const IDENTITY_INVALID = 'ETCH_SITE_IDENTITY_INVALID';

	private const PATTERN_MISSING = 'ETCH_SITE_PATTERN_MISSING';

	private const PATTERN_CYCLE = 'ETCH_SITE_PATTERN_CYCLE';

	private const POST_TYPE_INVALID = 'ETCH_SITE_POST_TYPE_INVALID';

	private const RUNTIME_UNAVAILABLE = 'ETCH_SITE_RUNTIME_UNAVAILABLE';

	private const SERIALIZATION_FAILED = 'ETCH_SITE_SERIALIZATION_FAILED';

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
		$diagnostics   = array();
		$pattern_graph = array();
		$pattern_keys  = array();

		$component_result = self::read_lane( $definition, 'components', $diagnostics );
		foreach ( $component_result as $component ) {
			self::compile_component( $component, $entities, $dependencies, $diagnostics );
		}

		$pattern_result = self::read_lane( $definition, 'patterns', $diagnostics );
		foreach ( $pattern_result as $pattern ) {
			$identity = 'pattern:' . $pattern->get_key();
			$pattern_keys[ $pattern->get_key() ] = $identity;
			self::compile_pattern( $pattern, $entities, $dependencies, $diagnostics, $pattern_graph );
		}

		$page_result = self::read_lane( $definition, 'pages', $diagnostics );
		foreach ( $page_result as $page ) {
			self::compile_page( $page, $entities, $dependencies, $diagnostics );
		}

		$post_result = self::read_lane( $definition, 'posts', $diagnostics );
		foreach ( $post_result as $post ) {
			self::compile_post( $post, $entities, $dependencies, $diagnostics, $runtime );
		}

		$template_result = self::read_lane( $definition, 'templates', $diagnostics );
		foreach ( $template_result as $template ) {
			self::compile_template( $template, $entities, $dependencies, $diagnostics );
		}

		self::validate_dependencies( $dependencies, $pattern_keys, $diagnostics );
		self::validate_pattern_cycles( $pattern_graph, $diagnostics );

		return CompiledSitePlan::from_sections(
			entities: $entities,
			dependencies: $dependencies,
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
				default      => throw new InvalidArgumentException( 'Unsupported Site Definition compiler lane.' ),
			};
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::IDENTITY_INVALID, $throwable->getMessage() );
			return array();
		}
	}

	/**
	 * @param array<int, CompiledSiteEntity>     $entities
	 * @param array<int, CompiledSiteDependency> $dependencies
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	private static function compile_component( Component $component, array &$entities, array &$dependencies, array &$diagnostics ): void {
		$identity = 'component:' . trim( $component->get_key() );
		try {
			$entities[] = CompiledSiteEntity::new(
				CompiledSiteEntityType::COMPONENT,
				$identity,
				array(
					'name'        => $component->get_name(),
					'description' => $component->get_description(),
					'blocks'      => Javascript::inject_placeholders( $component->get_blocks() ),
				)
			);
			self::append_pattern_dependencies( $identity, $component->get_pattern_uses(), $dependencies );
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::SERIALIZATION_FAILED, $throwable->getMessage(), $identity );
		}
	}

	/**
	 * @param array<int, CompiledSiteEntity>      $entities
	 * @param array<int, CompiledSiteDependency>  $dependencies
	 * @param array<int, CompiledSiteDiagnostic>  $diagnostics
	 * @param array<string, array<int, string>>   $pattern_graph
	 */
	private static function compile_pattern( Pattern $pattern, array &$entities, array &$dependencies, array &$diagnostics, array &$pattern_graph ): void {
		$identity = 'pattern:' . trim( $pattern->get_key() );
		$pattern_graph[ $identity ] = array();
		try {
			$entities[] = CompiledSiteEntity::new(
				CompiledSiteEntityType::PATTERN,
				$identity,
				array(
					'name'        => $pattern->get_name(),
					'description' => $pattern->get_description(),
					'blocks'      => Javascript::inject_placeholders( $pattern->get_blocks() ),
					'categories' => $pattern->get_categories(),
				)
			);
			$uses = $pattern->get_pattern_uses();
			self::append_pattern_dependencies( $identity, $uses, $dependencies );
			foreach ( $uses as $use ) {
				$pattern_graph[ $identity ][] = 'pattern:' . $use->pattern_key();
			}
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::SERIALIZATION_FAILED, $throwable->getMessage(), $identity );
		}
	}

	/**
	 * @param array<int, CompiledSiteEntity>     $entities
	 * @param array<int, CompiledSiteDependency> $dependencies
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	private static function compile_page( Page $page, array &$entities, array &$dependencies, array &$diagnostics ): void {
		$identity = self::page_identity( $page );
		try {
			$entities[] = CompiledSiteEntity::new( CompiledSiteEntityType::PAGE, $identity, array( 'blocks' => $page->get_blocks() ) );
			self::append_pattern_dependencies( $identity, $page->get_pattern_uses(), $dependencies );
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::SERIALIZATION_FAILED, $throwable->getMessage(), $identity );
		}
	}

	/**
	 * @param array<int, CompiledSiteEntity>     $entities
	 * @param array<int, CompiledSiteDependency> $dependencies
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	private static function compile_post( Post $post, array &$entities, array &$dependencies, array &$diagnostics, SiteRuntimeCapabilitiesInterface $runtime ): void {
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
			$entities[] = CompiledSiteEntity::new( CompiledSiteEntityType::POST, $identity, array( 'post_type' => $post_type, 'blocks' => $post->get_blocks() ) );
			self::append_pattern_dependencies( $identity, $post->get_pattern_uses(), $dependencies );
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::SERIALIZATION_FAILED, $throwable->getMessage(), $identity );
		}
	}

	/**
	 * @param array<int, CompiledSiteEntity>     $entities
	 * @param array<int, CompiledSiteDependency> $dependencies
	 * @param array<int, CompiledSiteDiagnostic> $diagnostics
	 */
	private static function compile_template( Template $template, array &$entities, array &$dependencies, array &$diagnostics ): void {
		$identity = 'template:slug:' . (string) $template->get_slug();
		try {
			$entities[] = CompiledSiteEntity::new( CompiledSiteEntityType::TEMPLATE, $identity, array( 'slug' => $template->get_slug(), 'blocks' => $template->get_blocks() ) );
			self::append_pattern_dependencies( $identity, $template->get_pattern_uses(), $dependencies );
		} catch ( Throwable $throwable ) {
			$diagnostics[] = self::error( self::SERIALIZATION_FAILED, $throwable->getMessage(), $identity );
		}
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
}
