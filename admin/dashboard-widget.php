<?php
/**
 * Dashboard widget: last import, running import, orders not synced with the MasterSpa server.
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_dashboard_setup',
	function () {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			wp_add_dashboard_widget( 'masterspa_status', __( 'MasterSpa', 'masterspa-wp-plugin' ), 'masterspa_render_dashboard_widget' );
		}
	}
);

/**
 * Number of orders with the given sync status.
 *
 * @param string $status failed|pending.
 * @return int
 */
function masterspa_count_orders_by_sync_status( $status ) {
	$ids = wc_get_orders(
		array(
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => array_keys( wc_get_order_statuses() ),
			// meta_key/meta_value: the posts order store ignores meta_query in wc_get_orders().
			'meta_key'   => MASTERSPA_SYNC_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => $status, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	return count( $ids );
}

/**
 * Render the widget.
 */
function masterspa_render_dashboard_widget() {
	$last     = get_option( 'masterspa_last_import', array() );
	$progress = MasterSpa_Importer::get_progress();
	$failed   = masterspa_count_orders_by_sync_status( 'failed' );
	$pending  = masterspa_count_orders_by_sync_status( 'pending' );
	$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	?>
	<h3 style="margin-top:0;"><?php esc_html_e( 'Import produse', 'masterspa-wp-plugin' ); ?></h3>
	<?php if ( $progress ) : ?>
		<p>
			<?php
			/* translators: 1: processed products, 2: total products */
			printf( esc_html__( 'Import în curs: %1$d / %2$d produse.', 'masterspa-wp-plugin' ), (int) $progress['processed'], (int) $progress['total'] );
			if ( $progress['stale'] ) {
				echo ' <strong style="color:#b32d2e;">' . esc_html__( 'Pare blocat.', 'masterspa-wp-plugin' ) . '</strong>';
			}
			?>
		</p>
	<?php endif; ?>
	<?php if ( ! empty( $last['timestamp'] ) ) : ?>
		<?php $stats = isset( $last['stats'] ) ? (array) $last['stats'] : array(); ?>
		<p>
			<?php
			/* translators: %s: date */
			printf( esc_html__( 'Ultimul import: %s', 'masterspa-wp-plugin' ), esc_html( date_i18n( $format, strtotime( $last['timestamp'] ) ) ) );
			?>
			<br>
			<?php
			printf(
				/* translators: 1: created, 2: updated, 3: unchanged, 4: errors */
				esc_html__( '%1$d create · %2$d actualizate · %3$d nemodificate · %4$d erori', 'masterspa-wp-plugin' ),
				isset( $stats['created'] ) ? (int) $stats['created'] : 0,
				isset( $stats['updated'] ) ? (int) $stats['updated'] : 0,
				isset( $stats['unchanged'] ) ? (int) $stats['unchanged'] : 0,
				isset( $stats['errors'] ) ? (int) $stats['errors'] : 0
			);
			?>
		</p>
	<?php else : ?>
		<p><?php esc_html_e( 'Nu a rulat încă niciun import.', 'masterspa-wp-plugin' ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Comenzi trimise către server', 'masterspa-wp-plugin' ); ?></h3>
	<p>
		<?php if ( $failed > 0 ) : ?>
			<a href="<?php echo esc_url( masterspa_orders_list_url( 'failed' ) ); ?>" style="color:#b32d2e; font-weight:600;">
				<?php
				/* translators: %d: number of orders */
				printf( esc_html( _n( '%d comandă nesincronizată', '%d comenzi nesincronizate', $failed, 'masterspa-wp-plugin' ) ), (int) $failed );
				?>
			</a>
		<?php else : ?>
			<?php esc_html_e( 'Toate comenzile sunt sincronizate.', 'masterspa-wp-plugin' ); ?>
		<?php endif; ?>
		<?php if ( $pending > 0 ) : ?>
			<br>
			<a href="<?php echo esc_url( masterspa_orders_list_url( 'pending' ) ); ?>">
				<?php
				/* translators: %d: number of orders */
				printf( esc_html( _n( '%d comandă în curs de trimitere', '%d comenzi în curs de trimitere', $pending, 'masterspa-wp-plugin' ) ), (int) $pending );
				?>
			</a>
		<?php endif; ?>
	</p>
	<p>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=masterspa-settings' ) ); ?>"><?php esc_html_e( 'Setări', 'masterspa-wp-plugin' ); ?></a>
		·
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=masterspa-logs' ) ); ?>"><?php esc_html_e( 'Log-uri', 'masterspa-wp-plugin' ); ?></a>
	</p>
	<?php
}
