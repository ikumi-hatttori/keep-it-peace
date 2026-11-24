<?php
/**
 * REST endpoints for the admin screens.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Rest;

use KeepItPeace\Install\Installer;
use KeepItPeace\Findings\FindingActions;
use KeepItPeace\Findings\FindingStatus;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\Scan;
use KeepItPeace\Scan\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under keepitpeace/v1. Every route requires the plugin capability; the REST nonce is checked by core.
 */
class RestController {

	const NAMESPACE_V1 = 'keepitpeace/v1';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'can_manage' );

		register_rest_route(
			self::NAMESPACE_V1,
			'/scans',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_scan' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/scans/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'step' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/scans/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $permission,
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/findings/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_finding_status' ),
				'permission_callback' => $permission,
				'args'                => array(
					'id'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'status' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => FindingStatus::all(),
					),
					'note'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Error returned while the database tables are missing.
	 *
	 * @return \WP_Error
	 */
	private static function not_ready(): \WP_Error {
		return new \WP_Error( 'keepitpeace_not_installed', __( 'The Keep It Peace database tables are missing. Open a Keep It Peace screen for details.', 'keep-it-peace' ), array( 'status' => 503 ) );
	}

	/**
	 * Permission callback.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( Plugin::capability() );
	}

	/**
	 * Starts (or joins) a manual scan.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_scan() {
		if ( ! Installer::is_ready() ) {
			return self::not_ready();
		}
		$scan = $this->plugin->scanner()->start( 'manual', get_current_user_id() );
		return rest_ensure_response(
			array(
				'status'   => $scan->status,
				'scan_id'  => $scan->id,
				'progress' => $scan->progress,
			)
		);
	}

	/**
	 * Runs one slice.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function step() {
		if ( ! Installer::is_ready() ) {
			return self::not_ready();
		}
		return rest_ensure_response( $this->plugin->scanner()->run_slice( Scanner::time_budget( 'manual' ) ) );
	}

	/**
	 * Current status.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function status() {
		if ( ! Installer::is_ready() ) {
			return self::not_ready();
		}
		$running = $this->plugin->scans()->get_running();
		$latest  = $this->plugin->scans()->latest_completed();
		return rest_ensure_response(
			array(
				'running'  => (bool) $running,
				'scan_id'  => $running ? $running->id : ( $latest ? $latest->id : 0 ),
				'progress' => $running ? $running->progress : 100,
				'status'   => $running ? Scan::RUNNING : ( $latest ? $latest->status : 'idle' ),
			)
		);
	}

	/**
	 * Changes a finding's status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_finding_status( $request ) {
		if ( ! Installer::is_ready() ) {
			return self::not_ready();
		}
		$result = FindingActions::change_status( $this->plugin, (int) $request['id'], (string) $request['status'], (string) $request['note'] );
		if ( null === $result ) {
			return new \WP_Error( 'keepitpeace_not_found', __( 'Finding not found.', 'keep-it-peace' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'id'     => $result->id,
				'status' => $result->status,
			)
		);
	}
}
