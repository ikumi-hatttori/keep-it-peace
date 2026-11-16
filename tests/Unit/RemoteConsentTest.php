<?php
/**
 * Opt-in for third-party services.
 *
 * @package KeepItPeace
 */

use KeepItPeace\Remote\HttpClient;
use KeepItPeace\Settings\RemoteConsent;
use KeepItPeace\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Nothing may be sent to third-party APIs until an administrator agrees.
 */
class RemoteConsentTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['kip_test_options']  = array();
		$GLOBALS['kip_test_requests'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['kip_test_options']  = array();
		$GLOBALS['kip_test_requests'] = array();
	}

	public function test_defaults_are_off(): void {
		$defaults = Settings::defaults();
		$this->assertFalse( $defaults['remote']['enabled'] );
		$this->assertTrue( $defaults['remote']['services']['osv'] );
		$this->assertSame( RemoteConsent::PENDING, RemoteConsent::status() );
		$this->assertFalse( ( new Settings( array() ) )->is_remote_enabled( 'nvd' ) );
		$this->assertFalse( ( new Settings( array() ) )->uses_remote_data() );
	}

	public function test_normalize(): void {
		$this->assertSame( RemoteConsent::GRANTED, RemoteConsent::normalize( 'granted' ) );
		$this->assertSame( RemoteConsent::DECLINED, RemoteConsent::normalize( 'declined' ) );
		$this->assertSame( RemoteConsent::PENDING, RemoteConsent::normalize( false ) );
		$this->assertSame( RemoteConsent::PENDING, RemoteConsent::normalize( 'yes' ) );
		$this->assertSame( RemoteConsent::PENDING, RemoteConsent::normalize( 1 ) );
	}

	public function test_allows_requires_consent_and_both_switches(): void {
		$this->assertTrue( RemoteConsent::allows( RemoteConsent::GRANTED, true, true ) );
		$this->assertFalse( RemoteConsent::allows( RemoteConsent::GRANTED, false, true ) );
		$this->assertFalse( RemoteConsent::allows( RemoteConsent::GRANTED, true, false ) );
		$this->assertFalse( RemoteConsent::allows( RemoteConsent::PENDING, true, true ) );
		$this->assertFalse( RemoteConsent::allows( RemoteConsent::DECLINED, true, true ) );
	}

	public function test_enabled_switch_without_consent_is_not_enough(): void {
		// E.g. settings imported from another site, or saved by an older version.
		$settings = new Settings( array( 'remote' => array( 'enabled' => true ) ) );
		$this->assertFalse( $settings->is_remote_enabled( 'endoflife' ) );

		$GLOBALS['kip_test_options'][ RemoteConsent::OPTION ] = RemoteConsent::DECLINED;
		$this->assertFalse( $settings->is_remote_enabled( 'endoflife' ) );

		$GLOBALS['kip_test_options'][ RemoteConsent::OPTION ] = RemoteConsent::GRANTED;
		$this->assertTrue( $settings->is_remote_enabled( 'endoflife' ) );
		$this->assertTrue( $settings->uses_remote_data() );
	}

	public function test_service_switch_still_applies_after_consent(): void {
		$GLOBALS['kip_test_options'][ RemoteConsent::OPTION ] = RemoteConsent::GRANTED;
		$settings = new Settings(
			array(
				'remote' => array(
					'enabled'  => true,
					'services' => array( 'nvd' => false ),
				),
			)
		);
		$this->assertFalse( $settings->is_remote_enabled( 'nvd' ) );
		$this->assertTrue( $settings->is_remote_enabled( 'osv' ) );
	}

	public function test_http_client_sends_nothing_without_consent(): void {
		$client = new HttpClient( new Settings( array( 'remote' => array( 'enabled' => true ) ) ) );
		$get    = $client->get_json( 'endoflife', 'https://endoflife.date/api/php.json', 3600 );
		$post   = $client->post_json( 'osv', 'https://api.osv.dev/v1/querybatch', array( 'queries' => array() ), 3600 );
		$this->assertTrue( $get['disabled'] );
		$this->assertTrue( $post['disabled'] );
		$this->assertNull( $get['data'] );
		$this->assertSame( array(), $GLOBALS['kip_test_requests'] );
	}

	public function test_http_client_sends_after_consent(): void {
		$GLOBALS['kip_test_options'][ RemoteConsent::OPTION ] = RemoteConsent::GRANTED;
		$client = new HttpClient( new Settings( array( 'remote' => array( 'enabled' => true ) ) ) );
		$client->get_json( 'endoflife', 'https://endoflife.date/api/php.json', 3600 );
		$this->assertSame( array( array( 'GET', 'https://endoflife.date/api/php.json' ) ), $GLOBALS['kip_test_requests'] );
	}
}
