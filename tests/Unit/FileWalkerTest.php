<?php
/**
 * Resumable traversal and path checks.
 *
 * @package KeepItPeace
 */

use KeepItPeace\Scan\FileInfo;
use KeepItPeace\Scan\FileWalker;
use KeepItPeace\Scan\PathGuard;
use PHPUnit\Framework\TestCase;

class FileWalkerTest extends TestCase {

	private $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/kip-walker-' . uniqid();
		foreach ( array( 'a.php', 'b/c.php', 'b/d/e.js', 'node_modules/x.js', 'f/g.txt', 'f/h.txt', 'z.css' ) as $file ) {
			$path = $this->root . '/' . $file;
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0777, true );
			}
			file_put_contents( $path, 'x' );
		}
	}

	protected function tearDown(): void {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
	}

	private function collect( FileWalker $walker, int $per_slice ): array {
		$files  = array();
		$cursor = array();
		$slices = 0;
		do {
			$budget = $per_slice;
			$result = $walker->walk(
				$cursor,
				static function ( FileInfo $f ) use ( &$files ) {
					$files[] = $f->rel_path;
				},
				static function () {},
				static function () use ( &$budget ) {
					return $budget-- > 0;
				}
			);
			// Simulate a new request: the cursor must survive JSON encoding.
			$cursor = json_decode( json_encode( $result['cursor'] ), true );
			++$slices;
		} while ( ! $result['done'] && $slices < 100 );
		return array( $files, $result, $slices );
	}

	public function test_full_walk_with_excludes(): void {
		list( $files ) = $this->collect( new FileWalker( $this->root, array( 'node_modules' ), 0 ), 1000 );
		sort( $files );
		$this->assertSame( array( 'a.php', 'b/c.php', 'b/d/e.js', 'f/g.txt', 'f/h.txt', 'z.css' ), $files );
	}

	public function test_resume_across_slices_gives_same_result(): void {
		list( $all ) = $this->collect( new FileWalker( $this->root, array( 'node_modules' ), 0 ), 1000 );
		list( $sliced, , $slices ) = $this->collect( new FileWalker( $this->root, array( 'node_modules' ), 0 ), 1 );
		$this->assertSame( $all, $sliced );
		$this->assertGreaterThan( 3, $slices );
	}

	public function test_path_patterns_and_limit(): void {
		list( $files ) = $this->collect( new FileWalker( $this->root, array( 'f/*', '*.css', 'node_modules' ), 0 ), 1000 );
		sort( $files );
		$this->assertSame( array( 'a.php', 'b/c.php', 'b/d/e.js' ), $files );

		list( $files, $result ) = $this->collect( new FileWalker( $this->root, array(), 2 ), 1000 );
		$this->assertCount( 2, $files );
		$this->assertTrue( $result['limited'] );
	}

	public function test_file_info(): void {
		$info = FileInfo::from_path( $this->root . '/a.php', 'a.php' );
		$this->assertTrue( $info->is_php() );
		$this->assertSame( hash( 'sha256', 'x' ), $info->hash() );
		$this->assertSame( 'x', $info->contents( 10 ) );
		$this->assertNull( $info->contents( 0 ) );
	}

	public function test_open_basedir(): void {
		$this->assertTrue( PathGuard::is_within_open_basedir( '/any/path', '' ) );
		$this->assertTrue( PathGuard::is_within_open_basedir( '/home/u/www/x', '/home/u/' . PATH_SEPARATOR . '/tmp/' ) );
		$this->assertFalse( PathGuard::is_within_open_basedir( '/home/other/x', '/home/u/' . PATH_SEPARATOR . '/tmp/' ) );
		$this->assertFalse( PathGuard::is_within_open_basedir( '/home/user2/x', '/home/user/' ) );
		// Without trailing slash PHP treats the value as a prefix.
		$this->assertTrue( PathGuard::is_within_open_basedir( '/home/user2/x', '/home/user' ) );
	}

	public function test_check_directory(): void {
		$this->assertSame( PathGuard::OK, PathGuard::check_directory( $this->root ) );
		$this->assertSame( PathGuard::NOT_FOUND, PathGuard::check_directory( $this->root . '/missing' ) );
		$this->assertSame( PathGuard::NOT_DIRECTORY, PathGuard::check_directory( $this->root . '/a.php' ) );
		$this->assertSame( PathGuard::NOT_ABSOLUTE, PathGuard::check_directory( 'relative/path' ) );
	}
}
