<?php
/**
 * Known vulnerabilities in Composer and npm dependencies.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\Dependencies;

use KeepItPeace\Checks\AbstractFileCheck;
use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Remote\OsvClient;
use KeepItPeace\Scan\FileInfo;
use KeepItPeace\Scan\PathGuard;
use KeepItPeace\Scan\ScanContext;
use KeepItPeace\Scan\ScanTarget;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads lock files found in the scan targets and looks the packages up on OSV.dev.
 * One finding per vulnerable package version.
 */
class DependencyCheck extends AbstractFileCheck {

	const MAX_LOCKFILE_BYTES = 16777216;

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'deps.osv';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Vulnerable dependencies', 'keep-it-peace' );
	}

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'dependency';
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Reads composer.lock and package-lock.json in themes and registered directories and looks up known vulnerabilities on OSV.dev. Only package names and versions are sent.', 'keep-it-peace' );
	}

	/**
	 * Requires the OSV.dev service to be allowed.
	 *
	 * @param Settings $settings Settings.
	 * @return bool
	 */
	public function is_enabled( Settings $settings ): bool {
		return parent::is_enabled( $settings ) && $settings->is_remote_enabled( 'osv' );
	}

	/**
	 * Analyzes a lock file.
	 *
	 * @param FileInfo    $file    File.
	 * @param ScanTarget  $target  Target.
	 * @param ScanContext $context Context.
	 * @return Finding[]
	 */
	public function analyze_file( FileInfo $file, ScanTarget $target, ScanContext $context ): array {
		if ( ! LockfileParser::supports( $file->basename ) ) {
			return array();
		}
		$contents = $file->contents( self::MAX_LOCKFILE_BYTES );
		$packages = null === $contents ? null : LockfileParser::parse( $file->basename, $contents );
		if ( null === $packages ) {
			return array(
				$this->notice( $file, $target, 'osv.unreadable', __( 'Lock file could not be read', 'keep-it-peace' ), __( 'The file is unreadable, too large or not valid JSON, so its packages were not checked.', 'keep-it-peace' ) ),
			);
		}
		// Development packages (build tools of themes) only matter when they are installed on this server.
		$dir      = dirname( $file->path );
		$packages = array_values(
			array_filter(
				$packages,
				static function ( array $package ) use ( $dir ) {
					return ! $package['dev'] || self::is_installed( $dir, $package );
				}
			)
		);
		if ( ! $packages ) {
			return array();
		}

		$osv    = new OsvClient( $context->http );
		$result = $osv->query( $packages );
		if ( '' !== $result['error'] && ! $result['ids'] ) {
			$context->keep( $this->get_id(), 'osv.vulnerable' );
			/* translators: %s: error message. */
			$message = sprintf( __( 'OSV.dev could not be reached: %s. Previous results are kept.', 'keep-it-peace' ), $result['error'] );
			return array(
				$this->notice( $file, $target, 'osv.unavailable', __( 'Dependencies could not be checked', 'keep-it-peace' ), $message ),
			);
		}

		$out = array();
		foreach ( $result['ids'] as $index => $ids ) {
			$out[] = $this->vulnerable( $file, $target, $packages[ $index ], $ids, $osv );
		}
		return $out;
	}

	/**
	 * Whether a package is installed next to its lock file (vendor/ or node_modules/).
	 *
	 * @param string $dir     Directory of the lock file.
	 * @param array  $package Package.
	 * @return bool
	 */
	public static function is_installed( string $dir, array $package ): bool {
		$sub  = 'npm' === $package['ecosystem'] ? 'node_modules' : 'vendor';
		$path = $dir . '/' . $sub . '/' . $package['name'];
		return PathGuard::is_within_open_basedir( $path ) && is_dir( $path );
	}

	/**
	 * Finding for a vulnerable package.
	 *
	 * @param FileInfo   $file    Lock file.
	 * @param ScanTarget $target  Target.
	 * @param array      $package Package.
	 * @param string[]   $ids     OSV IDs.
	 * @param OsvClient  $osv     Client.
	 * @return Finding
	 */
	private function vulnerable( FileInfo $file, ScanTarget $target, array $package, array $ids, OsvClient $osv ): Finding {
		$severity = Severity::MEDIUM;
		$fixed    = '';
		$lines    = array();
		$aliases  = array();
		foreach ( array_slice( $ids, 0, 15 ) as $id ) {
			$vuln = $osv->details( $id );
			if ( null === $vuln ) {
				$lines[] = $id;
				continue;
			}
			$severity = max( $severity, Severity::from( OsvClient::severity( $vuln ) ) );
			$fix      = OsvClient::fixed_version( $vuln, $package['ecosystem'], $package['name'], $package['version'] );
			if ( '' !== $fix && ( '' === $fixed || version_compare( $fix, $fixed, '>' ) ) ) {
				$fixed = $fix;
			}
			foreach ( (array) ( $vuln['aliases'] ?? array() ) as $alias ) {
				if ( 0 === strpos( (string) $alias, 'CVE-' ) ) {
					$aliases[] = (string) $alias;
				}
			}
			$summary = trim( (string) ( $vuln['summary'] ?? '' ) );
			$lines[] = $id . ( '' !== $summary ? ': ' . $summary : '' );
		}
		if ( count( $ids ) > 15 ) {
			/* translators: %d: number of vulnerabilities. */
			$lines[] = sprintf( __( 'and %d more', 'keep-it-peace' ), count( $ids ) - 15 );
		}
		if ( $package['dev'] && $severity > Severity::LOW ) {
			--$severity;
		}

		$command = 'npm' === $package['ecosystem'] ? 'npm update ' . $package['name'] : 'composer update ' . $package['name'];
		if ( '' !== $fixed ) {
			/* translators: 1: package, 2: version, 3: command. */
			$recommendation = sprintf( __( 'Update %1$s to %2$s or later (e.g. %3$s), deploy the result and run the scan again.', 'keep-it-peace' ), $package['name'], $fixed, $command );
		} else {
			/* translators: 1: package, 2: command. */
			$recommendation = sprintf( __( 'Update %1$s to a version without these vulnerabilities (e.g. %2$s), or replace it if no fix exists.', 'keep-it-peace' ), $package['name'], $command );
		}
		$message = sprintf(
			/* translators: 1: number, 2: list of vulnerabilities. */
			_n( '%1$d known vulnerability: %2$s', '%1$d known vulnerabilities: %2$s', count( $ids ), 'keep-it-peace' ),
			count( $ids ),
			implode( ' / ', $lines )
		);
		if ( $package['dev'] ) {
			$message .= ' ' . __( '(Development dependency, installed on this server.)', 'keep-it-peace' );
		}

		return $this->file_finding(
			$file,
			$target,
			array(
				'category'          => 'dependency',
				'rule_id'           => 'osv.vulnerable',
				'severity'          => $severity,
				/* translators: 1: package, 2: version. */
				'title'             => sprintf( __( 'Vulnerable package: %1$s %2$s', 'keep-it-peace' ), $package['name'], $package['version'] ),
				'message'           => $message,
				'recommendation'    => $recommendation,
				'fingerprint_basis' => $package['ecosystem'] . ':' . $package['name'] . '@' . $package['version'],
				'file_hash'         => null,
				'meta'              => array(
					'ecosystem' => $package['ecosystem'],
					'package'   => $package['name'],
					'version'   => $package['version'],
					'dev'       => $package['dev'],
					'ids'       => array_slice( $ids, 0, 50 ),
					'cves'      => array_values( array_unique( $aliases ) ),
					'fixed'     => $fixed,
				),
			)
		);
	}

	/**
	 * Info finding about a lock file.
	 *
	 * @param FileInfo   $file    File.
	 * @param ScanTarget $target  Target.
	 * @param string     $rule_id Rule.
	 * @param string     $title   Title.
	 * @param string     $message Message.
	 * @return Finding
	 */
	private function notice( FileInfo $file, ScanTarget $target, string $rule_id, string $title, string $message ): Finding {
		return $this->file_finding(
			$file,
			$target,
			array(
				'category'       => 'dependency',
				'rule_id'        => $rule_id,
				'severity'       => Severity::INFO,
				'title'          => $title,
				'message'        => $message,
				'recommendation' => __( 'Run the scan again later. You can disable this check in the settings.', 'keep-it-peace' ),
				'file_hash'      => null,
			)
		);
	}
}
