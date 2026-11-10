<?php
/**
 * Plugin targets (custom plugins only).
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Contracts\ScanTargetProviderInterface;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the plugins selected in Settings → Targets.
 *
 * Plugins distributed on WordPress.org are not offered: they are maintained (and
 * vulnerability-tracked) upstream. Nothing is selected by default.
 */
class PluginTargetProvider implements ScanTargetProviderInterface {

	const MU_PLUGINS = '__mu_plugins__';

	/**
	 * Installed directory-based plugins with their source.
	 *
	 * @return array<string,array{name:string,version:string,dir:string,file:string,wporg:bool}> Directory slug => info.
	 */
	public static function available(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$wporg = self::wporg_plugin_files();
		$self  = plugin_basename( KEEPITPEACE_FILE );
		$out   = array();
		foreach ( get_plugins() as $file => $data ) {
			$dir = dirname( $file );
			if ( '.' === $dir || $file === $self ) {
				continue; // Single-file plugins have no own directory; skip ourselves.
			}
			$out[ $dir ] = array(
				'name'    => (string) ( $data['Name'] ?? $dir ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'dir'     => PathGuard::normalize( WP_PLUGIN_DIR . '/' . $dir ),
				'file'    => $file,
				'wporg'   => isset( $wporg[ $file ] ) && ! self::has_custom_update_uri( (string) ( $data['UpdateURI'] ?? '' ) ),
			);
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	/**
	 * Plugin files WordPress.org knows about, from the update check WordPress already performs.
	 *
	 * @return array<string,bool>
	 */
	public static function wporg_plugin_files(): array {
		$transient = get_site_transient( 'update_plugins' );
		$files     = array();
		if ( ! is_object( $transient ) ) {
			return $files;
		}
		foreach ( array( 'response', 'no_update' ) as $key ) {
			if ( empty( $transient->$key ) || ! is_array( $transient->$key ) ) {
				continue;
			}
			foreach ( $transient->$key as $file => $item ) {
				$item = (object) $item;
				$id   = isset( $item->id ) ? (string) $item->id : '';
				$url  = isset( $item->url ) ? (string) $item->url : '';
				if ( 0 === strpos( $id, 'w.org/' ) || false !== strpos( $url, 'wordpress.org/plugins/' ) ) {
					$files[ (string) $file ] = true;
				}
			}
		}
		return $files;
	}

	/**
	 * Whether WordPress has checked plugin updates at least once (otherwise the source is unknown).
	 *
	 * @return bool
	 */
	public static function source_known(): bool {
		$transient = get_site_transient( 'update_plugins' );
		return is_object( $transient ) && ! empty( $transient->last_checked );
	}

	/**
	 * Whether an Update URI header points away from WordPress.org.
	 *
	 * @param string $uri Header value.
	 * @return bool
	 */
	private static function has_custom_update_uri( string $uri ): bool {
		if ( '' === $uri ) {
			return false;
		}
		$host = (string) wp_parse_url( $uri, PHP_URL_HOST );
		return ! in_array( $host, array( 'wordpress.org', 'w.org' ), true );
	}

	/**
	 * Whether the mu-plugins directory exists.
	 *
	 * @return bool
	 */
	public static function mu_plugins_available(): bool {
		return defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR );
	}

	/**
	 * Targets.
	 *
	 * @param Settings $settings Settings.
	 * @return ScanTarget[]
	 */
	public function get_targets( Settings $settings ): array {
		$selected = array_map( 'strval', (array) $settings->get( 'plugins', array() ) );
		if ( empty( $selected ) ) {
			return array();
		}
		$available = self::available();
		$targets   = array();
		foreach ( $selected as $slug ) {
			if ( self::MU_PLUGINS === $slug ) {
				if ( self::mu_plugins_available() ) {
					$target      = new ScanTarget( 'plugin:' . self::MU_PLUGINS, 'plugin', __( 'Must-use plugins (mu-plugins)', 'keep-it-peace' ), PathGuard::normalize( WPMU_PLUGIN_DIR ), 'plugin' );
					$target->url = defined( 'WPMU_PLUGIN_URL' ) ? WPMU_PLUGIN_URL : '';
					$targets[]   = $target;
				}
				continue;
			}
			if ( ! isset( $available[ $slug ] ) ) {
				continue;
			}
			$info                 = $available[ $slug ];
			$target               = new ScanTarget( 'plugin:' . $slug, 'plugin', $info['name'], $info['dir'], 'plugin' );
			$target->url          = plugins_url( $slug . '/' );
			$target->meta['slug'] = $slug;
			$target->meta['file'] = $info['file'];
			$targets[]            = $target;
		}
		return $targets;
	}

	/**
	 * Root directory of a plugin scope.
	 *
	 * @param string $scope Scope (plugin:<slug>).
	 * @return string|null
	 */
	public static function resolve_root( string $scope ): ?string {
		if ( 0 !== strpos( $scope, 'plugin:' ) ) {
			return null;
		}
		$slug = substr( $scope, 7 );
		if ( self::MU_PLUGINS === $slug ) {
			return self::mu_plugins_available() ? PathGuard::normalize( WPMU_PLUGIN_DIR ) : null;
		}
		if ( '' === $slug || false !== strpos( $slug, '/' ) || false !== strpos( $slug, '..' ) ) {
			return null;
		}
		$dir = WP_PLUGIN_DIR . '/' . $slug;
		return is_dir( $dir ) ? PathGuard::normalize( $dir ) : null;
	}
}
