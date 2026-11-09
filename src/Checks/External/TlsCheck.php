<?php
/**
 * TLS certificate and protocol.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\External;

use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Remote\SiteProbe;
use KeepItPeace\Remote\TlsInspector;
use KeepItPeace\Scan\ScanContext;

defined( 'ABSPATH' ) || exit;

/**
 * Certificate expiry, trust, host name and negotiated protocol of each HTTPS host.
 */
class TlsCheck extends AbstractExternalCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'external.tls';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'SSL/TLS certificate', 'keep-it-peace' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks that the site uses HTTPS, how many days the certificate is valid, whether it is trusted and matches the host name, and the TLS version.', 'keep-it-peace' );
	}

	/**
	 * Items: one per HTTPS host, plus a check that the site address uses HTTPS.
	 *
	 * @param ScanContext $context Context.
	 * @return array[]
	 */
	protected function items( ScanContext $context ): array {
		$items = array();
		$hosts = array();
		foreach ( ExternalTargets::urls( $context->settings, $this->probe()->policy() ) as $target ) {
			$parts = wp_parse_url( $target['url'] );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				continue;
			}
			$host   = strtolower( (string) $parts['host'] );
			$scheme = strtolower( (string) ( $parts['scheme'] ?? 'http' ) );
			if ( 'home' === $target['kind'] && 'https' !== $scheme && ! ExternalTargets::is_local_host( $host ) ) {
				$items[] = array(
					'type' => 'no_https',
					'url'  => $target['url'],
				);
			}
			if ( 'https' !== $scheme ) {
				continue;
			}
			$port = isset( $parts['port'] ) ? (int) $parts['port'] : 443;
			$key  = $host . ':' . $port;
			if ( isset( $hosts[ $key ] ) ) {
				continue;
			}
			$hosts[ $key ] = true;
			$items[]       = array(
				'type'    => 'host',
				'host'    => $host,
				'port'    => $port,
				'primary' => 'home' === $target['kind'],
			);
		}
		return $items;
	}

	/**
	 * Processes one item.
	 *
	 * @param array       $item    Item.
	 * @param ScanContext $context Context.
	 * @param array       $data    Data.
	 * @return Finding[]
	 */
	protected function process( array $item, ScanContext $context, array &$data ): array {
		if ( 'no_https' === $item['type'] ) {
			$data['https'] = false;
			return array(
				$this->url_finding(
					(string) $item['url'],
					array(
						'rule_id'        => 'tls.no_https',
						'severity'       => Severity::MEDIUM,
						'title'          => __( 'The site address does not use HTTPS', 'keep-it-peace' ),
						'message'        => __( 'Logins, cookies and form data are sent unencrypted.', 'keep-it-peace' ),
						'recommendation' => __( 'Enable a free certificate (e.g. Let\'s Encrypt) in your hosting panel and change the WordPress and site addresses to https://.', 'keep-it-peace' ),
					)
				),
			);
		}

		$target = $item['host'] . ( 443 === (int) $item['port'] ? '' : ':' . $item['port'] );
		if ( ! TlsInspector::is_supported() ) {
			return array(
				$this->tls_finding(
					$target,
					'tls.unsupported',
					Severity::INFO,
					__( 'The certificate could not be checked on this server', 'keep-it-peace' ),
					__( 'stream_socket_client() or the OpenSSL extension is not available to PHP.', 'keep-it-peace' ),
					__( 'Check the certificate expiry date in your hosting panel instead.', 'keep-it-peace' )
				),
			);
		}

		$info = TlsInspector::inspect( (string) $item['host'], (int) $item['port'], SiteProbe::timeout() );
		if ( '' !== $info['error'] ) {
			$context->keep( $this->get_id() );
			return array(
				$this->tls_finding(
					$target,
					'tls.connection_failed',
					Severity::INFO,
					__( 'Could not connect over HTTPS', 'keep-it-peace' ),
					/* translators: %s: error message. */
					sprintf( __( 'The TLS connection failed: %s', 'keep-it-peace' ), $info['error'] ),
					__( 'If visitors can open the site over HTTPS, the server may block connections to itself. Otherwise check the certificate in your hosting panel.', 'keep-it-peace' )
				),
			);
		}

		$days = (int) floor( ( $info['valid_to'] - time() ) / DAY_IN_SECONDS );
		if ( ! empty( $item['primary'] ) || ! isset( $data['days_remaining'] ) || $days < (int) $data['days_remaining'] ) {
			$data = array_merge(
				$data,
				array(
					'host'           => $item['host'],
					'days_remaining' => $days,
					'valid_to'       => gmdate( 'Y-m-d', $info['valid_to'] ),
					'protocol'       => $info['protocol'],
					'issuer'         => $info['issuer'],
					'trusted'        => $info['trusted'],
				)
			);
		}
		return self::evaluate( $info, (string) $item['host'], $days, (int) $context->settings->get( 'cert_warning_days', 21 ), $target, $this );
	}

	/**
	 * Findings for an inspected certificate.
	 *
	 * @param array    $info         TlsInspector::inspect() result.
	 * @param string   $host         Host.
	 * @param int      $days         Days until expiry.
	 * @param int      $warning_days Warning threshold.
	 * @param string   $target       Target label.
	 * @param TlsCheck $check        Check.
	 * @return Finding[]
	 */
	public static function evaluate( array $info, string $host, int $days, int $warning_days, string $target, TlsCheck $check ): array {
		$out = array();
		/* translators: %s: date. */
		$expiry_text = sprintf( __( 'The certificate expires on %s.', 'keep-it-peace' ), gmdate( 'Y-m-d', (int) $info['valid_to'] ) );
		$renew       = __( 'Renew the certificate or check that automatic renewal works in your hosting panel.', 'keep-it-peace' );
		if ( $days < 0 ) {
			$out[] = $check->tls_finding( $target, 'tls.expired', Severity::CRITICAL, __( 'The SSL certificate has expired', 'keep-it-peace' ), $expiry_text . ' ' . __( 'Browsers show a security warning to visitors.', 'keep-it-peace' ), $renew );
		} elseif ( $days <= 7 ) {
			/* translators: %d: days. */
			$out[] = $check->tls_finding( $target, 'tls.expiring', Severity::HIGH, sprintf( _n( 'The SSL certificate expires in %d day', 'The SSL certificate expires in %d days', $days, 'keep-it-peace' ), $days ), $expiry_text, $renew );
		} elseif ( $days <= $warning_days ) {
			/* translators: %d: days. */
			$out[] = $check->tls_finding( $target, 'tls.expiring', Severity::MEDIUM, sprintf( _n( 'The SSL certificate expires in %d day', 'The SSL certificate expires in %d days', $days, 'keep-it-peace' ), $days ), $expiry_text, $renew );
		}

		if ( ! self::host_ok( $host, (array) $info['names'] ) ) {
			/* translators: %s: names in the certificate. */
			$message = sprintf( __( 'The certificate is issued for: %s', 'keep-it-peace' ), implode( ', ', array_slice( (array) $info['names'], 0, 10 ) ) );
			$out[]   = $check->tls_finding( $target, 'tls.name_mismatch', Severity::HIGH, __( 'The certificate does not match the host name', 'keep-it-peace' ), $message, __( 'Issue a certificate that includes this host name (with and without www if both are used).', 'keep-it-peace' ) );
		} elseif ( ! $info['trusted'] && $days >= 0 ) {
			$out[] = $check->tls_finding(
				$target,
				'tls.untrusted',
				Severity::HIGH,
				$info['self_signed'] ? __( 'The certificate is self-signed', 'keep-it-peace' ) : __( 'The certificate is not trusted', 'keep-it-peace' ),
				/* translators: %s: error message. */
				sprintf( __( 'Certificate verification failed: %s', 'keep-it-peace' ), (string) $info['trust_error'] ),
				__( 'Use a certificate from a public certificate authority and make sure the intermediate certificates are installed.', 'keep-it-peace' )
			);
		}

		if ( in_array( $info['protocol'], array( 'TLSv1', 'TLSv1.0', 'TLSv1.1', 'SSLv3' ), true ) ) {
			/* translators: %s: protocol such as TLSv1.1. */
			$message = sprintf( __( 'The connection used %s, which is no longer considered secure.', 'keep-it-peace' ), $info['protocol'] );
			$out[]   = $check->tls_finding( $target, 'tls.old_protocol', Severity::MEDIUM, __( 'An outdated TLS version is used', 'keep-it-peace' ), $message, __( 'Enable TLS 1.2 and 1.3 and disable older versions on the server.', 'keep-it-peace' ) );
		}
		return $out;
	}

	/**
	 * Whether the certificate names cover the host.
	 *
	 * @param string   $host  Host.
	 * @param string[] $names Names.
	 * @return bool
	 */
	private static function host_ok( string $host, array $names ): bool {
		return TlsInspector::host_matches( $host, $names );
	}

	/**
	 * Dashboard data.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	protected function summarize( array $data ): array {
		return $data;
	}

	/**
	 * Builds a finding about a host.
	 *
	 * @param string $target         Host[:port].
	 * @param string $rule_id        Rule.
	 * @param int    $severity       Severity.
	 * @param string $title          Title.
	 * @param string $message        Message.
	 * @param string $recommendation Recommendation.
	 * @return Finding
	 */
	public function tls_finding( string $target, string $rule_id, int $severity, string $title, string $message, string $recommendation ): Finding {
		return $this->finding(
			array(
				'rule_id'        => $rule_id,
				'severity'       => $severity,
				'target_type'    => 'url',
				'target_scope'   => 'external',
				'target'         => $target,
				'title'          => $title,
				'message'        => $message,
				'recommendation' => $recommendation,
			)
		);
	}
}
