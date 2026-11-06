<?php
/**
 * MySQL / MariaDB version and end of life.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\Php;

use KeepItPeace\Checks\AbstractCheck;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Remote\ReleaseData;
use KeepItPeace\Remote\VersionEvaluator;
use KeepItPeace\Scan\CheckResult;
use KeepItPeace\Scan\ScanContext;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the database server and evaluates its support status.
 */
class DatabaseVersionCheck extends AbstractCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'server.database';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Database version', 'keep-it-peace' );
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
		return __( 'Checks whether the MySQL or MariaDB version still receives security updates.', 'keep-it-peace' );
	}

	/**
	 * Parses the server string.
	 *
	 * @param string $server Server info, e.g. "5.5.5-10.6.12-MariaDB-log" or "8.0.36".
	 * @return array{product:string,version:string}
	 */
	public static function parse( string $server ): array {
		$product = false !== stripos( $server, 'mariadb' ) ? 'mariadb' : 'mysql';
		return array(
			'product' => $product,
			'version' => VersionEvaluator::normalize( $server ),
		);
	}

	/**
	 * Runs.
	 *
	 * @param ScanContext $context Context.
	 * @param array       $cursor  Cursor.
	 * @return CheckResult
	 */
	public function run( ScanContext $context, array $cursor ): CheckResult {
		global $wpdb;
		$server = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		if ( '' === $server ) {
			$server = (string) $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		$parsed = self::parse( $server );
		if ( '' === $parsed['version'] ) {
			return CheckResult::complete();
		}

		$cycles  = ( new ReleaseData( $context->http ) )->cycles( $parsed['product'] );
		$status  = VersionEvaluator::evaluate( $parsed['version'], $cycles['cycles'], time() );
		$label   = 'mariadb' === $parsed['product'] ? 'MariaDB' : 'MySQL';
		$status += array(
			'product' => $parsed['product'],
			'label'   => $label,
			'source'  => $cycles['source'],
		);

		$findings = array();
		if ( $status['eol'] ) {
			$findings[] = $this->finding(
				array(
					'rule_id'        => 'database.eol',
					'severity'       => Severity::HIGH,
					'target'         => $label . ' ' . $status['branch'],
					/* translators: 1: MySQL or MariaDB, 2: version. */
					'title'          => sprintf( __( '%1$s %2$s is no longer supported', 'keep-it-peace' ), $label, $status['branch'] ),
					'message'        => $status['eol_date']
						/* translators: %s: date. */
						? sprintf( __( 'Security support ended on %s.', 'keep-it-peace' ), $status['eol_date'] )
						: __( 'Security support has ended.', 'keep-it-peace' ),
					'recommendation' => __( 'Ask your hosting provider to move the database to a supported version.', 'keep-it-peace' ),
				)
			);
		} elseif ( null !== $status['days_until_eol'] && $status['days_until_eol'] <= 180 ) {
			$findings[] = $this->finding(
				array(
					'rule_id'        => 'database.eol_soon',
					'severity'       => Severity::LOW,
					'target'         => $label . ' ' . $status['branch'],
					/* translators: 1: MySQL or MariaDB, 2: version. */
					'title'          => sprintf( __( '%1$s %2$s support ends soon', 'keep-it-peace' ), $label, $status['branch'] ),
					/* translators: 1: date, 2: days. */
					'message'        => sprintf( __( 'Security support ends on %1$s (in %2$d days).', 'keep-it-peace' ), $status['eol_date'], $status['days_until_eol'] ),
					'recommendation' => __( 'Plan a database upgrade with your hosting provider.', 'keep-it-peace' ),
				)
			);
		}

		$result = CheckResult::complete( $findings, $status );
		if ( null !== $cycles['error'] && 'bundled' === $cycles['source'] ) {
			$result->keep_rules = array( 'database.eol', 'database.eol_soon' );
		}
		return $result;
	}
}
