<?php
/**
 * Shared typed building blocks reused by atomic and composite recipes.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use HonestlyDesign\EtchBuilders\EtchBlocks\ElementBlock;
use HonestlyDesign\EtchBuilders\EtchBlocks\TextBlock;

/**
 * Keeps core recipe facts in one typed source so composite recipes cannot fork
 * the atomic component/page contract accidentally.
 */
final class CoreAuthoringRecipeFixtures {

	private const COMPONENT_KEY = 'Hero';
	private const DESCRIPTION    = 'Hero component';
	private const TAG           = 'section';
	private const CONTENT       = 'Welcome to the site.';

	private function __construct() {
	}

	public static function hero_component(): Component {
		return Component::new( self::hero_key(), self::hero_description() )
			->key( self::hero_key() )
			->blocks(
				ElementBlock::new()
					->tag( self::TAG )
					->content( self::CONTENT )
			);
	}

	public static function home_page(): Page {
		return Page::new()
			->slug( self::home_slug() )
			->block( TextBlock::new()->content( self::CONTENT ) );
	}

	public static function hero_key(): string {
		return self::COMPONENT_KEY;
	}

	public static function hero_description(): string {
		return self::DESCRIPTION;
	}

	public static function hero_tag(): string {
		return self::TAG;
	}

	public static function home_slug(): string {
		return 'home';
	}

	public static function content(): string {
		return self::CONTENT;
	}
}
