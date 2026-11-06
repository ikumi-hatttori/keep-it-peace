<?php
/**
 * Evaluates a version against release cycle data.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Pure logic, unit tested.
 */
final class VersionEvaluator {

	/**
	 * Extracts `x.y.z` from strings such as `8.1.2-1ubuntu4.14` or `5.5.5-10.6.12-MariaDB`.
	 *
	 * @param string $raw Raw version.
	 * @return string Empty when not found.
	 */
	public static function normalize( string $raw ): string {
		$raw = preg_replace( '/^5\.5\.5-/', '', trim( $raw ) );
		if ( preg_match( '/(\d+)\.(\d+)(?:\.(\d+))?/', (string) $raw, $m ) ) {
			return $m[1] . '.' . $m[2] . '.' . ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : '0' );
		}
		return '';
	}

	/**
	 * Major.minor branch.
	 *
	 * @param string $version Version.
	 * @return string
	 */
	public static function branch( string $version ): string {
		$parts = explode( '.', $version );
		return count( $parts ) >= 2 ? $parts[0] . '.' . $parts[1] : $version;
	}

	/**
	 * Evaluates support status.
	 *
	 * @param string      $version      Normalized version (x.y.z).
	 * @param array       $cycles       endoflife.date cycles.
	 * @param int         $now          Unix time.
	 * @param string|null $latest_patch Latest patch of the branch from another source (php.net), if known.
	 * @return array{version:string,branch:string,known:bool,eol:bool,eol_date:?string,active_support_until:?string,latest_patch:?string,behind:bool,days_until_eol:?int}
	 */
	public static function evaluate( string $version, array $cycles, int $now, ?string $latest_patch = null ): array {
		$branch = self::branch( $version );
		$result = array(
			'version'              => $version,
			'branch'               => $branch,
			'known'                => false,
			'eol'                  => false,
			'eol_date'             => null,
			'active_support_until' => null,
			'latest_patch'         => $latest_patch,
			'behind'               => false,
			'days_until_eol'       => null,
		);

		$cycle = null;
		foreach ( $cycles as $candidate ) {
			if ( is_array( $candidate ) && isset( $candidate['cycle'] ) && (string) $candidate['cycle'] === $branch ) {
				$cycle = $candidate;
				break;
			}
		}

		if ( null !== $cycle ) {
			$result['known'] = true;
			$eol             = $cycle['eol'] ?? false;
			if ( is_string( $eol ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $eol ) ) {
				$eol_time                 = strtotime( $eol . ' 23:59:59 UTC' );
				$result['eol_date']       = $eol;
				$result['eol']            = $eol_time < $now;
				$result['days_until_eol'] = (int) floor( ( $eol_time - $now ) / 86400 );
			} elseif ( true === $eol ) {
				$result['eol'] = true;
			}
			$support = $cycle['support'] ?? null;
			if ( is_string( $support ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $support ) ) {
				$result['active_support_until'] = $support;
			}
			if ( null === $result['latest_patch'] && ! empty( $cycle['latest'] ) ) {
				$result['latest_patch'] = (string) $cycle['latest'];
			}
		} elseif ( ! empty( $cycles ) ) {
			// Older than every known cycle means long unsupported.
			$oldest = null;
			foreach ( $cycles as $candidate ) {
				if ( isset( $candidate['cycle'] ) && ( null === $oldest || version_compare( (string) $candidate['cycle'], $oldest, '<' ) ) ) {
					$oldest = (string) $candidate['cycle'];
				}
			}
			if ( null !== $oldest && version_compare( $branch, $oldest, '<' ) ) {
				$result['known'] = true;
				$result['eol']   = true;
			}
		}

		if ( null !== $result['latest_patch'] && self::branch( (string) $result['latest_patch'] ) === $branch ) {
			$result['behind'] = version_compare( $version, (string) $result['latest_patch'], '<' );
		}
		return $result;
	}

	/**
	 * Maps a CVSS base score to a severity slug.
	 *
	 * @param float $score Score.
	 * @return string
	 */
	public static function cvss_to_severity( float $score ): string {
		if ( $score >= 9.0 ) {
			return 'critical';
		}
		if ( $score >= 7.0 ) {
			return 'high';
		}
		if ( $score >= 4.0 ) {
			return 'medium';
		}
		if ( $score > 0 ) {
			return 'low';
		}
		return 'info';
	}
}
