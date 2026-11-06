<?php
/**
 * Detects AJAX/admin-post/REST/form handlers without nonce or capability checks.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules\Detectors;

use KeepItPeace\Rules\SourceFile;

defined( 'ABSPATH' ) || exit;

/**
 * Follows callbacks registered in the same file and inspects their bodies.
 */
class RequestGuardDetector extends AbstractDetector {

	const NONCE_FUNCTIONS = array( 'check_ajax_referer', 'wp_verify_nonce', 'check_admin_referer' );

	const CAPABILITY_FUNCTIONS = array( 'current_user_can', 'user_can' );

	const WRITE_FUNCTIONS = array(
		'update_option',
		'add_option',
		'delete_option',
		'update_post_meta',
		'add_post_meta',
		'delete_post_meta',
		'update_user_meta',
		'delete_user_meta',
		'wp_insert_post',
		'wp_update_post',
		'wp_delete_post',
		'wp_insert_user',
		'wp_update_user',
		'wp_delete_user',
		'wp_set_password',
		'wp_mail',
		'move_uploaded_file',
		'file_put_contents',
		'->insert',
		'->update',
		'->delete',
		'->replace',
	);

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'request_guard';
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

		for ( $i = 0; $i + 1 < $count; $i++ ) {
			if ( ! self::is_name( $tokens[ $i ] ) || ! self::is_char( $tokens[ $i + 1 ], '(' ) ) {
				continue;
			}
			$prev = $tokens[ $i - 1 ] ?? null;
			if ( self::is_object_operator( $prev ) || self::is_type( $prev, array( T_FUNCTION, T_DOUBLE_COLON ) ) ) {
				continue;
			}
			$name = self::name( $tokens[ $i ] );
			if ( 'add_action' === $name ) {
				$out = array_merge( $out, $this->check_hook( $tokens, $i ) );
			} elseif ( 'register_rest_route' === $name ) {
				$out = array_merge( $out, $this->check_rest_route( $tokens, $i ) );
			}
		}

		return array_merge( $out, $this->check_form_processing( $tokens ) );
	}

	/**
	 * Checks add_action( 'wp_ajax_*' | 'admin_post_*', callback ).
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Index of add_action.
	 * @return array[]
	 */
	private function check_hook( array $tokens, int $i ): array {
		$args = self::arguments( $tokens, $i + 1 );
		if ( count( $args ) < 2 || 1 !== count( $args[0] ) ) {
			return array();
		}
		$hook = self::string_value( $args[0][0] );
		if ( null === $hook || ! preg_match( '/^(wp_ajax_|admin_post_)(nopriv_)?/', $hook, $m ) ) {
			return array();
		}
		$public = ! empty( $m[2] );
		$body   = $this->callback_body( $tokens, $args[1] );
		if ( null === $body ) {
			return array();
		}
		$text = self::text( $body );
		$line = $tokens[ $i ][2];
		$out  = array();
		if ( ! self::contains_call( $text, self::NONCE_FUNCTIONS ) ) {
			$out[] = array(
				'rule_id'  => 'php.ajax_missing_nonce',
				'line'     => $line,
				'severity' => $public ? 'medium' : 'high',
				'meta'     => array( 'hook' => $hook ),
			);
		}
		if ( ! $public && ! self::contains_call( $text, self::CAPABILITY_FUNCTIONS ) ) {
			$out[] = array(
				'rule_id' => 'php.ajax_missing_capability',
				'line'    => $line,
				'meta'    => array( 'hook' => $hook ),
			);
		}
		return $out;
	}

	/**
	 * Checks register_rest_route without permission_callback, or with __return_true.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Index.
	 * @return array[]
	 */
	private function check_rest_route( array $tokens, int $i ): array {
		$args = self::arguments( $tokens, $i + 1 );
		if ( count( $args ) < 3 ) {
			return array();
		}
		$options = $args[2];
		$first   = $options[0] ?? null;
		// Options passed as a variable or function result cannot be followed.
		if ( ! self::is_type( $first, array( T_ARRAY ) ) && ! self::is_char( $first, '[' ) ) {
			return array();
		}
		$line  = $tokens[ $i ][2];
		$found = false;
		$n     = count( $options );
		for ( $k = 0; $k < $n; $k++ ) {
			if ( 'permission_callback' !== self::string_value( $options[ $k ] ) ) {
				continue;
			}
			$found = true;
			$value = $options[ $k + 2 ] ?? null;
			if ( $value && '__return_true' === self::string_value( $value ) ) {
				return array(
					array(
						'rule_id' => 'php.rest_public_endpoint',
						'line'    => $line,
					),
				);
			}
		}
		if ( ! $found ) {
			return array(
				array(
					'rule_id' => 'php.rest_missing_permission',
					'line'    => $line,
				),
			);
		}
		return array();
	}

	/**
	 * $_POST processing with state-changing calls and no nonce check anywhere in the file.
	 *
	 * @param array $tokens Tokens.
	 * @return array[]
	 */
	private function check_form_processing( array $tokens ): array {
		$first_post = null;
		$writes     = false;
		$nonce      = false;
		$count      = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( null === $first_post && self::is_type( $token, array( T_VARIABLE ) ) && '$_POST' === $token[1] ) {
				$first_post = $token[2];
			}
			if ( ! self::is_name( $token ) || ! self::is_char( $tokens[ $i + 1 ] ?? null, '(' ) ) {
				continue;
			}
			$name = self::name( $token );
			if ( self::is_object_operator( $tokens[ $i - 1 ] ?? null ) ) {
				$name = '->' . $name;
			}
			if ( in_array( $name, self::NONCE_FUNCTIONS, true ) ) {
				$nonce = true;
			}
			if ( in_array( $name, self::WRITE_FUNCTIONS, true ) ) {
				$writes = true;
			}
		}
		if ( null !== $first_post && $writes && ! $nonce ) {
			return array(
				array(
					'rule_id' => 'php.form_missing_nonce',
					'line'    => $first_post,
				),
			);
		}
		return array();
	}

	/**
	 * Body tokens of a callback argument, if defined in this file.
	 *
	 * @param array $tokens   All tokens.
	 * @param array $callback Argument tokens.
	 * @return array|null
	 */
	private function callback_body( array $tokens, array $callback ): ?array {
		if ( empty( $callback ) ) {
			return null;
		}
		// Closure or arrow function.
		$head = $callback[0];
		if ( self::is_type( $head, array( T_STATIC ) ) ) {
			$head = $callback[1] ?? null;
		}
		if ( self::is_type( $head, array( T_FUNCTION, 'T_FN' ) ) ) {
			return $callback;
		}

		// Named callbacks: a function name string, a Class::method string or an array with a method name.
		$name = null;
		foreach ( array_reverse( $callback ) as $token ) {
			$value = self::string_value( $token );
			if ( null !== $value ) {
				$parts = explode( '::', $value );
				$name  = strtolower( (string) end( $parts ) );
				break;
			}
		}
		if ( null === $name || '' === $name ) {
			return null;
		}
		$count = count( $tokens );
		for ( $i = 0; $i + 1 < $count; $i++ ) {
			if ( ! self::is_type( $tokens[ $i ], array( T_FUNCTION ) ) ) {
				continue;
			}
			$next = $tokens[ $i + 1 ];
			if ( self::is_char( $next, '&' ) ) {
				$next = $tokens[ $i + 2 ] ?? null;
			}
			if ( ! self::is_type( $next, array( T_STRING ) ) || strtolower( $next[1] ) !== $name ) {
				continue;
			}
			for ( $k = $i; $k < $count; $k++ ) {
				if ( self::is_char( $tokens[ $k ], ';' ) ) {
					return null; // Abstract or interface method.
				}
				if ( self::is_char( $tokens[ $k ], '{' ) ) {
					$close = self::find_close( $tokens, $k );
					return null === $close ? null : array_slice( $tokens, $k, $close - $k + 1 );
				}
			}
		}
		return null;
	}

	/**
	 * Whether lowercase text contains a call to one of the functions.
	 *
	 * @param string   $text      Text from self::text().
	 * @param string[] $functions Functions.
	 * @return bool
	 */
	private static function contains_call( string $text, array $functions ): bool {
		foreach ( $functions as $function ) {
			if ( preg_match( '/(?<![a-z0-9_])' . preg_quote( $function, '/' ) . ' \(/', $text ) ) {
				return true;
			}
		}
		return false;
	}
}
