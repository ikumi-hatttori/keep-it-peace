<?php
/**
 * Registered checks.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Contracts\CheckInterface;
use KeepItPeace\Contracts\FileCheckInterface;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Collects checks through the `keepitpeace_checks` filter.
 */
class CheckRegistry {

	/**
	 * Factory.
	 *
	 * @var callable
	 */
	private $factory;
	/**
	 * Checks.
	 *
	 * @var CheckInterface[]|null
	 */
	private $checks = null;

	/**
	 * Constructor.
	 *
	 * @param callable $factory Returns the core checks.
	 */
	public function __construct( callable $factory ) {
		$this->factory = $factory;
	}

	/**
	 * All checks keyed by ID, in run order.
	 *
	 * @return CheckInterface[]
	 */
	public function all(): array {
		if ( null === $this->checks ) {
			/**
			 * Filters the registered checks. Add instances of CheckInterface or FileCheckInterface.
			 *
			 * @param CheckInterface[] $checks Checks.
			 */
			$checks       = apply_filters( 'keepitpeace_checks', call_user_func( $this->factory ) );
			$this->checks = array();
			foreach ( (array) $checks as $check ) {
				if ( $check instanceof CheckInterface ) {
					$this->checks[ $check->get_id() ] = $check;
				}
			}
		}
		return $this->checks;
	}

	/**
	 * Gets a check.
	 *
	 * @param string $id ID.
	 * @return CheckInterface|null
	 */
	public function get( string $id ): ?CheckInterface {
		$all = $this->all();
		return $all[ $id ] ?? null;
	}

	/**
	 * Enabled checks split by kind.
	 *
	 * @param Settings $settings Settings.
	 * @return array{standalone:CheckInterface[],file:FileCheckInterface[]}
	 */
	public function enabled( Settings $settings ): array {
		$out = array(
			'standalone' => array(),
			'file'       => array(),
		);
		foreach ( $this->all() as $id => $check ) {
			if ( ! $check->is_enabled( $settings ) ) {
				continue;
			}
			if ( $check instanceof FileCheckInterface ) {
				$out['file'][ $id ] = $check;
			} else {
				$out['standalone'][ $id ] = $check;
			}
		}
		return $out;
	}
}
