<?php
/**
 * Sanitizes settings per tab.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Admin;

use KeepItPeace\Findings\Severity;
use KeepItPeace\Notifications\NotificationPolicy;
use KeepItPeace\Plugin;
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
