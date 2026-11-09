<?php
/**
 * Context passed to checks.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Baseline\FileIndexRepository;
use KeepItPeace\Remote\HttpClient;
use KeepItPeace\Rules\RuleRepository;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a check needs during a scan slice.
 */
class ScanContext {

	/**
	 * Scan.
	 *
	 * @var Scan
	 */
	public $scan;
	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	public $settings;
	/**
	 * Rules.
	 *
	 * @var RuleRepository
	 */
	public $rules;
	/**
	 * Files.
	 *
	 * @var FileIndexRepository
	 */
	public $files;
	/**
	 * Http.
	 *
	 * @var HttpClient
	 */
	public $http;
	/**
	 * Unix time (microseconds) when the slice must stop.
	 *
	 * @var float
	 */
	public $deadline;
	/**
	 * Check/rule pairs whose open findings must not be resolved by this scan
	 * (e.g. a remote lookup failed). Merged into the scan state by the scanner.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	public $keep = array();

	/**
	 * Constructor.
	 *
	 * @param Scan                $scan     Scan.
	 * @param Settings            $settings Settings.
	 * @param RuleRepository      $rules    Rules.
	 * @param FileIndexRepository $files    File index.
	 * @param HttpClient          $http     HTTP client.
	 * @param float               $deadline Deadline.
	 */
	public function __construct( Scan $scan, Settings $settings, RuleRepository $rules, FileIndexRepository $files, HttpClient $http, float $deadline ) {
		$this->scan     = $scan;
		$this->settings = $settings;
		$this->rules    = $rules;
		$this->files    = $files;
		$this->http     = $http;
		$this->deadline = $deadline;
	}

	/**
	 * Whether there is time left in this slice.
	 *
	 * @return bool
	 */
	public function has_time(): bool {
		return microtime( true ) < $this->deadline;
	}

	/**
	 * Keeps the open findings of a check (or one rule) when the check could not evaluate them.
	 *
	 * @param string $check_id Check ID.
	 * @param string $rule_id  Rule ID or '*'.
	 */
	public function keep( string $check_id, string $rule_id = '*' ): void {
		$this->keep[] = array( $check_id, $rule_id );
	}

	/**
	 * Maximum file size for content analysis, in bytes.
	 *
	 * @return int
	 */
	public function max_file_bytes(): int {
		$limits = self::file_limits( $this->settings );
		return $limits['max_size'];
	}

	/**
	 * File limits.
	 *
	 * @param Settings $settings Settings.
	 * @return array{max_size:int,max_files:int}
	 */
	public static function file_limits( Settings $settings ): array {
		/**
		 * Filters file limits.
		 *
		 * @param array $limits max_size (bytes), max_files.
		 */
		$limits = apply_filters(
			'keepitpeace_file_limits',
			array(
				'max_size'  => max( 1, (int) $settings->get( 'max_file_size_kb', 2048 ) ) * 1024,
				'max_files' => max( 0, (int) $settings->get( 'max_files', 20000 ) ),
			)
		);
		return array(
			'max_size'  => (int) $limits['max_size'],
			'max_files' => (int) $limits['max_files'],
		);
	}
}
