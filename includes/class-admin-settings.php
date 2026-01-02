<?php
/**
 * Admin Settings class for MasterSpa WP Plugin
 *
 * Handles admin settings page and configuration
 *
 * @package MasterSpaWpPlugin
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MasterSpa Admin Settings class
 */
class MasterSpa_Admin_Settings {
	
	/**
	 * Single instance of the class
	 *
	 * @var MasterSpa_Admin_Settings
	 */
	private static $instance = null;
	
	/**
	 * Get single instance
	 *
	 * @return MasterSpa_Admin_Settings
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	/**
	 * Constructor
	 */
	private function __construct() {
		// Add settings page
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		
		// Register settings
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		
		// Handle manual import action
		add_action( 'admin_post_masterspa_run_import', array( $this, 'handle_manual_import' ) );
		
		// Handle clear logs action
		add_action( 'admin_post_masterspa_clear_logs', array( $this, 'handle_clear_logs' ) );
		
		// Enqueue admin scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		
		// Add settings link to plugins page
		add_filter( 'plugin_action_links_' . MASTERSPA_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
	}
	
	/**
	 * Add settings page to admin menu
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'MasterSpa Settings', 'masterspa-wp-plugin' ),
			__( 'MasterSpa Settings', 'masterspa-wp-plugin' ),
			'manage_woocommerce',
			'masterspa-settings',
			array( $this, 'render_settings_page' )
		);
	}
	
	/**
	 * Register plugin settings
	 */
	public function register_settings() {
		register_setting(
			'masterspa_settings_group',
			'masterspa_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}
	
	/**
	 * Sanitize settings
	 *
	 * @param array $settings Settings to sanitize
	 * @return array Sanitized settings
	 */
	public function sanitize_settings( $settings ) {
		$sanitized = array();
		
		// API Endpoint
		$sanitized['api_endpoint'] = ! empty( $settings['api_endpoint'] ) ? esc_url_raw( $settings['api_endpoint'] ) : 'http://localhost:8082/api/genprod/spa/only';
		
		// Request Method
		$sanitized['request_method'] = ! empty( $settings['request_method'] ) && 'POST' === $settings['request_method'] ? 'POST' : 'GET';
		
		// Authorization Header
		$sanitized['auth_header'] = ! empty( $settings['auth_header'] ) ? sanitize_text_field( $settings['auth_header'] ) : '';
		
		// Timeout
		$sanitized['timeout'] = ! empty( $settings['timeout'] ) ? absint( $settings['timeout'] ) : 30;
		if ( $sanitized['timeout'] < 5 ) {
			$sanitized['timeout'] = 5;
		}
		if ( $sanitized['timeout'] > 300 ) {
			$sanitized['timeout'] = 300;
		}
		
		// Batch Size
		$sanitized['batch_size'] = ! empty( $settings['batch_size'] ) ? absint( $settings['batch_size'] ) : 100;
		
		// Import Mode
		$sanitized['import_mode'] = ! empty( $settings['import_mode'] ) && 'update_only' === $settings['import_mode'] ? 'update_only' : 'create_update';
		
		// Delete Missing
		$sanitized['delete_missing'] = ! empty( $settings['delete_missing'] );
		
		// Dry Run
		$sanitized['dry_run'] = ! empty( $settings['dry_run'] );
		
		// Product Status
		$allowed_statuses = array( 'publish', 'draft', 'pending' );
		$sanitized['product_status'] = ! empty( $settings['product_status'] ) && in_array( $settings['product_status'], $allowed_statuses, true ) ? $settings['product_status'] : 'publish';
		
		// Cron settings
		$old_settings = get_option( 'masterspa_settings', array() );
		$old_cron_enabled = ! empty( $old_settings['cron_enabled'] );
		$new_cron_enabled = ! empty( $settings['cron_enabled'] );
		
		$sanitized['cron_enabled'] = $new_cron_enabled;
		$sanitized['cron_frequency'] = ! empty( $settings['cron_frequency'] ) ? sanitize_text_field( $settings['cron_frequency'] ) : 'daily';
		
		// Handle cron scheduling changes
		if ( $old_cron_enabled !== $new_cron_enabled ) {
			if ( $new_cron_enabled ) {
				$this->schedule_cron( $sanitized['cron_frequency'] );
			} else {
				$this->unschedule_cron();
			}
		} elseif ( $new_cron_enabled && ! empty( $old_settings['cron_frequency'] ) && $old_settings['cron_frequency'] !== $sanitized['cron_frequency'] ) {
			// Frequency changed, reschedule
			$this->unschedule_cron();
			$this->schedule_cron( $sanitized['cron_frequency'] );
		}
		
		// Webhook URL for order completed
		$sanitized['order_completed_webhook_url'] = ! empty( $settings['order_completed_webhook_url'] ) ? esc_url_raw( $settings['order_completed_webhook_url'] ) : '';

		return $sanitized;
	}
	
	/**
	 * Schedule cron event
	 *
	 * @param string $frequency Cron frequency
	 */
	private function schedule_cron( $frequency ) {
		// Clear any existing scheduled event
		$this->unschedule_cron();
		
		// Get next scheduled time based on frequency
		$schedules = wp_get_schedules();
		
		if ( isset( $schedules[ $frequency ] ) ) {
			wp_schedule_event( time(), $frequency, 'masterspa_scheduled_import' );
		} else {
			// Default to daily if frequency not found
			wp_schedule_event( time(), 'daily', 'masterspa_scheduled_import' );
		}
	}
	
	/**
	 * Unschedule cron event
	 */
	private function unschedule_cron() {
		$timestamp = wp_next_scheduled( 'masterspa_scheduled_import' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'masterspa_scheduled_import' );
		}
		wp_clear_scheduled_hook( 'masterspa_scheduled_import' );
	}
	
	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nu ai permisiunea de a accesa această pagină.', 'masterspa-wp-plugin' ) );
		}
		
		$settings = get_option( 'masterspa_settings', array() );
		$last_import = get_option( 'masterspa_last_import', array() );
		
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MasterSpa  Settings', 'masterspa-wp-plugin' ); ?></h1>
			
			<?php settings_errors(); ?>
			
			<div style="display: flex; gap: 20px;">
				<!-- Settings Form -->
				<div style="flex: 2;">
					<form method="post" action="options.php">
						<?php settings_fields( 'masterspa_settings_group' ); ?>
						
						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="api_endpoint"><?php esc_html_e( 'API Endpoint', 'masterspa-wp-plugin' ); ?></label>
								</th>
								<td>
									<input type="url" name="masterspa_settings[api_endpoint]" id="api_endpoint" value="<?php echo esc_attr( ! empty( $settings['api_endpoint'] ) ? $settings['api_endpoint'] : 'http://localhost:8082/api/genprod/spa/only' ); ?>" class="regular-text" required>
									<p class="description"><?php esc_html_e( 'URL-ul endpoint-ului API pentru import produse.', 'masterspa-wp-plugin' ); ?></p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="order_completed_webhook_url"><?php esc_html_e( 'Order Completed Webhook URL', 'masterspa-wp-plugin' ); ?></label>
								</th>
								<td>
									<input type="url" name="masterspa_settings[order_completed_webhook_url]" id="order_completed_webhook_url" value="<?php echo esc_attr( ! empty( $settings['order_completed_webhook_url'] ) ? $settings['order_completed_webhook_url'] : '' ); ?>" class="regular-text">
									<p class="description"><?php esc_html_e( 'URL la care se va trimite payload-ul JSON când o comandă spa este procesată (opțional).', 'masterspa-wp-plugin' ); ?></p>
								</td>
							</tr>
							
							<tr>
								<th scope="row">
									<label for="request_method"><?php esc_html_e( 'Request Method', 'masterspa-wp-plugin' ); ?></label>
								</th>
								<td>
									<select name="masterspa_settings[request_method]" id="request_method">
										<option value="GET" <?php selected( ! empty( $settings['request_method'] ) ? $settings['request_method'] : 'GET', 'GET' ); ?>>GET</option>
										<option value="POST" <?php selected( ! empty( $settings['request_method'] ) ? $settings['request_method'] : 'GET', 'POST' ); ?>>POST</option>
									</select>
								</td>
							</tr>
							
							<tr>
								<th scope="row">
									<label for="auth_header"><?php esc_html_e( 'Authorization Header', 'masterspa-wp-plugin' ); ?></label>
								</th>
								<td>
									<input type="text" name="masterspa_settings[auth_header]" id="auth_header" value="<?php echo esc_attr( ! empty( $settings['auth_header'] ) ? $settings['auth_header'] : '' ); ?>" class="regular-text" placeholder="Bearer your-token-here">
									<p class="description"><?php esc_html_e( 'Opțional: Header Authorization pentru API (ex: Bearer token).', 'masterspa-wp-plugin' ); ?></p>
								</td>
							</tr>
							
							<tr>
								<th scope="row">
									<label for="timeout"><?php esc_html_e( 'Timeout (seconds)', 'masterspa-wp-plugin' ); ?></label>
								</th>
								<td>
									<input type="number" name="masterspa_settings[timeout]" id="timeout" value="<?php echo esc_attr( ! empty( $settings['timeout'] ) ? $settings['timeout'] : 30 ); ?>" min="5" max="300" class="small-text">
									<p class="description"><?php esc_html_e( 'Timpul maxim de așteptare pentru răspunsul API (5-300 secunde).', 'masterspa-wp-plugin' ); ?></p>
								</td>
							</tr>
							
							<tr>
								<th scope="row">
									<label for="batch_size"><?php esc_html_e( 'Batch Size', 'masterspa-wp-plugin' ); ?></label>
								</th>
								<td>
									<input type="number" name="masterspa_settings[batch_size]" id="batch_size" value="<?php echo esc_attr( ! empty( $settings['batch_size'] ) ? $settings['batch_size'] : 100 ); ?>" min="1" class="small-text">
									<p class="description"><?php esc_html_e( 'Numărul de produse procesate per batch (dacă API-ul suportă).', 'masterspa-wp-plugin' ); ?></p>
								</td>
							</tr>
							
							<tr>
								<th scope="row">
									<label for="import_mode"><?php esc_html_e( 'Import Mode', 'masterspa-wp-plugin' ); ?></label>
								</th>
								<td>
									<select name="masterspa_settings[import_mode]" id="import_mode">
										<option value="create_update" <?php selected( ! empty( $settings['import_mode'] ) ? $settings['import_mode'] : 'create_update', 'create_update' ); ?>><?php esc_html_e( 'Create + Update', 'masterspa-wp-plugin' ); ?></option>
										<option value="update_only" <?php selected( ! empty( $settings['import_mode'] ) ? $settings['import_mode'] : 'create_update', 'update_only' ); ?>><?php esc_html_e( 'Update Only', 'masterspa-wp-plugin' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Create + Update: creează produse noi și actualizează cele existente. Update Only: actualizează doar produsele existente.', 'masterspa-wp-plugin' ); ?></p>
								</td>
							</tr>
							
							<tr>
								<th scope="row">
									<label for="product_status"><?php esc_html_e( 'Product Status', 'masterspa-wp-plugin' ); ?></label>
								</th>
								<td>
									<select name="masterspa_settings[product_status]" id="product_status">
										<option value="publish" <?php selected( ! empty( $settings['product_status'] ) ? $settings['product_status'] : 'publish', 'publish' ); ?>><?php esc_html_e( 'Published', 'masterspa-wp-plugin' ); ?></option>
										<option value="draft" <?php selected( ! empty( $settings['product_status'] ) ? $settings['product_status'] : 'publish', 'draft' ); ?>><?php esc_html_e( 'Draft', 'masterspa-wp-plugin' ); ?></option>
										<option value="pending" <?php selected( ! empty( $settings['product_status'] ) ? $settings['product_status'] : 'publish', 'pending' ); ?>><?php esc_html_e( 'Pending', 'masterspa-wp-plugin' ); ?></option>
									</select>
									<p class="description"><?php esc_html_e( 'Statusul produselor importate.', 'masterspa-wp-plugin' ); ?></p>
								</td>
							</tr>
							
							<tr>
								<th scope="row"><?php esc_html_e( 'Options', 'masterspa-wp-plugin' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="masterspa_settings[delete_missing]" value="1" <?php checked( ! empty( $settings['delete_missing'] ) ); ?>>
										<?php esc_html_e( 'Șterge produsele care nu mai sunt în feed', 'masterspa-wp-plugin' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Atenție: Produsele care au SKU-uri MSPA-* dar nu sunt în API vor fi șterse.', 'masterspa-wp-plugin' ); ?></p>
									
									<label>
										<input type="checkbox" name="masterspa_settings[dry_run]" value="1" <?php checked( ! empty( $settings['dry_run'] ) ); ?>>
										<?php esc_html_e( 'Dry Run (simulează import fără a salva)', 'masterspa-wp-plugin' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'Testează importul fără a modifica baza de date.', 'masterspa-wp-plugin' ); ?></p>
								</td>
							</tr>
							
							<tr>
								<th scope="row"><?php esc_html_e( 'Automatic Import (Cron)', 'masterspa-wp-plugin' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="masterspa_settings[cron_enabled]" value="1" <?php checked( ! empty( $settings['cron_enabled'] ) ); ?>>
										<?php esc_html_e( 'Activează import automat', 'masterspa-wp-plugin' ); ?>
									</label>
									<br><br>
									
									<label for="cron_frequency"><?php esc_html_e( 'Frecvență:', 'masterspa-wp-plugin' ); ?></label>
									<select name="masterspa_settings[cron_frequency]" id="cron_frequency">
										<option value="hourly" <?php selected( ! empty( $settings['cron_frequency'] ) ? $settings['cron_frequency'] : 'daily', 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'masterspa-wp-plugin' ); ?></option>
										<option value="twicedaily" <?php selected( ! empty( $settings['cron_frequency'] ) ? $settings['cron_frequency'] : 'daily', 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'masterspa-wp-plugin' ); ?></option>
										<option value="daily" <?php selected( ! empty( $settings['cron_frequency'] ) ? $settings['cron_frequency'] : 'daily', 'daily' ); ?>><?php esc_html_e( 'Daily', 'masterspa-wp-plugin' ); ?></option>
									</select>
									
									<?php
									$next_cron = wp_next_scheduled( 'masterspa_scheduled_import' );
									if ( $next_cron ) :
										?>
										<p class="description">
											<?php
											printf(
												/* translators: %s: date and time */
												esc_html__( 'Next import: %s', 'masterspa-wp-plugin' ),
												esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_cron ) )
											);
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>
						
						<?php submit_button( __( 'Save Settings', 'masterspa-wp-plugin' ) ); ?>
					</form>
					
					<!-- Manual Import -->
					<hr>
					<h2><?php esc_html_e( 'Manual Import', 'masterspa-wp-plugin' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="masterspa_run_import">
						<?php wp_nonce_field( 'masterspa_run_import', 'masterspa_import_nonce' ); ?>
						<p>
							<?php submit_button( __( 'Rulează Import Acum', 'masterspa-wp-plugin' ), 'primary', 'submit', false ); ?>
						</p>
					</form>
				</div>
				
				<!-- Import Report Sidebar -->
				<div style="flex: 1;">
					<div class="card">
						<h2><?php esc_html_e( 'Last Import Report', 'masterspa-wp-plugin' ); ?></h2>
						
						<?php if ( ! empty( $last_import['timestamp'] ) ) : ?>
							<p>
								<strong><?php esc_html_e( 'Date:', 'masterspa-wp-plugin' ); ?></strong><br>
								<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_import['timestamp'] ) ) ); ?>
							</p>
							
							<?php if ( ! empty( $last_import['stats'] ) ) : ?>
								<p>
									<strong><?php esc_html_e( 'Statistics:', 'masterspa-wp-plugin' ); ?></strong><br>
									<?php esc_html_e( 'Created:', 'masterspa-wp-plugin' ); ?> <?php echo esc_html( $last_import['stats']['created'] ); ?><br>
									<?php esc_html_e( 'Updated:', 'masterspa-wp-plugin' ); ?> <?php echo esc_html( $last_import['stats']['updated'] ); ?><br>
									<?php esc_html_e( 'Errors:', 'masterspa-wp-plugin' ); ?> <?php echo esc_html( $last_import['stats']['errors'] ); ?><br>
									<?php esc_html_e( 'Total:', 'masterspa-wp-plugin' ); ?> <?php echo esc_html( $last_import['stats']['total'] ); ?>
								</p>
							<?php endif; ?>
						<?php else : ?>
							<p><?php esc_html_e( 'No import has been run yet.', 'masterspa-wp-plugin' ); ?></p>
						<?php endif; ?>
					</div>
					
					<div class="card" style="margin-top: 20px;">
						<h2><?php esc_html_e( 'Recent Logs', 'masterspa-wp-plugin' ); ?></h2>
						
						<?php
						$recent_logs = MasterSpa_Logger::get_recent_logs( 10 );
						
						if ( ! empty( $recent_logs ) ) :
							?>
							<table class="widefat" style="font-size: 12px;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Type', 'masterspa-wp-plugin' ); ?></th>
										<th><?php esc_html_e( 'Message', 'masterspa-wp-plugin' ); ?></th>
										<th><?php esc_html_e( 'SKU', 'masterspa-wp-plugin' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $recent_logs as $log ) : ?>
										<tr>
											<td>
												<span class="masterspa-log-type masterspa-log-<?php echo esc_attr( $log->log_type ); ?>">
													<?php echo esc_html( ucfirst( $log->log_type ) ); ?>
												</span>
											</td>
											<td><?php echo esc_html( wp_trim_words( $log->message, 10 ) ); ?></td>
											<td><?php echo esc_html( $log->sku ? $log->sku : '-' ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<p><?php esc_html_e( 'No logs available.', 'masterspa-wp-plugin' ); ?></p>
						<?php endif; ?>
						
						<p style="margin-top: 15px;">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
								<input type="hidden" name="action" value="masterspa_clear_logs">
								<?php wp_nonce_field( 'masterspa_clear_logs', 'masterspa_clear_logs_nonce' ); ?>
								<button type="submit" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Ești sigur că vrei să ștergi toate log-urile?', 'masterspa-wp-plugin' ); ?>');">
									<?php esc_html_e( 'Clear All Logs', 'masterspa-wp-plugin' ); ?>
								</button>
							</form>
						</p>
					</div>
				</div>
			</div>
		</div>
		
		<style>
			.masterspa-log-type {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: bold;
			}
			.masterspa-log-created { background: #d4edda; color: #155724; }
			.masterspa-log-updated { background: #d1ecf1; color: #0c5460; }
			.masterspa-log-error { background: #f8d7da; color: #721c24; }
			.masterspa-log-warning { background: #fff3cd; color: #856404; }
			.masterspa-log-info { background: #e7f3ff; color: #004085; }
		</style>
		<?php
	}
	
	/**
	 * Handle manual import action
	 */
	public function handle_manual_import() {
		// Check permissions
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nu ai permisiunea de a rula import.', 'masterspa-wp-plugin' ) );
		}
		
		// Verify nonce
		if ( ! isset( $_POST['masterspa_import_nonce'] ) || ! wp_verify_nonce( $_POST['masterspa_import_nonce'], 'masterspa_run_import' ) ) {
			wp_die( esc_html__( 'Securitate: Nonce invalid.', 'masterspa-wp-plugin' ) );
		}
		
		// Run import
		$importer = new MasterSpa_Importer();
		$result   = $importer->import();
		
		// Redirect back with message
		$redirect_url = add_query_arg(
			array(
				'page'              => 'masterspa-settings',
				'import_result'     => $result['success'] ? 'success' : 'error',
				'import_message'    => urlencode( $result['message'] ),
				'products_created'  => $result['stats']['created'],
				'products_updated'  => $result['stats']['updated'],
				'products_errors'   => $result['stats']['errors'],
			),
			admin_url( 'options-general.php' )
		);
		
		wp_safe_redirect( $redirect_url );
		exit;
	}
	
	/**
	 * Handle clear logs action
	 */
	public function handle_clear_logs() {
		// Check permissions
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nu ai permisiunea de a șterge log-uri.', 'masterspa-wp-plugin' ) );
		}
		
		// Verify nonce
		if ( ! isset( $_POST['masterspa_clear_logs_nonce'] ) || ! wp_verify_nonce( $_POST['masterspa_clear_logs_nonce'], 'masterspa_clear_logs' ) ) {
			wp_die( esc_html__( 'Securitate: Nonce invalid.', 'masterspa-wp-plugin' ) );
		}
		
		// Clear logs
		MasterSpa_Logger::clear_all_logs();
		
		// Redirect back
		wp_safe_redirect( add_query_arg( 'page', 'masterspa-settings', admin_url( 'options-general.php' ) ) );
		exit;
	}
	
	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'settings_page_masterspa-settings' !== $hook ) {
			return;
		}
		
		// Display import result message if present
		if ( isset( $_GET['import_result'] ) ) {
			$type    = 'success' === $_GET['import_result'] ? 'success' : 'error';
			$message = isset( $_GET['import_message'] ) ? urldecode( $_GET['import_message'] ) : '';
			
			if ( 'success' === $type && isset( $_GET['products_created'] ) ) {
				$message .= sprintf(
					' (Created: %d, Updated: %d, Errors: %d)',
					absint( $_GET['products_created'] ),
					absint( $_GET['products_updated'] ),
					absint( $_GET['products_errors'] )
				);
			}
			
			add_settings_error( 'masterspa_messages', 'masterspa_import', $message, $type );
		}
	}
	
	/**
	 * Add settings link to plugins page
	 *
	 * @param array $links Existing plugin action links
	 * @return array Modified plugin action links
	 */
	public function add_plugin_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=masterspa-settings' ) ) . '">' . __( 'Settings', 'masterspa-wp-plugin' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}
