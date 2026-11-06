<?php
/**
 * World-writable files and directories.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\Files;

use KeepItPeace\Checks\AbstractFileCheck;
use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Scan\FileInfo;
use KeepItPeace\Scan\ScanContext;
use KeepItPeace\Scan\ScanTarget;

defined( 'ABSPATH' ) || exit;

/**
 * Flags 777 / 666 style permissions.
 */
class PermissionCheck extends AbstractFileCheck {

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'files.permissions';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'File permissions', 'keep-it-peace' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Finds files and directories that every user on the server can write to (e.g. 777, 666).', 'keep-it-peace' );
	}

	/**
	 * Skipped on Windows where permission bits are meaningless.
	 *
	 * @param ScanTarget $target Target.
	 * @return bool
	 */
	public function supports_target( ScanTarget $target ): bool {
		return 'WIN' !== strtoupper( substr( PHP_OS, 0, 3 ) );
	}

	/**
	 * Checks the root directory.
	 *
	 * @param ScanTarget  $target  Target.
	 * @param ScanContext $context Context.
	 * @return Finding[]
	 */
	public function begin_target( ScanTarget $target, ScanContext $context ): array {
		$perms = fileperms( $target->root );
		if ( false === $perms || ! ( $perms & 0002 ) ) {
			return array();
		}
		return array(
			$this->finding(
				array(
					'rule_id'        => 'permissions.world_writable_dir',
					'category'       => $target->category,
					'severity'       => Severity::HIGH,
					'target_type'    => 'file',
					'target_scope'   => $target->id,
					'target'         => '.',
					'title'          => __( 'World-writable directory', 'keep-it-peace' ),
					/* translators: %s: permissions such as 777. */
					'message'        => sprintf( __( 'The directory permissions are %s. Any user or process on the server can add files here.', 'keep-it-peace' ), sprintf( '%03o', $perms & 0777 ) ),
					'recommendation' => __( 'Set directories to 755 (or 750) and files to 644 (or 640).', 'keep-it-peace' ),
				)
			),
		);
	}

	/**
	 * Analyzes a file.
	 *
	 * @param FileInfo    $file    File.
	 * @param ScanTarget  $target  Target.
	 * @param ScanContext $context Context.
	 * @return Finding[]
	 */
	public function analyze_file( FileInfo $file, ScanTarget $target, ScanContext $context ): array {
		$out = array();
		if ( $file->perms & 0002 ) {
			$out[] = $this->file_finding(
				$file,
				$target,
				array(
					'rule_id'           => 'permissions.world_writable_file',
					'severity'          => $file->is_php() ? Severity::HIGH : Severity::MEDIUM,
					'title'             => __( 'World-writable file', 'keep-it-peace' ),
					/* translators: %s: permissions such as 666. */
					'message'           => sprintf( __( 'The file permissions are %s. Any user or process on the server can change it.', 'keep-it-peace' ), $file->perms_octal() ),
					'recommendation'    => __( 'Set files to 644 (or 640).', 'keep-it-peace' ),
					'fingerprint_basis' => 'file',
				)
			);
		}

		// Parent directory of the file (each directory is reported once, via its first file).
		$dir = dirname( $file->path );
		if ( '.' !== dirname( $file->rel_path ) && self::is_first_in_dir( $file ) ) {
			$perms = fileperms( $dir );
			if ( false !== $perms && ( $perms & 0002 ) ) {
				$out[] = $this->finding(
					array(
						'rule_id'           => 'permissions.world_writable_dir',
						'category'          => $target->category,
						'severity'          => Severity::HIGH,
						'target_type'       => 'file',
						'target_scope'      => $target->id,
						'target'            => dirname( $file->rel_path ),
						'title'             => __( 'World-writable directory', 'keep-it-peace' ),
						/* translators: %s: permissions such as 777. */
						'message'           => sprintf( __( 'The directory permissions are %s. Any user or process on the server can add files here.', 'keep-it-peace' ), sprintf( '%03o', $perms & 0777 ) ),
						'recommendation'    => __( 'Set directories to 755 (or 750) and files to 644 (or 640).', 'keep-it-peace' ),
						'fingerprint_basis' => 'dir',
					)
				);
			}
		}
		return $out;
	}

	/**
	 * Whether this is the first file (alphabetically) of its directory.
	 *
	 * @param FileInfo $file File.
	 * @return bool
	 */
	private static function is_first_in_dir( FileInfo $file ): bool {
		static $seen = array();
		$dir         = dirname( $file->path );
		if ( isset( $seen[ $dir ] ) ) {
			return false;
		}
		$seen[ $dir ] = true;
		if ( count( $seen ) > 5000 ) {
			$seen = array( $dir => true );
		}
		return true;
	}
}
