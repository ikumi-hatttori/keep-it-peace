<?php
/**
 * Notification channel contract.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Contracts;

use KeepItPeace\Scan\ScanReport;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Delivers a scan summary somewhere (email, chat, ...).
 */
interface NotificationChannelInterface {

	/**
	 * Unique ID such as `email`.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Translated name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Whether the channel is configured and enabled.
	 *
	 * @param Settings $settings Settings.
	 * @return bool
	 */
	public function is_enabled( Settings $settings ): bool;

	/**
	 * Sends a report. Never include file paths or code in the message.
	 *
	 * @param ScanReport $report Report.
	 * @return true|\WP_Error
	 */
	public function send( ScanReport $report );

	/**
	 * Sends a test message.
	 *
	 * @return true|\WP_Error
	 */
	public function send_test();

	/**
	 * Renders the channel's fields inside the notification settings tab.
	 * Field names must be `keepitpeace[channels][<id>][...]`.
	 *
	 * @param Settings $settings Settings.
	 */
	public function render_settings( Settings $settings ): void;

	/**
	 * Sanitizes the channel's submitted fields.
	 *
	 * @param array $input   Raw input for this channel.
	 * @param array $current Current values for this channel.
	 * @return array
	 */
	public function sanitize_settings( array $input, array $current ): array;
}
