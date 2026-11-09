<?php
/**
 * Lock file parsing and OSV helpers.
 *
 * @package KeepItPeace
 */

use KeepItPeace\Checks\Dependencies\LockfileParser;
use KeepItPeace\Remote\OsvClient;
use PHPUnit\Framework\TestCase;

/**
 * Dependency check logic.
 */
class DependencyTest extends TestCase {

	public function test_composer_lock(): void {
		$json     = json_encode(
			array(
				'content-hash' => 'x',
				'packages'     => array(
					array(
						'name'    => 'Guzzlehttp/Guzzle',
						'version' => 'v7.4.0',
					),
					array(
						'name'    => 'acme/branch',
						'version' => 'dev-main',
					),
				),
				'packages-dev' => array(
					array(
						'name'    => 'phpunit/phpunit',
						'version' => '9.6.0',
					),
				),
			)
		);
		$packages = LockfileParser::parse( 'composer.lock', $json );
		$this->assertCount( 2, $packages );
		$this->assertSame(
			array(
				'ecosystem' => 'Packagist',
				'name'      => 'guzzlehttp/guzzle',
				'version'   => '7.4.0',
				'dev'       => false,
			),
			$packages[0]
		);
		$this->assertTrue( $packages[1]['dev'] );
	}

	public function test_package_lock_v3(): void {
		$json     = json_encode(
			array(
				'lockfileVersion' => 3,
				'packages'        => array(
					''                                 => array(
						'name'    => 'my-theme',
						'version' => '1.0.0',
					),
					'node_modules/lodash'              => array( 'version' => '4.17.20' ),
					'node_modules/@babel/core'         => array(
						'version' => '7.0.0',
						'dev'     => true,
					),
					'node_modules/a/node_modules/lodash' => array( 'version' => '4.17.20' ),
					'node_modules/local'               => array( 'link' => true ),
				),
			)
		);
		$packages = LockfileParser::parse( 'package-lock.json', $json );
		$names    = array_map(
			static function ( $p ) {
				return $p['name'] . '@' . $p['version'];
			},
			$packages
		);
		$this->assertSame( array( '@babel/core@7.0.0', 'lodash@4.17.20' ), $names );
	}

	public function test_package_lock_v1(): void {
		$json     = json_encode(
			array(
				'lockfileVersion' => 1,
				'dependencies'    => array(
					'express' => array(
						'version'      => '4.17.1',
						'dependencies' => array(
							'qs' => array( 'version' => '6.7.0' ),
						),
					),
					'local'   => array( 'version' => 'file:../local' ),
				),
			)
		);
		$packages = LockfileParser::parse( 'npm-shrinkwrap.json', $json );
		$this->assertCount( 2, $packages );
		$this->assertSame( 'qs', $packages[1]['name'] );
	}

	public function test_invalid_lock_file(): void {
		$this->assertNull( LockfileParser::parse( 'composer.lock', '{oops' ) );
		$this->assertFalse( LockfileParser::supports( 'composer.json' ) );
		$this->assertTrue( LockfileParser::supports( 'package-lock.json' ) );
	}

	public function test_osv_severity_and_fixed_version(): void {
		$vuln = array(
			'id'                => 'GHSA-xxxx',
			'database_specific' => array( 'severity' => 'MODERATE' ),
			'affected'          => array(
				array(
					'package' => array(
						'name'      => 'lodash',
						'ecosystem' => 'npm',
					),
					'ranges'  => array(
						array(
							'type'   => 'SEMVER',
							'events' => array(
								array( 'introduced' => '0' ),
								array( 'fixed' => '4.17.21' ),
								array( 'introduced' => '5.0.0' ),
								array( 'fixed' => '5.0.2' ),
							),
						),
					),
				),
			),
		);
		$this->assertSame( 'medium', OsvClient::severity( $vuln ) );
		$this->assertSame( '4.17.21', OsvClient::fixed_version( $vuln, 'npm', 'lodash', '4.17.20' ) );
		$this->assertSame( '5.0.2', OsvClient::fixed_version( $vuln, 'npm', 'lodash', '5.0.1' ) );
		$this->assertSame( '', OsvClient::fixed_version( $vuln, 'Packagist', 'lodash', '4.17.20' ) );

		$vuln['database_specific']['severity'] = 'CRITICAL';
		$this->assertSame( 'critical', OsvClient::severity( $vuln ) );
		$this->assertSame( 'medium', OsvClient::severity( array() ) );
	}
}
