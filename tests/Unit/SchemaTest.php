<?php
/**
 * Schema tests.
 *
 * @package KeepItPeace
 */

use KeepItPeace\Install\Installer;
use PHPUnit\Framework\TestCase;

/**
 * Every index must fit in 767 bytes with utf8mb4, the limit of MySQL 5.6 / MariaDB 10.1
 * without innodb_large_prefix. Otherwise CREATE TABLE fails there.
 */
class SchemaTest extends TestCase {

	const MAX_KEY_BYTES = 767;
	const BYTES_PER_CHAR = 4;

	/**
	 * Column types of a CREATE TABLE statement.
	 *
	 * @param string $sql Statement.
	 * @return array<string,string>
	 */
	private static function columns( string $sql ): array {
		$columns = array();
		foreach ( explode( "\n", $sql ) as $line ) {
			if ( preg_match( '/^\s+([a-z_]+)\s+([a-z]+(?:\(\d+\))?)/i', $line, $m ) && ! in_array( strtoupper( $m[1] ), array( 'PRIMARY', 'KEY', 'UNIQUE' ), true ) ) {
				$columns[ $m[1] ] = strtolower( $m[2] );
			}
		}
		return $columns;
	}

	/**
	 * Maximum bytes one key part uses.
	 *
	 * @param string   $type   Column type.
	 * @param int|null $prefix Prefix length in characters.
	 * @return int
	 */
	public static function key_part_bytes( string $type, ?int $prefix ): int {
		if ( preg_match( '/^(var)?char\((\d+)\)$/', $type, $m ) ) {
			$chars = null === $prefix ? (int) $m[2] : min( $prefix, (int) $m[2] );
			return $chars * self::BYTES_PER_CHAR;
		}
		$fixed = array(
			'tinyint'  => 1,
			'smallint' => 2,
			'int'      => 4,
			'bigint'   => 8,
			'datetime' => 8,
		);
		$base  = preg_replace( '/\(\d+\)$/', '', $type );
		if ( isset( $fixed[ $base ] ) ) {
			return $fixed[ $base ];
		}
		throw new RuntimeException( "Unknown key type $type (TEXT columns need a prefix length)." );
	}

	/**
	 * Indexes and their byte sizes.
	 *
	 * @return array<string,int> "table.index" => bytes.
	 */
	private static function index_sizes(): array {
		$sizes = array();
		foreach ( Installer::schema( 'wp_', 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci' ) as $table => $sql ) {
			$columns = self::columns( $sql );
			preg_match_all( '/^\s+(PRIMARY KEY|UNIQUE KEY|KEY)\s*([a-z_]*)\s*\((.+)\),?$/im', $sql, $keys, PREG_SET_ORDER );
			foreach ( $keys as $key ) {
				$name  = '' !== $key[2] ? $key[2] : 'PRIMARY';
				$bytes = 0;
				foreach ( explode( ',', $key[3] ) as $part ) {
					if ( ! preg_match( '/^\s*([a-z_]+)(?:\((\d+)\))?\s*$/', $part, $p ) ) {
						throw new RuntimeException( "Cannot parse key part '$part' in $table.$name" );
					}
					if ( ! isset( $columns[ $p[1] ] ) ) {
						throw new RuntimeException( "Unknown column {$p[1]} in $table.$name" );
					}
					$bytes += self::key_part_bytes( $columns[ $p[1] ], isset( $p[2] ) ? (int) $p[2] : null );
				}
				$sizes[ $table . '.' . $name ] = $bytes;
			}
		}
		return $sizes;
	}

	public function test_all_indexes_fit_in_767_bytes(): void {
		$sizes = self::index_sizes();
		$this->assertGreaterThanOrEqual( 14, count( $sizes ), 'All indexes are parsed' );
		foreach ( $sizes as $index => $bytes ) {
			$this->assertLessThanOrEqual( self::MAX_KEY_BYTES, $bytes, "$index uses $bytes bytes" );
		}
		$this->assertSame( 608, $sizes['keepitpeace_files.scope_seen'] );
	}

	public function test_byte_calculation(): void {
		$this->assertSame( 764, self::key_part_bytes( 'varchar(191)', null ) );
		$this->assertSame( 600, self::key_part_bytes( 'varchar(191)', 150 ) );
		$this->assertSame( 256, self::key_part_bytes( 'char(64)', null ) );
		$this->assertSame( 8, self::key_part_bytes( 'bigint(20)', null ) );
		$this->assertSame( 1, self::key_part_bytes( 'tinyint(3)', null ) );
	}

	public function test_dbdelta_format(): void {
		foreach ( Installer::schema( 'wp_', '' ) as $table => $sql ) {
			$this->assertStringStartsWith( "CREATE TABLE wp_$table (", $sql );
			$this->assertMatchesRegularExpression( '/^  PRIMARY KEY  \(id\),$/m', $sql, "$table: two spaces after PRIMARY KEY" );
			$this->assertDoesNotMatchRegularExpression( '/^\s*INDEX\s/mi', $sql, "$table: dbDelta expects KEY, not INDEX" );
			$this->assertDoesNotMatchRegularExpression( '/IF NOT EXISTS/i', $sql );
		}
	}
}
