<?php
/**
 * Detection rule.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules;

use KeepItPeace\Findings\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * A regex or detector rule loaded from a rule set.
 */
class Rule {

	/**
	 * Id.
	 *
	 * @var string
	 */
	public $id = '';
	/**
	 * Regex|detector.
	 *
	 * @var string
	 */
	public $type = 'regex';
	/**
	 * Severity.
	 *
	 * @var int
	 */
	public $severity = Severity::MEDIUM;
	/**
	 * Category.
	 *
	 * @var string
	 */
	public $category = 'general';
	/**
	 * Regex body without delimiters.
	 *
	 * @var string
	 */
	public $pattern = '';
	/**
	 * Regex modifiers.
	 *
	 * @var string
	 */
	public $flags = '';
	/**
	 * Code (comments and inline HTML removed) | raw.
	 *
	 * @var string
	 */
	public $target = 'code';
	/**
	 * File type groups: php, js, html, any.
	 *
	 * @var string[]
	 */
	public $file_types = array( 'php' );
	/**
	 * Detector name for detector rules.
	 *
	 * @var string
	 */
	public $detector = '';
	/**
	 * Title.
	 *
	 * @var string
	 */
	public $title = '';
	/**
	 * Description.
	 *
	 * @var string
	 */
	public $description = '';
	/**
	 * Recommendation.
	 *
	 * @var string
	 */
	public $recommendation = '';
	/**
	 * Provider ID.
	 *
	 * @var string
	 */
	public $source = '';

	/**
	 * Builds and validates a rule.
	 *
	 * @param array  $data   Data.
	 * @param string $source Provider ID.
	 * @return self|null Null when invalid.
	 */
	public static function from_array( array $data, string $source ): ?self {
		$rule = new self();
		if ( empty( $data['id'] ) || ! is_string( $data['id'] ) || ! preg_match( '/^[a-z0-9_.\-]+$/', $data['id'] ) ) {
			return null;
		}
		$rule->id             = $data['id'];
		$rule->type           = isset( $data['type'] ) && 'detector' === $data['type'] ? 'detector' : 'regex';
		$rule->severity       = Severity::from( $data['severity'] ?? 'medium' );
		$rule->category       = isset( $data['category'] ) ? (string) $data['category'] : 'general';
		$rule->pattern        = isset( $data['pattern'] ) ? (string) $data['pattern'] : '';
		$rule->flags          = isset( $data['flags'] ) ? preg_replace( '/[^imsxuU]/', '', (string) $data['flags'] ) : '';
		$rule->target         = isset( $data['target'] ) && 'raw' === $data['target'] ? 'raw' : 'code';
		$rule->file_types     = isset( $data['file_types'] ) ? array_map( 'strval', (array) $data['file_types'] ) : array( 'php' );
		$rule->detector       = isset( $data['detector'] ) ? (string) $data['detector'] : '';
		$rule->title          = isset( $data['title'] ) ? (string) $data['title'] : $rule->id;
		$rule->description    = isset( $data['description'] ) ? (string) $data['description'] : '';
		$rule->recommendation = isset( $data['recommendation'] ) ? (string) $data['recommendation'] : '';
		$rule->source         = $source;

		if ( 'regex' === $rule->type && ( '' === $rule->pattern || null === $rule->regex() ) ) {
			return null;
		}
		if ( 'detector' === $rule->type && '' === $rule->detector ) {
			return null;
		}
		return $rule;
	}

	/**
	 * Full regex with delimiters, or null when invalid.
	 *
	 * @return string|null
	 */
	public function regex(): ?string {
		$regex = '#' . preg_replace( '/(?<!\\\\)#/', '\\#', $this->pattern ) . '#' . $this->flags;
		// Validate without emitting warnings.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Temporary, to validate user-supplied patterns.
			static function () {
				return true;
			}
		);
		$valid = false !== preg_match( $regex, '' );
		restore_error_handler();
		return $valid ? $regex : null;
	}

	/**
	 * Whether the rule applies to a file extension.
	 *
	 * @param string $extension Lowercase extension.
	 * @return bool
	 */
	public function applies_to( string $extension ): bool {
		foreach ( $this->file_types as $type ) {
			if ( 'any' === $type || $type === $extension ) {
				return true;
			}
			if ( 'php' === $type && in_array( $extension, \KeepItPeace\Scan\FileInfo::PHP_EXTENSIONS, true ) ) {
				return true;
			}
			if ( 'js' === $type && in_array( $extension, array( 'js', 'mjs', 'cjs' ), true ) ) {
				return true;
			}
			if ( 'html' === $type && in_array( $extension, array( 'html', 'htm' ), true ) ) {
				return true;
			}
		}
		return false;
	}
}
