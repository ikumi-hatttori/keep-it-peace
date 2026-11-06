<?php
/**
 * Detects request input reaching sinks without sanitization.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules\Detectors;

use KeepItPeace\Rules\SourceFile;

defined( 'ABSPATH' ) || exit;

/**
 * Looks at each $_GET/$_POST/$_REQUEST/$_COOKIE use within its statement:
 * enclosing calls decide whether it is sanitized, and the statement start or
 * enclosing call decides the sink (output, SQL, include, file).
 *
 * This is a heuristic: values copied into variables first are not tracked.
 */
class SuperglobalDetector extends AbstractDetector {

	const INPUTS = array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE' );

	const SAFE = array(
		'absint',
		'intval',
		'floatval',
		'boolval',
		'isset',
		'empty',
		'count',
		'in_array',
		'array_key_exists',
		'wp_verify_nonce',
		'check_admin_referer',
		'check_ajax_referer',
		'htmlspecialchars',
		'htmlentities',
		'urlencode',
		'rawurlencode',
		'json_encode',
		'wp_json_encode',
		'md5',
		'sha1',
		'hash',
		'wp_hash',
		'password_verify',
		'filter_var',
		'preg_match',
		'number_format',
		'strlen',
		'->prepare',
		'wp_validate_redirect',
		'wp_safe_redirect',
	);

	const SAFE_PREFIXES = array( 'sanitize_', 'esc_', 'wp_kses', 'is_', 'ctype_' );

	const SQL_SINKS = array( '->query', '->get_results', '->get_row', '->get_var', '->get_col', 'mysqli_query', 'mysql_query', '->exec' );

	const FILE_SINKS = array( 'file_get_contents', 'file_put_contents', 'fopen', 'readfile', 'unlink', 'file', 'copy', 'rename', 'opendir', 'scandir', 'mkdir', 'rmdir', 'touch', 'highlight_file', 'show_source', 'parse_ini_file' );

	const OUTPUT_SINKS = array( 'printf', 'vprintf', 'print_r', 'var_dump', 'die', 'exit', 'wp_die' );

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'superglobal_unsanitized';
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
		foreach ( $tokens as $i => $token ) {
			if ( ! self::is_type( $token, array( T_VARIABLE ) ) || ! in_array( $token[1], self::INPUTS, true ) ) {
				continue;
			}
			$analysis = $this->analyze( $tokens, $i );
			if ( $analysis['safe'] || null === $analysis['sink'] ) {
				continue;
			}
			$out[] = array(
				'rule_id' => 'php.input_' . $analysis['sink'],
				'line'    => $token[2],
				'meta'    => array( 'input' => $token[1] ),
			);
		}
		return $out;
	}

	/**
	 * Walks back from the variable to the statement start.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $index  Variable index.
	 * @return array{safe:bool,sink:?string}
	 */
	private function analyze( array $tokens, int $index ): array {
		$depth     = 0;
		$enclosing = array();
		$sink      = null;
		$safe      = false;

		for ( $j = $index - 1; $j >= 0; $j-- ) {
			$token = $tokens[ $j ];
			if ( self::is_char( $token, ')' ) || self::is_char( $token, ']' ) ) {
				++$depth;
				continue;
			}
			if ( self::is_char( $token, '(' ) || self::is_char( $token, '[' ) ) {
				if ( $depth > 0 ) {
					--$depth;
					continue;
				}
				if ( self::is_char( $token, '(' ) ) {
					$enclosing[] = $this->callee( $tokens, $j );
				}
				continue;
			}
			if ( self::is_char( $token, '}' ) ) {
				// Skip "{$var}" interpolation inside strings; a real block boundary ends the statement.
				$open = self::find_open_backward( $tokens, $j );
				if ( null !== $open && self::is_type( $tokens[ $open ], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ) ) ) {
					$j = $open;
					continue;
				}
				break;
			}
			if ( self::is_char( $token, '{' ) || self::is_type( $token, array( T_OPEN_TAG, T_CLOSE_TAG, T_RETURN, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ) ) ) {
				break;
			}
			if ( $depth > 0 ) {
				continue;
			}
			if ( self::is_char( $token, ';' ) ) {
				break;
			}
			if ( self::is_type( $token, array( T_INT_CAST, T_DOUBLE_CAST, T_BOOL_CAST ) ) ) {
				$safe = true;
			}
			if ( self::is_type( $token, array( T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO ) ) ) {
				$sink = 'output';
				break;
			}
			if ( self::is_type( $token, array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ) ) ) {
				$sink = 'include';
				break;
			}
		}

		foreach ( $enclosing as $name ) {
			if ( self::is_safe_function( $name ) ) {
				$safe = true;
			}
		}
		if ( null === $sink ) {
			foreach ( $enclosing as $name ) {
				if ( in_array( $name, self::SQL_SINKS, true ) ) {
					$sink = 'sql';
				} elseif ( in_array( $name, self::FILE_SINKS, true ) ) {
					$sink = 'file';
				} elseif ( in_array( $name, self::OUTPUT_SINKS, true ) ) {
					$sink = 'output';
				}
				if ( null !== $sink ) {
					break;
				}
			}
		}
		return array(
			'safe' => $safe,
			'sink' => $sink,
		);
	}

	/**
	 * Index of the opening brace matching the "}" at $close.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $close  Index.
	 * @return int|null
	 */
	private static function find_open_backward( array $tokens, int $close ): ?int {
		$depth = 0;
		for ( $k = $close; $k >= 0; $k-- ) {
			$token = $tokens[ $k ];
			if ( self::is_char( $token, '}' ) ) {
				++$depth;
			} elseif ( self::is_char( $token, '{' ) || self::is_type( $token, array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ) ) ) {
				--$depth;
				if ( 0 === $depth ) {
					return $k;
				}
			}
		}
		return null;
	}

	/**
	 * Name of the function whose "(" is at $open ('' for grouping parentheses).
	 *
	 * @param array $tokens Tokens.
	 * @param int   $open   Index.
	 * @return string
	 */
	private function callee( array $tokens, int $open ): string {
		$prev = $tokens[ $open - 1 ] ?? null;
		if ( null === $prev ) {
			return '';
		}
		if ( self::is_name( $prev ) ) {
			$name   = self::name( $prev );
			$before = $tokens[ $open - 2 ] ?? null;
			if ( self::is_object_operator( $before ) ) {
				return '->' . $name;
			}
			if ( self::is_type( $before, array( T_DOUBLE_COLON ) ) ) {
				return '::' . $name;
			}
			return $name;
		}
		if ( self::is_type( $prev, array( T_ISSET, T_EMPTY, T_EXIT, T_EVAL, T_UNSET, T_PRINT ) ) ) {
			return strtolower( $prev[1] );
		}
		return '';
	}

	/**
	 * Whether a function sanitizes or only inspects the value.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	private static function is_safe_function( string $name ): bool {
		if ( in_array( $name, self::SAFE, true ) ) {
			return true;
		}
		foreach ( self::SAFE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
