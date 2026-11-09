<?php
/**
 * Host policy tests.
 *
 * @package KeepItPeace
 */

use KeepItPeace\Remote\HostPolicy;
use KeepItPeace\Scan\PathValidator;
use PHPUnit\Framework\TestCase;

/**
 * Domain restriction of external checks and directory overlap rules.
 */
class HostPolicyTest extends TestCase {

	public function test_registrable_domain(): void {
		$this->assertSame( 'example.com', HostPolicy::registrable_domain( 'www.example.com' ) );
		$this->assertSame( 'example.com', HostPolicy::registrable_domain( 'a.b.example.com.' ) );
		$this->assertSame( 'example.co.jp', HostPolicy::registrable_domain( 'shop.example.co.jp' ) );
		$this->assertSame( 'example.co.jp', HostPolicy::registrable_domain( 'EXAMPLE.CO.JP' ) );
		$this->assertSame( 'foo.xsrv.jp', HostPolicy::registrable_domain( 'www.foo.xsrv.jp' ) );
		$this->assertSame( 'foo.sakura.ne.jp', HostPolicy::registrable_domain( 'foo.sakura.ne.jp' ) );
		$this->assertSame( 'localhost', HostPolicy::registrable_domain( 'localhost:8080' ) );
		$this->assertSame( '192.168.0.1', HostPolicy::registrable_domain( '192.168.0.1' ) );
	}

	public function test_same_registrable_domain_is_allowed(): void {
		$policy = new HostPolicy( array( 'www.example.co.jp' ) );
		$this->assertTrue( $policy->is_allowed_host( 'example.co.jp' ) );
		$this->assertTrue( $policy->is_allowed_host( 'shop.example.co.jp' ) );
		$this->assertFalse( $policy->is_allowed_host( 'other.co.jp' ) );
		$this->assertFalse( $policy->is_allowed_host( 'example.co.jp.evil.com' ) );
		$this->assertFalse( $policy->is_allowed_host( 'evilexample.co.jp' ) );
	}

	public function test_shared_hosting_neighbours_are_not_allowed(): void {
		$policy = new HostPolicy( array( 'mysite.xsrv.jp' ) );
		$this->assertTrue( $policy->is_allowed_host( 'mysite.xsrv.jp' ) );
		$this->assertFalse( $policy->is_allowed_host( 'neighbour.xsrv.jp' ) );
		$this->assertFalse( $policy->is_allowed_host( 'xsrv.jp' ) );
	}

	public function test_ip_and_localhost_need_exact_match(): void {
		$policy = new HostPolicy( array( 'localhost', '203.0.113.5' ) );
		$this->assertTrue( $policy->is_allowed_host( 'localhost' ) );
		$this->assertTrue( $policy->is_allowed_host( '203.0.113.5' ) );
		$this->assertFalse( $policy->is_allowed_host( '203.0.113.6' ) );
		$this->assertFalse( $policy->is_allowed_host( 'example.com' ) );
	}

	public function test_extra_hosts_and_wildcards(): void {
		$policy = new HostPolicy( array( 'example.com' ), array( 'cdn.partner.net', '*.client.org' ) );
		$this->assertTrue( $policy->is_allowed_host( 'cdn.partner.net' ) );
		$this->assertFalse( $policy->is_allowed_host( 'www.partner.net' ) );
		$this->assertTrue( $policy->is_allowed_host( 'a.client.org' ) );
		$this->assertFalse( $policy->is_allowed_host( 'client.org' ) );
		$this->assertFalse( $policy->is_allowed_host( 'evilclient.org' ) );
	}

	public function test_urls(): void {
		$policy = new HostPolicy( array( 'example.com' ) );
		$this->assertTrue( $policy->is_allowed_url( 'https://www.example.com/path?x=1' ) );
		$this->assertTrue( $policy->is_allowed_url( 'http://example.com:8080/' ) );
		$this->assertFalse( $policy->is_allowed_url( 'ftp://example.com/' ) );
		$this->assertFalse( $policy->is_allowed_url( 'https://user:pw@example.com/' ) );
		$this->assertFalse( $policy->is_allowed_url( 'https://example.org/' ) );
		$this->assertFalse( $policy->is_allowed_url( 'not a url' ) );
	}

	public function test_path_overlap(): void {
		$dirs = array(
			'root'      => '/var/www/html',
			'protected' => array( '/var/www/html/wp-admin', '/var/www/html/wp-includes', '/var/www/html/wp-content' ),
		);
		$this->assertSame( PathValidator::CONTAINS_WORDPRESS, PathValidator::overlap( '/var/www/html', $dirs ) );
		$this->assertSame( PathValidator::CONTAINS_WORDPRESS, PathValidator::overlap( '/var/www', $dirs ) );
		$this->assertSame( PathValidator::CONTAINS_WORDPRESS, PathValidator::overlap( '/', $dirs ) );
		$this->assertSame( PathValidator::OVERLAPS_WORDPRESS, PathValidator::overlap( '/var/www/html/wp-content/uploads', $dirs ) );
		$this->assertSame( PathValidator::OVERLAPS_WORDPRESS, PathValidator::overlap( '/var/www/html/wp-admin', $dirs ) );
		$this->assertSame( '', PathValidator::overlap( '/var/www/html/lp', $dirs ) );
		$this->assertSame( '', PathValidator::overlap( '/var/www/html/wp-content-old', $dirs ) );
		$this->assertSame( '', PathValidator::overlap( '/home/user/app', $dirs ) );
	}
}
