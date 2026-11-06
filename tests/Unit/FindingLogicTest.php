<?php
/**
 * Finding lifecycle, fingerprints and scan diffs.
 *
 * @package KeepItPeace
 */

use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\FindingLifecycle;
use KeepItPeace\Findings\FindingStatus;
use KeepItPeace\Findings\ScanDiff;
use KeepItPeace\Findings\Severity;
use PHPUnit\Framework\TestCase;

class FindingLogicTest extends TestCase {

	public function test_new_finding_is_created(): void {
		$this->assertSame( array( 'created', 'new' ), FindingLifecycle::on_detected( null, null, 'a' ) );
	}

	public function test_resolved_finding_is_reopened(): void {
		$this->assertSame( array( 'reopened', 'new' ), FindingLifecycle::on_detected( FindingStatus::RESOLVED, 'a', 'a' ) );
	}

	public function test_ignored_stays_ignored_while_file_unchanged(): void {
		$this->assertSame( array( 'unchanged', 'ignored' ), FindingLifecycle::on_detected( FindingStatus::IGNORED, 'a', 'a' ) );
		$this->assertSame( array( 'unchanged', 'ignored' ), FindingLifecycle::on_detected( FindingStatus::IGNORED, null, null ) );
	}

	public function test_ignore_is_reset_when_file_changes(): void {
		$this->assertSame( array( 'ignore_reset', 'new' ), FindingLifecycle::on_detected( FindingStatus::IGNORED, 'a', 'b' ) );
	}

	public function test_acknowledged_is_kept(): void {
		$this->assertSame( array( 'unchanged', 'acknowledged' ), FindingLifecycle::on_detected( FindingStatus::ACKNOWLEDGED, 'a', 'b' ) );
	}

	public function test_resolution(): void {
		$this->assertTrue( FindingLifecycle::resolves_when_missing( FindingStatus::IGNORED ) );
		$this->assertFalse( FindingLifecycle::resolves_when_missing( FindingStatus::RESOLVED ) );
	}

	public function test_fingerprint_ignores_line_and_whitespace(): void {
		$base = array(
			'check_id'          => 'files.code_pattern',
			'rule_id'           => 'php.eval',
			'target_scope'      => 'theme:x',
			'target'            => 'functions.php',
			'fingerprint_basis' => 'eval( $x );',
		);
		$a = Finding::create( $base + array( 'line' => 3 ) );
		$b = Finding::create( array_merge( $base, array( 'line' => 40, 'fingerprint_basis' => "  eval(   \$x );  " ) ) );
		$c = Finding::create( array_merge( $base, array( 'target' => 'header.php' ) ) );
		$this->assertSame( $a->fingerprint, $b->fingerprint );
		$this->assertNotSame( $a->fingerprint, $c->fingerprint );
		$this->assertSame( 64, strlen( $a->fingerprint ) );
	}

	public function test_severity_conversion(): void {
		$this->assertSame( Severity::CRITICAL, Severity::from( 'critical' ) );
		$this->assertSame( Severity::HIGH, Severity::from( 4 ) );
		$this->assertSame( Severity::LOW, Severity::from( '2' ) );
		$this->assertSame( Severity::INFO, Severity::from( 'nonsense' ) );
		$this->assertSame( 'medium', Severity::slug( Severity::MEDIUM ) );
	}

	public function test_diff_from_events(): void {
		$diff = ScanDiff::from_events( array( 'detected' => 3, 'reopened' => 1, 'ignore_reset' => 1, 'resolved' => 2, 'status_changed' => 9 ), 12 );
		$this->assertSame( array( 'new' => 5, 'resolved' => 2, 'ongoing' => 7 ), $diff->to_array() );
	}

	public function test_diff_compare(): void {
		$diff = ScanDiff::compare( array( 'a', 'b', 'c' ), array( 'b', 'c', 'd', 'd' ) );
		$this->assertSame( array( 'd' ), $diff['new'] );
		$this->assertSame( array( 'a' ), $diff['resolved'] );
		$this->assertSame( array( 'b', 'c' ), $diff['ongoing'] );
	}
}
