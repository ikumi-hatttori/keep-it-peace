<?php
/**
 * Static code analysis with the rule engine.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\Files;

use KeepItPeace\Checks\AbstractFileCheck;
use KeepItPeace\Findings\Finding;
use KeepItPeace\Rules\RuleEngine;
use KeepItPeace\Rules\SourceFile;
use KeepItPeace\Scan\FileInfo;
use KeepItPeace\Scan\ScanContext;
use KeepItPeace\Scan\ScanTarget;

defined( 'ABSPATH' ) || exit;

/**
 * Runs regex and detector rules on PHP, JS and HTML files.
 */
class CodePatternCheck extends AbstractFileCheck {

	const MAX_FINDINGS_PER_FILE = 50;

	/**
	 * Engine.
	 *
	 * @var RuleEngine
	 */
	private $engine;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->engine = new RuleEngine();
	}

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'files.code_pattern';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Dangerous code patterns', 'keep-it-peace' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Scans code for eval, shell execution, obfuscation, unsanitized input, unprepared SQL and missing nonce or capability checks.', 'keep-it-peace' );
	}

	/**
	 * Analyzes a file.
	 *
	 * @param FileInfo    $file    File.
	 * @param ScanTarget  $target  Target.
	 * @param ScanContext $context Context.
	 * @return Finding[]
	 */
	public function analyze_file( FileInfo $file, ScanTarget $target, ScanContext $context ): array {
		$rules = array_filter(
			$context->rules->get_rules(),
			static function ( $rule ) use ( $file ) {
				return $rule->applies_to( $file->extension );
			}
		);
		if ( empty( $rules ) ) {
			return array();
		}
		$code = $file->contents( $context->max_file_bytes() );
		if ( null === $code ) {
			return array();
		}

		$matches     = $this->engine->scan( new SourceFile( $code, $file->extension ), $rules, $context->rules->get_detectors() );
		$findings    = array();
		$occurrences = array();
		foreach ( array_slice( $matches, 0, self::MAX_FINDINGS_PER_FILE ) as $match ) {
			$rule = $match['rule'];
			$text = trim( (string) preg_replace( '/\s+/', ' ', $match['text'] ) );
			$key  = $rule->id . '|' . $text;

			$occurrences[ $key ] = ( $occurrences[ $key ] ?? 0 ) + 1;

			$findings[] = $this->file_finding(
				$file,
				$target,
				array(
					'rule_id'           => $rule->id,
					'severity'          => $match['severity'],
					'line'              => $match['line'],
					'title'             => $rule->title,
					'message'           => $rule->description,
					'recommendation'    => $rule->recommendation,
					'meta'              => array_merge( $match['meta'], array( 'rule_category' => $rule->category ) ),
					'fingerprint_basis' => $text . '#' . $occurrences[ $key ],
				)
			);
		}
		return $findings;
	}
}
