<?php
/**
 * Email notification channel.
 *
 * @package KeepItPeace
 */

namespace KeepItPeace\Notifications;

use KeepItPeace\Contracts\NotificationChannelInterface;
use KeepItPeace\Findings\Severity;
use KeepItPeace\Plugin;
use KeepItPeace\Scan\ScanReport;
use KeepItPeace\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a summary with wp_mail(). No paths or code are included.
 */
class EmailChannel implements NotificationChannelInterface {

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
	 * ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'email';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Email', 'keep-it-peace' );
	}

	/**
	 * Enabled with at least one recipient.
	 *
	 * @param Settings $settings Settings.
	 * @return bool
	 */
	public function is_enabled( Settings $settings ): bool {
		return (bool) $settings->get( 'channels.email.enabled', true ) && ! empty( $this->recipients() );
	}

	/**
	 * Recipients.
	 *
	 * @return string[]
	 */
	public function recipients(): array {
		$recipients = array_values( array_filter( (array) $this->plugin->settings()->get( 'channels.email.recipients', array() ), 'is_email' ) );

		/**
		 * Filters email recipients.
		 *
		 * @param string[] $recipients Addresses.
		 */
		return array_values( array_filter( (array) apply_filters( 'keepitpeace_email_recipients', $recipients ), 'is_email' ) );
	}

	/**
	 * Sends a report.
	 *
	 * @param ScanReport $report Report.
	 * @return true|\WP_Error
	 */
	public function send( ScanReport $report ) {
		$data    = self::view_data( $report );
		$subject = self::subject( $report );
		$text    = $this->render( 'summary-text', $data );

		/**
		 * Filters the email subject.
		 *
		 * @param string     $subject Subject.
		 * @param ScanReport $report  Report.
		 */
		$subject = (string) apply_filters( 'keepitpeace_email_subject', $subject, $report );

		/**
		 * Filters the plain-text email body.
		 *
		 * @param string     $text   Body.
		 * @param ScanReport $report Report.
		 */
		$text = (string) apply_filters( 'keepitpeace_email_body_text', $text, $report );

		if ( 'text' === $this->plugin->settings()->get( 'channels.email.format', 'html' ) ) {
			return $this->mail( $subject, $text, null );
		}

		/**
		 * Filters the HTML email body.
		 *
		 * @param string     $html   Body.
		 * @param ScanReport $report Report.
		 */
		$html = (string) apply_filters( 'keepitpeace_email_body_html', $this->render( 'summary-html', $data ), $report );
		return $this->mail( $subject, $text, $html );
	}

	/**
	 * Sends a test email.
	 *
	 * @return true|\WP_Error
	 */
	public function send_test() {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name. */
		$subject = sprintf( __( '[Test] %s Keep It Peace notification', 'keep-it-peace' ), $site );
		$body    = __( 'This is a test message from Keep It Peace. Notifications are configured correctly.', 'keep-it-peace' ) . "\n\n" . admin_url( 'admin.php?page=keep-it-peace' ) . "\n";
		return $this->mail( $subject, $body, null );
	}

	/**
	 * Sends with wp_mail, optionally as multipart/alternative.
	 *
	 * @param string      $subject Subject.
	 * @param string      $text    Text body.
	 * @param string|null $html    HTML body.
	 * @return true|\WP_Error
	 */
	private function mail( string $subject, string $text, ?string $html ) {
		$recipients = $this->recipients();
		if ( empty( $recipients ) ) {
			return new \WP_Error( 'keepitpeace_no_recipients', __( 'No valid email recipients are configured.', 'keep-it-peace' ) );
		}

		$error_message = '';
		$on_error      = static function ( $error ) use ( &$error_message ) {
			if ( is_wp_error( $error ) ) {
				$error_message = $error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $on_error );

		$alt_body = null;
		$headers  = array();
		if ( null !== $html ) {
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
			$alt_body  = static function ( $phpmailer ) use ( $text ) {
				$phpmailer->AltBody = $text; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			};
			add_action( 'phpmailer_init', $alt_body );
		}

		$sent = wp_mail( $recipients, $subject, null !== $html ? $html : $text, $headers );

		remove_action( 'wp_mail_failed', $on_error );
		if ( $alt_body ) {
			remove_action( 'phpmailer_init', $alt_body );
		}
		if ( $sent ) {
			return true;
		}
		return new \WP_Error( 'keepitpeace_mail_failed', '' !== $error_message ? $error_message : __( 'wp_mail() could not send the message.', 'keep-it-peace' ) );
	}

	/**
	 * Subject with a status prefix.
	 *
	 * @param ScanReport $report Report.
	 * @return string
	 */
	public static function subject( ScanReport $report ): string {
		$max = $report->max_severity();
		if ( $max >= Severity::HIGH ) {
			$status = __( '[Action required]', 'keep-it-peace' );
		} elseif ( $max >= Severity::LOW ) {
			$status = __( '[Attention]', 'keep-it-peace' );
		} else {
			$status = __( '[All clear]', 'keep-it-peace' );
		}
		if ( $report->is_digest ) {
			/* translators: 1: status prefix, 2: site name. */
			return sprintf( __( '%1$s %2$s weekly security digest', 'keep-it-peace' ), $status, $report->site_name );
		}
		/* translators: 1: status prefix, 2: site name. */
		return sprintf( __( '%1$s %2$s security check results', 'keep-it-peace' ), $status, $report->site_name );
	}

	/**
	 * Values for the templates (already plain strings, not escaped).
	 *
	 * @param ScanReport $report Report.
	 * @return array
	 */
	public static function view_data( ScanReport $report ): array {
		$finished = $report->scan->finished_at ? strtotime( $report->scan->finished_at . ' UTC' ) : time();
		$counts   = array();
		foreach ( Severity::all() as $level ) {
			$counts[] = array(
				'label' => Severity::label( $level ),
				'slug'  => Severity::slug( $level ),
				'count' => (int) ( $report->by_severity[ Severity::slug( $level ) ] ?? 0 ),
			);
		}

		$php_line = '';
		if ( is_array( $report->php ) && ! empty( $report->php['version'] ) ) {
			$php      = $report->php;
			$php_line = $php['version'];
			if ( ! empty( $php['eol'] ) ) {
				$php_line .= ' — ' . __( 'end of life (no security updates)', 'keep-it-peace' );
			} elseif ( ! empty( $php['eol_date'] ) ) {
				/* translators: %s: date. */
				$php_line .= ' — ' . sprintf( __( 'security support until %s', 'keep-it-peace' ), $php['eol_date'] );
			}
			if ( ! empty( $php['behind'] ) && ! empty( $php['latest_patch'] ) ) {
				/* translators: %s: version. */
				$php_line .= ' / ' . sprintf( __( 'update available: %s', 'keep-it-peace' ), $php['latest_patch'] );
			}
			if ( ! empty( $php['cve_count'] ) ) {
				/* translators: %d: number of CVEs. */
				$php_line .= ' / ' . sprintf( _n( '%d known vulnerability', '%d known vulnerabilities', (int) $php['cve_count'], 'keep-it-peace' ), (int) $php['cve_count'] );
			}
		}

		$db_line = '';
		if ( is_array( $report->database ) && ! empty( $report->database['version'] ) ) {
			$db_line = ( $report->database['label'] ?? '' ) . ' ' . $report->database['version'];
			if ( ! empty( $report->database['eol'] ) ) {
				$db_line .= ' — ' . __( 'end of life', 'keep-it-peace' );
			}
		}

		$tls_line = '';
		if ( is_array( $report->tls ) && isset( $report->tls['days_remaining'] ) ) {
			/* translators: %d: days. */
			$tls_line = sprintf( _n( '%d day left', '%d days left', (int) $report->tls['days_remaining'], 'keep-it-peace' ), (int) $report->tls['days_remaining'] );
		}

		return array(
			'heading'   => self::subject( $report ),
			'site_name' => $report->site_name,
			'site_url'  => $report->site_url,
			'date'      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $finished ),
			'counts'    => $counts,
			'new'       => (int) $report->diff['new'],
			'resolved'  => (int) $report->diff['resolved'],
			'php'       => $php_line,
			'database'  => $db_line,
			'tls'       => $tls_line,
			'admin_url' => $report->admin_url,
			'is_digest' => $report->is_digest,
			'issues'    => $report->total_issues(),
		);
	}

	/**
	 * Renders a template.
	 *
	 * @param string $template Name.
	 * @param array  $data     Data.
	 * @return string
	 */
	private function render( string $template, array $data ): string {
		ob_start();
		include KEEPITPEACE_DIR . 'templates/email/' . $template . '.php';
		return (string) ob_get_clean();
	}

	/**
	 * Settings fields.
	 *
	 * @param Settings $settings Settings.
	 */
	public function render_settings( Settings $settings ): void {
		$email = (array) $settings->get( 'channels.email', array() );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Email', 'keep-it-peace' ); ?></th>
			<td>
				<label><input type="checkbox" name="keepitpeace[channels][email][enabled]" value="1" <?php checked( ! empty( $email['enabled'] ) ); ?>> <?php esc_html_e( 'Send email notifications', 'keep-it-peace' ); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="keepitpeace-email-recipients"><?php esc_html_e( 'Recipients', 'keep-it-peace' ); ?></label></th>
			<td>
				<textarea id="keepitpeace-email-recipients" name="keepitpeace[channels][email][recipients]" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) ( $email['recipients'] ?? array() ) ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One address per line.', 'keep-it-peace' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="keepitpeace-email-format"><?php esc_html_e( 'Format', 'keep-it-peace' ); ?></label></th>
			<td>
				<select id="keepitpeace-email-format" name="keepitpeace[channels][email][format]">
					<option value="html" <?php selected( $email['format'] ?? 'html', 'html' ); ?>><?php esc_html_e( 'HTML (with plain-text alternative)', 'keep-it-peace' ); ?></option>
					<option value="text" <?php selected( $email['format'] ?? 'html', 'text' ); ?>><?php esc_html_e( 'Plain text', 'keep-it-peace' ); ?></option>
				</select>
			</td>
		</tr>
		<?php
	}

	/**
	 * Sanitizes settings.
	 *
	 * @param array $input   Input.
	 * @param array $current Current.
	 * @return array
	 */
	public function sanitize_settings( array $input, array $current ): array {
		$raw        = isset( $input['recipients'] ) ? (string) $input['recipients'] : '';
		$recipients = array();
		foreach ( preg_split( '/[\s,;]+/', $raw ) as $address ) {
			$address = sanitize_email( $address );
			if ( '' !== $address && is_email( $address ) ) {
				$recipients[] = $address;
			}
		}
		return array(
			'enabled'    => ! empty( $input['enabled'] ),
			'recipients' => array_values( array_unique( $recipients ) ),
			'format'     => isset( $input['format'] ) && 'text' === $input['format'] ? 'text' : 'html',
		);
	}
}
