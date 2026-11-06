<?php
/**
 * PHP version, support status and known CVEs.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\Php;

use KeepItPeace\Checks\AbstractCheck;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Remote\NvdClient;
use KeepItPeace\Remote\ReleaseData;
use KeepItPeace\Remote\VersionEvaluator;
use KeepItPeace\Scan\CheckResult;
use KeepItPeace\Scan\RuntimeEnvironment;
use KeepItPeace\Scan\ScanContext;

defined( 'ABSPATH' ) || exit;

/**
 * Uses endoflife.date, php.net and NVD (each can be disabled; a bundled snapshot is the fallback).
 */
class PhpVersionCheck extends AbstractCheck {

	const SUPPORT_WARNING_DAYS = 180;

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'php.version';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'PHP version', 'keep-it-peace' );
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
		return __( 'Checks end of life, missing patch releases and known vulnerabilities (CVE) of the running PHP version.', 'keep-it-peace' );
	}

	/**
	 * Runs.
	 *
	 * @param ScanContext $context Context.
	 * @param array       $cursor  Cursor.
	 * @return CheckResult
	 */
	public function run( ScanContext $context, array $cursor ): CheckResult {
		$runtime  = RuntimeEnvironment::current();
		$version  = VersionEvaluator::normalize( $runtime['php_version'] );
		$release  = new ReleaseData( $context->http );
		$cycles   = $release->cycles( 'php' );
		$branch   = VersionEvaluator::branch( $version );
		$latest   = $release->latest_php_patch( $branch );
		$status   = VersionEvaluator::evaluate( $version, $cycles['cycles'], time(), $latest );
		$findings = array();
		$keep     = array();

		$status['source'] = $cycles['source'];
		$status['sapi']   = $runtime['sapi'];

		if ( $status['eol'] ) {
			$findings[] = $this->finding(
				array(
					'rule_id'        => 'php.eol',
					'severity'       => Severity::CRITICAL,
					'target'         => 'PHP ' . $branch,
					/* translators: %s: PHP version. */
					'title'          => sprintf( __( 'PHP %s is no longer supported', 'keep-it-peace' ), $branch ),
					'message'        => $status['eol_date']
						/* translators: 1: PHP branch, 2: date. */
						? sprintf( __( 'Security support for PHP %1$s ended on %2$s. New vulnerabilities will not be fixed.', 'keep-it-peace' ), $branch, $status['eol_date'] )
						/* translators: %s: PHP branch. */
						: sprintf( __( 'Security support for PHP %s has ended. New vulnerabilities will not be fixed.', 'keep-it-peace' ), $branch ),
					'recommendation' => __( 'Upgrade to a supported PHP version in your hosting control panel after testing the site on it.', 'keep-it-peace' ),
					'meta'           => array( 'eol_date' => $status['eol_date'] ),
				)
			);
		} elseif ( null !== $status['days_until_eol'] && $status['days_until_eol'] <= self::SUPPORT_WARNING_DAYS ) {
			$findings[] = $this->finding(
				array(
					'rule_id'        => 'php.eol_soon',
					'severity'       => Severity::MEDIUM,
					'target'         => 'PHP ' . $branch,
					/* translators: %s: PHP version. */
					'title'          => sprintf( __( 'PHP %s support ends soon', 'keep-it-peace' ), $branch ),
					/* translators: 1: PHP branch, 2: date, 3: days. */
					'message'        => sprintf( __( 'Security support for PHP %1$s ends on %2$s (in %3$d days).', 'keep-it-peace' ), $branch, $status['eol_date'], $status['days_until_eol'] ),
					'recommendation' => __( 'Plan an upgrade to a newer PHP version.', 'keep-it-peace' ),
					'meta'           => array( 'eol_date' => $status['eol_date'] ),
				)
			);
		} elseif ( ! $status['known'] ) {
			$findings[] = $this->finding(
				array(
					'rule_id'        => 'php.unknown_branch',
					'severity'       => Severity::INFO,
					'target'         => 'PHP ' . $branch,
					'title'          => __( 'PHP support status unknown', 'keep-it-peace' ),
					/* translators: %s: PHP version. */
					'message'        => sprintf( __( 'No release data was found for PHP %s.', 'keep-it-peace' ), $branch ),
					'recommendation' => __( 'Enable the endoflife.date API in the settings or check the PHP website.', 'keep-it-peace' ),
				)
			);
		}

		if ( $status['behind'] ) {
			$findings[] = $this->finding(
				array(
					'rule_id'           => 'php.outdated_patch',
					'severity'          => Severity::MEDIUM,
					'target'            => 'PHP ' . $branch,
					'title'             => __( 'PHP patch release is outdated', 'keep-it-peace' ),
					/* translators: 1: running version, 2: latest version. */
					'message'           => sprintf( __( 'PHP %1$s is running, but %2$s is available in the same release series. Patch releases contain security fixes.', 'keep-it-peace' ), $version, $status['latest_patch'] ),
					'recommendation'    => __( 'Ask your hosting provider to update PHP, or select the latest version in the control panel.', 'keep-it-peace' ),
					'meta'              => array(
						'version' => $version,
						'latest'  => $status['latest_patch'],
					),
					'fingerprint_basis' => $version,
				)
			);
		}

		$status['cve_count']        = null;
		$status['cve_max_severity'] = null;
		$nvd                        = ( new NvdClient( $context->http ) )->cves( 'php', 'php', $version );
		if ( $nvd['disabled'] ) {
			$keep[] = 'php.known_cves';
		} elseif ( null !== $nvd['error'] && ! $nvd['stale'] ) {
			$keep[] = 'php.known_cves';
		} else {
			$status['cve_count'] = count( $nvd['cves'] );
			if ( $nvd['cves'] ) {
				$top                        = $nvd['cves'][0];
				$status['cve_max_severity'] = $top['severity'];
				$ids                        = array_column( array_slice( $nvd['cves'], 0, 30 ), 'id' );
				$findings[]                 = $this->finding(
					array(
						'rule_id'           => 'php.known_cves',
						'severity'          => Severity::from( $top['severity'] ),
						'target'            => 'PHP ' . $version,
						/* translators: 1: number of CVEs, 2: PHP version. */
						'title'             => sprintf( _n( '%1$d known vulnerability in PHP %2$s', '%1$d known vulnerabilities in PHP %2$s', count( $nvd['cves'] ), 'keep-it-peace' ), count( $nvd['cves'] ), $version ),
						/* translators: 1: CVE ID, 2: CVSS score. */
						'message'           => sprintf( __( 'The National Vulnerability Database lists vulnerabilities for this exact PHP version. The most severe is %1$s (CVSS %2$s).', 'keep-it-peace' ), $top['id'], number_format( (float) $top['score'], 1 ) ),
						'recommendation'    => __( 'Update to the latest patch release of a supported PHP version.', 'keep-it-peace' ),
						'meta'              => array(
							'cves'  => $ids,
							'total' => count( $nvd['cves'] ),
							'top'   => array_slice( $nvd['cves'], 0, 5 ),
						),
						'fingerprint_basis' => $version,
					)
				);
			}
		}

		if ( null !== $cycles['error'] && 'bundled' === $cycles['source'] ) {
			$keep[] = 'php.eol';
			$keep[] = 'php.eol_soon';
		}

		$result             = CheckResult::complete( $findings, $status );
		$result->keep_rules = $keep;
		return $result;
	}
}
