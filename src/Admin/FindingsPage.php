<?php
/**
 * Findings list and detail.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Admin;

use KeepItPeace\Findings\Finding;
use KeepItPeace\Findings\FindingActions;
use KeepItPeace\Findings\FindingStatus;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\PathGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Findings screen.
 */
class FindingsPage {

	const CONTEXT_LINES = 5;
	const MAX_READ_SIZE = 2097152;

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
	 * Handles bulk actions before output (load-{hook}).
	 */
	public function load(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		add_screen_option(
			'per_page',
			array(
				'default' => 50,
				'option'  => 'keepitpeace_findings_per_page',
			)
		);

		$action = '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified below before acting.
		foreach ( array( 'action', 'action2' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && '-1' !== $_REQUEST[ $key ] ) {
				$action = sanitize_key( wp_unslash( $_REQUEST[ $key ] ) );
				break;
			}
		}
		if ( '' === $action || ! FindingStatus::is_valid( $action ) || empty( $_REQUEST['ids'] ) ) {
			return;
		}
		// phpcs:enable
		check_admin_referer( 'bulk-findings' );
		$ids = array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) );
		foreach ( $ids as $id ) {
			FindingActions::change_status( $this->plugin, $id, $action, __( 'Changed with a bulk action.', 'keep-it-peace' ) );
		}
		$back = remove_query_arg( array( 'action', 'action2', 'ids', '_wpnonce', '_wp_http_referer' ), wp_get_referer() ? wp_get_referer() : Admin::url( Admin::FINDINGS_SLUG ) );
		wp_safe_redirect( add_query_arg( 'keepitpeace_msg', 'bulk', $back ) );
		exit;
	}

	/**
	 * Renders list or detail.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		$id = isset( $_GET['finding'] ) ? absint( $_GET['finding'] ) : 0;
		if ( $id ) {
			$finding = $this->plugin->findings()->get( $id );
			if ( $finding ) {
				$this->render_detail( $finding );
				return;
			}
		}
		$this->render_list();
	}

	/**
	 * List view.
	 */
	private function render_list(): void {
		$table = new FindingsListTable( $this->plugin );
		$table->prepare_items();
		?>
		<div class="wrap kip-wrap">
			<h1><?php esc_html_e( 'Findings', 'keep-it-peace' ); ?></h1>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::FINDINGS_SLUG ); ?>">
				<input type="hidden" name="status" value="<?php echo esc_attr( FindingsListTable::filters()['status'] ); ?>">
				<?php
				$table->search_box( __( 'Search findings', 'keep-it-peace' ), 'kip-findings' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Detail view.
	 *
	 * @param Finding $finding Finding.
	 */
	private function render_detail( Finding $finding ): void {
		$events = $this->plugin->findings()->events_for( $finding->id );
		?>
		<div class="wrap kip-wrap">
			<p><a href="<?php echo esc_url( Admin::url( Admin::FINDINGS_SLUG ) ); ?>">&larr; <?php esc_html_e( 'Back to findings', 'keep-it-peace' ); ?></a></p>
			<h1 class="kip-detail-title">
				<?php echo Ui::severity_badge( $finding->severity ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Ui. ?>
				<?php echo esc_html( $finding->title ); ?>
			</h1>

			<div class="kip-detail">
				<div class="kip-detail-main">
					<table class="kip-kv kip-kv-wide">
						<tr><th><?php esc_html_e( 'Status', 'keep-it-peace' ); ?></th><td><?php echo Ui::status_badge( $finding->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Ui. ?></td></tr>
						<tr><th><?php esc_html_e( 'Category', 'keep-it-peace' ); ?></th><td><?php echo esc_html( Ui::category_label( $finding->category ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Location', 'keep-it-peace' ); ?></th><td class="kip-mono"><?php echo esc_html( Ui::location( $finding ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Check / rule', 'keep-it-peace' ); ?></th><td class="kip-mono"><?php echo esc_html( $finding->check_id . ( '' !== $finding->rule_id ? ' / ' . $finding->rule_id : '' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'First detected', 'keep-it-peace' ); ?></th><td><?php echo esc_html( Ui::datetime( $finding->first_seen_at ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Last detected', 'keep-it-peace' ); ?></th><td><?php echo esc_html( Ui::datetime( $finding->last_seen_at ) ); ?></td></tr>
						<?php if ( '' !== $finding->status_note ) : ?>
							<tr><th><?php esc_html_e( 'Note', 'keep-it-peace' ); ?></th><td><?php echo esc_html( $finding->status_note ); ?></td></tr>
						<?php endif; ?>
					</table>

					<h2><?php esc_html_e( 'Description', 'keep-it-peace' ); ?></h2>
					<p><?php echo esc_html( $finding->message ); ?></p>

					<?php $this->render_code_context( $finding ); ?>
					<?php $this->render_meta( $finding ); ?>

					<h2><?php esc_html_e( 'Recommended action', 'keep-it-peace' ); ?></h2>
					<p><?php echo esc_html( $finding->recommendation ); ?></p>

					<?php
					/**
					 * Fires in the finding detail screen to add panels (diff, AI explanation, ...).
					 *
					 * @param Finding $finding Finding.
					 */
					do_action( 'keepitpeace_finding_detail_panels', $finding );
					?>

					<h2><?php esc_html_e( 'History', 'keep-it-peace' ); ?></h2>
					<table class="widefat striped">
						<thead><tr><th><?php esc_html_e( 'Date', 'keep-it-peace' ); ?></th><th><?php esc_html_e( 'Event', 'keep-it-peace' ); ?></th><th><?php esc_html_e( 'Status', 'keep-it-peace' ); ?></th><th><?php esc_html_e( 'By', 'keep-it-peace' ); ?></th><th><?php esc_html_e( 'Note', 'keep-it-peace' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $events as $event ) : ?>
							<tr>
								<td><?php echo esc_html( Ui::datetime( $event->created_at ) ); ?></td>
								<td><?php echo esc_html( self::event_label( (string) $event->event ) ); ?></td>
								<td><?php echo esc_html( ( $event->from_status ? FindingStatus::label( $event->from_status ) . ' → ' : '' ) . ( $event->to_status ? FindingStatus::label( $event->to_status ) : '' ) ); ?></td>
								<td>
									<?php
									$user = $event->user_id ? get_userdata( (int) $event->user_id ) : null;
									echo esc_html( $user ? $user->display_name : ( $event->scan_id ? sprintf( /* translators: %d: scan ID. */ __( 'Scan #%d', 'keep-it-peace' ), (int) $event->scan_id ) : '—' ) );
									?>
								</td>
								<td><?php echo esc_html( (string) $event->note ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="kip-detail-side">
					<div class="kip-card">
						<h2><?php esc_html_e( 'Change status', 'keep-it-peace' ); ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="keepitpeace_finding_status">
							<input type="hidden" name="finding" value="<?php echo esc_attr( (string) $finding->id ); ?>">
							<?php wp_nonce_field( 'keepitpeace_finding_status' ); ?>
							<p>
								<select name="status" class="widefat">
									<?php foreach ( FindingStatus::all() as $status ) : ?>
										<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $finding->status, $status ); ?>><?php echo esc_html( FindingStatus::label( $status ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p>
								<label for="kip-note"><?php esc_html_e( 'Note (e.g. why it is ignored)', 'keep-it-peace' ); ?></label>
								<textarea id="kip-note" name="note" rows="3" class="widefat"><?php echo esc_textarea( $finding->status_note ); ?></textarea>
							</p>
							<p class="description"><?php esc_html_e( 'Ignored findings about a file become active again when the file changes.', 'keep-it-peace' ); ?></p>
							<?php submit_button( __( 'Update', 'keep-it-peace' ), 'primary', 'submit', false ); ?>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Shows the lines around a code finding.
	 *
	 * @param Finding $finding Finding.
	 */
	private function render_code_context( Finding $finding ): void {
		if ( 'file' !== $finding->target_type || null === $finding->line ) {
			return;
		}
		$lines = $this->read_lines( $finding );
		if ( null === $lines ) {
			echo '<p class="description">' . esc_html__( 'The code could not be shown (the file was moved, deleted or is not readable).', 'keep-it-peace' ) . '</p>';
			return;
		}
		$start = max( 1, $finding->line - self::CONTEXT_LINES );
		$end   = min( count( $lines ), $finding->line + self::CONTEXT_LINES );
		echo '<h2>' . esc_html__( 'Code', 'keep-it-peace' ) . '</h2><div class="kip-code"><table>';
		for ( $n = $start; $n <= $end; $n++ ) {
			$text = $lines[ $n - 1 ];
			if ( strlen( $text ) > 500 ) {
				$text = substr( $text, 0, 500 ) . ' …';
			}
			printf(
				'<tr class="%1$s"><td class="kip-ln">%2$d</td><td><pre>%3$s</pre></td></tr>',
				$n === $finding->line ? 'kip-hit' : '',
				(int) $n,
				esc_html( $text )
			);
		}
		echo '</table></div>';
	}

	/**
	 * Reads a file of a finding, staying inside its scope root.
	 *
	 * @param Finding $finding Finding.
	 * @return string[]|null
	 */
	private function read_lines( Finding $finding ): ?array {
		$root = $this->plugin->targets()->resolve_scope_root( $finding->target_scope, $this->plugin->settings() );
		if ( null === $root ) {
			return null;
		}
		$path = PathGuard::normalize( $root . '/' . $finding->target );
		if ( ! PathGuard::is_within_open_basedir( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$real      = realpath( $path );
		$real_root = realpath( $root );
		if ( false === $real || false === $real_root || 0 !== strpos( PathGuard::normalize( $real ), PathGuard::normalize( $real_root ) . '/' ) ) {
			return null;
		}
		if ( filesize( $real ) > self::MAX_READ_SIZE ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$code = file_get_contents( $real );
		return false === $code ? null : preg_split( '/\r\n|\n|\r/', $code );
	}

	/**
	 * Extra information (CVE list etc.).
	 *
	 * @param Finding $finding Finding.
	 */
	private function render_meta( Finding $finding ): void {
		$meta = $finding->meta;
		if ( ! empty( $meta['top'] ) && is_array( $meta['top'] ) ) {
			echo '<h2>' . esc_html__( 'Vulnerabilities', 'keep-it-peace' ) . '</h2><ul class="kip-list">';
			foreach ( $meta['top'] as $cve ) {
				printf(
					'<li><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a> (CVSS %3$s) — %4$s</li>',
					esc_url( 'https://nvd.nist.gov/vuln/detail/' . rawurlencode( (string) $cve['id'] ) ),
					esc_html( (string) $cve['id'] ),
					esc_html( number_format( (float) $cve['score'], 1 ) ),
					esc_html( wp_trim_words( (string) $cve['summary'], 30 ) )
				);
			}
			echo '</ul>';
			if ( ! empty( $meta['cves'] ) && count( (array) $meta['cves'] ) > count( $meta['top'] ) ) {
				echo '<p class="kip-mono">' . esc_html( implode( ', ', (array) $meta['cves'] ) ) . '</p>';
			}
		}
	}

	/**
	 * Event label.
	 *
	 * @param string $event Event.
	 * @return string
	 */
	private static function event_label( string $event ): string {
		$labels = array(
			'detected'       => __( 'Detected', 'keep-it-peace' ),
			'resolved'       => __( 'No longer detected', 'keep-it-peace' ),
			'reopened'       => __( 'Detected again', 'keep-it-peace' ),
			'ignore_reset'   => __( 'Ignore cleared (file changed)', 'keep-it-peace' ),
			'status_changed' => __( 'Status changed', 'keep-it-peace' ),
		);
		return $labels[ $event ] ?? $event;
	}
}
