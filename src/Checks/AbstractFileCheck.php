<?php
/**
 * Base class for file checks.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks;

use KeepItPeace\Contracts\FileCheckInterface;
use KeepItPeace\Findings\Finding;
use KeepItPeace\Scan\CheckResult;
use KeepItPeace\Scan\FileInfo;
use KeepItPeace\Scan\ScanContext;
use KeepItPeace\Scan\ScanTarget;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults for file checks.
 */
abstract class AbstractFileCheck extends AbstractCheck implements FileCheckInterface {

	/**
	 * Category follows the target (theme, external_path, ...).
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'files';
	}

	/**
	 * Not used for file checks.
	 *
	 * @param ScanContext $context Context.
	 * @param array       $cursor  Cursor.
	 * @return CheckResult
	 */
	public function run( ScanContext $context, array $cursor ): CheckResult {
		return CheckResult::complete();
	}

	/**
	 * Applies to every target by default.
	 *
	 * @param ScanTarget $target Target.
	 * @return bool
	 */
	public function supports_target( ScanTarget $target ): bool {
		return true;
	}

	/**
	 * No-op.
	 *
	 * @param ScanTarget  $target  Target.
	 * @param ScanContext $context Context.
	 * @return Finding[]
	 */
	public function begin_target( ScanTarget $target, ScanContext $context ): array {
		return array();
	}

	/**
	 * No-op.
	 *
	 * @param ScanTarget  $target  Target.
	 * @param ScanContext $context Context.
	 * @return Finding[]
	 */
	public function end_target( ScanTarget $target, ScanContext $context ): array {
		return array();
	}

	/**
	 * Finding about a file.
	 *
	 * @param FileInfo   $file   File.
	 * @param ScanTarget $target Target.
	 * @param array      $data   Data.
	 * @return Finding
	 */
	protected function file_finding( FileInfo $file, ScanTarget $target, array $data ): Finding {
		return $this->finding(
			array_merge(
				array(
					'category'     => $target->category,
					'target_type'  => 'file',
					'target_scope' => $target->id,
					'target'       => $file->rel_path,
					'file_hash'    => $file->hash(),
				),
				$data
			)
		);
	}
}
