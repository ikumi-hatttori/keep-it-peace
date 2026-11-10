<?php
/**
 * Small rendering helpers.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Admin;

use KeepItPeace\Findings\FindingStatus;
use KeepItPeace\Findings\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Escaped HTML snippets.
 */
final class Ui {

	/**
	 * Severity badge.
	 *
	 * @param int $severity Level.
	 * @return string
	 */
	public static function severity_badge( int $severity ): string {
		return sprintf( '<span class="kip-badge kip-sev-%1$s">%2$s</span>', esc_attr( Severity::slug( $severity ) ), esc_html( Severity::label( $severity ) ) );
	}

	/**
	 * Status badge.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_badge( string $status ): string {
		return sprintf( '<span class="kip-status kip-status-%1$s">%2$s</span>', esc_attr( $status ), esc_html( FindingStatus::label( $status ) ) );
	}

	/**
	 * Formats a UTC datetime in the site timezone.
	 *
	 * @param string|null $utc UTC datetime.
	 * @return string
	 */
	public static function datetime( ?string $utc ): string {
		if ( ! $utc ) {
			return '—';
		}
		return self::timestamp( (int) strtotime( $utc . ' UTC' ) );
	}

	/**
	 * Formats a timestamp in the site timezone.
	 *
	 * @param int|null $time Timestamp.
	 * @return string
	 */
	public static function timestamp( ?int $time ): string {
		if ( ! $time ) {
			return '—';
		}
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Category label.
	 *
	 * @param string $category Category.
	 * @return string
	 */
	public static function category_label( string $category ): string {
		$labels = array(
			'php'           => __( 'PHP / server', 'keep-it-peace' ),
			'theme'         => __( 'Theme', 'keep-it-peace' ),
			'plugin'        => __( 'Plugin', 'keep-it-peace' ),
			'external_path' => __( 'Outside WordPress', 'keep-it-peace' ),
			'external'      => __( 'Public surface', 'keep-it-peace' ),
			'dependency'    => __( 'Dependencies', 'keep-it-peace' ),
			'environment'   => __( 'WordPress environment', 'keep-it-peace' ),
			'files'         => __( 'Files', 'keep-it-peace' ),
		);
		return $labels[ $category ] ?? $category;
	}

	/**
	 * Readable name of a finding scope (registered directories show their label).
	 *
	 * @param string $scope Scope.
	 * @return string
	 */
	public static function scope_label( string $scope ): string {
		static $labels = null;
		if ( 0 !== strpos( $scope, 'path:' ) ) {
			return $scope;
		}
		if ( null === $labels ) {
			$labels = array();
			foreach ( (array) \KeepItPeace\Plugin::instance()->settings()->get( 'paths', array() ) as $entry ) {
				if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
					$labels[ 'path:' . $entry['id'] ] = (string) ( $entry['label'] ?? $entry['path'] ?? '' );
				}
			}
		}
		return isset( $labels[ $scope ] ) && '' !== $labels[ $scope ] ? $labels[ $scope ] : $scope;
	}

	/**
	 * Human-readable location of a finding (scope + target + line).
	 *
	 * @param \KeepItPeace\Findings\Finding $finding Finding.
	 * @return string Plain text.
	 */
	public static function location( $finding ): string {
		$text = $finding->target;
		if ( 'file' === $finding->target_type && '' !== $finding->target_scope ) {
			$text = self::scope_label( $finding->target_scope ) . ': ' . $finding->target;
		}
		if ( null !== $finding->line ) {
			$text .= ':' . $finding->line;
		}
		return $text;
	}
}
