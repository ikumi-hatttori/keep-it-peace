<?php
/**
 * Plugin Name:       Keep It Peace – Sample Add-on
 * Description:       Example of extending Keep It Peace: one custom check, one notification channel and one settings tab. For developers; not for production.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  keep-it-peace
 * Author:            mackarel Inc.
 * License:           GPL-2.0-or-later
 * Text Domain:       kip-sample-addon
 *
 * @package KeepItPeaceSample
 */

namespace KeepItPeaceSample;

defined( 'ABSPATH' ) || exit;

/*
 * Start only when Keep It Peace is loaded and its extension API is compatible.
 * keepitpeace_loaded fires on plugins_loaded after the core plugin has registered its hooks.
 */
add_action(
	'keepitpeace_loaded',
	static function () {
		if ( ! defined( 'KEEPITPEACE_API_VERSION' ) || version_compare( KEEPITPEACE_API_VERSION, '1.0', '<' ) || version_compare( KEEPITPEACE_API_VERSION, '2.0', '>=' ) ) {
			return;
		}
		require_once __DIR__ . '/src/AdminUsernameCheck.php';
		require_once __DIR__ . '/src/LogChannel.php';
		require_once __DIR__ . '/src/Addon.php';
		( new Addon() )->register();
	}
);
