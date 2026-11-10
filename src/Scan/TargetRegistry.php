<?php
/**
 * Scan targets.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Contracts\ScanTargetProviderInterface;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Collects targets from providers registered with `keepitpeace_scan_target_providers`.
 */
class TargetRegistry {

	/**
	 * Core providers.
	 *
	 * @var ScanTargetProviderInterface[]
	 */
	private $core_providers;

	/**
	 * Constructor.
	 *
	 * @param ScanTargetProviderInterface[] $core_providers Core providers.
	 */
	public function __construct( array $core_providers ) {
		$this->core_providers = $core_providers;
	}

	/**
	 * Targets keyed by ID.
	 *
	 * @param Settings $settings Settings.
	 * @return ScanTarget[]
	 */
	public function get_targets( Settings $settings ): array {
		/**
		 * Filters scan target providers.
		 *
		 * @param ScanTargetProviderInterface[] $providers Providers.
		 */
		$providers = apply_filters( 'keepitpeace_scan_target_providers', $this->core_providers );
		$targets   = array();
		foreach ( (array) $providers as $provider ) {
			if ( ! $provider instanceof ScanTargetProviderInterface ) {
				continue;
			}
			foreach ( $provider->get_targets( $settings ) as $target ) {
				if ( $target instanceof ScanTarget ) {
					$targets[ $target->id ] = $target;
				}
			}
		}
		return $targets;
	}

	/**
	 * Gets one target.
	 *
	 * @param string   $id       ID.
	 * @param Settings $settings Settings.
	 * @return ScanTarget|null
	 */
	public function get( string $id, Settings $settings ): ?ScanTarget {
		$targets = $this->get_targets( $settings );
		return $targets[ $id ] ?? null;
	}

	/**
	 * Absolute root for a finding scope (targets plus built-in scopes).
	 *
	 * @param string   $scope    Scope.
	 * @param Settings $settings Settings.
	 * @return string|null
	 */
	public function resolve_scope_root( string $scope, Settings $settings ): ?string {
		$target = $this->get( $scope, $settings );
		if ( $target ) {
			return $target->root;
		}
		switch ( $scope ) {
			case 'uploads':
				$uploads = wp_upload_dir( null, false );
				return PathGuard::normalize( $uploads['basedir'] );
			case 'content':
				return PathGuard::normalize( WP_CONTENT_DIR );
			case 'site':
				return PathGuard::normalize( ABSPATH );
		}
		if ( 0 === strpos( $scope, 'plugin:' ) ) {
			return PluginTargetProvider::resolve_root( $scope );
		}
		if ( 0 === strpos( $scope, 'theme:' ) ) {
			$theme = wp_get_theme( substr( $scope, 6 ) );
			if ( $theme->exists() ) {
				return PathGuard::normalize( $theme->get_stylesheet_directory() );
			}
		}
		return null;
	}
}
