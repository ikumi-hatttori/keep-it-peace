<?php
/**
 * HTTP security headers and version exposure.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\External;

use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\ScanContext;

defined( 'ABSPATH' ) || exit;

/**
 * Checks response headers of the site address and the other registered URLs.
 */
class SecurityHeadersCheck extends AbstractExternalCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'external.headers';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Security headers', 'keep-it-peace' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Checks HSTS, X-Content-Type-Options, clickjacking protection, Referrer-Policy and Content-Security-Policy, the redirect from HTTP to HTTPS, and whether server, PHP or WordPress versions are revealed.', 'keep-it-peace' );
	}

	/**
	 * Items.
	 *
	 * @param ScanContext $context Context.
	 * @return array[]
	 */
	protected function items( ScanContext $context ): array {
		$items = array();
		foreach ( ExternalTargets::urls( $context->settings, $this->probe()->policy() ) as $target ) {
			$items[] = array(
				'type' => 'page',
				'url'  => $target['url'],
				'kind' => $target['kind'],
			);
			if ( 'home' === $target['kind'] && 0 === stripos( $target['url'], 'https://' ) ) {
				$items[] = array(
					'type' => 'http_redirect',
					'url'  => 'http://' . substr( $target['url'], 8 ),
				);
			}
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
		if ( 'http_redirect' === $item['type'] ) {
			$response = $this->probe()->get( (string) $item['url'] );
			if ( '' !== $response['error'] ) {
				// Port 80 closed is fine: nothing can be sent unencrypted.
				return array();
			}
			$location = (string) ( $response['headers']['location'] ?? '' );
			if ( $response['code'] >= 300 && $response['code'] < 400 && 0 === stripos( $location, 'https://' ) ) {
				return array();
			}
			return array(
				$this->url_finding(
					(string) $item['url'],
					array(
						'rule_id'        => 'headers.no_https_redirect',
						'severity'       => Severity::LOW,
						'title'          => __( 'HTTP is not redirected to HTTPS', 'keep-it-peace' ),
						'message'        => __( 'The site can be opened over unencrypted http:// without being redirected to https://.', 'keep-it-peace' ),
						'recommendation' => __( 'Redirect all http:// requests to https:// with a 301 redirect (hosting panel setting or .htaccess).', 'keep-it-peace' ),
					)
				),
			);
		}

		$url      = (string) $item['url'];
		$response = $this->probe()->get( $url, array( 'follow' => 3 ) );
		if ( '' !== $response['error'] ) {
			return array( $this->unreachable( $url, $response['error'], $context ) );
		}
		$https = 0 === stripos( $response['final_url'], 'https://' );
		$out   = array();
		foreach ( self::analyze( $response['headers'], $https, $response['body'] ) as $issue ) {
			$out[] = $this->url_finding(
				$url,
				array(
					'rule_id'           => $issue['rule'],
					'severity'          => $issue['severity'],
					'title'             => $issue['title'],
					'message'           => $issue['message'],
					'recommendation'    => $issue['recommendation'],
					'meta'              => array( 'value' => $issue['value'] ),
					'fingerprint_basis' => '',
				)
			);
		}
		if ( 'home' === $item['kind'] ) {
			$data['home_status'] = $response['code'];
		}
		return $out;
	}

	/**
	 * Analyzes response headers and the start of the HTML.
	 *
	 * @param array<string,string> $headers Lowercase headers.
	 * @param bool                 $https   Served over HTTPS.
	 * @param string               $body    Body (may be truncated).
	 * @return array<int,array{rule:string,severity:int,title:string,message:string,recommendation:string,value:string}>
	 */
	public static function analyze( array $headers, bool $https, string $body ): array {
		$issues = array();
		$add    = static function ( string $rule, int $severity, string $title, string $message, string $recommendation, string $value = '' ) use ( &$issues ) {
			$issues[] = array(
				'rule'           => $rule,
				'severity'       => $severity,
				'title'          => $title,
				'message'        => $message,
				'recommendation' => $recommendation,
				'value'          => $value,
			);
		};
		$csp    = strtolower( (string) ( $headers['content-security-policy'] ?? '' ) );

		if ( $https ) {
			$hsts = (string) ( $headers['strict-transport-security'] ?? '' );
			if ( '' === $hsts ) {
				$add( 'headers.hsts_missing', Severity::LOW, __( 'Strict-Transport-Security (HSTS) is not set', 'keep-it-peace' ), __( 'Without HSTS, browsers may still try http:// first, which allows downgrade attacks on public Wi-Fi.', 'keep-it-peace' ), __( 'Send "Strict-Transport-Security: max-age=31536000" once HTTPS works on all pages and subdomains you use.', 'keep-it-peace' ) );
			} elseif ( preg_match( '/max-age\s*=\s*"?(\d+)/i', $hsts, $m ) && (int) $m[1] < 15552000 ) {
				$add( 'headers.hsts_short', Severity::INFO, __( 'HSTS max-age is short', 'keep-it-peace' ), __( 'The HSTS max-age is less than 180 days.', 'keep-it-peace' ), __( 'Use max-age=31536000 (one year) once the setting is confirmed to work.', 'keep-it-peace' ), $hsts );
			}
		}
		if ( 'nosniff' !== strtolower( trim( (string) ( $headers['x-content-type-options'] ?? '' ) ) ) ) {
			$add( 'headers.nosniff_missing', Severity::LOW, __( 'X-Content-Type-Options is not set', 'keep-it-peace' ), __( 'Browsers may guess the type of uploaded files and run them as scripts.', 'keep-it-peace' ), __( 'Send "X-Content-Type-Options: nosniff".', 'keep-it-peace' ) );
		}
		if ( '' === (string) ( $headers['x-frame-options'] ?? '' ) && false === strpos( $csp, 'frame-ancestors' ) ) {
			$add( 'headers.clickjacking', Severity::LOW, __( 'Clickjacking protection is not set', 'keep-it-peace' ), __( 'Other sites can show your pages in a frame and trick visitors into clicking buttons.', 'keep-it-peace' ), __( 'Send "X-Frame-Options: SAMEORIGIN" or a Content-Security-Policy with frame-ancestors \'self\'.', 'keep-it-peace' ) );
		}
		if ( '' === (string) ( $headers['referrer-policy'] ?? '' ) ) {
			$add( 'headers.referrer_policy', Severity::INFO, __( 'Referrer-Policy is not set', 'keep-it-peace' ), __( 'Full URLs, including query strings, may be sent to other sites when visitors follow links.', 'keep-it-peace' ), __( 'Send "Referrer-Policy: strict-origin-when-cross-origin".', 'keep-it-peace' ) );
		}
		if ( '' === $csp && '' === (string) ( $headers['content-security-policy-report-only'] ?? '' ) ) {
			$add( 'headers.csp_missing', Severity::INFO, __( 'Content-Security-Policy is not set', 'keep-it-peace' ), __( 'A Content-Security-Policy limits where scripts can be loaded from and reduces the impact of XSS.', 'keep-it-peace' ), __( 'Start with a Content-Security-Policy-Report-Only header, check the reports, then enforce it.', 'keep-it-peace' ) );
		}

		$server = (string) ( $headers['server'] ?? '' );
		if ( '' !== $server && preg_match( '#/\s*\d#', $server ) ) {
			/* translators: %s: header value. */
			$message = sprintf( __( 'The Server header is "%s". Attackers use versions to pick known exploits.', 'keep-it-peace' ), $server );
			$add( 'headers.server_version', Severity::LOW, __( 'The web server version is revealed', 'keep-it-peace' ), $message, __( 'Hide the version (e.g. ServerTokens Prod for Apache, server_tokens off for nginx) or ask your host.', 'keep-it-peace' ), $server );
		}
		$powered = (string) ( $headers['x-powered-by'] ?? '' );
		if ( '' !== $powered ) {
			/* translators: %s: header value. */
			$message = sprintf( __( 'The X-Powered-By header is "%s".', 'keep-it-peace' ), $powered );
			$add( 'headers.powered_by', Severity::LOW, __( 'X-Powered-By reveals the PHP version', 'keep-it-peace' ), $message, __( 'Set expose_php = Off in php.ini (or .user.ini), or remove the header in the server configuration.', 'keep-it-peace' ), $powered );
		}
		if ( preg_match( '#<meta[^>]+name=["\']generator["\'][^>]+content=["\']WordPress\s*([\d.]+)#i', $body, $m ) ) {
			/* translators: %s: WordPress version. */
			$message = sprintf( __( 'The generator meta tag reveals WordPress %s.', 'keep-it-peace' ), $m[1] );
			$add( 'headers.wp_generator', Severity::INFO, __( 'The WordPress version is shown in the page source', 'keep-it-peace' ), $message, __( 'Keep WordPress up to date. You can remove the tag with remove_action( \'wp_head\', \'wp_generator\' ).', 'keep-it-peace' ), $m[1] );
		}
		return $issues;
	}
}
