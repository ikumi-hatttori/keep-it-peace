<?php
/**
 * Rule provider contract.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Contracts;

use KeepItPeace\Rules\RuleSet;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies a rule set (bundled file, remote feed, ...).
 */
interface RuleProviderInterface {

	/**
	 * Unique ID such as `bundled`.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Returns the rule set. Rules with the same ID from later providers override earlier ones.
	 *
	 * @return RuleSet
	 */
	public function get_rule_set(): RuleSet;
}
