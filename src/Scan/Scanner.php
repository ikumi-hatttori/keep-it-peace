<?php
/**
 * Scan orchestration with time-boxed slices.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Scan;

use KeepItPeace\Contracts\FileCheckInterface;
use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\FindingLifecycle;
use KeepItPeace\Findings\ScanDiff;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Plugin;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Starts scans and runs them slice by slice.
 */
class Scanner {

	const LAST_COMPLETED_OPTION = 'keepitpeace_last_completed_scan';

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
	 * Starts a scan, or returns the one already running.
	 *
	 * @param string $trigger cron|manual|cli|api.
	 * @param int    $user_id User.
	 * @return Scan
	 */
	public function start( string $trigger, int $user_id = 0 ): Scan {
		$scans   = $this->plugin->scans();
		$running = $scans->get_running();
		if ( $running ) {
			return $running;
		}
		$settings = $this->plugin->settings();
		$id       = $scans->create( $trigger, $user_id, $this->build_plan( $settings ), $this->plugin->rules()->get_version() );
		$scan     = $scans->get( $id );

		/**
		 * Fires when a scan starts.
		 *
		 * @param Scan $scan Scan.
		 */
		do_action( 'keepitpeace_scan_started', $scan );
		return $scan;
	}

	/**
	 * Builds the step list.
	 *
	 * @param Settings $settings Settings.
	 * @return array
	 */
	public function build_plan( Settings $settings ): array {
		$enabled = $this->plugin->checks()->enabled( $settings );
		$steps   = array();
		foreach ( array_keys( $enabled['standalone'] ) as $id ) {
			$steps[] = array(
				'type' => 'check',
				'id'   => $id,
			);
		}
		if ( $enabled['file'] ) {
			$steps[] = array(
				'type'    => 'files',
				'checks'  => array_keys( $enabled['file'] ),
				'targets' => array_keys( $this->plugin->targets()->get_targets( $settings ) ),
			);
		}
		return array(
			'steps'            => $steps,
			'step'             => 0,
			'cursor'           => array(),
			'step_progress'    => 0.0,
			'completed_checks' => array(),
			'keep'             => array(),
			'keep_scopes'      => array(),
			'data'             => array(),
		);
	}

	/**
	 * Time budget for one slice in seconds.
	 *
	 * @param string $trigger Trigger.
	 * @return float
	 */
	public static function time_budget( string $trigger ): float {
		$budget = 'manual' === $trigger ? 8.0 : 20.0;
		$max    = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			$budget = min( $budget, $max * 0.6 );
		}
		/**
		 * Filters the time budget of one scan slice.
		 *
		 * @param float  $budget  Seconds.
		 * @param string $trigger cron|manual|cli|api.
		 */
		return max( 1.0, (float) apply_filters( 'keepitpeace_time_budget', $budget, $trigger ) );
	}

	/**
	 * Runs one slice of the running scan.
	 *
	 * @param float $budget Seconds.
	 * @return array{status:string,scan_id:int,progress:int}
	 */
	public function run_slice( float $budget ): array {
		$lock = new ScanLock();
		if ( ! $lock->acquire( (int) ceil( $budget ) + 120 ) ) {
			$running = $this->plugin->scans()->get_running();
			return array(
				'status'   => 'busy',
				'scan_id'  => $running ? $running->id : 0,
				'progress' => $running ? $running->progress : 0,
			);
		}

		$scan = null;
		try {
			$scan = $this->plugin->scans()->get_running();
			if ( ! $scan ) {
				return array(
					'status'   => 'idle',
					'scan_id'  => 0,
					'progress' => 0,
				);
			}
			return $this->process( $scan, microtime( true ) + $budget );
		} catch ( \Throwable $e ) {
			if ( $scan ) {
				$this->plugin->scans()->finish( $scan->id, Scan::FAILED, array( 'error' => $e->getMessage() ) );

				/**
				 * Fires when a scan fails.
				 *
				 * @param Scan      $scan  Scan.
				 * @param \WP_Error $error Error.
				 */
				do_action( 'keepitpeace_scan_failed', $scan, new \WP_Error( 'keepitpeace_scan_failed', $e->getMessage() ) );
			}
			return array(
				'status'   => Scan::FAILED,
				'scan_id'  => $scan ? $scan->id : 0,
				'progress' => 0,
			);
		} finally {
			$lock->release();
		}
	}

	/**
	 * Runs slices until the scan completes (CLI).
	 *
	 * @param callable|null $on_progress function( array $status ).
	 * @return array Final status.
	 */
	public function run_to_completion( ?callable $on_progress = null ): array {
		do {
			$status = $this->run_slice( self::time_budget( 'cli' ) );
			if ( $on_progress ) {
				$on_progress( $status );
			}
		} while ( Scan::RUNNING === $status['status'] );
		return $status;
	}

	/**
	 * Processes steps until the deadline.
	 *
	 * @param Scan  $scan     Scan.
	 * @param float $deadline Deadline.
	 * @return array
	 */
	private function process( Scan $scan, float $deadline ): array {
		$settings = $this->plugin->settings();
		$context  = new ScanContext( $scan, $settings, $this->plugin->rules(), $this->plugin->files(), $this->plugin->http(), $deadline );
		$state    = $scan->state;
		if ( empty( $state['steps'] ) && ! isset( $state['step'] ) ) {
			$state = $this->build_plan( $settings );
		}
		$steps = (array) $state['steps'];
		$total = count( $steps );

		while ( $state['step'] < $total && $context->has_time() ) {
			$step = $steps[ $state['step'] ];
			if ( 'files' === $step['type'] ) {
				$done = $this->run_files_step( $context, $state, $step );
			} else {
				$done = $this->run_check_step( $context, $state, (string) $step['id'] );
			}
			if ( $done ) {
				++$state['step'];
				$state['cursor']        = array();
				$state['step_progress'] = 0.0;
			}
		}

		if ( $context->keep ) {
			$state['keep'] = array_merge( (array) $state['keep'], $context->keep );
		}
		$progress = (int) floor( ( $state['step'] + (float) $state['step_progress'] ) / max( 1, $total ) * 100 );

		if ( $state['step'] >= $total ) {
			$this->finalize( $scan, $state );
			return array(
				'status'   => Scan::COMPLETED,
				'scan_id'  => $scan->id,
				'progress' => 100,
			);
		}

		$this->plugin->scans()->save_state( $scan->id, $state, min( 99, $progress ) );
		return array(
			'status'   => Scan::RUNNING,
			'scan_id'  => $scan->id,
			'progress' => min( 99, $progress ),
		);
	}

	/**
	 * Runs a standalone check (or one slice of it).
	 *
	 * @param ScanContext $context Context.
	 * @param array       $state   State (by reference).
	 * @param string      $id      Check ID.
	 * @return bool Step finished.
	 */
	private function run_check_step( ScanContext $context, array &$state, string $id ): bool {
		$check = $this->plugin->checks()->get( $id );
		if ( ! $check ) {
			return true;
		}
		try {
			$result = $check->run( $context, (array) $state['cursor'] );
		} catch ( \Throwable $e ) {
			$this->store( $context->scan, array( $this->error_finding( $id, $check->get_category(), $e ) ) );
			$state['keep'][] = array( $id, '*' );
			return true;
		}

		$this->store( $context->scan, $result->findings );
		if ( $result->data ) {
			$state['data'][ $id ] = $result->data;
		}
		foreach ( $result->keep_rules as $rule_id ) {
			$state['keep'][] = array( $id, (string) $rule_id );
		}
		if ( $result->complete ) {
			$state['completed_checks'][] = $id;
			return true;
		}
		$state['cursor']        = $result->cursor;
		$state['step_progress'] = $result->progress;
		return false;
	}

	/**
	 * Runs the shared file traversal for all file checks.
	 *
	 * @param ScanContext $context Context.
	 * @param array       $state   State (by reference).
	 * @param array       $step    Step.
	 * @return bool Step finished.
	 */
	private function run_files_step( ScanContext $context, array &$state, array $step ): bool {
		$checks = array();
		foreach ( (array) $step['checks'] as $id ) {
			$check = $this->plugin->checks()->get( $id );
			if ( $check instanceof FileCheckInterface ) {
				$checks[ $id ] = $check;
			}
		}
		$target_ids = (array) $step['targets'];
		$cursor     = (array) $state['cursor'] + array(
			'target' => 0,
			'begun'  => false,
			'walker' => array(),
		);
		$limits     = ScanContext::file_limits( $context->settings );

		$target_count = count( $target_ids );
		while ( $cursor['target'] < $target_count ) {
			if ( ! $context->has_time() ) {
				break;
			}
			$target = $this->plugin->targets()->get( (string) $target_ids[ $cursor['target'] ], $context->settings );
			if ( ! $target ) {
				++$cursor['target'];
				continue;
			}
			$active = array_filter(
				$checks,
				static function ( FileCheckInterface $check ) use ( $target ) {
					return $target->is_check_enabled( $check->get_id() ) && $check->supports_target( $target );
				}
			);

			if ( ! $cursor['begun'] ) {
				$status = PathGuard::check_directory( $target->root );
				if ( PathGuard::OK !== $status ) {
					$this->store( $context->scan, array( $this->unreadable_finding( $target, '', $status ) ) );
					$state['keep_scopes'][] = $target->id;
					++$cursor['target'];
					continue;
				}
				foreach ( $active as $check ) {
					$this->store( $context->scan, $this->safe_call( $check, 'begin_target', array( $target, $context ), $state ) );
				}
				$cursor['begun']  = true;
				$cursor['walker'] = array();
			}

			$walker           = new FileWalker( $target->root, $this->exclude_patterns( $context->settings, $target ), $limits['max_files'] );
			$scanner          = $this;
			$result           = $walker->walk(
				(array) $cursor['walker'],
				static function ( FileInfo $file ) use ( $active, $target, $context, $scanner, &$state ) {
					foreach ( $active as $check ) {
						$scanner->store( $context->scan, $scanner->safe_call( $check, 'analyze_file', array( $file, $target, $context ), $state ) );
					}
				},
				static function ( string $rel_dir, string $reason ) use ( $target, $context, $scanner ) {
					$scanner->store( $context->scan, array( $scanner->unreadable_finding( $target, $rel_dir, $reason ) ) );
				},
				array( $context, 'has_time' )
			);
			$cursor['walker'] = $result['cursor'];

			if ( ! $result['done'] ) {
				break;
			}
			if ( $result['limited'] ) {
				$state['keep_scopes'][] = $target->id;
				$this->store(
					$context->scan,
					array(
						Finding::create(
							array(
								'check_id'       => 'scanner',
								'rule_id'        => 'file_limit',
								'category'       => $target->category,
								'severity'       => Severity::INFO,
								'target_type'    => 'file',
								'target_scope'   => $target->id,
								'target'         => '',
								'title'          => __( 'File limit reached', 'keep-it-peace' ),
								/* translators: %d: number of files. */
								'message'        => sprintf( __( 'Scanning stopped after %d files. Files after that were not checked.', 'keep-it-peace' ), $limits['max_files'] ),
								'recommendation' => __( 'Add exclude patterns for generated or vendor directories, or raise the file limit in the settings.', 'keep-it-peace' ),
							)
						),
					)
				);
			}
			foreach ( $active as $check ) {
				$this->store( $context->scan, $this->safe_call( $check, 'end_target', array( $target, $context ), $state ) );
			}
			++$cursor['target'];
			$cursor['begun']  = false;
			$cursor['walker'] = array();
		}

		$state['cursor']        = $cursor;
		$state['step_progress'] = count( $target_ids ) ? $cursor['target'] / count( $target_ids ) : 1.0;

		if ( $cursor['target'] >= count( $target_ids ) ) {
			foreach ( array_keys( $checks ) as $id ) {
				$state['completed_checks'][] = $id;
			}
			return true;
		}
		return false;
	}

	/**
	 * Calls a file check method, converting exceptions into a finding.
	 *
	 * @param FileCheckInterface $check  Check.
	 * @param string             $method Method.
	 * @param array              $args   Arguments.
	 * @param array              $state  State (by reference).
	 * @return Finding[]
	 */
	public function safe_call( FileCheckInterface $check, string $method, array $args, array &$state ): array {
		try {
			return (array) call_user_func_array( array( $check, $method ), $args );
		} catch ( \Throwable $e ) {
			$state['keep'][] = array( $check->get_id(), '*' );
			return array( $this->error_finding( $check->get_id(), $check->get_category(), $e ) );
		}
	}

	/**
	 * Exclude patterns for a target.
	 *
	 * @param Settings   $settings Settings.
	 * @param ScanTarget $target   Target.
	 * @return string[]
	 */
	private function exclude_patterns( Settings $settings, ScanTarget $target ): array {
		$patterns = array_merge( (array) $settings->get( 'exclude_patterns', array() ), $target->excludes );

		/**
		 * Filters exclude patterns for a target.
		 *
		 * @param string[]   $patterns Patterns.
		 * @param ScanTarget $target   Target.
		 */
		return (array) apply_filters( 'keepitpeace_exclude_patterns', $patterns, $target );
	}

	/**
	 * Persists findings and fires hooks.
	 *
	 * @param Scan      $scan     Scan.
	 * @param Finding[] $findings Findings.
	 */
	public function store( Scan $scan, array $findings ): void {
		$repo = $this->plugin->findings();
		foreach ( $findings as $finding ) {
			if ( ! $finding instanceof Finding ) {
				continue;
			}
			list( $outcome, $stored ) = $repo->upsert( $finding, $scan->id );
			if ( FindingLifecycle::OUTCOME_CREATED === $outcome ) {
				/**
				 * Fires when a finding is detected for the first time.
				 *
				 * @param Finding $finding Finding.
				 * @param Scan    $scan    Scan.
				 */
				do_action( 'keepitpeace_finding_created', $stored, $scan );
			} elseif ( FindingLifecycle::OUTCOME_UNCHANGED !== $outcome ) {
				/**
				 * Fires when a resolved or ignored finding becomes active again.
				 *
				 * @param Finding $finding Finding.
				 * @param Scan    $scan    Scan.
				 * @param string  $outcome reopened|ignore_reset.
				 */
				do_action( 'keepitpeace_finding_reopened', $stored, $scan, $outcome );
			}
		}
	}

	/**
	 * Completes the scan: resolves missing findings, writes the summary, notifies.
	 *
	 * @param Scan  $scan  Scan.
	 * @param array $state State.
	 */
	private function finalize( Scan $scan, array $state ): void {
		$repo = $this->plugin->findings();

		$completed   = array_values( array_unique( (array) $state['completed_checks'] ) );
		$completed[] = 'scanner';
		$resolved    = $repo->resolve_missing( $scan->id, $completed, (array) $state['keep'], array_values( array_unique( (array) $state['keep_scopes'] ) ) );
		foreach ( $resolved as $finding ) {
			/**
			 * Fires when a finding is no longer detected.
			 *
			 * @param Finding $finding Finding.
			 * @param Scan    $scan    Scan.
			 */
			do_action( 'keepitpeace_finding_resolved', $finding, $scan );
		}

		$diff    = ScanDiff::from_events( $repo->event_counts_for_scan( $scan->id ), $repo->count_open_seen_in_scan( $scan->id ) );
		$summary = array(
			'by_severity' => $repo->count_open_by_severity(),
			'diff'        => $diff->to_array(),
			'data'        => (array) $state['data'],
			'checks'      => $completed,
		);
		$this->plugin->scans()->finish( $scan->id, Scan::COMPLETED, $summary );
		update_option(
			self::LAST_COMPLETED_OPTION,
			array(
				'id'   => $scan->id,
				'time' => time(),
			),
			false
		);

		$scan   = $this->plugin->scans()->get( $scan->id );
		$report = ScanReport::from_scan( $scan );

		/**
		 * Fires when a scan completes.
		 *
		 * @param ScanReport $report Report.
		 */
		do_action( 'keepitpeace_scan_completed', $report );

		$this->plugin->notifier()->notify( $report );
	}

	/**
	 * Finding for a check that threw.
	 *
	 * @param string     $check_id Check.
	 * @param string     $category Category.
	 * @param \Throwable $e        Exception.
	 * @return Finding
	 */
	private function error_finding( string $check_id, string $category, \Throwable $e ): Finding {
		return Finding::create(
			array(
				'check_id'       => 'scanner',
				'rule_id'        => 'check_error',
				'category'       => $category,
				'severity'       => Severity::INFO,
				'target_type'    => 'env',
				'target_scope'   => 'site',
				'target'         => $check_id,
				'title'          => __( 'A check could not finish', 'keep-it-peace' ),
				/* translators: 1: check ID, 2: error message. */
				'message'        => sprintf( __( 'The check %1$s stopped with an error: %2$s', 'keep-it-peace' ), $check_id, $e->getMessage() ),
				'recommendation' => __( 'Run the scan again. If the error persists, disable the check and report the problem.', 'keep-it-peace' ),
			)
		);
	}

	/**
	 * Finding for an unreadable directory.
	 *
	 * @param ScanTarget $target  Target.
	 * @param string     $rel_dir Relative directory ('' = root).
	 * @param string     $reason  PathGuard status.
	 * @return Finding
	 */
	public function unreadable_finding( ScanTarget $target, string $rel_dir, string $reason ): Finding {
		return Finding::create(
			array(
				'check_id'       => 'scanner',
				'rule_id'        => 'unreadable',
				'category'       => $target->category,
				'severity'       => Severity::INFO,
				'target_type'    => 'file',
				'target_scope'   => $target->id,
				'target'         => '' === $rel_dir ? '.' : $rel_dir,
				'title'          => __( 'Directory could not be read', 'keep-it-peace' ),
				'message'        => PathGuard::describe( $reason ),
				'recommendation' => __( 'Check the path, permissions and the open_basedir setting. Files in this directory were not checked.', 'keep-it-peace' ),
				'meta'           => array( 'reason' => $reason ),
			)
		);
	}
}
