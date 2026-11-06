<?php
/**
 * Scan history.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Admin;

use KeepItPeace\Findings\Severity;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Lists scans.
 */
class ScansPage {

	const PER_PAGE = 30;

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
	 * Status label.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			Scan::RUNNING   => __( 'Running', 'keep-it-peace' ),
			Scan::COMPLETED => __( 'Completed', 'keep-it-peace' ),
			Scan::FAILED    => __( 'Failed', 'keep-it-peace' ),
			Scan::ABORTED   => __( 'Aborted', 'keep-it-peace' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Trigger label.
	 *
	 * @param string $trigger Trigger.
	 * @return string
	 */
	private static function trigger_label( string $trigger ): string {
		$labels = array(
			'cron'   => __( 'Scheduled', 'keep-it-peace' ),
			'manual' => __( 'Manual', 'keep-it-peace' ),
			'cli'    => 'WP-CLI',
			'api'    => 'API',
		);
		return $labels[ $trigger ] ?? $trigger;
	}

	/**
	 * Renders.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination.
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$scans = $this->plugin->scans()->list( self::PER_PAGE, $page );
		$total = $this->plugin->scans()->count();
		?>
		<div class="wrap kip-wrap">
			<h1><?php esc_html_e( 'Scan history', 'keep-it-peace' ); ?></h1>
			<p class="description">
				<?php
				/* translators: %d: days. */
				echo esc_html( sprintf( __( 'History is kept for %d days.', 'keep-it-peace' ), $this->plugin->settings()->retention_days() ) );
				?>
			</p>
			<table class="widefat striped kip-scans">
				<thead>
				<tr>
					<th>#</th>
					<th><?php esc_html_e( 'Started', 'keep-it-peace' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'keep-it-peace' ); ?></th>
					<th><?php esc_html_e( 'Trigger', 'keep-it-peace' ); ?></th>
					<th><?php esc_html_e( 'Status', 'keep-it-peace' ); ?></th>
					<th><?php esc_html_e( 'Open issues', 'keep-it-peace' ); ?></th>
					<th><?php esc_html_e( 'New', 'keep-it-peace' ); ?></th>
					<th><?php esc_html_e( 'Resolved', 'keep-it-peace' ); ?></th>
					<th><?php esc_html_e( 'Ongoing', 'keep-it-peace' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php if ( empty( $scans ) ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'No scans yet.', 'keep-it-peace' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $scans as $scan ) : ?>
					<?php
					$counts   = (array) ( $scan->summary['by_severity'] ?? array() );
					$diff     = (array) ( $scan->summary['diff'] ?? array() );
					$duration = $scan->duration();
					?>
					<tr>
						<td><?php echo esc_html( (string) $scan->id ); ?></td>
						<td><?php echo esc_html( Ui::datetime( $scan->started_at ) ); ?></td>
						<td><?php echo esc_html( null === $duration ? '—' : human_time_diff( 0, max( 1, $duration ) ) ); ?></td>
						<td><?php echo esc_html( self::trigger_label( $scan->trigger_type ) ); ?></td>
						<td>
							<?php echo esc_html( self::status_label( $scan->status ) ); ?>
							<?php if ( Scan::RUNNING === $scan->status ) : ?>
								(<?php echo esc_html( (string) $scan->progress ); ?>%)
							<?php elseif ( ! empty( $scan->summary['error'] ) ) : ?>
								<br><span class="description"><?php echo esc_html( (string) $scan->summary['error'] ); ?></span>
							<?php endif; ?>
						</td>
						<td class="kip-scan-counts">
							<?php
							foreach ( Severity::all() as $level ) {
								$count = (int) ( $counts[ Severity::slug( $level ) ] ?? 0 );
								if ( $count > 0 ) {
									printf( '<span class="kip-badge kip-sev-%1$s" title="%2$s">%3$d</span> ', esc_attr( Severity::slug( $level ) ), esc_attr( Severity::label( $level ) ), (int) $count );
								}
							}
							?>
						</td>
						<td><?php echo esc_html( isset( $diff['new'] ) ? (string) $diff['new'] : '—' ); ?></td>
						<td><?php echo esc_html( isset( $diff['resolved'] ) ? (string) $diff['resolved'] : '—' ); ?></td>
						<td><?php echo esc_html( isset( $diff['ongoing'] ) ? (string) $diff['ongoing'] : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / self::PER_PAGE );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $page,
							'total'   => $pages,
						)
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}
}
