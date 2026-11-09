<?php
/**
 * External check logic (no network).
 *
 * @package KeepItPeace
 */

use KeepItPeace\Checks\External\DirectoryListingCheck;
use KeepItPeace\Checks\External\ExposedFilesCheck;
use KeepItPeace\Checks\External\ExternalTargets;
use KeepItPeace\Checks\External\SecurityHeadersCheck;
use KeepItPeace\Checks\External\TlsCheck;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Remote\SiteProbe;
use KeepItPeace\Remote\TlsInspector;
use PHPUnit\Framework\TestCase;

/**
 * Header analysis, exposed file validation, listing detection, certificate evaluation.
 */
class ExternalChecksTest extends TestCase {

	/**
	 * Rule IDs from analyze().
	 *
	 * @param array $issues Issues.
	 * @return string[]
	 */
	private static function rules( array $issues ): array {
		return array_column( $issues, 'rule' );
	}

	public function test_missing_headers_are_reported(): void {
		$rules = self::rules( SecurityHeadersCheck::analyze( array(), true, '' ) );
		$this->assertSame( array( 'headers.hsts_missing', 'headers.nosniff_missing', 'headers.clickjacking', 'headers.referrer_policy', 'headers.csp_missing' ), $rules );
		$this->assertNotContains( 'headers.hsts_missing', self::rules( SecurityHeadersCheck::analyze( array(), false, '' ) ) );
	}

	public function test_good_headers_pass(): void {
		$headers = array(
			'strict-transport-security' => 'max-age=63072000; includeSubDomains',
			'x-content-type-options'    => 'nosniff',
			'content-security-policy'   => "default-src 'self'; frame-ancestors 'self'",
			'referrer-policy'           => 'strict-origin-when-cross-origin',
			'server'                    => 'nginx',
		);
		$this->assertSame( array(), SecurityHeadersCheck::analyze( $headers, true, '<html></html>' ) );
	}

	public function test_version_exposure(): void {
		$headers = array(
			'server'       => 'Apache/2.4.41 (Ubuntu)',
			'x-powered-by' => 'PHP/7.4.3',
		);
		$body    = '<meta name="generator" content="WordPress 6.4.2" />';
		$issues  = SecurityHeadersCheck::analyze( $headers, false, $body );
		$rules   = self::rules( $issues );
		$this->assertContains( 'headers.server_version', $rules );
		$this->assertContains( 'headers.powered_by', $rules );
		$this->assertContains( 'headers.wp_generator', $rules );
		$this->assertSame( Severity::LOW, $issues[ array_search( 'headers.powered_by', $rules, true ) ]['severity'] );
		$this->assertSame( '6.4.2', $issues[ array_search( 'headers.wp_generator', $rules, true ) ]['value'] );
	}

	public function test_short_hsts(): void {
		$rules = self::rules( SecurityHeadersCheck::analyze( array( 'strict-transport-security' => 'max-age=300' ), true, '' ) );
		$this->assertContains( 'headers.hsts_short', $rules );
	}

	/**
	 * Definition by path.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	private static function definition( string $path ): array {
		foreach ( ExposedFilesCheck::definitions() as $definition ) {
			if ( $definition['path'] === $path ) {
				return $definition;
			}
		}
		throw new RuntimeException( $path );
	}

	public function test_exposed_file_validation(): void {
		$this->assertTrue( ExposedFilesCheck::matches( self::definition( '.env' ), "APP_KEY=base64:xyz\nDB_PASSWORD=secret\n" ) );
		$this->assertFalse( ExposedFilesCheck::matches( self::definition( '.env' ), "<!DOCTYPE html>\n<html><body>APP_KEY=1</body></html>" ) );
		$this->assertTrue( ExposedFilesCheck::matches( self::definition( '.git/HEAD' ), "ref: refs/heads/main\n" ) );
		$this->assertFalse( ExposedFilesCheck::matches( self::definition( '.git/HEAD' ), 'Not found' ) );
		$this->assertTrue( ExposedFilesCheck::matches( self::definition( 'wp-config.php.bak' ), "<?php\ndefine( 'DB_PASSWORD', 'x' );" ) );
		$this->assertTrue( ExposedFilesCheck::matches( self::definition( 'backup.zip' ), "PK\x03\x04rest" ) );
		$this->assertFalse( ExposedFilesCheck::matches( self::definition( 'backup.zip' ), '<html>' ) );
		$this->assertTrue( ExposedFilesCheck::matches( self::definition( 'debug.log' ), "[06-Nov-2026 09:00:00 UTC] PHP Warning:  Undefined variable\n" ) );
		$this->assertTrue( ExposedFilesCheck::matches( self::definition( 'phpinfo.php' ), '<!DOCTYPE html><html><head><title>PHP 8.2.1 - phpinfo()</title>' ) );
		$this->assertFalse( ExposedFilesCheck::matches( self::definition( 'phpinfo.php' ), '<!DOCTYPE html><html><head><title>Page not found</title>' ) );
		$this->assertTrue( ExposedFilesCheck::matches( self::definition( 'dump.sql' ), "-- MySQL dump 10.13\nCREATE TABLE `wp_users`" ) );
		$this->assertFalse( ExposedFilesCheck::matches( self::definition( '.env' ), '' ) );
	}

	public function test_every_definition_has_valid_matcher(): void {
		foreach ( ExposedFilesCheck::definitions() as $definition ) {
			$this->assertContains( $definition['match']['type'], array( 'regex', 'contains', 'prefix', 'magic' ), $definition['path'] );
			if ( 'regex' === $definition['match']['type'] ) {
				$this->assertNotFalse( @preg_match( $definition['match']['value'], '' ), $definition['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$this->assertNotSame( Severity::INFO, Severity::from( $definition['severity'] ), $definition['path'] );
		}
	}

	public function test_directory_listing_detection(): void {
		$this->assertTrue( DirectoryListingCheck::is_listing( '<html><head><title>Index of /wp-content/uploads</title></head>' ) );
		$this->assertTrue( DirectoryListingCheck::is_listing( '<html><body><h1>Index of /wp-content/uploads/</h1><hr><pre>' ) );
		$this->assertFalse( DirectoryListingCheck::is_listing( '' ) );
		$this->assertFalse( DirectoryListingCheck::is_listing( '<title>Uploads index of my photos</title>' ) );
	}

	public function test_certificate_names(): void {
		$info = array(
			'subject'    => array( 'CN' => 'example.com' ),
			'extensions' => array( 'subjectAltName' => 'DNS:example.com, DNS:*.example.com, IP Address:1.2.3.4' ),
		);
		$this->assertSame( array( 'example.com', '*.example.com' ), TlsInspector::names( $info ) );
		$names = TlsInspector::names( $info );
		$this->assertTrue( TlsInspector::host_matches( 'www.example.com', $names ) );
		$this->assertTrue( TlsInspector::host_matches( 'EXAMPLE.com', $names ) );
		$this->assertFalse( TlsInspector::host_matches( 'a.b.example.com', $names ) );
		$this->assertFalse( TlsInspector::host_matches( 'example.org', $names ) );
		$this->assertSame( array( 'only.example.net' ), TlsInspector::names( array( 'subject' => array( 'CN' => 'only.example.net' ) ) ) );
	}

	/**
	 * Certificate info.
	 *
	 * @param int   $days      Days until expiry.
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private static function cert( int $days, array $overrides = array() ): array {
		return array_merge(
			array(
				'valid_to'    => time() + $days * 86400 + 3600,
				'names'       => array( 'example.com' ),
				'trusted'     => true,
				'trust_error' => '',
				'self_signed' => false,
				'protocol'    => 'TLSv1.3',
			),
			$overrides
		);
	}

	/**
	 * Rule => severity.
	 *
	 * @param array $info Certificate info.
	 * @param int   $days Days.
	 * @return array<string,int>
	 */
	private static function evaluate( array $info, int $days ): array {
		$out = array();
		foreach ( TlsCheck::evaluate( $info, 'example.com', $days, 21, 'example.com', new TlsCheck() ) as $finding ) {
			$out[ $finding->rule_id ] = $finding->severity;
		}
		return $out;
	}

	public function test_certificate_evaluation(): void {
		$this->assertSame( array(), self::evaluate( self::cert( 90 ), 90 ) );
		$this->assertSame( array( 'tls.expiring' => Severity::MEDIUM ), self::evaluate( self::cert( 20 ), 20 ) );
		$this->assertSame( array( 'tls.expiring' => Severity::HIGH ), self::evaluate( self::cert( 3 ), 3 ) );
		$this->assertSame( array( 'tls.expired' => Severity::CRITICAL ), self::evaluate( self::cert( -2, array( 'trusted' => false ) ), -2 ) );
		$this->assertSame( array( 'tls.name_mismatch' => Severity::HIGH ), self::evaluate( self::cert( 90, array( 'names' => array( 'other.com' ) ) ), 90 ) );
		$this->assertSame( array( 'tls.untrusted' => Severity::HIGH ), self::evaluate( self::cert( 90, array( 'trusted' => false ) ), 90 ) );
		$this->assertSame( array( 'tls.old_protocol' => Severity::MEDIUM ), self::evaluate( self::cert( 90, array( 'protocol' => 'TLSv1.1' ) ), 90 ) );
	}

	public function test_expiring_fingerprint_is_stable_across_days(): void {
		$a = TlsCheck::evaluate( self::cert( 20 ), 'example.com', 20, 21, 'example.com', new TlsCheck() );
		$b = TlsCheck::evaluate( self::cert( 5 ), 'example.com', 5, 21, 'example.com', new TlsCheck() );
		$this->assertSame( $a[0]->fingerprint, $b[0]->fingerprint );
	}

	public function test_redirect_resolution(): void {
		$this->assertSame( 'https://example.com/a', SiteProbe::absolute( 'https://example.com/a', 'http://example.com/' ) );
		$this->assertSame( 'http://example.com:8080/login', SiteProbe::absolute( '/login', 'http://example.com:8080/x/y' ) );
		$this->assertSame( 'https://cdn.example.com/x', SiteProbe::absolute( '//cdn.example.com/x', 'https://example.com/' ) );
		$this->assertSame( 'https://example.com/dir/next', SiteProbe::absolute( 'next', 'https://example.com/dir/page' ) );
	}

	public function test_local_hosts(): void {
		$this->assertTrue( ExternalTargets::is_local_host( 'localhost' ) );
		$this->assertTrue( ExternalTargets::is_local_host( 'mysite.test' ) );
		$this->assertTrue( ExternalTargets::is_local_host( '192.168.1.10' ) );
		$this->assertFalse( ExternalTargets::is_local_host( 'example.com' ) );
		$this->assertFalse( ExternalTargets::is_local_host( '203.0.113.10' ) );
	}
}
