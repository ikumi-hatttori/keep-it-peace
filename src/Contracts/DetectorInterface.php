<?php
/**
 * Detector contract (token-based code analysis used by `detector` rules).
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Contracts;

use KeepItPeace\Rules\SourceFile;

defined( 'ABSPATH' ) || exit;

/**
 * Detects problems that regular expressions cannot express well.
 */
interface DetectorInterface {

	/**
	 * Detector name referenced by rules (`"detector": "<name>"`).
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Detects issues.
	 *
	 * @param SourceFile $source Tokenized source.
	 * @return array[] Each item: `rule_id`, `line`, optional `severity` and `meta`.
	 */
	public function detect( SourceFile $source ): array;
}
