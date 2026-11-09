<?php
/**
 * Plugin dashboard and WP dashboard widget.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Admin;

use KeepItPeace\Findings\Severity;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\CronHealth;

defined( 'ABSPATH' ) || exit;

/**
 * Overview screen.
 */
class DashboardPage {

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
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		$settings = $this->plugin->settings();
		$latest   = $this->plugin->scans()->latest_completed();
		$running  = $this->plugin->scans()->get_running();
		$counts   = $this->plugin->findings()->count_open_by_severity();
		$health   = CronHealth::status( $settings );
		$data     = $latest ? (array) ( $latest->summary['data'] ?? array() ) : array();
		$php      = $data['php.version'] ?? null;
		$db       = $data['server.database'] ?? null;
		$tls      = $data['external.tls'] ?? null;
		$diff     = $latest ? (array) ( $latest->summary['diff'] ?? array() ) : array();
		?>
		<div class="wrap kip-wrap">
			<h1 class="wp-heading-inline">Keep It Peace</h1>
			<button type="button" class="page-title-action" id="kip-scan-now" <?php disabled( ! current_user_can( Plugin::capability() ) ); ?>><?php esc_html_e( 'Scan now', 'keep-it-peace' ); ?></button>
			<hr class="wp-header-end">

			<div id="kip-scan-progress" class="kip-progress" <?php echo $running ? '' : 'hidden'; ?> data-running="<?php echo $running ? '1' : '0'; ?>">
				<div class="kip-progress-bar"><span style="width:<?php echo esc_attr( (string) ( $running ? $running->progress : 0 ) ); ?>%"></span></div>
				<p class="kip-progress-text" aria-live="polite"></p>
			</div>

			<div class="kip-cards">
				<div class="kip-card">
					<h2><?php esc_html_e( 'Open issues', 'keep-it-peace' ); ?></h2>
					<ul class="kip-counts">
						<?php foreach ( Severity::all() as $level ) : ?>
							<li>
								<a href="<?php echo esc_url( Admin::url( Admin::FINDINGS_SLUG, array( 'severity' => $level ) ) ); ?>">
									<?php echo wp_kses_post( Ui::severity_badge( $level ) ); ?>
									<strong><?php echo esc_html( number_format_i18n( $counts[ Severity::slug( $level ) ] ?? 0 ) ); ?></strong>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( $diff ) : ?>
						<p class="description">
							<?php
							/* translators: 1: new, 2: resolved, 3: ongoing. */
							echo esc_html( sprintf( __( 'Last scan: %1$d new, %2$d resolved, %3$d ongoing', 'keep-it-peace' ), (int) ( $diff['new'] ?? 0 ), (int) ( $diff['resolved'] ?? 0 ), (int) ( $diff['ongoing'] ?? 0 ) ) );
							?>
						</p>
					<?php endif; ?>
				</div>

				<div class="kip-card">
					<h2><?php esc_html_e( 'Scans', 'keep-it-peace' ); ?></h2>
					<table class="kip-kv">
						<tr><th><?php esc_html_e( 'Last completed', 'keep-it-peace' ); ?></th><td><?php echo esc_html( $latest ? Ui::datetime( $latest->finished_at ) : __( 'Never', 'keep-it-peace' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Next scheduled', 'keep-it-peace' ); ?></th><td><?php echo esc_html( Ui::timestamp( $health['next_run'] ) ); ?></td></tr>
						<tr>
							<th><?php esc_html_e( 'Cron health', 'keep-it-peace' ); ?></th>
							<td>
								<?php if ( $health['healthy'] ) : ?>
									<span class="kip-ok"><?php esc_html_e( 'OK', 'keep-it-peace' ); ?></span>
								<?php else : ?>
									<span class="kip-ng"><?php esc_html_e( 'Scans may not be running', 'keep-it-peace' ); ?></span>
								<?php endif; ?>
								<?php if ( $health['wp_cron_disabled'] ) : ?>
									<br><span class="description"><?php esc_html_e( 'DISABLE_WP_CRON is set: make sure a server cron job runs.', 'keep-it-peace' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>

				<div class="kip-card">
					<h2><?php esc_html_e( 'Server', 'keep-it-peace' ); ?></h2>
					<table class="kip-kv">
						<tr>
							<th>PHP</th>
							<td><?php echo wp_kses_post( $this->php_summary( is_array( $php ) ? $php : null ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Database', 'keep-it-peace' ); ?></th>
							<td>
								<?php
								if ( is_array( $db ) && ! empty( $db['version'] ) ) {
									echo esc_html( ( $db['label'] ?? '' ) . ' ' . $db['version'] );
									if ( ! empty( $db['eol'] ) ) {
										echo ' <span class="kip-ng">' . esc_html__( 'end of life', 'keep-it-peace' ) . '</span>';
									} elseif ( ! empty( $db['eol_date'] ) ) {
										/* translators: %s: date. */
										echo ' <span class="description">' . esc_html( sprintf( __( 'supported until %s', 'keep-it-peace' ), $db['eol_date'] ) ) . '</span>';
									}
								} else {
									echo '—';
								}
								?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'SSL certificate', 'keep-it-peace' ); ?></th>
							<td>
								<?php
								if ( is_array( $tls ) && isset( $tls['days_remaining'] ) ) {
									/* translators: %d: days. */
									echo esc_html( sprintf( _n( '%d day left', '%d days left', (int) $tls['days_remaining'], 'keep-it-peace' ), (int) $tls['days_remaining'] ) );
								} else {
									echo '—';
								}
								?>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<?php $this->render_top_findings(); ?>

			<?php
			/**
			 * Fires at the end of the plugin dashboard (add cards here).
			 *
			 * @param \KeepItPeace\Settings\Settings $settings Settings.
			 */
			do_action( 'keepitpeace_dashboard_widgets', $settings );
			?>
		</div>
		<?php
	}

	/**
	 * PHP summary HTML.
	 *
	 * @param array|null $php PHP status.
	 * @return string
	 */
	private function php_summary( ?array $php ): string {
		$version = $php['version'] ?? PHP_VERSION;
		$html    = esc_html( $version );
		if ( ! $php ) {
			return $html;
		}
		if ( ! empty( $php['eol'] ) ) {
			$html .= ' <span class="kip-ng">' . esc_html__( 'end of life', 'keep-it-peace' ) . '</span>';
		} elseif ( ! empty( $php['eol_date'] ) ) {
			/* translators: %s: date. */
			$html .= ' <span class="description">' . esc_html( sprintf( __( 'security support until %s', 'keep-it-peace' ), $php['eol_date'] ) ) . '</span>';
		}
		if ( ! empty( $php['behind'] ) ) {
			/* translators: %s: version. */
			$html .= '<br><span class="kip-warn">' . esc_html( sprintf( __( 'Update available: %s', 'keep-it-peace' ), $php['latest_patch'] ) ) . '</span>';
		}
		if ( isset( $php['cve_count'] ) && null !== $php['cve_count'] ) {
			/* translators: %d: number of CVEs. */
			$html .= '<br><span class="description">' . esc_html( sprintf( _n( '%d known vulnerability (NVD)', '%d known vulnerabilities (NVD)', (int) $php['cve_count'], 'keep-it-peace' ), (int) $php['cve_count'] ) ) . '</span>';
		}
		return $html;
	}

	/**
	 * Most severe open findings.
	 */
	private function render_top_findings(): void {
		$result = $this->plugin->findings()->query(
			array(
				'per_page' => 8,
				'orderby'  => 'severity',
				'order'    => 'DESC',
			)
		);
		?>
		<div class="kip-card kip-card-wide">
			<h2><?php esc_html_e( 'Most severe open issues', 'keep-it-peace' ); ?></h2>
			<?php if ( empty( $result['items'] ) ) : ?>
				<p><?php esc_html_e( 'No open issues.', 'keep-it-peace' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<tbody>
					<?php foreach ( $result['items'] as $finding ) : ?>
						<tr>
							<td style="width:90px"><?php echo wp_kses_post( Ui::severity_badge( $finding->severity ) ); ?></td>
							<td><a href="<?php echo esc_url( Admin::url( Admin::FINDINGS_SLUG, array( 'finding' => $finding->id ) ) ); ?>"><?php echo esc_html( $finding->title ); ?></a></td>
							<td class="kip-mono"><?php echo esc_html( Ui::location( $finding ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><a href="<?php echo esc_url( Admin::url( Admin::FINDINGS_SLUG ) ); ?>"><?php esc_html_e( 'View all findings', 'keep-it-peace' ); ?> &rarr;</a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * WP dashboard widget.
	 */
	public function render_widget(): void {
		$counts = $this->plugin->findings()->count_open_by_severity();
		$latest = $this->plugin->scans()->latest_completed();
		$health = CronHealth::status( $this->plugin->settings() );
		echo '<ul class="kip-counts kip-counts-compact">';
		foreach ( Severity::all() as $level ) {
			printf(
				'<li><a href="%1$s">%2$s <strong>%3$s</strong></a></li>',
				esc_url( Admin::url( Admin::FINDINGS_SLUG, array( 'severity' => $level ) ) ),
				wp_kses_post( Ui::severity_badge( $level ) ),
				esc_html( number_format_i18n( $counts[ Severity::slug( $level ) ] ?? 0 ) )
			);
		}
		echo '</ul>';
		echo '<p>' . esc_html__( 'Last scan:', 'keep-it-peace' ) . ' ' . esc_html( $latest ? Ui::datetime( $latest->finished_at ) : __( 'Never', 'keep-it-peace' ) ) . '</p>';
		if ( ! $health['healthy'] ) {
			echo '<p class="kip-ng">' . esc_html__( 'Scheduled scans may not be running.', 'keep-it-peace' ) . '</p>';
		}
		echo '<p><a href="' . esc_url( Admin::url( Admin::SLUG ) ) . '">' . esc_html__( 'Open Keep It Peace', 'keep-it-peace' ) . '</a></p>';
	}
}
