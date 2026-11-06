<?php
/**
 * Detects $wpdb queries built without prepare().
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules\Detectors;

use KeepItPeace\Rules\SourceFile;

defined( 'ABSPATH' ) || exit;

/**
 * `$wpdb->query( "... $var" )` and similar.
 */
class WpdbDetector extends AbstractDetector {

	const METHODS = array( 'query', 'get_results', 'get_row', 'get_var', 'get_col' );

	const ESCAPES = array( 'esc_sql', 'absint', 'intval', 'prepare', 'implode_ints' );

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'wpdb_unprepared';
	}

	/**
	 * Detects.
	 *
	 * @param SourceFile $source Source.
	 * @return array[]
	 */
	public function detect( SourceFile $source ): array {
		$tokens = $source->significant();
		$out    = array();
		$count  = count( $tokens );
		for ( $i = 0; $i + 3 < $count; $i++ ) {
			if ( ! self::is_type( $tokens[ $i ], array( T_VARIABLE ) )
				|| ! self::is_object_operator( $tokens[ $i + 1 ] )
				|| ! self::is_type( $tokens[ $i + 2 ], array( T_STRING ) )
				|| ! in_array( strtolower( $tokens[ $i + 2 ][1] ), self::METHODS, true )
				|| ! self::is_char( $tokens[ $i + 3 ], '(' ) ) {
				continue;
			}
			$args = self::arguments( $tokens, $i + 3 );
			if ( empty( $args ) || empty( $args[0] ) ) {
				continue;
			}
			$verdict = $this->classify( $args[0], $tokens, $i );
			if ( null === $verdict ) {
				continue;
			}
			$out[] = array(
				'rule_id'  => 'php.wpdb_unprepared',
				'line'     => $tokens[ $i ][2],
				'severity' => $verdict,
				'meta'     => array( 'method' => strtolower( $tokens[ $i + 2 ][1] ) ),
			);
		}
		return $out;
	}

	/**
	 * Severity slug when the query is unsafe, null when safe.
	 *
	 * @param array $arg    Tokens of the first argument.
	 * @param array $tokens All tokens.
	 * @param int   $call   Index of the receiver variable.
	 * @return string|null
	 */
	private function classify( array $arg, array $tokens, int $call ): ?string {
		foreach ( $arg as $token ) {
			if ( self::is_name( $token ) && in_array( self::name( $token ), self::ESCAPES, true ) ) {
				return null;
			}
		}

		// A single variable: safe if it was assigned from ->prepare( earlier in the file.
		if ( 1 === count( $arg ) && self::is_type( $arg[0], array( T_VARIABLE ) ) ) {
			return $this->assigned_from_prepare( $arg[0][1], $tokens, $call ) ? null : 'medium';
		}

		$n = count( $arg );
		for ( $k = 0; $k < $n; $k++ ) {
			$token = $arg[ $k ];
			if ( ! self::is_type( $token, array( T_VARIABLE ) ) ) {
				continue;
			}
			// $wpdb->posts, $wpdb->prefix, $this->table: property access is treated as safe.
			$next  = $arg[ $k + 1 ] ?? null;
			$prop  = $arg[ $k + 2 ] ?? null;
			$after = $arg[ $k + 3 ] ?? null;
			if ( self::is_object_operator( $next ) && self::is_type( $prop, array( T_STRING ) ) && ! self::is_char( $after, '(' ) ) {
				$k += 2;
				continue;
			}
			return 'high';
		}
		return null;
	}

	/**
	 * Whether `$name = $x->prepare(` appears before the call.
	 *
	 * @param string $name   Variable.
	 * @param array  $tokens Tokens.
	 * @param int    $before Index.
	 * @return bool
	 */
	private function assigned_from_prepare( string $name, array $tokens, int $before ): bool {
		for ( $k = $before - 1; $k >= 0; $k-- ) {
			if ( ! self::is_type( $tokens[ $k ], array( T_VARIABLE ) ) || $tokens[ $k ][1] !== $name || ! self::is_char( $tokens[ $k + 1 ] ?? null, '=' ) ) {
				continue;
			}
			$assignment = '';
			$m          = $k + 2;
			while ( isset( $tokens[ $m ] ) && ! self::is_char( $tokens[ $m ], ';' ) ) {
				$assignment .= $tokens[ $m ][1];
				++$m;
			}
			return false !== stripos( $assignment, '->prepare(' ) || false !== stripos( $assignment, 'esc_sql(' );
		}
		return false;
	}
}
