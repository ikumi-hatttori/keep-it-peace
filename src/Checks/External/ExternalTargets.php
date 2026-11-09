<?php
/**
 * URLs probed by the external checks.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\External;

use KeepItPeace\Remote\HostPolicy;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The site address, the WordPress address, extra URLs and URLs of registered directories.
 */
final class ExternalTargets {

	/**
	 * URLs to probe.
	 *
	 * @param Settings   $settings Settings.
	 * @param HostPolicy $policy   Host policy.
	 * @return array<int,array{url:string,kind:string,label:string}> kind: home|site|extra|path.
	 */
	public static function urls( Settings $settings, HostPolicy $policy ): array {
		$items = array(
			array(
				'url'   => trailingslashit( home_url() ),
				'kind'  => 'home',
				'label' => __( 'Site address', 'keep-it-peace' ),
			),
			array(
				'url'   => trailingslashit( site_url() ),
				'kind'  => 'site',
				'label' => __( 'WordPress address', 'keep-it-peace' ),
			),
		);
		foreach ( (array) $settings->get( 'urls', array() ) as $url ) {
			$items[] = array(
				'url'   => (string) $url,
				'kind'  => 'extra',
				'label' => (string) $url,
			);
		}
		foreach ( (array) $settings->get( 'paths', array() ) as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['url'] ) ) {
				$items[] = array(
					'url'   => (string) $entry['url'],
					'kind'  => 'path',
					'label' => (string) ( $entry['label'] ?? $entry['url'] ),
				);
			}
		}

		/**
		 * Filters the URLs probed by the external checks. URLs outside the allowed hosts are dropped.
		 *
		 * @param array    $items    Items with url, kind and label.
		 * @param Settings $settings Settings.
		 */
		$items = (array) apply_filters( 'keepitpeace_external_urls', $items, $settings );

		$out  = array();
		$seen = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['url'] ) ) {
				continue;
			}
			$url = (string) $item['url'];
			$key = strtolower( untrailingslashit( $url ) );
			if ( isset( $seen[ $key ] ) || ! $policy->is_allowed_url( $url ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'url'   => $url,
				'kind'  => (string) ( $item['kind'] ?? 'extra' ),
				'label' => (string) ( $item['label'] ?? $url ),
			);
		}
		return $out;
	}

	/**
	 * Whether a host is a local development name (TLS is not expected there).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public static function is_local_host( string $host ): bool {
		$host = HostPolicy::normalize_host( $host );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		if ( preg_match( '/\.(localhost|local|test|example|invalid)$/', $host ) ) {
			return true;
		}
		return (bool) filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}
