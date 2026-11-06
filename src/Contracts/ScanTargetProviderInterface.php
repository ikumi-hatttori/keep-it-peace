<?php
/**
 * Scan target provider contract.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Contracts;

use KeepItPeace\Scan\ScanTarget;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies directories to scan (themes, paths outside WordPress, ...).
 */
interface ScanTargetProviderInterface {

	/**
	 * Returns targets. IDs must be stable between requests.
	 *
	 * @param Settings $settings Settings.
	 * @return ScanTarget[]
	 */
	public function get_targets( Settings $settings ): array;
}
