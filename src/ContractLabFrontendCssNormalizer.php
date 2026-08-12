<?php
/**
 * Ordered CSS normalization for Contract Lab frontend observations.
 *
 * @package HonestlyDesignEtchBuilders
 */

declare( strict_types=1 );

namespace HonestlyDesign\EtchBuilders;

/**
 * Normalizes the small CSS rule surface needed by the Contract Lab without
 * claiming to be a browser CSS parser.
 */
final class ContractLabFrontendCssNormalizer {

	private function __construct() {
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function normalize( string $css ): array {
		if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $css ) ) {
			throw new ContractLabObservationException( 'malformed', 'Frontend stylesheet contains control characters.' );
		}
		$without_comments = preg_replace( '/\/\*.*?\*\//s', '', $css );
		if ( ! is_string( $without_comments ) ) {
			throw new ContractLabObservationException( 'malformed', 'Frontend stylesheet comments could not be normalized.' );
		}

		return self::parse_sequence( $without_comments );
	}

	/**
	 * @param array<int, array<string, mixed>> $rules
	 */
	public static function contains_selector( array $rules, string $selector ): bool {
		$selector = self::normalize_selector( $selector );
		foreach ( $rules as $rule ) {
			if ( isset( $rule['selector'] ) && $rule['selector'] === $selector ) {
				return true;
			}
			if ( isset( $rule['rules'] ) && is_array( $rule['rules'] ) && self::contains_selector( $rule['rules'], $selector ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function parse_sequence( string $source ): array {
		$rules  = array();
		$length = strlen( $source );
		$cursor = 0;
		while ( $cursor < $length ) {
			$cursor = self::skip_space_and_semicolons( $source, $cursor );
			if ( $cursor >= $length ) {
				break;
			}

			$start = $cursor;
			$found = self::find_top_level_delimiter( $source, $cursor );
			if ( null === $found ) {
				throw new ContractLabObservationException( 'malformed', 'Frontend stylesheet contains an unterminated rule.' );
			}
			if ( ';' === $found['delimiter'] ) {
				$statement = trim( substr( $source, $start, $found['position'] - $start ) );
				if ( '' !== $statement ) {
					$rules[] = array( 'statement' => self::normalize_space( $statement ) );
				}
				$cursor = $found['position'] + 1;
				continue;
			}

			$prelude = trim( substr( $source, $start, $found['position'] - $start ) );
			if ( '' === $prelude ) {
				throw new ContractLabObservationException( 'malformed', 'Frontend stylesheet contains an empty rule prelude.' );
			}
			$close = self::find_matching_brace( $source, $found['position'] );
			if ( null === $close ) {
				throw new ContractLabObservationException( 'malformed', 'Frontend stylesheet contains an unclosed rule.' );
			}
			$body = substr( $source, $found['position'] + 1, $close - $found['position'] - 1 );
			if ( str_starts_with( ltrim( $prelude ), '@' ) ) {
				$record = array( 'at_rule' => self::normalize_space( $prelude ) );
				if ( self::has_top_level_open_brace( $body ) ) {
					$record['rules'] = self::parse_sequence( $body );
				} else {
					$record['declarations'] = self::parse_declarations( $body );
				}
				$rules[] = $record;
			} else {
				$rules[] = array(
					'selector'     => self::normalize_selector( $prelude ),
					'declarations' => self::parse_declarations( $body ),
				);
			}
			$cursor = $close + 1;
		}

		return $rules;
	}

	/**
	 * @return array{delimiter: string, position: int}|null
	 */
	private static function find_top_level_delimiter( string $source, int $start ): ?array {
		$quote  = null;
		$parens = 0;
		$length = strlen( $source );
		for ( $index = $start; $index < $length; $index++ ) {
			$character = $source[ $index ];
			if ( null !== $quote ) {
				if ( '\\' === $character ) {
					$index++;
				} elseif ( $character === $quote ) {
					$quote = null;
				}
				continue;
			}
			if ( '"' === $character || "'" === $character ) {
				$quote = $character;
			} elseif ( '(' === $character ) {
				$parens++;
			} elseif ( ')' === $character && $parens > 0 ) {
				$parens--;
			} elseif ( 0 === $parens && ( '{' === $character || ';' === $character ) ) {
				return array( 'delimiter' => $character, 'position' => $index );
			}
		}

		return null;
	}

	private static function find_matching_brace( string $source, int $open ): ?int {
		$depth  = 1;
		$quote  = null;
		$length = strlen( $source );
		for ( $index = $open + 1; $index < $length; $index++ ) {
			$character = $source[ $index ];
			if ( null !== $quote ) {
				if ( '\\' === $character ) {
					$index++;
				} elseif ( $character === $quote ) {
					$quote = null;
				}
				continue;
			}
			if ( '"' === $character || "'" === $character ) {
				$quote = $character;
			} elseif ( '{' === $character ) {
				$depth++;
			} elseif ( '}' === $character ) {
				$depth--;
				if ( 0 === $depth ) {
					return $index;
				}
			}
		}

		return null;
	}

	private static function has_top_level_open_brace( string $source ): bool {
		$delimiter = self::find_top_level_delimiter( $source, 0 );

		return null !== $delimiter && '{' === $delimiter['delimiter'];
	}

	/**
	 * @return array<int, array{property: string, value: string}>
	 */
	private static function parse_declarations( string $source ): array {
		$parts   = array();
		$start   = 0;
		$quote   = null;
		$parens  = 0;
		$length  = strlen( $source );
		for ( $index = 0; $index < $length; $index++ ) {
			$character = $source[ $index ];
			if ( null !== $quote ) {
				if ( '\\' === $character ) {
					$index++;
				} elseif ( $character === $quote ) {
					$quote = null;
				}
				continue;
			}
			if ( '"' === $character || "'" === $character ) {
				$quote = $character;
			} elseif ( '(' === $character ) {
				$parens++;
			} elseif ( ')' === $character && $parens > 0 ) {
				$parens--;
			} elseif ( 0 === $parens && ';' === $character ) {
				$parts[] = substr( $source, $start, $index - $start );
				$start   = $index + 1;
			}
		}
		$parts[] = substr( $source, $start );

		$declarations = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$colon = self::find_top_level_colon( $part );
			if ( null === $colon ) {
				throw new ContractLabObservationException( 'malformed', 'Frontend stylesheet contains a declaration without a colon.' );
			}
			$property = trim( substr( $part, 0, $colon ) );
			$value    = self::normalize_space( substr( $part, $colon + 1 ) );
			if ( '' === $property || '' === $value || preg_match( '/[{}]/', $property . $value ) ) {
				throw new ContractLabObservationException( 'malformed', 'Frontend stylesheet contains an invalid declaration.' );
			}
			$declarations[] = array( 'property' => $property, 'value' => $value );
		}

		return $declarations;
	}

	private static function find_top_level_colon( string $source ): ?int {
		$quote  = null;
		$parens = 0;
		$length = strlen( $source );
		for ( $index = 0; $index < $length; $index++ ) {
			$character = $source[ $index ];
			if ( null !== $quote ) {
				if ( '\\' === $character ) {
					$index++;
				} elseif ( $character === $quote ) {
					$quote = null;
				}
				continue;
			}
			if ( '"' === $character || "'" === $character ) {
				$quote = $character;
			} elseif ( '(' === $character ) {
				$parens++;
			} elseif ( ')' === $character && $parens > 0 ) {
				$parens--;
			} elseif ( 0 === $parens && ':' === $character ) {
				return $index;
			}
		}

		return null;
	}

	private static function normalize_selector( string $selector ): string {
		$selector = trim( self::normalize_space( $selector ) );
		if ( '' === $selector ) {
			throw new ContractLabObservationException( 'malformed', 'Frontend stylesheet contains an empty selector.' );
		}

		return $selector;
	}

	private static function normalize_space( string $value ): string {
		$normalized = preg_replace( '/\s+/u', ' ', trim( $value ) );

		return is_string( $normalized ) ? $normalized : trim( $value );
	}

	private static function skip_space_and_semicolons( string $source, int $cursor ): int {
		$length = strlen( $source );
		while ( $cursor < $length && ( ctype_space( $source[ $cursor ] ) || ';' === $source[ $cursor ] ) ) {
			$cursor++;
		}

		return $cursor;
	}
}
