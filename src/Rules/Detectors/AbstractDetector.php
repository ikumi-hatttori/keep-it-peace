<?php
/**
 * Token helpers for detectors.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules\Detectors;

use KeepItPeace\Contracts\DetectorInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Base class with token utilities. Tokens are [id|null, text, line].
 */
abstract class AbstractDetector implements DetectorInterface {

	/**
	 * Whether a token is a given single character.
	 *
	 * @param array|null $token Token.
	 * @param string     $char  Character(s).
	 * @return bool
	 */
	protected static function is_char( $token, string $char ): bool {
		return is_array( $token ) && null === $token[0] && $token[1] === $char;
	}

	/**
	 * Whether a token has one of the given IDs (undefined constant names are ignored).
	 *
	 * @param array|null $token Token.
	 * @param array      $ids   Token IDs or constant names.
	 * @return bool
	 */
	protected static function is_type( $token, array $ids ): bool {
		if ( ! is_array( $token ) || null === $token[0] ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( is_string( $id ) ) {
				if ( ! defined( $id ) ) {
					continue;
				}
				$id = constant( $id );
			}
			if ( $token[0] === $id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a token is an object operator (-> or ?->).
	 *
	 * @param array|null $token Token.
	 * @return bool
	 */
	protected static function is_object_operator( $token ): bool {
		return self::is_type( $token, array( T_OBJECT_OPERATOR, 'T_NULLSAFE_OBJECT_OPERATOR' ) );
	}

	/**
	 * Whether a token is a name (function, constant, class).
	 *
	 * @param array|null $token Token.
	 * @return bool
	 */
	protected static function is_name( $token ): bool {
		return self::is_type( $token, array( T_STRING, 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE' ) );
	}

	/**
	 * Lowercase unqualified name of a name token.
	 *
	 * @param array $token Token.
	 * @return string
	 */
	protected static function name( array $token ): string {
		$parts = explode( '\\', $token[1] );
		return strtolower( (string) end( $parts ) );
	}

	/**
	 * Index of the bracket matching the opening bracket at $open.
	 *
	 * @param array $tokens Significant tokens.
	 * @param int   $open   Index of an opening ( [ or {.
	 * @return int|null
	 */
	protected static function find_close( array $tokens, int $open ): ?int {
		$depth = 0;
		$count = count( $tokens );
		for ( $i = $open; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( self::is_opening( $token ) ) {
				++$depth;
			} elseif ( self::is_closing( $token ) ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return null;
	}

	/**
	 * Opening bracket.
	 *
	 * @param array $token Token.
	 * @return bool
	 */
	protected static function is_opening( array $token ): bool {
		return self::is_char( $token, '(' ) || self::is_char( $token, '[' ) || self::is_char( $token, '{' )
			|| self::is_type( $token, array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ) );
	}

	/**
	 * Closing bracket.
	 *
	 * @param array $token Token.
	 * @return bool
	 */
	protected static function is_closing( array $token ): bool {
		return self::is_char( $token, ')' ) || self::is_char( $token, ']' ) || self::is_char( $token, '}' );
	}

	/**
	 * Splits call arguments between $open and its closing parenthesis at top-level commas.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $open   Index of "(".
	 * @return array[] Lists of tokens per argument.
	 */
	protected static function arguments( array $tokens, int $open ): array {
		$close = self::find_close( $tokens, $open );
		if ( null === $close ) {
			return array();
		}
		$args    = array();
		$current = array();
		$depth   = 0;
		for ( $i = $open + 1; $i < $close; $i++ ) {
			$token = $tokens[ $i ];
			if ( self::is_opening( $token ) ) {
				++$depth;
			} elseif ( self::is_closing( $token ) ) {
				--$depth;
			}
			if ( 0 === $depth && self::is_char( $token, ',' ) ) {
				$args[]  = $current;
				$current = array();
				continue;
			}
			$current[] = $token;
		}
		if ( $current ) {
			$args[] = $current;
		}
		return $args;
	}

	/**
	 * Value of a quoted string literal token.
	 *
	 * @param array $token Token.
	 * @return string|null
	 */
	protected static function string_value( array $token ): ?string {
		if ( ! self::is_type( $token, array( T_CONSTANT_ENCAPSED_STRING ) ) ) {
			return null;
		}
		return substr( $token[1], 1, -1 );
	}

	/**
	 * Lowercase concatenated text of tokens.
	 *
	 * @param array $tokens Tokens.
	 * @return string
	 */
	protected static function text( array $tokens ): string {
		$out = '';
		foreach ( $tokens as $token ) {
			$out .= $token[1] . ' ';
		}
		return strtolower( $out );
	}
}
