<?php
/**
 * Version evaluation.
 *
 * @package KeepItPeace
 */

use KeepItPeace\Checks\Php\DatabaseVersionCheck;
use KeepItPeace\Checks\Php\PhpIniCheck;
use KeepItPeace\Remote\NvdClient;
use KeepItPeace\Remote\VersionEvaluator;
use PHPUnit\Framework\TestCase;

class VersionEvaluatorTest extends TestCase {

	private $cycles = array(
		array( 'cycle' => '8.3', 'support' => '2025-12-31', 'eol' => '2027-12-31', 'latest' => '8.3.20' ),
		array( 'cycle' => '8.1', 'support' => '2023-11-25', 'eol' => '2025-12-31', 'latest' => '8.1.33' ),
		array( 'cycle' => '7.4', 'support' => '2021-11-28', 'eol' => '2022-11-28', 'latest' => '7.4.33' ),
	);

	public function test_normalize(): void {
		$this->assertSame( '8.1.2', VersionEvaluator::normalize( '8.1.2-1ubuntu2.14' ) );
		$this->assertSame( '10.6.12', VersionEvaluator::normalize( '5.5.5-10.6.12-MariaDB-log' ) );
		$this->assertSame( '8.0.0', VersionEvaluator::normalize( '8.0' ) );
		$this->assertSame( '', VersionEvaluator::normalize( 'unknown' ) );
	}

	public function test_eol(): void {
		$r = VersionEvaluator::evaluate( '7.4.33', $this->cycles, strtotime( '2026-11-06' ) );
		$this->assertTrue( $r['known'] );
		$this->assertTrue( $r['eol'] );
		$this->assertSame( '2022-11-28', $r['eol_date'] );
		$this->assertFalse( $r['behind'] );
	}

	public function test_supported_and_behind(): void {
		$r = VersionEvaluator::evaluate( '8.3.10', $this->cycles, strtotime( '2026-11-06' ) );
		$this->assertFalse( $r['eol'] );
		$this->assertTrue( $r['behind'] );
		$this->assertSame( '8.3.20', $r['latest_patch'] );
		$this->assertGreaterThan( 365, $r['days_until_eol'] );
	}

	public function test_latest_patch_override_wins(): void {
		$r = VersionEvaluator::evaluate( '8.3.20', $this->cycles, strtotime( '2026-11-06' ), '8.3.25' );
		$this->assertTrue( $r['behind'] );
		$this->assertSame( '8.3.25', $r['latest_patch'] );
	}

	public function test_newer_than_snapshot_is_not_behind(): void {
		$r = VersionEvaluator::evaluate( '8.3.30', $this->cycles, strtotime( '2026-11-06' ) );
		$this->assertFalse( $r['behind'] );
	}

	public function test_eol_boundary_is_end_of_day(): void {
		$r = VersionEvaluator::evaluate( '8.1.33', $this->cycles, strtotime( '2025-12-31 12:00:00 UTC' ) );
		$this->assertFalse( $r['eol'] );
		$r = VersionEvaluator::evaluate( '8.1.33', $this->cycles, strtotime( '2026-01-01 00:00:01 UTC' ) );
		$this->assertTrue( $r['eol'] );
	}

	public function test_older_than_all_cycles_is_eol(): void {
		$r = VersionEvaluator::evaluate( '5.4.45', $this->cycles, time() );
		$this->assertTrue( $r['known'] );
		$this->assertTrue( $r['eol'] );
	}

	public function test_unknown_future_branch(): void {
		$r = VersionEvaluator::evaluate( '9.9.0', $this->cycles, time() );
		$this->assertFalse( $r['known'] );
		$this->assertFalse( $r['eol'] );
	}

	public function test_bundled_snapshot_is_valid(): void {
		$data = json_decode( file_get_contents( KEEPITPEACE_DIR . 'data/eol-snapshot.json' ), true );
		foreach ( array( 'php', 'mysql', 'mariadb' ) as $product ) {
			$this->assertNotEmpty( $data[ $product ] );
			foreach ( $data[ $product ] as $cycle ) {
				$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $cycle['eol'] );
			}
		}
	}

	public function test_cvss_mapping(): void {
		$this->assertSame( 'critical', VersionEvaluator::cvss_to_severity( 9.8 ) );
		$this->assertSame( 'high', VersionEvaluator::cvss_to_severity( 7.0 ) );
		$this->assertSame( 'medium', VersionEvaluator::cvss_to_severity( 5.3 ) );
		$this->assertSame( 'low', VersionEvaluator::cvss_to_severity( 2.0 ) );
		$this->assertSame( 'info', VersionEvaluator::cvss_to_severity( 0 ) );
	}

	public function test_nvd_parse(): void {
		$data = array(
			'vulnerabilities' => array(
				array( 'cve' => array( 'id' => 'CVE-1', 'published' => '2024-01-02T00:00:00', 'metrics' => array( 'cvssMetricV31' => array( array( 'cvssData' => array( 'baseScore' => 5.0 ) ) ) ), 'descriptions' => array( array( 'lang' => 'en', 'value' => 'One' ) ) ) ),
				array( 'cve' => array( 'id' => 'CVE-2', 'metrics' => array( 'cvssMetricV2' => array( array( 'cvssData' => array( 'baseScore' => 9.1 ) ) ) ) ) ),
			),
		);
		$cves = NvdClient::parse( $data );
		$this->assertSame( 'CVE-2', $cves[0]['id'] );
		$this->assertSame( 'critical', $cves[0]['severity'] );
		$this->assertSame( 'One', $cves[1]['summary'] );
		$this->assertSame( '2024-01-02', $cves[1]['published'] );
	}

	public function test_database_parse(): void {
		$this->assertSame( array( 'product' => 'mariadb', 'version' => '10.6.12' ), DatabaseVersionCheck::parse( '5.5.5-10.6.12-MariaDB-log' ) );
		$this->assertSame( array( 'product' => 'mysql', 'version' => '8.0.36' ), DatabaseVersionCheck::parse( '8.0.36' ) );
	}

	public function test_ini_on(): void {
		$this->assertTrue( PhpIniCheck::is_on( '1' ) );
		$this->assertTrue( PhpIniCheck::is_on( 'On' ) );
		$this->assertTrue( PhpIniCheck::is_on( 'stderr' ) );
		$this->assertFalse( PhpIniCheck::is_on( '' ) );
		$this->assertFalse( PhpIniCheck::is_on( '0' ) );
		$this->assertFalse( PhpIniCheck::is_on( false ) );
	}
}
