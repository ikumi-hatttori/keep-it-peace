<?php
/**
 * Detects variable function calls and backtick shell execution.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules\Detectors;

use KeepItPeace\Rules\SourceFile;

defined( 'ABSPATH' ) || exit;

/**
 * `$func(...)` and `` `cmd` ``.
 */
class VariableFunctionDetector extends AbstractDetector {

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'variable_function';
	}

	/**
	 * Detects.
	 *
	 * @param SourceFile $source Source.
	 * @return array[]
	 */
	public function detect( SourceFile $source ): array {
		$tokens   = $source->significant();
		$out      = array();
		$backtick = false;
		$count    = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( self::is_char( $token, '`' ) ) {
				if ( ! $backtick ) {
					$out[] = array(
						'rule_id' => 'php.backtick_exec',
						'line'    => $token[2],
					);
				}
				$backtick = ! $backtick;
				continue;
			}
			if ( ! self::is_type( $token, array( T_VARIABLE ) ) || '$this' === $token[1] ) {
				continue;
			}
			if ( ! isset( $tokens[ $i + 1 ] ) || ! self::is_char( $tokens[ $i + 1 ], '(' ) ) {
				continue;
			}
			$prev = $tokens[ $i - 1 ] ?? null;
			if ( self::is_object_operator( $prev ) || self::is_type( $prev, array( T_DOUBLE_COLON, T_NEW, T_FUNCTION ) ) ) {
				continue;
			}
			$out[] = array(
				'rule_id' => 'php.variable_function',
				'line'    => $token[2],
				'meta'    => array( 'variable' => $token[1] ),
			);
		}
		return $out;
	}
}
