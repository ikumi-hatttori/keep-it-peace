<?php
/**
 * Base class for checks that probe the site over HTTP.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Checks\External;

use KeepItPeace\Checks\AbstractCheck;
use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Remote\SiteProbe;
use KeepItPeace\Scan\CheckResult;
use KeepItPeace\Scan\ScanContext;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a deterministic list of requests, resumable across scan slices.
 */
abstract class AbstractExternalCheck extends AbstractCheck {

	/**
	 * Probe.
	 *
	 * @var SiteProbe|null
	 */
	private $probe = null;

	/**
	 * Category.
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'external';
	}

	/**
	 * Probe for the current site.
	 *
	 * @return SiteProbe
	 */
	protected function probe(): SiteProbe {
		if ( null === $this->probe ) {
			$this->probe = new SiteProbe();
		}
		return $this->probe;
	}

	/**
	 * Uses a custom probe (tests, add-ons).
	 *
	 * @param SiteProbe $probe Probe.
	 */
	public function set_probe( SiteProbe $probe ): void {
		$this->probe = $probe;
	}

	/**
	 * Work items, in a stable order.
	 *
	 * @param ScanContext $context Context.
	 * @return array[]
	 */
	abstract protected function items( ScanContext $context ): array;

	/**
	 * Processes one item.
	 *
	 * @param array       $item    Item.
	 * @param ScanContext $context Context.
	 * @param array       $data    Data collected so far (by reference, saved between slices).
	 * @return Finding[]
	 */
	abstract protected function process( array $item, ScanContext $context, array &$data ): array;

	/**
	 * Dashboard data from the collected data.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	protected function summarize( array $data ): array {
		return array();
	}

	/**
	 * Runs.
	 *
	 * @param ScanContext $context Context.
	 * @param array       $cursor  Cursor.
	 * @return CheckResult
	 */
	public function run( ScanContext $context, array $cursor ): CheckResult {
		$items    = $this->items( $context );
		$total    = count( $items );
		$index    = (int) ( $cursor['i'] ?? 0 );
		$data     = (array) ( $cursor['data'] ?? array() );
		$start    = $index;
		$findings = array();

		while ( $index < $total ) {
			if ( $index > $start && ! $context->has_time() ) {
				break;
			}
			$findings = array_merge( $findings, $this->process( $items[ $index ], $context, $data ) );
			++$index;
		}

		if ( $index >= $total ) {
			return CheckResult::complete( $findings, $this->summarize( $data ) );
		}
		return CheckResult::partial(
			$findings,
			array(
				'i'    => $index,
				'data' => $data,
			),
			$index / max( 1, $total )
		);
	}

	/**
	 * Finding about a URL.
	 *
	 * @param string $url  URL.
	 * @param array  $data Finding data.
	 * @return Finding
	 */
	protected function url_finding( string $url, array $data ): Finding {
		return $this->finding(
			array_merge(
				array(
					'target_type'  => 'url',
					'target_scope' => 'external',
					'target'       => $url,
				),
				$data
			)
		);
	}

	/**
	 * Info finding for an unreachable URL. Existing findings of the check are kept.
	 *
	 * @param string      $url     URL.
	 * @param string      $error   Error.
	 * @param ScanContext $context Context.
	 * @return Finding
	 */
	protected function unreachable( string $url, string $error, ScanContext $context ): Finding {
		$context->keep( $this->get_id() );
		return $this->url_finding(
			$url,
			array(
				'rule_id'        => 'external.unreachable',
				'severity'       => Severity::INFO,
				'title'          => __( 'The site could not be reached for the external check', 'keep-it-peace' ),
				/* translators: %s: error message. */
				'message'        => sprintf( __( 'The request failed: %s. Previous results of this check are kept until the site can be checked again.', 'keep-it-peace' ), $error ),
				'recommendation' => __( 'Some hosts block requests from the server to itself (loopback). If the site works for visitors, ask your host to allow loopback requests or disable this check.', 'keep-it-peace' ),
			)
		);
	}
}
