<?php
/**
 * Detects long encoded or escaped strings typical of injected code.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules\Detectors;

use KeepItPeace\Rules\SourceFile;

defined( 'ABSPATH' ) || exit;

/**
 * Base64 blobs, \x escape runs and chr() chains.
 */
class ObfuscationDetector extends AbstractDetector {

	const MIN_BASE64_LENGTH = 1000;
	const MIN_ESCAPES       = 40;
	const MIN_CHR_CALLS     = 25;

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'obfuscation';
	}

	/**
	 * Detects.
	 *
	 * @param SourceFile $source Source.
	 * @return array[]
	 */
	public function detect( SourceFile $source ): array {
		$out       = array();
		$chr_calls = 0;
		$chr_line  = 0;
		$tokens    = $source->significant();
		foreach ( $tokens as $i => $token ) {
			if ( self::is_type( $token, array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ) ) ) {
				$value = $token[1];
				$inner = trim( $value, "'\"" );
				if ( strlen( $inner ) >= self::MIN_BASE64_LENGTH && preg_match( '/^[A-Za-z0-9+\/=\s]+$/', $inner ) ) {
					$out[] = array(
						'rule_id' => 'php.obfuscated_string',
						'line'    => $token[2],
						'meta'    => array(
							'kind'   => 'base64',
							'length' => strlen( $inner ),
						),
					);
				} elseif ( preg_match_all( '/\\\\(x[0-9a-fA-F]{2}|[0-7]{3})/', $value ) >= self::MIN_ESCAPES ) {
					$out[] = array(
						'rule_id' => 'php.obfuscated_string',
						'line'    => $token[2],
						'meta'    => array( 'kind' => 'escapes' ),
					);
				}
				continue;
			}
			if ( self::is_name( $token ) && 'chr' === self::name( $token ) && self::is_char( $tokens[ $i + 1 ] ?? null, '(' ) ) {
				++$chr_calls;
				if ( 0 === $chr_line ) {
					$chr_line = $token[2];
				}
			}
		}
		if ( $chr_calls >= self::MIN_CHR_CALLS ) {
			$out[] = array(
				'rule_id' => 'php.obfuscated_string',
				'line'    => $chr_line,
				'meta'    => array(
					'kind'  => 'chr',
					'count' => $chr_calls,
				),
			);
		}
		return $out;
	}
}
