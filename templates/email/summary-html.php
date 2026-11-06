<?php
/**
 * HTML email. Variables: $data (see EmailChannel::view_data()).
 *
 * @package KeepItPeace
 */

defined( 'ABSPATH' ) || exit;

$keepitpeace_colors = array(
	'critical' => '#b32d2e',
	'high'     => '#d63638',
	'medium'   => '#dba617',
	'low'      => '#2271b1',
	'info'     => '#646970',
);
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title><?php echo esc_html( $data['heading'] ); ?></title></head>
<body style="margin:0;padding:24px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Hiragino Sans','Noto Sans JP',sans-serif;color:#1d2327;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;">
	<tr><td style="padding:24px 28px 8px;">
		<h1 style="margin:0 0 8px;font-size:18px;"><?php echo esc_html( $data['heading'] ); ?></h1>
		<p style="margin:0;color:#50575e;font-size:13px;"><?php echo esc_html( $data['site_name'] ); ?> — <?php echo esc_html( $data['date'] ); ?></p>
	</td></tr>
	<tr><td style="padding:16px 28px;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
			<?php foreach ( $data['counts'] as $keepitpeace_row ) : ?>
			<tr>
				<td style="padding:6px 0;border-bottom:1px solid #f0f0f1;">
					<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $keepitpeace_colors[ $keepitpeace_row['slug'] ] ); ?>;margin-right:8px;"></span><?php echo esc_html( $keepitpeace_row['label'] ); ?>
				</td>
				<td style="padding:6px 0;border-bottom:1px solid #f0f0f1;text-align:right;font-weight:600;"><?php echo esc_html( number_format_i18n( $keepitpeace_row['count'] ) ); ?></td>
			</tr>
			<?php endforeach; ?>
		</table>
	</td></tr>
	<tr><td style="padding:0 28px 16px;font-size:14px;line-height:1.7;">
		<?php if ( ! $data['is_digest'] ) : ?>
			<div><strong><?php esc_html_e( 'Since the previous scan', 'keep-it-peace' ); ?>:</strong>
			<?php
			/* translators: 1: new count, 2: resolved count. */
			echo esc_html( sprintf( __( '%1$d new, %2$d resolved', 'keep-it-peace' ), $data['new'], $data['resolved'] ) );
			?>
			</div>
		<?php endif; ?>
		<?php if ( '' !== $data['php'] ) : ?>
			<div><strong>PHP:</strong> <?php echo esc_html( $data['php'] ); ?></div>
		<?php endif; ?>
		<?php if ( '' !== $data['database'] ) : ?>
			<div><strong><?php esc_html_e( 'Database', 'keep-it-peace' ); ?>:</strong> <?php echo esc_html( $data['database'] ); ?></div>
		<?php endif; ?>
		<?php if ( '' !== $data['tls'] ) : ?>
			<div><strong><?php esc_html_e( 'SSL certificate', 'keep-it-peace' ); ?>:</strong> <?php echo esc_html( $data['tls'] ); ?></div>
		<?php endif; ?>
	</td></tr>
	<tr><td style="padding:8px 28px 28px;">
		<a href="<?php echo esc_url( $data['admin_url'] ); ?>" style="display:inline-block;background:#2271b1;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:4px;font-size:14px;"><?php esc_html_e( 'Open the dashboard', 'keep-it-peace' ); ?></a>
		<p style="margin:16px 0 0;color:#646970;font-size:12px;"><?php esc_html_e( 'File names and code are not included in email for security reasons. Please check the details in the dashboard.', 'keep-it-peace' ); ?></p>
	</td></tr>
</table>
</body>
</html>
