<?php
/**
 * Applies rules to source code.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Pure matcher: rules + detectors in, matches out.
 */
class RuleEngine {

	const MAX_MATCHES_PER_RULE = 20;

	/**
	 * Scans a source file.
	 *
	 * @param SourceFile                                 $source    Source.
	 * @param Rule[]                                     $rules     Rules.
	 * @param \KeepItPeace\Contracts\DetectorInterface[] $detectors Detectors keyed by name.
	 * @return array[] Items: rule (Rule), line (int), text (string), severity (int), meta (array).
	 */
	public function scan( SourceFile $source, array $rules, array $detectors ): array {
		$matches        = array();
		$detector_rules = array();
		$extension      = $source->extension();

		foreach ( $rules as $rule ) {
			if ( ! $rule->applies_to( $extension ) ) {
				continue;
			}
			if ( 'detector' === $rule->type ) {
				$detector_rules[ $rule->detector ][ $rule->id ] = $rule;
				continue;
			}
			$regex   = $rule->regex();
			$subject = 'raw' === $rule->target ? $source->code() : $source->code_view();
			if ( null === $regex || ! preg_match_all( $regex, $subject, $found, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$seen_lines = array();
			foreach ( $found[0] as $match ) {
				$line = $source->line_at( (int) $match[1] );
				if ( isset( $seen_lines[ $line ] ) ) {
					continue;
				}
				$seen_lines[ $line ] = true;
				$matches[]           = $this->match( $rule, $source, $line, $rule->severity, array() );
				if ( count( $seen_lines ) >= self::MAX_MATCHES_PER_RULE ) {
					break;
				}
			}
		}

		foreach ( $detector_rules as $name => $rules_for_detector ) {
			if ( ! isset( $detectors[ $name ] ) ) {
				continue;
			}
			$counts = array();
			foreach ( $detectors[ $name ]->detect( $source ) as $item ) {
				$rule_id = (string) ( $item['rule_id'] ?? '' );
				if ( ! isset( $rules_for_detector[ $rule_id ] ) ) {
					continue;
				}
				$counts[ $rule_id ] = ( $counts[ $rule_id ] ?? 0 ) + 1;
				if ( $counts[ $rule_id ] > self::MAX_MATCHES_PER_RULE ) {
					continue;
				}
				$rule      = $rules_for_detector[ $rule_id ];
				$severity  = isset( $item['severity'] ) ? \KeepItPeace\Findings\Severity::from( $item['severity'] ) : $rule->severity;
				$matches[] = $this->match( $rule, $source, max( 1, (int) ( $item['line'] ?? 1 ) ), $severity, (array) ( $item['meta'] ?? array() ) );
			}
		}

		usort(
			$matches,
			static function ( $a, $b ) {
				return array( $a['line'], $a['rule']->id ) <=> array( $b['line'], $b['rule']->id );
			}
		);
		return $matches;
	}

	/**
	 * Builds a match.
	 *
	 * @param Rule       $rule     Rule.
	 * @param SourceFile $source   Source.
	 * @param int        $line     Line.
	 * @param int        $severity Severity.
	 * @param array      $meta     Meta.
	 * @return array
	 */
	private function match( Rule $rule, SourceFile $source, int $line, int $severity, array $meta ): array {
		return array(
			'rule'     => $rule,
			'line'     => $line,
			'text'     => $source->line_text( $line ),
			'severity' => $severity,
			'meta'     => $meta,
		);
	}
}
