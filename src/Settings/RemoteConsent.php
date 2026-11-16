<?php
/**
 * Consent to use third-party vulnerability databases.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Third-party APIs (endoflife.date, php.net, NVD, OSV.dev) are opt-in. Until an administrator
 * grants consent, nothing is sent and the bundled snapshot is used.
 *
 * The choice is stored separately from the settings so that "not asked yet" and "declined"
 * can be told apart (the onboarding notice is shown only while the choice is pending).
 */
final class RemoteConsent {

	const OPTION   = 'keepitpeace_remote_consent';
	const GRANTED  = 'granted';
	const DECLINED = 'declined';
	const PENDING  = 'pending';

	/**
	 * Current choice.
	 *
	 * @return string granted|declined|pending
	 */
	public static function status(): string {
		return self::normalize( get_option( self::OPTION, '' ) );
	}

	/**
	 * Normalizes a stored value.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	public static function normalize( $value ): string {
		return in_array( $value, array( self::GRANTED, self::DECLINED ), true ) ? (string) $value : self::PENDING;
	}

	/**
	 * Whether a third-party service may be contacted. Requires both the consent and the switches.
	 *
	 * @param string $consent         granted|declined|pending.
	 * @param bool   $remote_enabled  Global switch (remote.enabled).
	 * @param bool   $service_enabled Switch of the service.
	 * @return bool
	 */
	public static function allows( string $consent, bool $remote_enabled, bool $service_enabled ): bool {
		return self::GRANTED === $consent && $remote_enabled && $service_enabled;
	}

	/**
	 * Records the administrator's choice and keeps the global switch in sync.
	 *
	 * @param bool $granted Granted.
	 */
	public static function record( bool $granted ): void {
		update_option( self::OPTION, $granted ? self::GRANTED : self::DECLINED, false );
		$settings = new Settings();
		$values   = $settings->all();

		$values['remote']['enabled'] = $granted;
		$settings->save( $values );
	}
}
