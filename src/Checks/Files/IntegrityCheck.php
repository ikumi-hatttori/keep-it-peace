<?php
/**
 * File integrity (tamper detection).
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
 * Compares sha256 of every file with the approved baseline.
 * The first scan of a target records the baseline automatically.
 */
class IntegrityCheck extends AbstractFileCheck {

	const MAX_DELETED_FINDINGS = 200;

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'files.integrity';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'File integrity', 'keep-it-peace' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Detects files added, changed or deleted since the approved baseline (sha256).', 'keep-it-peace' );
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
		$hash = $file->hash();
		if ( null === $hash ) {
			return array(
				$this->file_finding(
					$file,
					$target,
					array(
						'rule_id'        => 'integrity.unreadable',
						'severity'       => Severity::INFO,
						'title'          => __( 'File could not be read', 'keep-it-peace' ),
						'message'        => __( 'The file exists but PHP cannot read it, so changes cannot be detected.', 'keep-it-peace' ),
						'recommendation' => __( 'Check the file permissions.', 'keep-it-peace' ),
					)
				),
			);
		}

		$baseline = $context->files->record( $target->id, $file, $hash, $context->scan->id );

		if ( ! $context->files->is_scope_initialized( $target->id ) ) {
			$context->files->approve_file( $target->id, $file->rel_path );
			self::fire_recorded( $file->path, $target->id, $file->rel_path, $hash );
			return array();
		}

		if ( null === $baseline['baseline_hash'] ) {
			return array(
				$this->file_finding(
					$file,
					$target,
					array(
						'rule_id'           => 'integrity.added',
						'severity'          => self::severity_for( $file->extension, true ),
						'title'             => __( 'New file', 'keep-it-peace' ),
						'message'           => __( 'This file was added after the baseline was approved.', 'keep-it-peace' ),
						'recommendation'    => __( 'If you deployed this file, approve the current state as the baseline. Otherwise inspect it and remove it if it is not yours.', 'keep-it-peace' ),
						'meta'              => array(
							'size' => $file->size,
						),
						'fingerprint_basis' => 'added',
					)
				),
			);
		}

		if ( ! hash_equals( (string) $baseline['baseline_hash'], $hash ) ) {
			return array(
				$this->file_finding(
					$file,
					$target,
					array(
						'rule_id'           => 'integrity.modified',
						'severity'          => self::severity_for( $file->extension, false ),
						'title'             => __( 'File changed', 'keep-it-peace' ),
						'message'           => __( 'The contents of this file differ from the approved baseline.', 'keep-it-peace' ),
						'recommendation'    => __( 'If the change was a planned deployment, approve the current state as the baseline. Otherwise compare the file with your source and restore it.', 'keep-it-peace' ),
						'meta'              => array(
							'baseline_size' => $baseline['baseline_size'],
							'size'          => $file->size,
							'mtime'         => $file->mtime,
						),
						'fingerprint_basis' => 'modified',
					)
				),
			);
		}
		return array();
	}

	/**
	 * Reports deleted files and initializes the baseline on the first scan.
	 *
	 * @param ScanTarget  $target  Target.
	 * @param ScanContext $context Context.
	 * @return Finding[]
	 */
	public function end_target( ScanTarget $target, ScanContext $context ): array {
		$files = $context->files;
		$files->purge_unbaselined_missing( $target->id, $context->scan->id );

		if ( ! $files->is_scope_initialized( $target->id ) ) {
			$files->mark_scope_initialized( $target->id );
			return array();
		}

		$findings = array();
		foreach ( $files->missing( $target->id, $context->scan->id, self::MAX_DELETED_FINDINGS ) as $row ) {
			$extension  = strtolower( (string) pathinfo( (string) $row->rel_path, PATHINFO_EXTENSION ) );
			$findings[] = $this->finding(
				array(
					'rule_id'           => 'integrity.deleted',
					'category'          => $target->category,
					'severity'          => in_array( $extension, FileInfo::PHP_EXTENSIONS, true ) ? Severity::MEDIUM : Severity::LOW,
					'target_type'       => 'file',
					'target_scope'      => $target->id,
					'target'            => (string) $row->rel_path,
					'title'             => __( 'File deleted', 'keep-it-peace' ),
					'message'           => __( 'This file is in the baseline but no longer exists.', 'keep-it-peace' ),
					'recommendation'    => __( 'If you removed it on purpose, approve the current state as the baseline.', 'keep-it-peace' ),
					'fingerprint_basis' => 'deleted',
				)
			);
		}
		return $findings;
	}

	/**
	 * Severity by file type.
	 *
	 * @param string $extension Extension.
	 * @param bool   $added     New file.
	 * @return int
	 */
	private static function severity_for( string $extension, bool $added ): int {
		if ( in_array( $extension, FileInfo::PHP_EXTENSIONS, true ) || 'htaccess' === $extension ) {
			return Severity::HIGH;
		}
		if ( in_array( $extension, array( 'js', 'mjs', 'html', 'htm', 'svg', 'json' ), true ) ) {
			return Severity::MEDIUM;
		}
		return $added ? Severity::LOW : Severity::LOW;
	}

	/**
	 * Fires the hook that lets add-ons store file contents for diffs.
	 *
	 * @param string $path  Absolute path.
	 * @param string $scope Scope.
	 * @param string $rel   Relative path.
	 * @param string $hash  sha256.
	 */
	public static function fire_recorded( string $path, string $scope, string $rel, string $hash ): void {
		/**
		 * Fires when a file is recorded in the baseline. The core stores only the hash;
		 * add-ons can store the contents here to show diffs later.
		 *
		 * @param string $path  Absolute path.
		 * @param string $scope Scope (e.g. theme:twentytwentyfour).
		 * @param string $rel   Relative path.
		 * @param string $hash  sha256.
		 */
		do_action( 'keepitpeace_baseline_file_recorded', $path, $scope, $rel, $hash );
	}
}
