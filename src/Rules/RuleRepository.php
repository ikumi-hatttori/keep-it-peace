<?php
/**
 * Merged rules from all providers.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules;

use KeepItPeace\Contracts\DetectorInterface;
use KeepItPeace\Contracts\RuleProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Collects rule providers (`keepitpeace_rule_providers`) and detectors (`keepitpeace_rule_detectors`).
 */
class RuleRepository {

	/**
	 * Core providers.
	 *
	 * @var RuleProviderInterface[]
	 */
	private $core_providers;
	/**
	 * Core detectors.
	 *
	 * @var DetectorInterface[]
	 */
	private $core_detectors;
	/**
	 * Rules.
	 *
	 * @var Rule[]|null
	 */
	private $rules = null;
	/**
	 * Sets.
	 *
	 * @var RuleSet[]
	 */
	private $sets = array();
	/**
	 * Detectors.
	 *
	 * @var DetectorInterface[]|null
	 */
	private $detectors = null;

	/**
	 * Constructor.
	 *
	 * @param RuleProviderInterface[] $providers Core providers.
	 * @param DetectorInterface[]     $detectors Core detectors.
	 */
	public function __construct( array $providers, array $detectors ) {
		$this->core_providers = $providers;
		$this->core_detectors = $detectors;
	}

	/**
	 * All rules keyed by ID (later providers override earlier ones).
	 *
	 * @return Rule[]
	 */
	public function get_rules(): array {
		if ( null !== $this->rules ) {
			return $this->rules;
		}
		/**
		 * Filters rule providers. Providers added later override rules with the same ID.
		 *
		 * @param RuleProviderInterface[] $providers Providers.
		 */
		$providers  = apply_filters( 'keepitpeace_rule_providers', $this->core_providers );
		$rules      = array();
		$this->sets = array();
		foreach ( (array) $providers as $provider ) {
			if ( ! $provider instanceof RuleProviderInterface ) {
				continue;
			}
			$set = $provider->get_rule_set();
			if ( $set->schema_version > KEEPITPEACE_RULESET_SCHEMA_VERSION ) {
				continue;
			}
			$this->sets[ $provider->get_id() ] = $set;
			foreach ( $set->rules as $rule ) {
				$rules[ $rule->id ] = $rule;
			}
		}
		/**
		 * Filters the final rules.
		 *
		 * @param Rule[] $rules Rules keyed by ID.
		 */
		$rules       = (array) apply_filters( 'keepitpeace_rules', $rules );
		$this->rules = array_filter(
			$rules,
			static function ( $rule ) {
				return $rule instanceof Rule;
			}
		);
		return $this->rules;
	}

	/**
	 * Gets a rule.
	 *
	 * @param string $id ID.
	 * @return Rule|null
	 */
	public function get( string $id ): ?Rule {
		$rules = $this->get_rules();
		return $rules[ $id ] ?? null;
	}

	/**
	 * Combined version string such as `bundled:2026.11.0`.
	 *
	 * @return string
	 */
	public function get_version(): string {
		$this->get_rules();
		$parts = array();
		foreach ( $this->sets as $id => $set ) {
			$parts[] = $id . ':' . $set->version;
		}
		return substr( implode( ',', $parts ), 0, 32 );
	}

	/**
	 * Detectors keyed by name.
	 *
	 * @return DetectorInterface[]
	 */
	public function get_detectors(): array {
		if ( null === $this->detectors ) {
			/**
			 * Filters token-based detectors.
			 *
			 * @param DetectorInterface[] $detectors Detectors.
			 */
			$detectors       = apply_filters( 'keepitpeace_rule_detectors', $this->core_detectors );
			$this->detectors = array();
			foreach ( (array) $detectors as $detector ) {
				if ( $detector instanceof DetectorInterface ) {
					$this->detectors[ $detector->get_name() ] = $detector;
				}
			}
		}
		return $this->detectors;
	}
}
