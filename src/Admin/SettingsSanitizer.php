<?php
/**
 * Sanitizes settings per tab.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Admin;

use KeepItPeace\Contracts\FileCheckInterface;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Notifications\NotificationPolicy;
use KeepItPeace\Plugin;
use KeepItPeace\Remote\HostPolicy;
use KeepItPeace\Scan\PathTargetProvider;
use KeepItPeace\Scan\PathValidator;
use KeepItPeace\Scan\Scheduler;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Only the fields of the submitted tab are changed.
 */
class SettingsSanitizer {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Problems found while sanitizing (shown after saving).
	 *
	 * @var string[]
	 */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Sanitizes one tab and returns the full settings array.
	 *
	 * @param string $tab     Tab.
	 * @param array  $input   Raw (unslashed) input.
	 * @param array  $current Current settings.
	 * @return array
	 */
	public function sanitize_tab( string $tab, array $input, array $current ): array {
		$values = $current;
		switch ( $tab ) {
			case 'general':
				$options                  = Scheduler::frequency_options();
				$schedule                 = isset( $input['schedule'] ) ? sanitize_key( $input['schedule'] ) : 'daily';
				$values['schedule']       = isset( $options[ $schedule ] ) ? $schedule : 'daily';
				$max                      = Settings::max_retention_days();
				$days                     = isset( $input['retention_days'] ) ? absint( $input['retention_days'] ) : 30;
				$values['retention_days'] = 0 === $max ? $days : max( 1, min( $max, $days ) );
				break;

			case 'targets':
				$themes = array();
				foreach ( (array) ( $input['themes'] ?? array() ) as $slug ) {
					$slug = sanitize_text_field( (string) $slug );
					if ( wp_get_theme( $slug )->exists() ) {
						$themes[] = $slug;
					}
				}
				$values['themes'] = array_values( array_unique( $themes ) );
				$values['paths']  = $this->sanitize_paths( (array) ( $input['paths'] ?? array() ), (array) ( $current['paths'] ?? array() ) );
				$values['urls']   = $this->sanitize_urls( self::lines( (string) ( $input['urls'] ?? '' ) ) );
				break;

			case 'checks':
				$checks = array();
				foreach ( array_keys( $this->plugin->checks()->all() ) as $id ) {
					$checks[ $id ] = ! empty( $input['checks'][ $id ] );
				}
				$values['checks']           = $checks;
				$values['exclude_patterns'] = self::lines( (string) ( $input['exclude_patterns'] ?? '' ) );
				$values['max_file_size_kb'] = max( 16, min( 102400, absint( $input['max_file_size_kb'] ?? 2048 ) ) );
				$values['max_files']        = min( 1000000, absint( $input['max_files'] ?? 20000 ) );
				break;

			case 'notifications':
				$condition        = isset( $input['notify']['condition'] ) ? sanitize_key( $input['notify']['condition'] ) : NotificationPolicy::ISSUES_ONLY;
				$severity         = isset( $input['notify']['min_severity'] ) ? sanitize_key( $input['notify']['min_severity'] ) : 'high';
				$values['notify'] = array(
					'condition'    => isset( NotificationPolicy::conditions()[ $condition ] ) ? $condition : NotificationPolicy::ISSUES_ONLY,
					'min_severity' => in_array( $severity, Severity::SLUGS, true ) ? $severity : 'high',
					'digest'       => ! empty( $input['notify']['digest'] ),
				);
				foreach ( $this->plugin->channels()->all() as $id => $channel ) {
					$values['channels'][ $id ] = $channel->sanitize_settings(
						(array) ( $input['channels'][ $id ] ?? array() ),
						(array) ( $current['channels'][ $id ] ?? array() )
					);
				}
				break;

			case 'remote':
				$services = array();
				foreach ( array_keys( (array) ( $current['remote']['services'] ?? array() ) ) as $service ) {
					$services[ $service ] = ! empty( $input['remote']['services'][ $service ] );
				}
				$values['remote'] = array(
					'enabled'  => ! empty( $input['remote']['enabled'] ),
					'services' => $services,
				);
				break;
		}

		/**
		 * Filters sanitized settings (add-ons sanitize their own tabs here).
		 *
		 * @param array  $values Sanitized full settings.
		 * @param array  $input  Raw input of the tab.
		 * @param string $tab    Tab ID.
		 */
		return (array) apply_filters( 'keepitpeace_sanitize_settings', $values, $input, $tab );
	}

	/**
	 * Sanitizes registered directories outside WordPress. Invalid new entries are dropped;
	 * invalid changes to existing entries keep the previous value. Problems are added to $errors.
	 *
	 * @param array $rows    Submitted rows.
	 * @param array $current Current entries.
	 * @return array
	 */
	private function sanitize_paths( array $rows, array $current ): array {
		$by_id = array();
		foreach ( $current as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
				$by_id[ (string) $entry['id'] ] = $entry;
			}
		}
		$file_checks = array();
		foreach ( $this->plugin->checks()->all() as $id => $check ) {
			if ( $check instanceof FileCheckInterface ) {
				$file_checks[] = $id;
			}
		}
		$policy = HostPolicy::for_site();
		$out    = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$old_id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			$old    = $by_id[ $old_id ] ?? null;
			$raw    = trim( (string) ( $row['path'] ?? '' ) );
			if ( ! empty( $row['remove'] ) || '' === $raw ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$check = PathValidator::validate( wp_normalize_path( $raw ) );
			if ( '' !== $check['error'] ) {
				/* translators: 1: path, 2: reason. */
				$this->errors[] = sprintf( __( '%1$s: %2$s', 'keep-it-peace' ), $raw, PathValidator::describe( $check['error'] ) );
				if ( $old ) {
					$out[ (string) $old['id'] ] = $old;
				}
				continue;
			}
			$id = PathTargetProvider::id_for( $check['path'] );
			if ( isset( $out[ $id ] ) ) {
				continue;
			}

			$url = esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ), array( 'http', 'https' ) );
			if ( '' !== $url && ! $policy->is_allowed_url( $url ) ) {
				/* translators: %s: URL. */
				$this->errors[] = sprintf( __( '%s: only URLs on your own domain can be checked.', 'keep-it-peace' ), $url );
				$url            = $old ? (string) ( $old['url'] ?? '' ) : '';
			}

			$checks = array();
			foreach ( $file_checks as $check_id ) {
				$checks[ $check_id ] = ! empty( $row['checks'][ $check_id ] );
			}
			$out[ $id ] = array(
				'id'       => $id,
				'label'    => '' !== $label ? $label : basename( $check['path'] ),
				'path'     => $check['path'],
				'url'      => $url,
				'checks'   => $checks,
				'excludes' => self::lines( (string) ( $row['excludes'] ?? '' ) ),
			);
		}
		return array_values( $out );
	}

	/**
	 * Keeps only http(s) URLs on the site's own domain.
	 *
	 * @param string[] $urls URLs.
	 * @return string[]
	 */
	private function sanitize_urls( array $urls ): array {
		$policy = HostPolicy::for_site();
		$out    = array();
		foreach ( $urls as $url ) {
			$clean = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' === $clean || ! $policy->is_allowed_url( $clean ) ) {
				/* translators: %s: URL. */
				$this->errors[] = sprintf( __( '%s: only URLs on your own domain can be checked.', 'keep-it-peace' ), $url );
				continue;
			}
			$out[] = $clean;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Splits a textarea into trimmed, unique lines.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function lines( string $text ): array {
		$lines = array_map( 'sanitize_text_field', preg_split( '/\r\n|\n|\r/', $text ) );
		return array_values( array_unique( array_filter( array_map( 'trim', $lines ), 'strlen' ) ) );
	}
}
