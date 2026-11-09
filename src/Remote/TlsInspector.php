<?php
/**
 * Reads the TLS certificate and protocol of a host.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Remote;

defined( 'ABSPATH' ) || exit;

/**
 * Connects with stream_socket_client() (no shell, no cURL CLI) and inspects the certificate.
 */
class TlsInspector {

	/**
	 * Whether the required functions are available.
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		return function_exists( 'stream_socket_client' ) && function_exists( 'stream_context_create' ) && function_exists( 'stream_context_get_params' ) && function_exists( 'openssl_x509_parse' );
	}

	/**
	 * Inspects a host.
	 *
	 * @param string $host    Host.
	 * @param int    $port    Port.
	 * @param int    $timeout Timeout in seconds.
	 * @return array{error:string,protocol:string,valid_from:int,valid_to:int,names:string[],issuer:string,subject:string,self_signed:bool,trusted:bool,trust_error:string}
	 */
	public static function inspect( string $host, int $port, int $timeout ): array {
		$result = array(
			'error'       => '',
			'protocol'    => '',
			'valid_from'  => 0,
			'valid_to'    => 0,
			'names'       => array(),
			'issuer'      => '',
			'subject'     => '',
			'self_signed' => false,
			'trusted'     => false,
			'trust_error' => '',
		);

		// Pass 1: read the certificate whatever its validity (one retry for dropped handshakes).
		$read = array(
			'capture_peer_cert' => true,
			'verify_peer'       => false,
			'verify_peer_name'  => false,
			'allow_self_signed' => true,
			// Only reads the certificate; weak server settings are reported by the trust pass below.
			'security_level'    => 0,
		);
		$conn = self::connect( $host, $port, $timeout, $read );
		if ( '' !== $conn['error'] ) {
			$conn = self::connect( $host, $port, $timeout, $read );
		}
		if ( '' !== $conn['error'] ) {
			$result['error'] = $conn['error'];
			return $result;
		}
		$result['protocol'] = $conn['protocol'];
		$cert               = $conn['cert'];
		$info               = $cert ? openssl_x509_parse( $cert ) : false;
		if ( ! is_array( $info ) ) {
			$result['error'] = __( 'The certificate could not be read.', 'keep-it-peace' );
			return $result;
		}
		$result['valid_from']  = (int) ( $info['validFrom_time_t'] ?? 0 );
		$result['valid_to']    = (int) ( $info['validTo_time_t'] ?? 0 );
		$result['names']       = self::names( $info );
		$result['issuer']      = self::dn( $info['issuer'] ?? array() );
		$result['subject']     = self::dn( $info['subject'] ?? array() );
		$result['self_signed'] = isset( $info['issuer'], $info['subject'] ) && $info['issuer'] === $info['subject'];

		// Pass 2: verify the chain and host name against the CA bundle shipped with WordPress.
		$options = array(
			'verify_peer'      => true,
			'verify_peer_name' => true,
		);
		$bundle  = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
		if ( defined( 'WPINC' ) && is_readable( $bundle ) ) {
			$options['cafile'] = $bundle;
		}
		$verify                = self::connect( $host, $port, $timeout, $options );
		$result['trusted']     = '' === $verify['error'];
		$result['trust_error'] = $verify['error'];
		return $result;
	}

	/**
	 * Opens a TLS connection.
	 *
	 * @param string $host    Host.
	 * @param int    $port    Port.
	 * @param int    $timeout Timeout.
	 * @param array  $ssl     SSL context options.
	 * @return array{error:string,protocol:string,cert:mixed}
	 */
	private static function connect( string $host, int $port, int $timeout, array $ssl ): array {
		$ssl['SNI_enabled'] = true;
		$ssl['peer_name']   = $host;
		$context            = stream_context_create( array( 'ssl' => $ssl ) );
		$errno              = 0;
		$errstr             = '';
		$warning            = '';
		// Connection failures raise warnings; capture them instead of printing them.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Restored right after the call.
			static function ( $severity, $message ) use ( &$warning ) {
				$warning = (string) $message;
				return true;
			}
		);
		try {
			$stream = stream_socket_client( 'ssl://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context );
		} finally {
			restore_error_handler();
		}
		if ( false === $stream ) {
			$message = '' !== $errstr ? $errstr : $warning;
			return array(
				'error'    => '' !== $message ? self::clean_error( $message ) : __( 'Connection failed.', 'keep-it-peace' ),
				'protocol' => '',
				'cert'     => null,
			);
		}
		$meta   = stream_get_meta_data( $stream );
		$params = stream_context_get_params( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Network socket, not a file.
		return array(
			'error'    => '',
			'protocol' => (string) ( $meta['crypto']['protocol'] ?? '' ),
			'cert'     => $params['options']['ssl']['peer_certificate'] ?? null,
		);
	}

	/**
	 * DNS names of a parsed certificate (SAN, falling back to the CN).
	 *
	 * @param array $info openssl_x509_parse() result.
	 * @return string[]
	 */
	public static function names( array $info ): array {
		$names = array();
		$san   = (string) ( $info['extensions']['subjectAltName'] ?? '' );
		foreach ( explode( ',', $san ) as $part ) {
			$part = trim( $part );
			if ( 0 === stripos( $part, 'DNS:' ) ) {
				$names[] = strtolower( trim( substr( $part, 4 ) ) );
			}
		}
		if ( ! $names && ! empty( $info['subject']['CN'] ) ) {
			$names[] = strtolower( (string) ( is_array( $info['subject']['CN'] ) ? reset( $info['subject']['CN'] ) : $info['subject']['CN'] ) );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Whether a certificate name list covers a host (single-label wildcards).
	 *
	 * @param string   $host  Host.
	 * @param string[] $names Names.
	 * @return bool
	 */
	public static function host_matches( string $host, array $names ): bool {
		$host = strtolower( rtrim( $host, '.' ) );
		foreach ( $names as $name ) {
			$name = strtolower( rtrim( (string) $name, '.' ) );
			if ( $name === $host ) {
				return true;
			}
			if ( 0 === strpos( $name, '*.' ) ) {
				$suffix = substr( $name, 1 );
				$pos    = strpos( $host, '.' );
				if ( false !== $pos && $pos > 0 && substr( $host, $pos ) === $suffix ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Formats a distinguished name.
	 *
	 * @param array $dn DN parts.
	 * @return string
	 */
	private static function dn( array $dn ): string {
		foreach ( array( 'O', 'CN' ) as $key ) {
			if ( ! empty( $dn[ $key ] ) ) {
				return (string) ( is_array( $dn[ $key ] ) ? reset( $dn[ $key ] ) : $dn[ $key ] );
			}
		}
		return '';
	}

	/**
	 * Shortens OpenSSL error messages.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function clean_error( string $message ): string {
		$message = (string) preg_replace( '/^stream_socket_client\(\):\s*/', '', $message );
		return trim( $message );
	}
}
