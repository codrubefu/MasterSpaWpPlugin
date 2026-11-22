<?php
/**
 * Admin page: WooCommerce > MasterSpa Logs (unified import / order / HTTP log).
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			'woocommerce',
			__( 'MasterSpa Logs', 'masterspa-wp-plugin' ),
			__( 'MasterSpa Logs', 'masterspa-wp-plugin' ),
			'manage_woocommerce',
			'masterspa-logs',
			'masterspa_render_logs_page'
		);
	},
	60
);

/**
 * Render the logs page.
 */
function masterspa_render_logs_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Nu ai permisiunea de a accesa această pagină.', 'masterspa-wp-plugin' ) );
	}

	$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
	$type   = isset( $_GET['log_type'] ) ? sanitize_key( wp_unslash( $_GET['log_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$result = MasterSpa_Logger::get_logs_page( $paged, 20, $type );
	$types  = MasterSpa_Logger::get_log_types();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'MasterSpa Logs', 'masterspa-wp-plugin' ); ?></h1>

		<form method="get" style="margin:12px 0;">
			<input type="hidden" name="page" value="masterspa-logs">
			<label for="masterspa-log-type"><?php esc_html_e( 'Tip:', 'masterspa-wp-plugin' ); ?></label>
			<select name="log_type" id="masterspa-log-type">
				<option value=""><?php esc_html_e( 'Toate', 'masterspa-wp-plugin' ); ?></option>
				<?php foreach ( $types as $t ) : ?>
					<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $t ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filtrează', 'masterspa-wp-plugin' ), 'secondary', '', false ); ?>
			<span style="margin-left:12px;">
				<?php
				/* translators: %d: number of log entries */
				printf( esc_html__( '%d înregistrări', 'masterspa-wp-plugin' ), (int) $result['total'] );
				?>
			</span>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:60px;">ID</th>
					<th style="width:150px;"><?php esc_html_e( 'Data', 'masterspa-wp-plugin' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Tip', 'masterspa-wp-plugin' ); ?></th>
					<th style="width:140px;"><?php esc_html_e( 'SKU / Produs', 'masterspa-wp-plugin' ); ?></th>
					<th><?php esc_html_e( 'Mesaj', 'masterspa-wp-plugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( $result['logs'] ) : ?>
					<?php foreach ( $result['logs'] as $log ) : ?>
						<?php
						$message = (string) $log->message;
						$is_long = strlen( $message ) > 200 || false !== strpos( $message, "\n" );
						?>
						<tr>
							<td><?php echo esc_html( $log->id ); ?></td>
							<td><?php echo esc_html( $log->created_at ); ?></td>
							<td><?php echo esc_html( $log->log_type ); ?></td>
							<td>
								<?php echo esc_html( $log->sku ? $log->sku : '' ); ?>
								<?php if ( $log->product_id ) : ?>
									<br><a href="<?php echo esc_url( get_edit_post_link( (int) $log->product_id ) ); ?>">#<?php echo esc_html( $log->product_id ); ?></a>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $is_long ) : ?>
									<details>
										<summary><?php echo esc_html( wp_html_excerpt( $message, 120, '…' ) ); ?></summary>
										<pre style="white-space:pre-wrap; max-height:400px; overflow:auto;"><?php echo esc_html( $message ); ?></pre>
									</details>
								<?php else : ?>
									<?php echo esc_html( $message ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Nu există log-uri.', 'masterspa-wp-plugin' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $result['pages'] > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $result['pages'],
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
	</div>
	<?php
}
