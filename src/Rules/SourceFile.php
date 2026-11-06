<?php
/**
 * Source code helper for rules and detectors.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules;

use KeepItPeace\Scan\FileInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily tokenizes source code and provides line helpers.
 */
class SourceFile {

	/**
	 * Code.
	 *
	 * @var string
	 */
	private $code;
	/**
	 * Extension.
	 *
	 * @var string
	 */
	private $extension;
	/**
	 * Tokens.
	 *
	 * @var array|null
	 */
	private $tokens = null;
	/**
	 * Significant.
	 *
	 * @var array|null
	 */
	private $significant = null;
	/**
	 * Code view.
	 *
	 * @var string|null
	 */
	private $code_view = null;
	/**
	 * Byte offset of each line start.
	 *
	 * @var int[]|null
	 */
	private $line_starts = null;
	/**
	 * Lines.
	 *
	 * @var string[]|null
	 */
	private $lines = null;

	/**
	 * Constructor.
	 *
	 * @param string $code      Source.
	 * @param string $extension Lowercase extension.
	 */
	public function __construct( string $code, string $extension ) {
		$this->code      = $code;
		$this->extension = $extension;
	}

	/**
	 * Source.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Extension.
	 *
	 * @return string
	 */
	public function extension(): string {
		return $this->extension;
	}

	/**
	 * Whether the file is PHP.
	 *
	 * @return bool
	 */
	public function is_php(): bool {
		return in_array( $this->extension, FileInfo::PHP_EXTENSIONS, true );
	}

	/**
	 * All tokens as [id|null, text, line]. Single-character tokens have id null.
	 *
	 * @return array
	 */
	public function tokens(): array {
		if ( null === $this->tokens ) {
			$this->tokens = array();
			if ( $this->is_php() ) {
				$line = 1;
				foreach ( token_get_all( $this->code ) as $token ) {
					if ( is_array( $token ) ) {
						$this->tokens[] = array( $token[0], $token[1], $token[2] );
						$line           = $token[2] + substr_count( $token[1], "\n" );
					} else {
						$this->tokens[] = array( null, $token, $line );
					}
				}
			}
		}
		return $this->tokens;
	}

	/**
	 * Tokens without whitespace and comments.
	 *
	 * @return array
	 */
	public function significant(): array {
		if ( null === $this->significant ) {
			$this->significant = array();
			foreach ( $this->tokens() as $token ) {
				if ( in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$this->significant[] = $token;
			}
		}
		return $this->significant;
	}

	/**
	 * Source with comments and inline HTML blanked (newlines kept so offsets map to lines).
	 *
	 * @return string
	 */
	public function code_view(): string {
		if ( null === $this->code_view ) {
			if ( ! $this->is_php() ) {
				$this->code_view = $this->code;
			} else {
				$out = '';
				foreach ( $this->tokens() as $token ) {
					if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML ), true ) ) {
						$out .= preg_replace( '/[^\n]/', ' ', $token[1] );
					} else {
						$out .= $token[1];
					}
				}
				$this->code_view = $out;
			}
		}
		return $this->code_view;
	}

	/**
	 * Line number (1-based) of a byte offset.
	 *
	 * @param int $offset Offset.
	 * @return int
	 */
	public function line_at( int $offset ): int {
		if ( null === $this->line_starts ) {
			$this->line_starts = array( 0 );
			$pos               = -1;
			while ( false !== ( $pos = strpos( $this->code, "\n", $pos + 1 ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$this->line_starts[] = $pos + 1;
			}
		}
		$low  = 0;
		$high = count( $this->line_starts ) - 1;
		while ( $low < $high ) {
			$mid = (int) ( ( $low + $high + 1 ) / 2 );
			if ( $this->line_starts[ $mid ] <= $offset ) {
				$low = $mid;
			} else {
				$high = $mid - 1;
			}
		}
		return $low + 1;
	}

	/**
	 * Text of a line.
	 *
	 * @param int $line 1-based line.
	 * @return string
	 */
	public function line_text( int $line ): string {
		if ( null === $this->lines ) {
			$this->lines = preg_split( '/\r\n|\n|\r/', $this->code );
		}
		return $this->lines[ $line - 1 ] ?? '';
	}
}
