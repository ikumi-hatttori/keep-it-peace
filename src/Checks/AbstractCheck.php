<?php
/**
 * Base class for checks.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks;

use KeepItPeace\Contracts\CheckInterface;
use KeepItPeace\Findings\Finding;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Common helpers.
 */
abstract class AbstractCheck implements CheckInterface {

	/**
	 * Enabled unless switched off in the settings.
	 *
	 * @param Settings $settings Settings.
	 * @return bool
	 */
	public function is_enabled( Settings $settings ): bool {
		return $settings->is_check_enabled( $this->get_id() );
	}

	/**
	 * Creates a finding owned by this check.
	 *
	 * @param array $data Finding data.
	 * @return Finding
	 */
	protected function finding( array $data ): Finding {
		return Finding::create(
			array_merge(
				array(
					'check_id'     => $this->get_id(),
					'category'     => $this->get_category(),
					'target_type'  => 'env',
					'target_scope' => 'site',
				),
				$data
			)
		);
	}
}
