<?php
/**
 * Versioned rule set.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Rules from one provider.
 */
class RuleSet {

	/**
	 * Source.
	 *
	 * @var string
	 */
	public $source;
	/**
	 * E.g. 2026.11.0.
	 *
	 * @var string
	 */
	public $version;
	/**
	 * Schema version.
	 *
	 * @var int
	 */
	public $schema_version;
	/**
	 * Rules.
	 *
	 * @var Rule[]
	 */
	public $rules;

	/**
	 * Constructor.
	 *
	 * @param string $source         Provider ID.
	 * @param string $version        Version.
	 * @param int    $schema_version Schema version.
	 * @param Rule[] $rules          Rules.
	 */
	public function __construct( string $source, string $version, int $schema_version, array $rules ) {
		$this->source         = $source;
		$this->version        = $version;
		$this->schema_version = $schema_version;
		$this->rules          = $rules;
	}

	/**
	 * Parses a rule set document.
	 *
	 * @param array  $data   Decoded JSON.
	 * @param string $source Provider ID.
	 * @return self
	 */
	public static function from_array( array $data, string $source ): self {
		$rules = array();
		foreach ( (array) ( $data['rules'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$rule = Rule::from_array( $item, $source );
			if ( $rule ) {
				$rules[ $rule->id ] = $rule;
			}
		}
		return new self( $source, (string) ( $data['ruleset_version'] ?? '0' ), (int) ( $data['schema_version'] ?? 1 ), $rules );
	}
}
