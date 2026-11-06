<?php
/**
 * Dangerous php.ini settings.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\Php;

use KeepItPeace\Checks\AbstractCheck;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\CheckResult;
use KeepItPeace\Scan\RuntimeEnvironment;
use KeepItPeace\Scan\ScanContext;

defined( 'ABSPATH' ) || exit;

/**
 * Reads ini settings of the web runtime (see RuntimeEnvironment).
 */
class PhpIniCheck extends AbstractCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'php.ini';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'PHP settings', 'keep-it-peace' );
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'php';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks display_errors, expose_php, allow_url_include, allow_url_fopen and similar settings.', 'keep-it-peace' );
	}

	/**
	 * Whether an ini value is on.
	 *
	 * @param string|false $value Value.
	 * @return bool
	 */
	public static function is_on( $value ): bool {
		if ( false === $value ) {
			return false;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'on', 'yes', 'true', 'stdout', 'stderr' ), true );
	}

	/**
	 * Runs.
	 *
	 * @param ScanContext $context Context.
	 * @param array       $cursor  Cursor.
	 * @return CheckResult
	 */
	public function run( ScanContext $context, array $cursor ): CheckResult {
		$checks = array(
			array( 'display_errors', true, Severity::HIGH, __( 'display_errors is on', 'keep-it-peace' ), __( 'PHP errors are shown to visitors. Error messages reveal file paths and code structure.', 'keep-it-peace' ), __( 'Set display_errors = Off and log errors instead (log_errors = On).', 'keep-it-peace' ) ),
			array( 'expose_php', true, Severity::LOW, __( 'expose_php is on', 'keep-it-peace' ), __( 'The PHP version is sent in the X-Powered-By header, which helps attackers pick exploits.', 'keep-it-peace' ), __( 'Set expose_php = Off in php.ini (or the hosting control panel).', 'keep-it-peace' ) ),
			array( 'allow_url_include', true, Severity::CRITICAL, __( 'allow_url_include is on', 'keep-it-peace' ), __( 'include/require can load code from remote URLs, which turns file inclusion bugs into remote code execution.', 'keep-it-peace' ), __( 'Set allow_url_include = Off.', 'keep-it-peace' ) ),
			array( 'allow_url_fopen', true, Severity::LOW, __( 'allow_url_fopen is on', 'keep-it-peace' ), __( 'File functions can open remote URLs. WordPress does not need this (it uses the HTTP API), but some code may rely on it.', 'keep-it-peace' ), __( 'Turn it off if no code on the site depends on it.', 'keep-it-peace' ) ),
			array( 'log_errors', false, Severity::LOW, __( 'log_errors is off', 'keep-it-peace' ), __( 'PHP errors are not logged, so problems and attacks may go unnoticed.', 'keep-it-peace' ), __( 'Set log_errors = On and make sure the log is not publicly accessible.', 'keep-it-peace' ) ),
			array( 'session.cookie_httponly', false, Severity::INFO, __( 'session.cookie_httponly is off', 'keep-it-peace' ), __( 'PHP session cookies can be read by JavaScript. WordPress itself does not use PHP sessions, but other code may.', 'keep-it-peace' ), __( 'Set session.cookie_httponly = On.', 'keep-it-peace' ) ),
		);

		$runtime  = RuntimeEnvironment::current();
		$ini      = (array) $runtime['ini'];
		$findings = array();
		$values   = array();
		foreach ( $checks as $check ) {
			list( $key, $bad_when_on, $severity, $title, $message, $recommendation ) = $check;
			$value          = array_key_exists( $key, $ini ) ? $ini[ $key ] : ini_get( $key );
			$on             = self::is_on( $value );
			$values[ $key ] = $on;
			if ( $on !== $bad_when_on ) {
				continue;
			}
			$findings[] = $this->finding(
				array(
					'rule_id'        => 'ini.' . $key,
					'severity'       => $severity,
					'target'         => $key,
					'title'          => $title,
					'message'        => $message,
					'recommendation' => $recommendation,
					'meta'           => array( 'value' => false === $value ? null : (string) $value ),
				)
			);
		}

		$disabled                          = array_filter( array_map( 'trim', explode( ',', (string) ( $ini['disable_functions'] ?? '' ) ) ) );
		$values['disable_functions_count'] = count( $disabled );
		$values['sapi']                    = $runtime['sapi'];
		return CheckResult::complete( $findings, $values );
	}
}
