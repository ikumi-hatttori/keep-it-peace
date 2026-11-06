<?php
/**
 * Rule engine and detectors.
 *
 * @package KeepItPeace
 */

use KeepItPeace\Rules\BundledRuleProvider;
use KeepItPeace\Rules\Detectors\ObfuscationDetector;
use KeepItPeace\Rules\Detectors\RequestGuardDetector;
use KeepItPeace\Rules\Detectors\SuperglobalDetector;
use KeepItPeace\Rules\Detectors\VariableFunctionDetector;
use KeepItPeace\Rules\Detectors\WpdbDetector;
use KeepItPeace\Rules\Rule;
use KeepItPeace\Rules\RuleEngine;
use KeepItPeace\Rules\RuleSet;
use KeepItPeace\Rules\SourceFile;
use PHPUnit\Framework\TestCase;

class RuleEngineTest extends TestCase {

	private function scan( string $file ): array {
		$set       = ( new BundledRuleProvider( KEEPITPEACE_DIR . 'rules/core.json' ) )->get_rule_set();
		$detectors = array();
		foreach ( array( new VariableFunctionDetector(), new SuperglobalDetector(), new WpdbDetector(), new RequestGuardDetector(), new ObfuscationDetector() ) as $detector ) {
			$detectors[ $detector->get_name() ] = $detector;
		}
		$code = file_get_contents( KEEPITPEACE_DIR . 'tests/fixtures/' . $file );
		return ( new RuleEngine() )->scan( new SourceFile( $code, strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ), $set->rules, $detectors );
	}

	private static function ids( array $matches ): array {
		return array_values( array_unique( array_map( static function ( $m ) {
			return $m['rule']->id;
		}, $matches ) ) );
	}

	public function test_bundled_rules_are_valid(): void {
		$json = json_decode( file_get_contents( KEEPITPEACE_DIR . 'rules/core.json' ), true );
		$set  = RuleSet::from_array( $json, 'bundled' );
		$this->assertCount( count( $json['rules'] ), $set->rules, 'Every bundled rule must be valid.' );
		$this->assertSame( 1, $set->schema_version );
		$this->assertNotSame( '', $set->version );
	}

	public function test_vulnerable_fixture_is_detected(): void {
		$ids = self::ids( $this->scan( 'vulnerable-theme/functions.php' ) );
		$expected = array(
			'php.eval',
			'php.assert_dynamic',
			'php.create_function',
			'php.preg_replace_e',
			'php.shell_exec',
			'php.decode_chain',
			'php.remote_include',
			'php.unserialize_input',
			'php.extract_input',
			'php.error_reporting_off',
			'php.backtick_exec',
			'php.variable_function',
			'php.input_output',
			'php.input_include',
			'php.input_sql',
			'php.input_file',
			'php.wpdb_unprepared',
			'php.ajax_missing_nonce',
			'php.ajax_missing_capability',
			'php.rest_missing_permission',
			'php.rest_public_endpoint',
			'php.remote_fetch',
		);
		foreach ( $expected as $id ) {
			$this->assertContains( $id, $ids, "Expected rule $id" );
		}
	}

	public function test_line_numbers(): void {
		$matches = $this->scan( 'vulnerable-theme/functions.php' );
		foreach ( $matches as $match ) {
			if ( 'php.eval' === $match['rule']->id ) {
				$this->assertSame( 4, $match['line'] );
				$this->assertStringContainsString( 'eval', $match['text'] );
				return;
			}
		}
		$this->fail( 'eval not found' );
	}

	public function test_form_without_nonce(): void {
		$this->assertContains( 'php.form_missing_nonce', self::ids( $this->scan( 'vulnerable-theme/form.php' ) ) );
	}

	public function test_obfuscated_string(): void {
		$this->assertContains( 'php.obfuscated_string', self::ids( $this->scan( 'vulnerable-theme/obfuscated.php' ) ) );
	}

	public function test_safe_fixture_has_no_findings(): void {
		$matches = $this->scan( 'safe-theme/functions.php' );
		$this->assertSame( array(), array_map( static function ( $m ) {
			return $m['rule']->id . ':' . $m['line'];
		}, $matches ) );
	}

	public function test_comments_are_ignored_but_raw_rules_see_them(): void {
		$rule = Rule::from_array( array( 'id' => 'x.raw', 'pattern' => 'FilesMan', 'target' => 'raw' ), 't' );
		$code = Rule::from_array( array( 'id' => 'x.code', 'pattern' => 'FilesMan' ), 't' );
		$src  = new SourceFile( "<?php\n// FilesMan\n", 'php' );
		$ids  = self::ids( ( new RuleEngine() )->scan( $src, array( $rule, $code ), array() ) );
		$this->assertSame( array( 'x.raw' ), $ids );
	}

	public function test_invalid_rules_are_rejected(): void {
		$this->assertNull( Rule::from_array( array( 'id' => 'bad', 'pattern' => '(' ), 't' ) );
		$this->assertNull( Rule::from_array( array( 'id' => 'Bad ID', 'pattern' => 'x' ), 't' ) );
		$this->assertNull( Rule::from_array( array( 'id' => 'd', 'type' => 'detector' ), 't' ) );
		$this->assertNotNull( Rule::from_array( array( 'id' => 'hash.ok', 'pattern' => 'a#b' ), 't' ) );
	}

	public function test_file_types(): void {
		$rule = Rule::from_array( array( 'id' => 'js.x', 'pattern' => 'x', 'file_types' => array( 'js' ) ), 't' );
		$this->assertTrue( $rule->applies_to( 'js' ) );
		$this->assertFalse( $rule->applies_to( 'php' ) );
		$php = Rule::from_array( array( 'id' => 'php.x', 'pattern' => 'x' ), 't' );
		$this->assertTrue( $php->applies_to( 'phtml' ) );
	}

	public function test_line_at_offset(): void {
		$src = new SourceFile( "a\nb\n\nc", 'txt' );
		$this->assertSame( 1, $src->line_at( 0 ) );
		$this->assertSame( 2, $src->line_at( 2 ) );
		$this->assertSame( 4, $src->line_at( 5 ) );
		$this->assertSame( 'c', $src->line_text( 4 ) );
	}
}
