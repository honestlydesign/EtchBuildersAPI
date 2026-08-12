<?php
/**
 * Explicit front-page policy for a Site Definition.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

use InvalidArgumentException;

/**
 * Value object describing whether a site has a static or posts front page.
 */
final class SiteHomePolicy {

	/**
	 * @param SiteHomePolicyMode $mode Policy mode.
	 * @param string|null        $page_slug Static page slug for PAGE mode.
	 */
	private function __construct(
		private readonly SiteHomePolicyMode $mode,
		private readonly ?string $page_slug
	) {
	}

	/**
	 * Do not change the WordPress reading setting.
	 */
	public static function none(): self {
		return new self( SiteHomePolicyMode::NONE, null );
	}

	/**
	 * Use the latest-posts view as the front page.
	 */
	public static function latest_posts(): self {
		return new self( SiteHomePolicyMode::LATEST_POSTS, null );
	}

	/**
	 * Use one explicit Page slug as the front page.
	 *
	 * The slug is deliberately validated as an already-normalized identity;
	 * Site Definition must not silently invent a different page identity.
	 *
	 * @param string $slug Normalized page slug.
	 * @throws InvalidArgumentException When the slug is malformed.
	 */
	public static function page( string $slug ): self {
		$slug = trim( $slug );
		if ( '' === $slug || 1 !== preg_match( '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D', $slug ) ) {
			throw new InvalidArgumentException( 'SiteHomePolicy::page() requires a normalized page slug.' );
		}

		return new self( SiteHomePolicyMode::PAGE, $slug );
	}

	/**
	 * Return the policy mode.
	 */
	public function mode(): SiteHomePolicyMode {
		return $this->mode;
	}

	/**
	 * Return the static page slug, when this is a PAGE policy.
	 */
	public function page_slug(): ?string {
		return $this->page_slug;
	}

	/**
	 * Return a deterministic, non-wire policy record.
	 *
	 * @return array{mode: string, slug?: string}
	 */
	public function to_array(): array {
		$record = array( 'mode' => $this->mode->value );
		if ( SiteHomePolicyMode::PAGE === $this->mode ) {
			$record['slug'] = (string) $this->page_slug;
		}

		return $record;
	}
}
