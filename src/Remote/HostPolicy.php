<?php
/**
 * Which hosts the external checks may contact.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * External checks only probe the site itself: hosts sharing the registrable domain of
 * home_url()/site_url(), plus hosts allowed with the `keepitpeace_allowed_hosts` filter.
 */
class HostPolicy {

	/**
	 * Public suffixes with more than one label that are common enough to matter, and shared
	 * hosting domains where subdomains belong to different customers. Not a full public suffix list.
	 */
	const MULTI_LABEL_SUFFIXES = array(
		// Japan.
		'ac.jp',
		'ad.jp',
		'co.jp',
		'ed.jp',
		'go.jp',
		'gr.jp',
		'lg.jp',
		'ne.jp',
		'or.jp',
		// Other country code second levels.
		'co.uk',
		'org.uk',
		'me.uk',
		'ltd.uk',
		'plc.uk',
		'net.uk',
		'ac.uk',
		'gov.uk',
		'com.au',
		'net.au',
		'org.au',
		'edu.au',
		'gov.au',
		'co.nz',
		'net.nz',
		'org.nz',
		'com.br',
		'com.cn',
		'net.cn',
		'org.cn',
		'co.kr',
		'or.kr',
		'com.tw',
		'org.tw',
		'com.hk',
		'com.sg',
		'co.in',
		'co.za',
		'com.mx',
		// Shared hosting and platforms (subdomains are different owners).
		'xsrv.jp',
		'sakura.ne.jp',
		'lolipop.jp',
		'heteml.net',
		'coreserver.jp',
		'wpx.jp',
		'github.io',
		'netlify.app',
		'vercel.app',
		'pages.dev',
		'wpengine.com',
		'wpenginepowered.com',
		'kinsta.cloud',
		'azurewebsites.net',
		'cloudfront.net',
		'appspot.com',
		'web.app',
		'firebaseapp.com',
		'onrender.com',
		'wordpress.com',
		'wpcomstaging.com',
		'blogspot.com',
	);

	/**
	 * Hosts of the site itself.
	 *
	 * @var string[]
	 */
	private $site_hosts;
	/**
	 * Extra hosts (exact, or `*.example.com`).
	 *
	 * @var string[]
	 */
	private $extra_hosts;

	/**
	 * Constructor.
	 *
	 * @param string[] $site_hosts  Hosts of home_url() and site_url().
	 * @param string[] $extra_hosts Explicitly allowed hosts.
	 */
	public function __construct( array $site_hosts, array $extra_hosts = array() ) {
		$this->site_hosts  = array_values( array_filter( array_map( array( __CLASS__, 'normalize_host' ), $site_hosts ) ) );
		$this->extra_hosts = array_values( array_filter( array_map( array( __CLASS__, 'normalize_host' ), $extra_hosts ) ) );
	}

	/**
	 * Policy for the current site.
	 *
	 * @return self
	 */
	public static function for_site(): self {
		$hosts = array(
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			(string) wp_parse_url( site_url(), PHP_URL_HOST ),
		);

		/**
		 * Filters hosts that external checks may contact in addition to the site's own domain.
		 * Use exact host names or `*.example.com`.
		 *
		 * @param string[] $hosts Hosts.
		 */
		$extra = (array) apply_filters( 'keepitpeace_allowed_hosts', array() );
		return new self( $hosts, array_map( 'strval', $extra ) );
	}

	/**
	 * Lowercases and strips brackets, a trailing dot and the port.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	public static function normalize_host( $host ): string {
		$host = strtolower( trim( (string) $host ) );
		if ( '' !== $host && '[' === $host[0] ) {
			$end = strpos( $host, ']' );
			return false === $end ? '' : substr( $host, 1, $end - 1 );
		}
		if ( substr_count( $host, ':' ) === 1 ) {
			$host = substr( $host, 0, (int) strpos( $host, ':' ) );
		}
		return rtrim( $host, '.' );
	}

	/**
	 * Registrable domain (e.g. shop.example.co.jp → example.co.jp). IPs and single labels are returned as is.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	public static function registrable_domain( string $host ): string {
		$host = self::normalize_host( $host );
		if ( '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) || false === strpos( $host, '.' ) ) {
			return $host;
		}
		$labels = explode( '.', $host );

		/**
		 * Filters the multi-label public suffixes used to find a host's registrable domain.
		 *
		 * @param string[] $suffixes Suffixes such as co.jp.
		 */
		$suffixes = (array) apply_filters( 'keepitpeace_public_suffixes', self::MULTI_LABEL_SUFFIXES );
		$best     = 1;
		foreach ( $suffixes as $suffix ) {
			$suffix = strtolower( (string) $suffix );
			$count  = substr_count( $suffix, '.' ) + 1;
			if ( $count > $best && count( $labels ) > $count && substr( $host, -strlen( $suffix ) - 1 ) === '.' . $suffix ) {
				$best = $count;
			}
		}
		if ( count( $labels ) <= $best ) {
			return $host;
		}
		return implode( '.', array_slice( $labels, -( $best + 1 ) ) );
	}

	/**
	 * Whether a host may be probed.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	public function is_allowed_host( string $host ): bool {
		$host = self::normalize_host( $host );
		if ( '' === $host ) {
			return false;
		}
		foreach ( $this->site_hosts as $site_host ) {
			if ( $host === $site_host ) {
				return true;
			}
			if ( filter_var( $site_host, FILTER_VALIDATE_IP ) || filter_var( $host, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( self::registrable_domain( $host ) === self::registrable_domain( $site_host ) && false !== strpos( self::registrable_domain( $host ), '.' ) ) {
				return true;
			}
		}
		foreach ( $this->extra_hosts as $allowed ) {
			if ( $host === $allowed ) {
				return true;
			}
			if ( 0 === strpos( $allowed, '*.' ) && substr( $host, -strlen( $allowed ) + 1 ) === substr( $allowed, 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an http(s) URL may be probed.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public function is_allowed_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		return $this->is_allowed_host( (string) $parts['host'] );
	}
}
