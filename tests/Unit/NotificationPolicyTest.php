<?php
/**
 * Notification conditions.
 *
 * @package KeepItPeace
 */

use KeepItPeace\Notifications\NotificationPolicy;
use KeepItPeace\Scan\ScanReport;
use PHPUnit\Framework\TestCase;

class NotificationPolicyTest extends TestCase {

	private function report( array $counts, int $new = 0 ): ScanReport {
		$report              = new ScanReport();
		$report->by_severity = array_merge( array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0 ), $counts );
		$report->diff        = array( 'new' => $new, 'resolved' => 0, 'ongoing' => 0 );
		return $report;
	}

	public function test_always(): void {
		$this->assertTrue( NotificationPolicy::evaluate( $this->report( array() ), 'always', 'high' ) );
	}

	public function test_issues_only_ignores_info(): void {
		$this->assertFalse( NotificationPolicy::evaluate( $this->report( array( 'info' => 4 ) ), 'issues_only', 'high' ) );
		$this->assertTrue( NotificationPolicy::evaluate( $this->report( array( 'low' => 1 ) ), 'issues_only', 'high' ) );
	}

	public function test_new_only(): void {
		$this->assertFalse( NotificationPolicy::evaluate( $this->report( array( 'high' => 3 ) ), 'new_only', 'high' ) );
		$this->assertTrue( NotificationPolicy::evaluate( $this->report( array( 'high' => 3 ), 1 ), 'new_only', 'high' ) );
	}

	public function test_min_severity(): void {
		$this->assertFalse( NotificationPolicy::evaluate( $this->report( array( 'medium' => 5 ) ), 'min_severity', 'high' ) );
		$this->assertTrue( NotificationPolicy::evaluate( $this->report( array( 'critical' => 1 ) ), 'min_severity', 'high' ) );
		$this->assertTrue( NotificationPolicy::evaluate( $this->report( array( 'medium' => 1 ) ), 'min_severity', 'medium' ) );
	}

	public function test_digest_is_always_sent_and_filter_applies(): void {
		$report            = $this->report( array() );
		$report->is_digest = true;
		$this->assertTrue( NotificationPolicy::should_send( $report, array( 'condition' => 'new_only' ), 'email' ) );

		add_filter( 'keepitpeace_should_notify', static function ( $send, $r, $channel ) {
			return 'muted' === $channel ? false : $send;
		} );
		$this->assertFalse( NotificationPolicy::should_send( $report, array(), 'muted' ) );
	}

	public function test_report_helpers(): void {
		$report = $this->report( array( 'medium' => 2, 'info' => 7 ) );
		$this->assertSame( 3, $report->max_severity() );
		$this->assertSame( 2, $report->total_issues() );
		$this->assertTrue( $report->has_issues() );
	}
}
