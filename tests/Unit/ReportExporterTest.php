<?php
/**
 * Export format tests.
 *
 * @package KeepItPeace
 */

use KeepItPeace\Export\ReportExporter;
use KeepItPeace\Findings\Finding;
use KeepItPeace\Scan\Scan;
use PHPUnit\Framework\TestCase;

/**
 * Checks the export against docs/export-schema.json.
 */
class ReportExporterTest extends TestCase {

	/**
	 * Builds a sample export.
	 *
	 * @return array
	 */
	private function sample(): array {
		$scan                  = new Scan();
		$scan->id              = 7;
		$scan->status          = Scan::COMPLETED;
		$scan->trigger_type    = 'cron';
		$scan->started_at      = '2026-11-08 03:00:00';
		$scan->finished_at     = '2026-11-08 03:01:30';
		$scan->ruleset_version = '2026.11.0';
		$scan->plugin_version  = '0.2.0';
		$scan->summary         = array(
			'by_severity' => array(
				'high' => 1,
				'info' => 2,
			),
			'diff'        => array(
				'new'      => 1,
				'resolved' => 1,
				'ongoing'  => 1,
			),
			'checks'      => array( 'php.version', 'files.integrity' ),
			'data'        => array(
				'php.version'  => array(
					'version'      => '8.1.2',
					'eol'          => true,
					'eol_date'     => '2025-12-31',
					'latest_patch' => '8.1.31',
					'cve_count'    => 4,
				),
				'external.tls' => array(
					'host'           => 'example.com',
					'days_remaining' => 54,
					'valid_to'       => '2027-01-01',
					'protocol'       => 'TLSv1.3',
				),
			),
		);

		$new = Finding::create(
			array(
				'id'             => 1,
				'check_id'       => 'files.integrity',
				'rule_id'        => 'integrity.modified',
				'category'       => 'theme',
				'severity'       => 'high',
				'target_type'    => 'file',
				'target_scope'   => 'theme:demo',
				'target'         => 'functions.php',
				'title'          => 'Modified',
				'first_scan_id'  => 7,
				'last_scan_id'   => 7,
				'first_seen_at'  => '2026-11-08 03:00:10',
				'last_seen_at'   => '2026-11-08 03:00:10',
				'meta'           => array( 'size' => 10 ),
			)
		);
		$old = Finding::create(
			array(
				'id'            => 2,
				'check_id'      => 'php.ini',
				'rule_id'       => 'ini.expose_php',
				'category'      => 'php',
				'severity'      => 'info',
				'target'        => 'expose_php',
				'first_scan_id' => 3,
				'last_scan_id'  => 7,
				'first_seen_at' => '2026-11-01 03:00:00',
				'last_seen_at'  => '2026-11-08 03:00:01',
			)
		);
		$gone = Finding::create(
			array(
				'id'            => 3,
				'check_id'      => 'files.integrity',
				'rule_id'       => 'integrity.added',
				'category'      => 'theme',
				'severity'      => 'medium',
				'target_type'   => 'file',
				'status'        => 'resolved',
				'first_scan_id' => 5,
				'last_scan_id'  => 6,
				'first_seen_at' => '2026-11-06 03:00:00',
				'last_seen_at'  => '2026-11-07 03:00:00',
				'resolved_at'   => '2026-11-08 03:01:29',
			)
		);
		$site = array(
			'uuid'           => '0f8fad5b-d9cb-469f-a165-70867728950e',
			'name'           => 'Demo',
			'url'            => 'https://example.com/',
			'wp_version'     => '6.7',
			'php_version'    => '8.1.2',
			'plugin_version' => '0.2.0',
			'api_version'    => '1.0.0',
		);
		return ReportExporter::build( $site, $scan, array( $new, $old, $gone ), array( 3 ), '2026-11-08T03:02:00Z' );
	}

	public function test_structure_and_values(): void {
		$data = $this->sample();
		$this->assertSame( 'keepitpeace.scan-report', $data['schema'] );
		$this->assertSame( '2026-11-08T03:00:00Z', $data['scan']['started_at'] );
		$this->assertSame( 90, $data['scan']['duration_seconds'] );
		$this->assertSame( 0, $data['summary']['by_severity']['critical'] );
		$this->assertSame( 1, $data['summary']['by_severity']['high'] );
		$this->assertSame( '2025-12-31', $data['summary']['php']['security_support_until'] );
		$this->assertSame( 54, $data['summary']['tls']['days_remaining'] );
		$this->assertNull( $data['summary']['database'] );
		$this->assertSame(
			array(
				'added'    => 0,
				'modified' => 1,
				'deleted'  => 0,
			),
			$data['summary']['files']
		);
		$this->assertSame( array( 'new', 'ongoing', 'resolved' ), array_column( $data['findings'], 'diff' ) );
		$this->assertSame( 'high', $data['findings'][0]['severity'] );
		$this->assertSame( '2026-11-08T03:01:29Z', $data['findings'][2]['resolved_at'] );
	}

	public function test_matches_json_schema(): void {
		$schema = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/export-schema.json' ), true );
		$this->assertIsArray( $schema );
		$data = json_decode( (string) json_encode( $this->sample() ), true );
		$this->validate( $data, $schema, $schema, '$' );
	}

	/**
	 * Minimal JSON Schema validation (type, required, properties, items, enum, const, pattern, $ref).
	 *
	 * @param mixed  $value  Value.
	 * @param array  $node   Schema node.
	 * @param array  $root   Root schema.
	 * @param string $path   Path for messages.
	 */
	private function validate( $value, array $node, array $root, string $path ): void {
		if ( isset( $node['$ref'] ) ) {
			$name = substr( $node['$ref'], strlen( '#/$defs/' ) );
			$this->validate( $value, $root['$defs'][ $name ], $root, $path );
			return;
		}
		if ( isset( $node['const'] ) ) {
			$this->assertSame( $node['const'], $value, $path );
		}
		if ( isset( $node['enum'] ) ) {
			$this->assertContains( $value, $node['enum'], $path );
		}
		if ( isset( $node['type'] ) ) {
			$types = (array) $node['type'];
			$this->assertContains( self::type_of( $value ), $types, $path );
		}
		if ( isset( $node['pattern'] ) && is_string( $value ) ) {
			$this->assertMatchesRegularExpression( '/' . str_replace( '/', '\/', $node['pattern'] ) . '/', $value, $path );
		}
		if ( is_array( $value ) && isset( $node['required'] ) ) {
			foreach ( $node['required'] as $key ) {
				$this->assertArrayHasKey( $key, $value, $path );
			}
		}
		if ( is_array( $value ) && isset( $node['properties'] ) ) {
			foreach ( $node['properties'] as $key => $child ) {
				if ( array_key_exists( $key, $value ) ) {
					$this->validate( $value[ $key ], $child, $root, $path . '.' . $key );
				}
			}
		}
		if ( is_array( $value ) && isset( $node['items'] ) ) {
			foreach ( $value as $index => $item ) {
				$this->validate( $item, $node['items'], $root, $path . '[' . $index . ']' );
			}
		}
	}

	/**
	 * JSON type of a decoded value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function type_of( $value ): string {
		if ( null === $value ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		if ( is_string( $value ) ) {
			return 'string';
		}
		return array() === $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ? 'object' : 'array';
	}
}
