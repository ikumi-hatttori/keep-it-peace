<?php
/**
 * Resumable directory traversal.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Walks a directory tree with a serializable cursor so work can be split across requests.
 */
class FileWalker {

	/**
	 * Root.
	 *
	 * @var string
	 */
	private $root;
	/**
	 * Excludes.
	 *
	 * @var string[]
	 */
	private $excludes;
	/**
	 * Max files.
	 *
	 * @var int
	 */
	private $max_files;

	/**
	 * Constructor.
	 *
	 * @param string   $root      Absolute root.
	 * @param string[] $excludes  Exclude patterns (fnmatch). Without `/` they match any file or directory name.
	 * @param int      $max_files Maximum files (0 = unlimited).
	 */
	public function __construct( string $root, array $excludes, int $max_files ) {
		$this->root      = PathGuard::normalize( $root );
		$this->excludes  = array_values( array_filter( array_map( 'trim', $excludes ), 'strlen' ) );
		$this->max_files = max( 0, $max_files );
	}

	/**
	 * Continues the traversal.
	 *
	 * @param array    $cursor   Cursor from the previous call (empty to start).
	 * @param callable $on_file  function( FileInfo $file ): void.
	 * @param callable $on_error function( string $rel_dir, string $reason ): void.
	 * @param callable $has_time function(): bool.
	 * @return array{cursor:array,done:bool,limited:bool,count:int}
	 */
	public function walk( array $cursor, callable $on_file, callable $on_error, callable $has_time ): array {
		if ( empty( $cursor ) ) {
			$cursor = array(
				'stack'  => array( '' ),
				'dir'    => null,
				'offset' => 0,
				'count'  => 0,
			);
		}

		while ( true ) {
			if ( null === $cursor['dir'] ) {
				if ( empty( $cursor['stack'] ) ) {
					return $this->result( $cursor, true, false );
				}
				$cursor['dir']    = array_pop( $cursor['stack'] );
				$cursor['offset'] = 0;
			}

			$rel_dir = (string) $cursor['dir'];
			$abs_dir = '' === $rel_dir ? $this->root : $this->root . '/' . $rel_dir;

			$entries = $this->list_directory( $abs_dir, $rel_dir, $on_error );
			if ( null === $entries ) {
				$cursor['dir'] = null;
				continue;
			}

			$total = count( $entries );
			for ( $i = (int) $cursor['offset']; $i < $total; $i++ ) {
				if ( ! $has_time() ) {
					$cursor['offset'] = $i;
					return $this->result( $cursor, false, false );
				}
				$name = $entries[ $i ];
				$rel  = '' === $rel_dir ? $name : $rel_dir . '/' . $name;
				if ( $this->is_excluded( $name, $rel ) ) {
					continue;
				}
				$abs = $abs_dir . '/' . $name;
				if ( is_link( $abs ) ) {
					continue;
				}
				if ( is_dir( $abs ) ) {
					$cursor['stack'][] = $rel;
					continue;
				}
				if ( ! is_file( $abs ) ) {
					continue;
				}
				++$cursor['count'];
				if ( $this->max_files > 0 && $cursor['count'] > $this->max_files ) {
					return $this->result( $cursor, true, true );
				}
				$on_file( FileInfo::from_path( $abs, $rel ) );
			}
			$cursor['dir'] = null;
		}
	}

	/**
	 * Sorted directory entries, or null on failure.
	 *
	 * @param string   $abs_dir  Absolute directory.
	 * @param string   $rel_dir  Relative directory.
	 * @param callable $on_error Error callback.
	 * @return string[]|null
	 */
	private function list_directory( string $abs_dir, string $rel_dir, callable $on_error ): ?array {
		if ( ! PathGuard::is_within_open_basedir( $abs_dir ) ) {
			$on_error( $rel_dir, PathGuard::OUTSIDE_BASEDIR );
			return null;
		}
		if ( ! is_dir( $abs_dir ) || ! is_readable( $abs_dir ) ) {
			$on_error( $rel_dir, PathGuard::NOT_READABLE );
			return null;
		}
		$entries = scandir( $abs_dir );
		if ( false === $entries ) {
			$on_error( $rel_dir, PathGuard::NOT_READABLE );
			return null;
		}
		$entries = array_values( array_diff( $entries, array( '.', '..' ) ) );
		sort( $entries, SORT_STRING );
		return $entries;
	}

	/**
	 * Whether an entry is excluded.
	 *
	 * @param string $name Entry name.
	 * @param string $rel  Relative path.
	 * @return bool
	 */
	public function is_excluded( string $name, string $rel ): bool {
		foreach ( $this->excludes as $pattern ) {
			if ( false === strpos( $pattern, '/' ) ) {
				if ( fnmatch( $pattern, $name ) ) {
					return true;
				}
			} elseif ( fnmatch( trim( $pattern, '/' ), $rel ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds a result.
	 *
	 * @param array $cursor  Cursor.
	 * @param bool  $done    Done.
	 * @param bool  $limited Limit reached.
	 * @return array
	 */
	private function result( array $cursor, bool $done, bool $limited ): array {
		return array(
			'cursor'  => $cursor,
			'done'    => $done,
			'limited' => $limited,
			'count'   => (int) $cursor['count'],
		);
	}
}
