<?php
/**
 * Rules shipped with the plugin.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules;

use KeepItPeace\Contracts\RuleProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Reads rules/core.json.
 */
class BundledRuleProvider implements RuleProviderInterface {

	/**
	 * File.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Constructor.
	 *
	 * @param string $file JSON file.
	 */
	public function __construct( string $file ) {
		$this->file = $file;
	}

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'bundled';
	}

	/**
	 * Rule set.
	 *
	 * @return RuleSet
	 */
	public function get_rule_set(): RuleSet {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled file.
		$json = is_readable( $this->file ) ? file_get_contents( $this->file ) : false;
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		$set  = RuleSet::from_array( is_array( $data ) ? $data : array(), $this->get_id() );
		foreach ( $set->rules as $rule ) {
			RuleMessages::apply( $rule );
		}
		return $set;
	}
}
