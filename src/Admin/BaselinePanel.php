<?php
/**
 * File baseline UI: explanation, approval table and the pending-changes notice.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Admin;

use KeepItPeace\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders everything users need to understand and approve the file baseline.
 */
class BaselinePanel {

	const CHECK_ID = 'files.integrity';
	const ANCHOR   = 'kip-baseline';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Cached pending counts.
	 *
	 * @var array<string,int>|null
	 */
	private $pending = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Unapproved file changes per configured target.
	 *
	 * @return array<string,int> Target ID => open integrity findings.
	 */
	public function pending(): array {
		if ( null === $this->pending ) {
			$targets       = $this->plugin->targets()->get_targets( $this->plugin->settings() );
			$counts        = $this->plugin->findings()->count_open_by_scope( self::CHECK_ID, array( 'integrity.unreadable' ) );
			$this->pending = array_intersect_key( $counts, $targets );
		}
		return $this->pending;
	}

	/**
	 * Findings screen filtered to file changes.
	 *
	 * @return string
	 */
	public static function findings_url(): string {
		return Admin::url( Admin::FINDINGS_SLUG, array( 'check' => self::CHECK_ID ) );
	}

	/**
	 * Settings section URL.
	 *
	 * @return string
	 */
	public static function section_url(): string {
		return Admin::url( Admin::SETTINGS_SLUG, array( 'tab' => 'general' ) ) . '#' . self::ANCHOR;
	}

	/**
	 * Explanation of the baseline.
	 */
	public static function render_help(): void {
		?>
		<div class="kip-help">
			<p><?php esc_html_e( 'The baseline is the list of fingerprints (sha256 hashes) of every file in your scan targets, recorded as the trusted state. Each scan compares the current files with it and reports added, changed and deleted files as "File integrity" findings. The first scan of a target records the baseline automatically.', 'keep-it-peace' ); ?></p>
			<p><strong><?php esc_html_e( 'When you changed files yourself (theme update, deployment, edits):', 'keep-it-peace' ); ?></strong></p>
			<ol>
				<li><?php esc_html_e( 'Run a scan so that the latest files are recorded.', 'keep-it-peace' ); ?></li>
				<li><?php esc_html_e( 'Check that the reported files are the ones you changed.', 'keep-it-peace' ); ?></li>
				<li><?php esc_html_e( 'Approve the current state. The changes are marked as resolved and are no longer reported.', 'keep-it-peace' ); ?></li>
			</ol>
			<p class="kip-ng"><?php esc_html_e( 'Do not approve changes you do not recognize. They may be signs of tampering; check the findings first.', 'keep-it-peace' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Full section (General tab).
	 */
	public function render_section(): void {
		$targets = $this->plugin->targets()->get_targets( $this->plugin->settings() );
		$scopes  = $this->plugin->files()->scopes();
		$counts  = $this->plugin->files()->counts();
		$pending = $this->pending();
		$latest  = $this->plugin->scans()->latest_completed();
		?>
		<h2 id="<?php echo esc_attr( self::ANCHOR ); ?>"><?php esc_html_e( 'File baseline', 'keep-it-peace' ); ?></h2>
		<?php self::render_help(); ?>
		<p class="description">
			<?php
			$last_scan = $latest ? Ui::datetime( $latest->finished_at ) : __( 'never', 'keep-it-peace' );
			/* translators: %s: date. */
			echo esc_html( sprintf( __( 'Approval uses the file state recorded by the last scan (%s). If you deployed after that, run a scan first.', 'keep-it-peace' ), $last_scan ) );
			?>
		</p>
		<table class="widefat striped kip-baseline-table">
			<thead><tr><th><?php esc_html_e( 'Target', 'keep-it-peace' ); ?></th><th><?php esc_html_e( 'Files', 'keep-it-peace' ); ?></th><th><?php esc_html_e( 'Unapproved changes', 'keep-it-peace' ); ?></th><th><?php esc_html_e( 'Approved', 'keep-it-peace' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $targets as $id => $target ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $target->label ); ?></strong><br><code><?php echo esc_html( $id ); ?></code></td>
					<td><?php echo esc_html( number_format_i18n( $counts[ $id ] ?? 0 ) ); ?></td>
					<td>
						<?php if ( ! empty( $pending[ $id ] ) ) : ?>
							<a class="kip-ng" href="<?php echo esc_url( self::findings_url() ); ?>"><?php echo esc_html( number_format_i18n( $pending[ $id ] ) ); ?></a>
						<?php else : ?>
							<span class="kip-ok">0</span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						if ( isset( $scopes[ $id ] ) ) {
							$user = ! empty( $scopes[ $id ]['approved_by'] ) ? get_userdata( (int) $scopes[ $id ]['approved_by'] ) : null;
							echo esc_html( Ui::timestamp( (int) $scopes[ $id ]['approved_at'] ) . ( $user ? ' / ' . $user->display_name : ' / ' . __( 'first scan', 'keep-it-peace' ) ) );
						} else {
							esc_html_e( 'Not yet (recorded on the next scan)', 'keep-it-peace' );
						}
						?>
					</td>
					<td><?php self::approve_button( $id, __( 'Approve current state', 'keep-it-peace' ), ! isset( $scopes[ $id ] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><?php self::approve_button( '__all__', __( 'Approve all targets', 'keep-it-peace' ), empty( $scopes ) ); ?></p>
		<?php
	}

	/**
	 * Notice shown on every Keep It Peace screen while changes are unapproved.
	 */
	public function render_notice(): void {
		$pending = $this->pending();
		$total   = array_sum( $pending );
		if ( $total < 1 ) {
			return;
		}
		$targets = $this->plugin->targets()->get_targets( $this->plugin->settings() );
		$parts   = array();
		foreach ( $pending as $id => $count ) {
			$parts[] = ( isset( $targets[ $id ] ) ? $targets[ $id ]->label : $id ) . ' (' . number_format_i18n( $count ) . ')';
		}
		?>
		<div class="notice notice-warning kip-baseline-notice">
			<p>
				<strong>
					<?php
					/* translators: %s: number of files. */
					echo esc_html( sprintf( _n( '%s file differs from the approved baseline.', '%s files differ from the approved baseline.', $total, 'keep-it-peace' ), number_format_i18n( $total ) ) );
					?>
				</strong>
				<?php echo esc_html( implode( ', ', $parts ) ); ?>
			</p>
			<p><?php esc_html_e( 'If you changed these files yourself, approve them. If you do not recognize the changes, check them before approving.', 'keep-it-peace' ); ?></p>
			<p class="kip-notice-actions">
				<a class="button" href="<?php echo esc_url( self::findings_url() ); ?>"><?php esc_html_e( 'Review changes', 'keep-it-peace' ); ?></a>
				<?php self::approve_button( '__pending__', __( 'Approve these changes', 'keep-it-peace' ), false, 'primary' ); ?>
				<a href="<?php echo esc_url( self::section_url() ); ?>"><?php esc_html_e( 'What is the baseline?', 'keep-it-peace' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Baseline approval button.
	 *
	 * @param string $scope    Scope, `__all__` or `__pending__`.
	 * @param string $label    Label.
	 * @param bool   $disabled Disabled.
	 * @param string $type     Button type.
	 */
	public static function approve_button( string $scope, string $label, bool $disabled, string $type = 'secondary' ): void {
		?>
		<form class="kip-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Approve the current files as the trusted baseline?', 'keep-it-peace' ) ) ); ?>);">
			<input type="hidden" name="action" value="keepitpeace_approve_baseline">
			<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
			<?php wp_nonce_field( 'keepitpeace_approve_baseline' ); ?>
			<?php submit_button( $label, $type, 'submit', false, $disabled ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
		<?php
	}
}
