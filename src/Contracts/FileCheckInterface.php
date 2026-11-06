<?php
/**
 * File check contract.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Contracts;

use KeepItPeace\Findings\Finding;
use KeepItPeace\Scan\FileInfo;
use KeepItPeace\Scan\ScanContext;
use KeepItPeace\Scan\ScanTarget;

defined( 'ABSPATH' ) || exit;

/**
 * A check that receives every file of every scan target during one shared traversal.
 *
 * `run()` is not called for file checks. Implementations must not keep state in memory
 * between calls because a target may be processed over several requests.
 */
interface FileCheckInterface extends CheckInterface {

	/**
	 * Whether this check applies to the target (per-target ON/OFF).
	 *
	 * @param ScanTarget $target Target.
	 * @return bool
	 */
	public function supports_target( ScanTarget $target ): bool;

	/**
	 * Called once before the first file of a target.
	 *
	 * @param ScanTarget  $target  Target.
	 * @param ScanContext $context Context.
	 * @return Finding[]
	 */
	public function begin_target( ScanTarget $target, ScanContext $context ): array;

	/**
	 * Analyzes one file.
	 *
	 * @param FileInfo    $file    File.
	 * @param ScanTarget  $target  Target.
	 * @param ScanContext $context Context.
	 * @return Finding[]
	 */
	public function analyze_file( FileInfo $file, ScanTarget $target, ScanContext $context ): array;

	/**
	 * Called once after the last file of a target.
	 *
	 * @param ScanTarget  $target  Target.
	 * @param ScanContext $context Context.
	 * @return Finding[]
	 */
	public function end_target( ScanTarget $target, ScanContext $context ): array;
}
