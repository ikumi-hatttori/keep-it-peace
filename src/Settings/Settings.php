<?php
/**
 * Plugin settings.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the single `keepitpeace_settings` option.
 */
class Settings {

	const OPTION = 'keepitpeace_settings';

	/**
	 * Current values (merged with defaults).
	 *
	 * @var array
	 */
	private $values;

	/**
	 * Constructor.
	 *
	 * @param array|null $values Values; null loads from the database.
	 */
	public function __construct( ?array $values = null ) {
		if ( null === $values ) {
			$stored = get_option( self::OPTION, array() );
			$values = is_array( $stored ) ? $stored : array();
		}
		$this->values = self::merge( self::defaults(), $values );
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		$admin_email = function_exists( 'get_option' ) ? (string) get_option( 'admin_email', '' ) : '';
		$defaults    = array(
			'schedule'          => 'daily',
			'retention_days'    => 30,
			'themes'            => array(),
			'checks'            => array(),
			'exclude_patterns'  => array( 'node_modules', 'vendor', '.git', '.svn', 'cache' ),
			'max_file_size_kb'  => 2048,
			'max_files'         => 20000,
			'notify'            => array(
				'condition'    => 'issues_only',
				'min_severity' => 'high',
				'digest'       => true,
			),
			'channels'          => array(
				'email' => array(
					'enabled'    => true,
					'recipients' => '' !== $admin_email ? array( $admin_email ) : array(),
					'format'     => 'html',
				),
			),
			'remote'            => array(
				'enabled'  => true,
				'services' => array(
					'endoflife' => true,
					'phpnet'    => true,
					'nvd'       => true,
					'osv'       => true,
				),
			),
			'cert_warning_days' => 21,
		);

		/**
		 * Filters the default settings (Pro add-ons may add their own keys).
		 *
		 * @param array $defaults Default settings.
		 */
		return apply_filters( 'keepitpeace_default_settings', $defaults );
	}

	/**
	 * Gets a value with dot notation (e.g. `channels.email.recipients`).
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Value when missing.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		$value = $this->values;
		foreach ( explode( '.', $key ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return $fallback;
			}
			$value = $value[ $part ];
		}
		return $value;
	}

	/**
	 * All values.
	 *
	 * @return array
	 */
	public function all(): array {
		return $this->values;
	}

	/**
	 * Whether a check is enabled (checks default to enabled).
	 *
	 * @param string $check_id Check ID.
	 * @return bool
	 */
	public function is_check_enabled( string $check_id ): bool {
		$checks = $this->get( 'checks', array() );
		return ! isset( $checks[ $check_id ] ) || (bool) $checks[ $check_id ];
	}

	/**
	 * Whether a remote service may be contacted.
	 *
	 * @param string $service Service ID (endoflife, phpnet, nvd, osv).
	 * @return bool
	 */
	public function is_remote_enabled( string $service ): bool {
		if ( ! $this->get( 'remote.enabled', true ) ) {
			return false;
		}
		$services = $this->get( 'remote.services', array() );
		return ! isset( $services[ $service ] ) || (bool) $services[ $service ];
	}

	/**
	 * Maximum allowed retention in days (0 = unlimited).
	 *
	 * @return int
	 */
	public static function max_retention_days(): int {
		/**
		 * Filters the maximum history retention in days. 0 means unlimited.
		 *
		 * @param int $max Maximum days.
		 */
		return max( 0, (int) apply_filters( 'keepitpeace_retention_max_days', 365 ) );
	}

	/**
	 * Effective retention in days (0 = keep forever).
	 *
	 * @return int
	 */
	public function retention_days(): int {
		$days = max( 0, (int) $this->get( 'retention_days', 30 ) );
		$max  = self::max_retention_days();
		if ( 0 === $max ) {
			return $days;
		}
		if ( 0 === $days ) {
			return $max;
		}
		return min( $days, $max );
	}

	/**
	 * Saves new values.
	 *
	 * @param array $values Full settings array.
	 */
	public function save( array $values ): void {
		$this->values = self::merge( self::defaults(), $values );
		update_option( self::OPTION, $this->values, false );
	}

	/**
	 * Recursively merges stored values over defaults (lists are replaced, not merged).
	 *
	 * @param array $defaults Defaults.
	 * @param array $values   Values.
	 * @return array
	 */
	public static function merge( array $defaults, array $values ): array {
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && ! self::is_list( $defaults[ $key ] ) && ! self::is_list( $value ) ) {
				$defaults[ $key ] = self::merge( $defaults[ $key ], $value );
			} else {
				$defaults[ $key ] = $value;
			}
		}
		return $defaults;
	}

	/**
	 * Whether the array is a list.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
