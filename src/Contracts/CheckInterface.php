<?php
/**
 * Check contract.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Contracts;

use KeepItPeace\Scan\CheckResult;
use KeepItPeace\Scan\ScanContext;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A check run once per scan. Long checks process a slice per call and return a cursor.
 */
interface CheckInterface {

	/**
	 * Unique ID such as `php.version`.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Translated name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Category slug (php, theme, external_path, external, dependency, environment).
	 *
	 * @return string
	 */
	public function get_category(): string;

	/**
	 * Translated description for the settings screen.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Whether the check should run.
	 *
	 * @param Settings $settings Settings.
	 * @return bool
	 */
	public function is_enabled( Settings $settings ): bool;

	/**
	 * Runs the check (or a slice of it).
	 *
	 * Implementations should stop when `$context->has_time()` returns false and
	 * return an incomplete result with a cursor; the scanner calls again with it.
	 *
	 * @param ScanContext $context Context.
	 * @param array       $cursor  Cursor returned by the previous call (empty on first call).
	 * @return CheckResult
	 */
	public function run( ScanContext $context, array $cursor ): CheckResult;
}
