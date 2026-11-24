<?php
/**
 * Unreadable directories must not hide existing findings.
 *
 * @package KeepItPeace
 */

use KeepItPeace\Baseline\FileIndexRepository;
use KeepItPeace\Checks\Files\IntegrityCheck;
use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\FindingRepository;
use KeepItPeace\Remote\HttpClient;
use KeepItPeace\Rules\RuleRepository;
use KeepItPeace\Scan\FileWalker;
use KeepItPeace\Scan\Scan;
use KeepItPeace\Scan\ScanContext;
use KeepItPeace\Scan\Scanner;
use KeepItPeace\Scan\ScanTarget;
use KeepItPeace\Scan\UnreadableDirs;
use KeepItPeace\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * File index stub: answers like the SQL in FileIndexRepository and records what it was asked.
 */
class KipStubFileIndex extends FileIndexRepository {
	/**
	 * Baseline rows not seen in the scan.
	 *
	 * @var string[]
	 */
	public $not_seen = array();
	/**
	 * Arguments received.
	 *
	 * @var array
	 */
	public $calls = array();

	public function is_scope_initialized( string $scope ): bool {
		return true;
	}

	public function purge_unbaselined_missing( string $scope, int $scan_id, array $exclude_dirs = array() ): void {
		$this->calls['purge'] = $exclude_dirs;
	}

	public function missing( string $scope, int $scan_id, int $limit, array $exclude_dirs = array() ): array {
		$this->calls['missing'] = $exclude_dirs;
		$rows = array();
		foreach ( $this->not_seen as $rel ) {
			if ( ! UnreadableDirs::covers( $rel, $exclude_dirs ) ) {
				$rows[] = (object) array(
					'rel_path'      => $rel,
					'baseline_hash' => str_repeat( 'a', 64 ),
				);
			}
		}
		return $rows;
	}
}

/**
 * Tests.
 */
class UnreadableDirsTest extends TestCase {

	/**
	 * Fixture root.
	 *
	 * @var string
	 */
	private $root;

	protected function setUp(): void {
		$GLOBALS['kip_test_options'] = array();
		$this->root                  = sys_get_temp_dir() . '/kip-unreadable-' . uniqid();
		mkdir( $this->root . '/locked/deep', 0777, true );
		mkdir( $this->root . '/open', 0777, true );
		file_put_contents( $this->root . '/style.css', 'a' );
		file_put_contents( $this->root . '/open/a.php', '<?php' );
		file_put_contents( $this->root . '/locked/backdoor.php', '<?php' );
		file_put_contents( $this->root . '/locked/deep/b.php', '<?php' );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->root . '/locked' ) ) {
			chmod( $this->root . '/locked', 0755 );
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
		$GLOBALS['kip_test_options'] = array();
	}

	/**
	 * Makes "locked" unreadable, or skips when permissions are not enforced (root, some CI containers).
	 */
	private function lock(): void {
		chmod( $this->root . '/locked', 0000 );
		clearstatcache();
		if ( is_readable( $this->root . '/locked' ) ) {
			$this->markTestSkipped( 'Directory permissions are not enforced for this user (running as root?).' );
		}
	}

	/**
	 * Walks the fixture.
	 *
	 * @param int $files_per_slice Files per slice (0 = no limit).
	 * @return array{result:array,files:string[],errors:string[]}
	 */
	private function walk( int $files_per_slice = 0 ): array {
		$walker = new FileWalker( $this->root, array(), 0 );
		$files  = array();
		$errors = array();
		$cursor = array();
		do {
			$budget = $files_per_slice;
			$result = $walker->walk(
				$cursor,
				static function ( $file ) use ( &$files ) {
					$files[] = $file->rel_path;
				},
				static function ( $rel, $reason ) use ( &$errors ) {
					$errors[] = $rel;
				},
				static function () use ( &$budget, $files_per_slice ) {
					return 0 === $files_per_slice || $budget-- > 0;
				}
			);
			$cursor = $result['cursor'];
			// Simulates saving the cursor between requests.
			$cursor = json_decode( wp_json_encode( $cursor ), true );
		} while ( ! $result['done'] );
		sort( $files );
		return compact( 'result', 'files', 'errors' );
	}

	public function test_walker_reports_unreadable_directories(): void {
		$this->lock();
		$walk = $this->walk();
		$this->assertSame( array( 'locked' ), $walk['result']['unreadable'] );
		$this->assertSame( array( 'locked' ), $walk['errors'] );
		$this->assertSame( array( 'open/a.php', 'style.css' ), $walk['files'] );
	}

	public function test_unreadable_list_survives_resumed_slices(): void {
		$this->lock();
		$walk = $this->walk( 1 );
		$this->assertSame( array( 'locked' ), $walk['result']['unreadable'] );
		$this->assertSame( array( 'open/a.php', 'style.css' ), $walk['files'] );
	}

	public function test_readable_tree_has_no_unreadable_dirs(): void {
		$walk = $this->walk();
		$this->assertSame( array(), $walk['result']['unreadable'] );
		$this->assertCount( 4, $walk['files'] );
	}

	public function test_target_is_kept_and_remembered(): void {
		$this->lock();
		$walk   = $this->walk();
		$target = new ScanTarget( 'theme:demo', 'theme', 'Demo', $this->root, 'theme' );
		$state  = array( 'keep_scopes' => array() );
		Scanner::apply_unreadable( $target, $walk['result']['unreadable'], $state );

		$this->assertSame( array( 'theme:demo' ), $state['keep_scopes'] );
		$this->assertSame( array( 'locked' ), $target->unreadable_dirs );
		$this->assertSame( array( 'locked' ), UnreadableDirs::get( 'theme:demo' ) );

		// A later scan that can read everything clears it (approval is allowed again).
		$state = array( 'keep_scopes' => array() );
		Scanner::apply_unreadable( $target, array(), $state );
		$this->assertSame( array(), $state['keep_scopes'] );
		$this->assertSame( array(), UnreadableDirs::get( 'theme:demo' ) );
	}

	/**
	 * Runs IntegrityCheck::end_target() with the stub index.
	 *
	 * @param string[] $unreadable Unreadable directories.
	 * @return array{0:Finding[],1:KipStubFileIndex}
	 */
	private function end_target( array $unreadable ): array {
		$index           = new KipStubFileIndex();
		$index->not_seen = array( 'locked/backdoor.php', 'locked/deep/b.php', 'removed.css' );
		$settings        = new Settings( array() );
		$scan            = new Scan();
		$scan->id        = 7;
		$context         = new ScanContext( $scan, $settings, new RuleRepository( array(), array() ), $index, new HttpClient( $settings ), microtime( true ) + 10 );
		$target          = new ScanTarget( 'theme:demo', 'theme', 'Demo', $this->root, 'theme' );

		$target->unreadable_dirs = $unreadable;
		return array( ( new IntegrityCheck() )->end_target( $target, $context ), $index );
	}

	public function test_files_below_unreadable_dirs_are_not_reported_deleted(): void {
		list( $findings, $index ) = $this->end_target( array( 'locked' ) );
		$this->assertSame( array( 'removed.css' ), array_map(
			static function ( Finding $f ) {
				return $f->target;
			},
			$findings
		) );
		$this->assertSame( array( 'locked' ), $index->calls['missing'] );
		$this->assertSame( array( 'locked' ), $index->calls['purge'] );
	}

	public function test_without_unreadable_dirs_deletions_are_reported(): void {
		list( $findings ) = $this->end_target( array() );
		$this->assertCount( 3, $findings );
		$this->assertSame( 'integrity.deleted', $findings[0]->rule_id );
	}

	public function test_covers(): void {
		$this->assertTrue( UnreadableDirs::covers( 'locked/a.php', array( 'locked' ) ) );
		$this->assertTrue( UnreadableDirs::covers( 'locked/deep/b.php', array( 'locked' ) ) );
		$this->assertTrue( UnreadableDirs::covers( 'anything.php', array( '.' ) ) );
		$this->assertFalse( UnreadableDirs::covers( 'locked-not/a.php', array( 'locked' ) ) );
		$this->assertFalse( UnreadableDirs::covers( 'a.php', array() ) );
	}

	public function test_keep_entries_for_one_target(): void {
		$finding = Finding::create(
			array(
				'check_id'     => 'files.code_pattern',
				'rule_id'      => 'php.eval',
				'target_scope' => 'theme:demo',
				'target'       => 'big.php',
			)
		);
		$this->assertTrue( FindingRepository::is_kept( $finding, array( array( 'files.code_pattern', '*', 'theme:demo', 'big.php' ) ) ) );
		$this->assertFalse( FindingRepository::is_kept( $finding, array( array( 'files.code_pattern', '*', 'theme:demo', 'other.php' ) ) ) );
		$this->assertFalse( FindingRepository::is_kept( $finding, array( array( 'files.integrity', '*', 'theme:demo', 'big.php' ) ) ) );
		$this->assertTrue( FindingRepository::is_kept( $finding, array( array( 'files.code_pattern', 'php.eval' ) ) ) );
	}
}
