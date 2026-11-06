<?php
/**
 * Scan target.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * A directory scanned by file checks.
 */
class ScanTarget {

	/**
	 * Stable ID, also used as finding scope (e.g. theme:twentytwentyfour).
	 *
	 * @var string
	 */
	public $id;
	/**
	 * Theme|path|...
	 *
	 * @var string
	 */
	public $type;
	/**
	 * Label.
	 *
	 * @var string
	 */
	public $label;
	/**
	 * Absolute path without trailing slash.
	 *
	 * @var string
	 */
	public $root;
	/**
	 * Finding category.
	 *
	 * @var string
	 */
	public $category;
	/**
	 * Extra exclude patterns.
	 *
	 * @var string[]
	 */
	public $excludes = array();
	/**
	 * Per-check switches; missing = enabled.
	 *
	 * @var array<string,bool>
	 */
	public $checks = array();
	/**
	 * Public URL for the directory, if any.
	 *
	 * @var string
	 */
	public $url = '';
	/**
	 * Meta.
	 *
	 * @var array
	 */
	public $meta = array();

	/**
	 * Constructor.
	 *
	 * @param string $id       ID.
	 * @param string $type     Type.
	 * @param string $label    Label.
	 * @param string $root     Absolute path.
	 * @param string $category Category.
	 */
	public function __construct( string $id, string $type, string $label, string $root, string $category ) {
		$this->id       = $id;
		$this->type     = $type;
		$this->label    = $label;
		$this->root     = rtrim( str_replace( '\\', '/', $root ), '/' );
		$this->category = $category;
	}

	/**
	 * Whether a check is enabled for this target.
	 *
	 * @param string $check_id Check.
	 * @return bool
	 */
	public function is_check_enabled( string $check_id ): bool {
		return ! isset( $this->checks[ $check_id ] ) || (bool) $this->checks[ $check_id ];
	}
}
