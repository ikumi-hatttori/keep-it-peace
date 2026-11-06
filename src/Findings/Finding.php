<?php
/**
 * Finding value object.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Findings;

defined( 'ABSPATH' ) || exit;

/**
 * A detected problem. Checks create instances with `Finding::create()`.
 */
class Finding {

	/**
	 * Id.
	 *
	 * @var int
	 */
	public $id = 0;
	/**
	 * Check id.
	 *
	 * @var string
	 */
	public $check_id = '';
	/**
	 * Rule id.
	 *
	 * @var string
	 */
	public $rule_id = '';
	/**
	 * Category.
	 *
	 * @var string
	 */
	public $category = '';
	/**
	 * Severity.
	 *
	 * @var int
	 */
	public $severity = Severity::INFO;
	/**
	 * File|url|env|package.
	 *
	 * @var string
	 */
	public $target_type = 'env';
	/**
	 * E.g. theme:twentytwentyfour, uploads, site.
	 *
	 * @var string
	 */
	public $target_scope = '';
	/**
	 * Relative path, URL or setting name.
	 *
	 * @var string
	 */
	public $target = '';
	/**
	 * Line.
	 *
	 * @var int|null
	 */
	public $line = null;
	/**
	 * Title.
	 *
	 * @var string
	 */
	public $title = '';
	/**
	 * Message.
	 *
	 * @var string
	 */
	public $message = '';
	/**
	 * Recommendation.
	 *
	 * @var string
	 */
	public $recommendation = '';
	/**
	 * Meta.
	 *
	 * @var array
	 */
	public $meta = array();
	/**
	 * Sha256 of the file when detected.
	 *
	 * @var string|null
	 */
	public $file_hash = null;
	/**
	 * Extra text that identifies the finding (e.g. the normalized code line). Not stored.
	 *
	 * @var string
	 */
	public $fingerprint_basis = '';
	/**
	 * Fingerprint.
	 *
	 * @var string
	 */
	public $fingerprint = '';
	/**
	 * Status.
	 *
	 * @var string
	 */
	public $status = FindingStatus::NEW_FINDING;
	/**
	 * Status note.
	 *
	 * @var string
	 */
	public $status_note = '';
	/**
	 * First seen at.
	 *
	 * @var string
	 */
	public $first_seen_at = '';
	/**
	 * Last seen at.
	 *
	 * @var string
	 */
	public $last_seen_at = '';
	/**
	 * First scan id.
	 *
	 * @var int
	 */
	public $first_scan_id = 0;
	/**
	 * Last scan id.
	 *
	 * @var int
	 */
	public $last_scan_id = 0;
	/**
	 * Resolved at.
	 *
	 * @var string|null
	 */
	public $resolved_at = null;

	/**
	 * Creates a finding.
	 *
	 * @param array $data Keys matching the public properties. `severity` may be a slug.
	 * @return self
	 */
	public static function create( array $data ): self {
		$finding = new self();
		foreach ( $data as $key => $value ) {
			if ( property_exists( $finding, $key ) ) {
				$finding->$key = $value;
			}
		}
		$finding->severity = Severity::from( $finding->severity );
		if ( null !== $finding->line ) {
			$finding->line = (int) $finding->line;
		}
		if ( ! is_array( $finding->meta ) ) {
			$finding->meta = array();
		}
		if ( '' === $finding->fingerprint ) {
			$finding->fingerprint = $finding->compute_fingerprint();
		}
		return $finding;
	}

	/**
	 * Creates a finding from a database row.
	 *
	 * @param object|array $row Row.
	 * @return self
	 */
	public static function from_row( $row ): self {
		$row  = (array) $row;
		$meta = isset( $row['meta'] ) ? json_decode( (string) $row['meta'], true ) : array();

		$row['meta']          = is_array( $meta ) ? $meta : array();
		$row['id']            = (int) $row['id'];
		$row['severity']      = (int) $row['severity'];
		$row['first_scan_id'] = (int) $row['first_scan_id'];
		$row['last_scan_id']  = (int) $row['last_scan_id'];
		$row['line']          = null === $row['line'] ? null : (int) $row['line'];
		$row['status_note']   = (string) $row['status_note'];

		$finding = new self();
		foreach ( $row as $key => $value ) {
			if ( property_exists( $finding, $key ) ) {
				$finding->$key = $value;
			}
		}
		return $finding;
	}

	/**
	 * Stable identity. Line numbers are excluded so edits above a finding do not create a new one.
	 *
	 * @return string
	 */
	public function compute_fingerprint(): string {
		$basis = preg_replace( '/\s+/', ' ', trim( (string) $this->fingerprint_basis ) );
		return hash( 'sha256', implode( "\0", array( $this->check_id, $this->rule_id, $this->target_scope, $this->target, $basis ) ) );
	}

	/**
	 * Whether the finding is still an open problem.
	 *
	 * @return bool
	 */
	public function is_open(): bool {
		return in_array( $this->status, FindingStatus::open(), true );
	}
}
