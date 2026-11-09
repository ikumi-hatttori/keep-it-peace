<?php
/**
 * Versioned JSON export of a scan.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Export;

use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Install\Installer;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the `keepitpeace.scan-report` document described in docs/export-schema.json.
 * The export is for use inside the site (PHP API, admin download); it contains URLs and paths.
 */
class ReportExporter {

	const SCHEMA = 'keepitpeace.scan-report';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Exports a scan.
	 *
	 * @param int|null $scan_id Scan ID; null = latest completed scan.
	 * @return array|null Null when the scan does not exist or is not finished.
	 */
	public function export( ?int $scan_id = null ): ?array {
		$scan = null === $scan_id ? $this->plugin->scans()->latest_completed() : $this->plugin->scans()->get( $scan_id );
		if ( ! $scan || Scan::RUNNING === $scan->status ) {
			return null;
		}
		$found = $this->plugin->findings()->for_scan( $scan->id );
		$data  = self::build( self::site_info( $scan ), $scan, $found['seen'], $found['resolved_ids'], gmdate( 'Y-m-d\TH:i:s\Z' ) );

		/**
		 * Filters the export. Add-ons should put their data under the `extensions` key, namespaced by add-on slug.
		 *
		 * @param array $data    Export.
		 * @param int   $scan_id Scan ID.
		 */
		$data = (array) apply_filters( 'keepitpeace_export_data', $data, $scan->id );
		if ( empty( $data['extensions'] ) ) {
			$data['extensions'] = new \stdClass();
		}
		return $data;
	}

	/**
	 * Exports a scan as JSON.
	 *
	 * @param int|null $scan_id Scan ID.
	 * @param int      $flags   json_encode flags.
	 * @return string|null
	 */
	public function export_json( ?int $scan_id = null, int $flags = 0 ): ?string {
		$data = $this->export( $scan_id );
		if ( null === $data ) {
			return null;
		}
		$json = wp_json_encode( $data, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? null : $json;
	}

	/**
	 * Site block.
	 *
	 * @param Scan $scan Scan.
	 * @return array
	 */
	private static function site_info( Scan $scan ): array {
		$php = (array) ( $scan->summary['data']['php.version'] ?? array() );
		return array(
			'uuid'           => (string) get_option( Installer::UUID_OPTION, '' ),
			'name'           => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'            => home_url( '/' ),
			'wp_version'     => (string) get_bloginfo( 'version' ),
			'php_version'    => (string) ( $php['version'] ?? PHP_VERSION ),
			'plugin_version' => KEEPITPEACE_VERSION,
			'api_version'    => KEEPITPEACE_API_VERSION,
		);
	}

	/**
	 * Builds the document (no WordPress calls; unit tested).
	 *
	 * @param array     $site         Site block.
	 * @param Scan      $scan         Scan.
	 * @param Finding[] $findings     Findings seen in or resolved by the scan.
	 * @param int[]     $resolved_ids IDs resolved by the scan.
	 * @param string    $generated_at ISO 8601 time.
	 * @return array
	 */
	public static function build( array $site, Scan $scan, array $findings, array $resolved_ids, string $generated_at ): array {
		$summary  = (array) $scan->summary;
		$data     = (array) ( $summary['data'] ?? array() );
		$diff     = array_merge(
			array(
				'new'      => 0,
				'resolved' => 0,
				'ongoing'  => 0,
			),
			(array) ( $summary['diff'] ?? array() )
		);
		$resolved = array_flip( array_map( 'intval', $resolved_ids ) );
		$files    = array(
			'added'    => 0,
			'modified' => 0,
			'deleted'  => 0,
		);
		$items    = array();
		foreach ( $findings as $finding ) {
			if ( ! $finding instanceof Finding ) {
				continue;
			}
			if ( isset( $resolved[ $finding->id ] ) ) {
				$state = 'resolved';
			} elseif ( $finding->first_scan_id === $scan->id ) {
				$state = 'new';
			} else {
				$state = 'ongoing';
			}
			if ( 'resolved' !== $state && 'files.integrity' === $finding->check_id && preg_match( '/^integrity\.(added|modified|deleted)$/', $finding->rule_id, $m ) ) {
				++$files[ $m[1] ];
			}
			$items[] = self::finding( $finding, $state );
		}

		$php = isset( $data['php.version'] ) && is_array( $data['php.version'] ) ? $data['php.version'] : null;
		$tls = isset( $data['external.tls'] ) && is_array( $data['external.tls'] ) ? $data['external.tls'] : null;
		$db  = isset( $data['server.database'] ) && is_array( $data['server.database'] ) ? $data['server.database'] : null;

		return array(
			'schema'         => self::SCHEMA,
			'schema_version' => KEEPITPEACE_EXPORT_SCHEMA_VERSION,
			'generated_at'   => $generated_at,
			'site'           => $site,
			'scan'           => array(
				'id'               => $scan->id,
				'status'           => $scan->status,
				'trigger'          => $scan->trigger_type,
				'started_at'       => self::time( $scan->started_at ),
				'finished_at'      => self::time( $scan->finished_at ),
				'duration_seconds' => $scan->duration(),
				'ruleset_version'  => $scan->ruleset_version,
				'plugin_version'   => $scan->plugin_version,
				'checks'           => array_values( array_map( 'strval', (array) ( $summary['checks'] ?? array() ) ) ),
			),
			'summary'        => array(
				'by_severity' => array_merge( array_fill_keys( array_values( Severity::SLUGS ), 0 ), array_map( 'intval', (array) ( $summary['by_severity'] ?? array() ) ) ),
				'new'         => (int) $diff['new'],
				'resolved'    => (int) $diff['resolved'],
				'ongoing'     => (int) $diff['ongoing'],
				'php'         => $php ? array(
					'version'                => (string) ( $php['version'] ?? '' ),
					'eol'                    => (bool) ( $php['eol'] ?? false ),
					'security_support_until' => $php['eol_date'] ?? null,
					'latest_patch'           => $php['latest_patch'] ?? null,
					'cve_count'              => isset( $php['cve_count'] ) ? (int) $php['cve_count'] : null,
				) : null,
				'database'    => $db ? array(
					'product' => (string) ( $db['product'] ?? '' ),
					'version' => (string) ( $db['version'] ?? '' ),
					'eol'     => (bool) ( $db['eol'] ?? false ),
				) : null,
				'tls'         => $tls && isset( $tls['days_remaining'] ) ? array(
					'host'           => (string) ( $tls['host'] ?? '' ),
					'days_remaining' => (int) $tls['days_remaining'],
					'valid_to'       => $tls['valid_to'] ?? null,
					'protocol'       => (string) ( $tls['protocol'] ?? '' ),
				) : null,
				'files'       => $files,
			),
			'findings'       => $items,
			'extensions'     => array(),
		);
	}

	/**
	 * One finding.
	 *
	 * @param Finding $finding Finding.
	 * @param string  $state   new|ongoing|resolved.
	 * @return array
	 */
	private static function finding( Finding $finding, string $state ): array {
		return array(
			'id'             => $finding->id,
			'fingerprint'    => $finding->fingerprint,
			'check_id'       => $finding->check_id,
			'rule_id'        => $finding->rule_id,
			'category'       => $finding->category,
			'severity'       => Severity::slug( $finding->severity ),
			'status'         => $finding->status,
			'target_type'    => $finding->target_type,
			'target_scope'   => $finding->target_scope,
			'target'         => $finding->target,
			'line'           => $finding->line,
			'title'          => $finding->title,
			'message'        => $finding->message,
			'recommendation' => $finding->recommendation,
			'meta'           => $finding->meta ? $finding->meta : new \stdClass(),
			'first_seen_at'  => self::time( $finding->first_seen_at ),
			'last_seen_at'   => self::time( $finding->last_seen_at ),
			'resolved_at'    => self::time( $finding->resolved_at ),
			'diff'           => $state,
		);
	}

	/**
	 * Converts a stored UTC datetime to ISO 8601.
	 *
	 * @param string|null $utc Datetime.
	 * @return string|null
	 */
	private static function time( ?string $utc ): ?string {
		if ( null === $utc || '' === $utc || 0 === strpos( $utc, '0000' ) ) {
			return null;
		}
		$time = strtotime( $utc . ' UTC' );
		return false === $time ? null : gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}
}
