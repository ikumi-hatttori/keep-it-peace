<?php
/**
 * Prevents concurrent scan slices.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Option-based lock (add_option is atomic thanks to the unique option_name key).
 */
class ScanLock {

	const OPTION = 'keepitpeace_scan_lock';

	/**
	 * Acquires the lock.
	 *
	 * @param int $ttl Seconds before the lock may be taken over.
	 * @return bool
	 */
	public function acquire( int $ttl ): bool {
		$now = time();
		if ( add_option( self::OPTION, $now + $ttl, '', false ) ) {
			return true;
		}
		wp_cache_delete( self::OPTION, 'options' );
		$expires = (int) get_option( self::OPTION, 0 );
		if ( $expires < $now ) {
			update_option( self::OPTION, $now + $ttl, false );
			return true;
		}
		return false;
	}

	/**
	 * Releases the lock.
	 */
	public function release(): void {
		delete_option( self::OPTION );
	}
}
