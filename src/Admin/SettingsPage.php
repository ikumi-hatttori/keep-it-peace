<?php
/**
 * Settings screen with tabs.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Admin;

use KeepItPeace\Contracts\FileCheckInterface;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Notifications\NotificationPolicy;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\CronHealth;
use KeepItPeace\Scan\PluginTargetProvider;
use KeepItPeace\Scan\Scheduler;
use KeepItPeace\Scan\ThemeTargetProvider;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tabs are filterable (`keepitpeace_settings_tabs`); add-ons render theirs with `keepitpeace_settings_tab_{$tab}`.
 */
class SettingsPage {

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
	 * Tabs.
	 *
	 * @return array<string,string>
	 */
	public static function tabs(): array {
		$tabs = array(
			'general'       => __( 'General', 'keep-it-peace' ),
			'targets'       => __( 'Targets', 'keep-it-peace' ),
			'checks'        => __( 'Checks', 'keep-it-peace' ),
			'notifications' => __( 'Notifications', 'keep-it-peace' ),
			'remote'        => __( 'External services', 'keep-it-peace' ),
			'tools'         => __( 'Tools', 'keep-it-peace' ),
		);

		/**
		 * Filters settings tabs.
		 *
		 * @param array<string,string> $tabs Tab ID => label.
		 */
		return (array) apply_filters( 'keepitpeace_settings_tabs', $tabs );
	}

	/**
	 * Renders.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation.
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$tab      = isset( $tabs[ $tab ] ) ? $tab : 'general';
		$settings = $this->plugin->settings();
		?>
		<div class="wrap kip-wrap">
			<h1><?php esc_html_e( 'Keep It Peace settings', 'keep-it-peace' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="<?php echo esc_url( Admin::url( Admin::SETTINGS_SLUG, array( 'tab' => $id ) ) ); ?>" class="nav-tab <?php echo $id === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="kip-settings">
				<div class="kip-settings-main">
					<?php
					$method = 'tab_' . $tab;
					if ( method_exists( $this, $method ) ) {
						$this->$method( $settings );
					} else {
						/**
						 * Renders an add-on settings tab. Use a form posting to admin-post.php with
						 * action=keepitpeace_save_settings, tab=<id>, nonce keepitpeace_save_settings
						 * and fields named keepitpeace[...]; sanitize them in `keepitpeace_sanitize_settings`.
						 *
						 * @param Settings $settings Settings.
						 */
						do_action( 'keepitpeace_settings_tab_' . $tab, $settings );
					}
					?>
				</div>
				<?php $this->render_sidebar(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Opens the settings form.
	 *
	 * @param string $tab Tab.
	 */
	private function form_start( string $tab ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="keepitpeace_save_settings">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'keepitpeace_save_settings' );
		echo '<table class="form-table" role="presentation">';
	}

	/**
	 * Closes the settings form.
	 *
	 * @param string   $tab      Tab.
	 * @param Settings $settings Settings.
	 */
	private function form_end( string $tab, Settings $settings ): void {
		/**
		 * Adds rows to a core settings tab (inside the form table).
		 *
		 * @param Settings $settings Settings.
		 */
		do_action( 'keepitpeace_settings_section_' . $tab, $settings );
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	/**
	 * General tab.
	 *
	 * @param Settings $settings Settings.
	 */
	private function tab_general( Settings $settings ): void {
		$max = Settings::max_retention_days();
		$this->form_start( 'general' );
		?>
		<tr>
			<th scope="row"><label for="kip-schedule"><?php esc_html_e( 'Scan frequency', 'keep-it-peace' ); ?></label></th>
			<td>
				<select id="kip-schedule" name="keepitpeace[schedule]">
					<?php foreach ( Scheduler::frequency_options() as $key => $option ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings->get( 'schedule' ), $key ); ?>><?php echo esc_html( $option['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="kip-retention"><?php esc_html_e( 'Keep history for', 'keep-it-peace' ); ?></label></th>
			<td>
				<input type="number" id="kip-retention" name="keepitpeace[retention_days]" min="<?php echo 0 === $max ? '0' : '1'; ?>" <?php echo 0 === $max ? '' : 'max="' . esc_attr( (string) $max ) . '"'; ?> value="<?php echo esc_attr( (string) $settings->get( 'retention_days' ) ); ?>" class="small-text"> <?php esc_html_e( 'days', 'keep-it-peace' ); ?>
				<?php if ( 0 === $max ) : ?>
					<p class="description"><?php esc_html_e( '0 keeps history forever.', 'keep-it-peace' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		$this->form_end( 'general', $settings );
		( new BaselinePanel( $this->plugin ) )->render_section();
	}

	/**
	 * Targets tab.
	 *
	 * @param Settings $settings Settings.
	 */
	private function tab_targets( Settings $settings ): void {
		$selected = (array) $settings->get( 'themes', array() );
		$this->form_start( 'targets' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Themes to scan', 'keep-it-peace' ); ?></th>
			<td>
				<fieldset>
					<?php foreach ( wp_get_themes() as $slug => $theme ) : ?>
						<label style="display:block">
							<input type="checkbox" name="keepitpeace[themes][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected, true ) ); ?>>
							<?php echo esc_html( $theme->get( 'Name' ) ); ?> <code><?php echo esc_html( $slug ); ?></code>
							<?php if ( get_stylesheet() === $slug ) : ?>
								<span class="description"><?php esc_html_e( '(active)', 'keep-it-peace' ); ?></span>
							<?php elseif ( get_template() === $slug ) : ?>
								<span class="description"><?php esc_html_e( '(parent of the active theme)', 'keep-it-peace' ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<p class="description">
					<?php esc_html_e( 'When nothing is selected, the active theme and its parent are scanned.', 'keep-it-peace' ); ?>
					<?php
					/* translators: %s: theme slugs. */
					echo esc_html( sprintf( __( 'Currently scanned: %s', 'keep-it-peace' ), implode( ', ', ThemeTargetProvider::selected_slugs( $settings ) ) ) );
					?>
				</p>
			</td>
		</tr>
		<?php $this->plugins_row( $settings ); ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Directories outside WordPress', 'keep-it-peace' ); ?></th>
			<td>
				<p class="description"><?php esc_html_e( 'Other programs on this server, such as a landing page, a form mailer or a legacy application. Enter the absolute server path. The directory must be readable by PHP and inside open_basedir. WordPress itself, themes and plugins cannot be registered here; select themes and plugins above.', 'keep-it-peace' ); ?></p>
				<?php $this->render_path_reference(); ?>
				<div id="kip-path-rows">
					<?php
					$rows   = array_values( (array) $settings->get( 'paths', array() ) );
					$rows[] = array();
					foreach ( $rows as $index => $row ) {
						$this->path_row( (string) $index, (array) $row );
					}
					?>
				</div>
				<template id="kip-path-row-template"><?php $this->path_row( '__INDEX__', array() ); ?></template>
				<p><button type="button" class="button" id="kip-add-path" data-next="<?php echo esc_attr( (string) count( $rows ) ); ?>"><?php esc_html_e( '+ Add another directory', 'keep-it-peace' ); ?></button></p>
				<p class="description"><?php esc_html_e( 'You can add several directories at once. Empty rows are ignored when saving.', 'keep-it-peace' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="kip-urls"><?php esc_html_e( 'Additional URLs for external checks', 'keep-it-peace' ); ?></label></th>
			<td>
				<textarea id="kip-urls" name="keepitpeace[urls]" rows="3" class="large-text code" placeholder="https://shop.example.com/"><?php echo esc_textarea( implode( "\n", (array) $settings->get( 'urls', array() ) ) ); ?></textarea>
				<p class="description">
					<?php
					/* translators: %s: site address. */
					echo esc_html( sprintf( __( 'One URL per line. %s is always checked. Only URLs on your own domain (including subdomains) are accepted.', 'keep-it-peace' ), home_url( '/' ) ) );
					?>
				</p>
			</td>
		</tr>
		<?php
		$this->form_end( 'targets', $settings );
	}

	/**
	 * Custom plugins to scan. Plugins from WordPress.org are listed but cannot be selected.
	 *
	 * @param Settings $settings Settings.
	 */
	private function plugins_row( Settings $settings ): void {
		$selected  = array_map( 'strval', (array) $settings->get( 'plugins', array() ) );
		$available = PluginTargetProvider::available();
		$custom    = array_filter(
			$available,
			static function ( $info ) {
				return ! $info['wporg'];
			}
		);
		$wporg     = array_diff_key( $available, $custom );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Plugins to scan', 'keep-it-peace' ); ?></th>
			<td>
				<p class="description"><?php esc_html_e( 'Select plugins you or your client developed (custom plugins). Plugins distributed on WordPress.org are not scanned because they are maintained and checked for vulnerabilities by their authors and WordPress.org. Nothing is scanned unless you select it.', 'keep-it-peace' ); ?></p>
				<fieldset>
					<?php if ( empty( $custom ) && ! PluginTargetProvider::mu_plugins_available() ) : ?>
						<p><em><?php esc_html_e( 'No custom plugins were found.', 'keep-it-peace' ); ?></em></p>
					<?php endif; ?>
					<?php foreach ( $custom as $slug => $info ) : ?>
						<label style="display:block">
							<input type="checkbox" name="keepitpeace[plugins][]" value="<?php echo esc_attr( (string) $slug ); ?>" <?php checked( in_array( (string) $slug, $selected, true ) ); ?>>
							<?php echo esc_html( $info['name'] ); ?> <code><?php echo esc_html( (string) $slug ); ?></code>
							<?php if ( '' !== $info['version'] ) : ?>
								<span class="description"><?php echo esc_html( $info['version'] ); ?></span>
							<?php endif; ?>
							<?php if ( is_plugin_active( $info['file'] ) ) : ?>
								<span class="description"><?php esc_html_e( '(active)', 'keep-it-peace' ); ?></span>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
					<?php if ( PluginTargetProvider::mu_plugins_available() ) : ?>
						<label style="display:block">
							<input type="checkbox" name="keepitpeace[plugins][]" value="<?php echo esc_attr( PluginTargetProvider::MU_PLUGINS ); ?>" <?php checked( in_array( PluginTargetProvider::MU_PLUGINS, $selected, true ) ); ?>>
							<?php esc_html_e( 'Must-use plugins (mu-plugins)', 'keep-it-peace' ); ?> <code><?php echo esc_html( wp_basename( WPMU_PLUGIN_DIR ) ); ?></code>
						</label>
					<?php endif; ?>
				</fieldset>
				<?php if ( ! PluginTargetProvider::source_known() ) : ?>
					<p class="description kip-warn"><?php esc_html_e( 'WordPress has not checked plugin updates yet, so plugins from WordPress.org cannot be told apart. Open Dashboard → Updates once and reload this page.', 'keep-it-peace' ); ?></p>
				<?php endif; ?>
				<?php if ( $wporg ) : ?>
					<details>
						<summary>
							<?php
							/* translators: %d: number of plugins. */
							echo esc_html( sprintf( _n( '%d plugin from WordPress.org (not scanned)', '%d plugins from WordPress.org (not scanned)', count( $wporg ), 'keep-it-peace' ), count( $wporg ) ) );
							?>
						</summary>
						<ul class="kip-list">
							<?php foreach ( $wporg as $slug => $info ) : ?>
								<li><?php echo esc_html( $info['name'] ); ?> <code><?php echo esc_html( (string) $slug ); ?></code></li>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Single-file plugins placed directly in the plugins folder are not listed.', 'keep-it-peace' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Server paths for reference when registering directories.
	 */
	private function render_path_reference(): void {
		$wp_root  = untrailingslashit( wp_normalize_path( ABSPATH ) );
		$rows     = array(
			__( 'WordPress root (ABSPATH)', 'keep-it-peace' ) => $wp_root,
		);
		$doc_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? untrailingslashit( wp_normalize_path( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) ) ) : '';
		if ( '' !== $doc_root && $doc_root !== $wp_root ) {
			$rows[ __( 'Document root', 'keep-it-peace' ) ] = $doc_root;
		}
		$basedir = (string) ini_get( 'open_basedir' );
		if ( '' !== $basedir ) {
			$rows['open_basedir'] = $basedir;
		}

		// Ancestors of the WordPress root, from "/" down to ABSPATH.
		$ancestors = array();
		$current   = '';
		foreach ( array_filter( explode( '/', $wp_root ), 'strlen' ) as $segment ) {
			$current    .= '/' . $segment;
			$ancestors[] = $current;
		}
		?>
		<div class="kip-path-ref">
			<p><strong><?php esc_html_e( 'Paths on this server (for reference)', 'keep-it-peace' ); ?></strong></p>
			<table class="kip-kv">
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr><th><?php echo esc_html( $label ); ?></th><td class="kip-mono"><?php echo esc_html( $value ); ?></td></tr>
				<?php endforeach; ?>
			</table>
			<?php if ( $ancestors ) : ?>
				<p class="description"><?php esc_html_e( 'Click a folder from the server root to the WordPress root to insert it into an empty Path field, then append the rest of the path.', 'keep-it-peace' ); ?></p>
				<p class="kip-path-chips">
					<?php foreach ( $ancestors as $path ) : ?>
						<button type="button" class="button button-small kip-path-chip" data-path="<?php echo esc_attr( $path . '/' ); ?>"><?php echo esc_html( $path . '/' ); ?></button>
					<?php endforeach; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One registered directory (an empty row adds a new one).
	 *
	 * @param string $index Row index (`__INDEX__` in the JS template).
	 * @param array  $row   Entry.
	 */
	private function path_row( string $index, array $row ): void {
		$is_new = empty( $row['path'] );
		$name   = 'keepitpeace[paths][' . $index . ']';
		$prefix = 'kip-path-' . $index;
		?>
		<fieldset class="kip-path-row">
			<legend><?php echo $is_new ? esc_html__( 'Add a directory', 'keep-it-peace' ) : esc_html( (string) $row['label'] ); ?></legend>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( (string) ( $row['id'] ?? '' ) ); ?>">
			<p>
				<label for="<?php echo esc_attr( $prefix ); ?>-path"><?php esc_html_e( 'Path', 'keep-it-peace' ); ?></label><br>
				<input type="text" id="<?php echo esc_attr( $prefix ); ?>-path" name="<?php echo esc_attr( $name ); ?>[path]" value="<?php echo esc_attr( (string) ( $row['path'] ?? '' ) ); ?>" class="large-text code" placeholder="/home/example/public_html/form">
			</p>
			<p class="kip-path-cols">
				<span>
					<label for="<?php echo esc_attr( $prefix ); ?>-label"><?php esc_html_e( 'Label', 'keep-it-peace' ); ?></label><br>
					<input type="text" id="<?php echo esc_attr( $prefix ); ?>-label" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>" class="regular-text">
				</span>
				<span>
					<label for="<?php echo esc_attr( $prefix ); ?>-url"><?php esc_html_e( 'Public URL (optional)', 'keep-it-peace' ); ?></label><br>
					<input type="url" id="<?php echo esc_attr( $prefix ); ?>-url" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( (string) ( $row['url'] ?? '' ) ); ?>" class="regular-text code" placeholder="https://example.com/form/">
				</span>
			</p>
			<details>
				<summary><?php esc_html_e( 'Checks and exclusions', 'keep-it-peace' ); ?></summary>
				<p>
					<?php foreach ( $this->plugin->checks()->all() as $id => $check ) : ?>
						<?php
						if ( ! $check instanceof FileCheckInterface ) {
							continue;
						}
						$enabled = $is_new || ! isset( $row['checks'][ $id ] ) || ! empty( $row['checks'][ $id ] );
						?>
						<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[checks][<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( $enabled ); ?>> <?php echo esc_html( $check->get_name() ); ?></label>
					<?php endforeach; ?>
				</p>
				<p>
					<label for="<?php echo esc_attr( $prefix ); ?>-excludes"><?php esc_html_e( 'Additional exclude patterns (one per line)', 'keep-it-peace' ); ?></label><br>
					<textarea id="<?php echo esc_attr( $prefix ); ?>-excludes" name="<?php echo esc_attr( $name ); ?>[excludes]" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) ( $row['excludes'] ?? array() ) ) ); ?></textarea>
				</p>
			</details>
			<?php if ( ! $is_new ) : ?>
				<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[remove]" value="1"> <?php esc_html_e( 'Remove this directory', 'keep-it-peace' ); ?></label></p>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * Checks tab.
	 *
	 * @param Settings $settings Settings.
	 */
	private function tab_checks( Settings $settings ): void {
		$this->form_start( 'checks' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Enabled checks', 'keep-it-peace' ); ?></th>
			<td>
				<fieldset>
					<?php foreach ( $this->plugin->checks()->all() as $id => $check ) : ?>
						<label class="kip-check-option">
							<input type="checkbox" name="keepitpeace[checks][<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( $settings->is_check_enabled( $id ) ); ?>>
							<strong><?php echo esc_html( $check->get_name() ); ?></strong> <code><?php echo esc_html( $id ); ?></code><br>
							<span class="description"><?php echo esc_html( $check->get_description() ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="kip-excludes"><?php esc_html_e( 'Exclude patterns', 'keep-it-peace' ); ?></label></th>
			<td>
				<textarea id="kip-excludes" name="keepitpeace[exclude_patterns]" rows="5" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings->get( 'exclude_patterns', array() ) ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One pattern per line. Without "/" a pattern matches any file or folder name (e.g. node_modules, *.min.js). With "/" it matches the path from the target root (e.g. assets/vendor/*).', 'keep-it-peace' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="kip-maxsize"><?php esc_html_e( 'Maximum file size for code analysis', 'keep-it-peace' ); ?></label></th>
			<td><input type="number" id="kip-maxsize" name="keepitpeace[max_file_size_kb]" min="16" value="<?php echo esc_attr( (string) $settings->get( 'max_file_size_kb' ) ); ?>" class="small-text"> KB</td>
		</tr>
		<tr>
			<th scope="row"><label for="kip-maxfiles"><?php esc_html_e( 'Maximum files per target', 'keep-it-peace' ); ?></label></th>
			<td>
				<input type="number" id="kip-maxfiles" name="keepitpeace[max_files]" min="0" value="<?php echo esc_attr( (string) $settings->get( 'max_files' ) ); ?>" class="small-text">
				<p class="description"><?php esc_html_e( '0 means no limit.', 'keep-it-peace' ); ?></p>
			</td>
		</tr>
		<?php
		$this->form_end( 'checks', $settings );
	}

	/**
	 * Notifications tab.
	 *
	 * @param Settings $settings Settings.
	 */
	private function tab_notifications( Settings $settings ): void {
		$notify = (array) $settings->get( 'notify', array() );
		$this->form_start( 'notifications' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Send a report', 'keep-it-peace' ); ?></th>
			<td>
				<fieldset>
					<?php foreach ( NotificationPolicy::conditions() as $key => $label ) : ?>
						<label style="display:block"><input type="radio" name="keepitpeace[notify][condition]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $notify['condition'] ?? '', $key ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					<label for="kip-min-severity"><?php esc_html_e( 'Minimum severity:', 'keep-it-peace' ); ?></label>
					<select id="kip-min-severity" name="keepitpeace[notify][min_severity]">
						<?php foreach ( Severity::all() as $level ) : ?>
							<?php
							if ( Severity::INFO === $level ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( Severity::slug( $level ) ); ?>" <?php selected( $notify['min_severity'] ?? 'high', Severity::slug( $level ) ); ?>><?php echo esc_html( Severity::label( $level ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Weekly digest', 'keep-it-peace' ); ?></th>
			<td><label><input type="checkbox" name="keepitpeace[notify][digest]" value="1" <?php checked( ! empty( $notify['digest'] ) ); ?>> <?php esc_html_e( 'Send a weekly summary of open issues regardless of the condition above', 'keep-it-peace' ); ?></label></td>
		</tr>
		<?php
		foreach ( $this->plugin->channels()->all() as $channel ) {
			$channel->render_settings( $settings );
		}
		$this->form_end( 'notifications', $settings );
		?>
		<h2><?php esc_html_e( 'Test', 'keep-it-peace' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Save your settings before sending a test.', 'keep-it-peace' ); ?></p>
		<?php foreach ( $this->plugin->channels()->all() as $id => $channel ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="action" value="keepitpeace_test_notification">
				<input type="hidden" name="channel" value="<?php echo esc_attr( $id ); ?>">
				<?php wp_nonce_field( 'keepitpeace_test_notification' ); ?>
				<?php
				/* translators: %s: channel name. */
				submit_button( sprintf( __( 'Send test (%s)', 'keep-it-peace' ), $channel->get_name() ), 'secondary', 'submit', false );
				?>
			</form>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * External services tab.
	 *
	 * @param Settings $settings Settings.
	 */
	private function tab_remote( Settings $settings ): void {
		$services = array(
			'endoflife' => array( 'endoflife.date', __( 'Support periods of PHP, MySQL and MariaDB. Sends: product name only.', 'keep-it-peace' ) ),
			'phpnet'    => array( 'php.net', __( 'Latest PHP patch release. Sends: PHP release series (e.g. 8.2).', 'keep-it-peace' ) ),
			'nvd'       => array( 'NVD (nvd.nist.gov)', __( 'Known vulnerabilities of your PHP version. Sends: PHP version (e.g. 8.2.10).', 'keep-it-peace' ) ),
			'osv'       => array( 'OSV.dev', __( 'Known vulnerabilities of Composer and npm packages. Sends: package names and versions.', 'keep-it-peace' ) ),
		);
		$this->form_start( 'remote' );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'External APIs', 'keep-it-peace' ); ?></th>
			<td>
				<label><input type="checkbox" name="keepitpeace[remote][enabled]" value="1" <?php checked( (bool) $settings->get( 'remote.enabled' ) ); ?>> <?php esc_html_e( 'Allow requests to the services below', 'keep-it-peace' ); ?></label>
				<p class="description"><?php esc_html_e( 'Your site URL, file contents and personal data are never sent. Results are cached. When disabled, a bundled snapshot is used for support periods.', 'keep-it-peace' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Services', 'keep-it-peace' ); ?></th>
			<td>
				<?php foreach ( $services as $key => $service ) : ?>
					<label class="kip-check-option">
						<input type="checkbox" name="keepitpeace[remote][services][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( (bool) $settings->get( 'remote.services.' . $key, true ) ); ?>>
						<strong><?php echo esc_html( $service[0] ); ?></strong><br><span class="description"><?php echo esc_html( $service[1] ); ?></span>
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
		$this->form_end( 'remote', $settings );
	}

	/**
	 * Tools tab: export, cron instructions, status.
	 *
	 * @param Settings $settings Settings.
	 */
	private function tab_tools( Settings $settings ): void {
		$health = CronHealth::status( $settings );
		$latest = $this->plugin->scans()->latest_completed();
		?>
		<p class="description">
			<?php esc_html_e( 'File baseline approval has moved to the General tab.', 'keep-it-peace' ); ?>
			<a href="<?php echo esc_url( BaselinePanel::section_url() ); ?>"><?php esc_html_e( 'Go to the General tab', 'keep-it-peace' ); ?></a>
		</p>
		<h2><?php esc_html_e( 'Export', 'keep-it-peace' ); ?></h2>
		<p><?php esc_html_e( 'Download the results of the last completed scan as JSON (the format is documented for add-ons and external tools). The file contains file paths and URLs of this site; store it safely.', 'keep-it-peace' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="keepitpeace_export">
			<?php wp_nonce_field( 'keepitpeace_export' ); ?>
			<?php submit_button( __( 'Download JSON report', 'keep-it-peace' ), 'secondary', 'submit', false, $latest ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Running scans from the server cron', 'keep-it-peace' ); ?></h2>
		<p><?php esc_html_e( 'WP-Cron only runs when someone visits the site. For reliable monitoring, run the scan from the server cron with WP-CLI:', 'keep-it-peace' ); ?></p>
		<pre class="kip-pre">0 3 * * * cd <?php echo esc_html( untrailingslashit( ABSPATH ) ); ?> &amp;&amp; wp keep-it-peace scan --quiet</pre>
		<p><?php esc_html_e( 'If WP-CLI is not available (some shared hosts), call wp-cron.php instead:', 'keep-it-peace' ); ?></p>
		<pre class="kip-pre">*/15 * * * * curl -s <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt; /dev/null</pre>
		<p class="description"><?php esc_html_e( "On Xserver, add the command in Server Panel → Cron settings. Use the full path to PHP or WP-CLI shown in the panel's help. When you use a server cron, you can add define( 'DISABLE_WP_CRON', true ); to wp-config.php.", 'keep-it-peace' ); ?></p>

		<h2><?php esc_html_e( 'Status', 'keep-it-peace' ); ?></h2>
		<table class="kip-kv">
			<tr><th><?php esc_html_e( 'Last completed scan', 'keep-it-peace' ); ?></th><td><?php echo esc_html( Ui::timestamp( $health['last_completed'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Next scheduled scan', 'keep-it-peace' ); ?></th><td><?php echo esc_html( Ui::timestamp( $health['next_run'] ) ); ?></td></tr>
			<tr><th>DISABLE_WP_CRON</th><td><?php echo esc_html( $health['wp_cron_disabled'] ? 'true' : 'false' ); ?></td></tr>
			<tr><th>open_basedir</th><td class="kip-mono"><?php echo esc_html( '' !== (string) ini_get( 'open_basedir' ) ? (string) ini_get( 'open_basedir' ) : '—' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Site ID', 'keep-it-peace' ); ?></th><td class="kip-mono"><?php echo esc_html( (string) get_option( 'keepitpeace_site_uuid' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Rule set', 'keep-it-peace' ); ?></th><td class="kip-mono"><?php echo esc_html( $this->plugin->rules()->get_version() ); ?></td></tr>
		</table>
		<?php
		/** This action is documented in SettingsPage::form_end(). */
		do_action( 'keepitpeace_settings_section_tools', $settings );
	}

	/**
	 * Sidebar with the (dismissible) Pro notice.
	 */
	private function render_sidebar(): void {
		$show = ! get_user_meta( get_current_user_id(), 'keepitpeace_hide_pro_notice', true );

		/**
		 * Filters whether the Pro notice is shown on the settings screen.
		 *
		 * @param bool $show Show.
		 */
		if ( ! apply_filters( 'keepitpeace_show_pro_notice', $show ) ) {
			return;
		}
		?>
		<aside class="kip-settings-side">
			<div class="kip-card kip-pro">
				<h2><?php esc_html_e( 'Keep It Peace Pro', 'keep-it-peace' ); ?></h2>
				<p><?php esc_html_e( 'Monitor many sites from one dashboard, get alerts when a site stops reporting, send monthly PDF reports to clients and use Slack or Chatwork notifications.', 'keep-it-peace' ); ?></p>
				<p><a href="<?php echo esc_url( Admin::PRO_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'keep-it-peace' ); ?></a></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="keepitpeace_dismiss_pro">
					<?php wp_nonce_field( 'keepitpeace_dismiss_pro' ); ?>
					<button type="submit" class="button-link"><?php esc_html_e( 'Hide this', 'keep-it-peace' ); ?></button>
				</form>
			</div>
		</aside>
		<?php
	}
}
