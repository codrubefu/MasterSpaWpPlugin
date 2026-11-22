<?php
/**
 * Plugin Name: MasterSpa WP Plugin
 * Description: Importă pachetele spa din API-ul MasterSpa în WooCommerce și trimite comenzile spa către serverul MasterSpa.
 * Version: 1.2.0
 * Author: MasterSpa Team
 * Text Domain: masterspa-wp-plugin
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.0
 * WC requires at least: 7.0
 * WC tested up to: 10.4
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MASTERSPA_VERSION', '1.2.0' );
define( 'MASTERSPA_DB_VERSION', 3 );
define( 'MASTERSPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MASTERSPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MASTERSPA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'MASTERSPA_PLUGIN_FILE', __FILE__ );
// Action Scheduler group used for every background job of the plugin.
define( 'MASTERSPA_AS_GROUP', 'masterspa' );

/**
 * Main plugin class
 */
class MasterSpa_WP_Plugin {

	/**
	 * Single instance of the class
	 *
	 * @var MasterSpa_WP_Plugin
	 */
	private static $instance = null;

	/**
	 * Get single instance
	 *
	 * @return MasterSpa_WP_Plugin
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
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_wc_compatibility' ) );

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * Declare compatibility with HPOS (custom order tables) and the Cart/Checkout blocks.
	 */
	public function declare_wc_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		$this->load_dependencies();
		$this->init_components();

		load_plugin_textdomain( 'masterspa-wp-plugin', false, dirname( MASTERSPA_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Display admin notice if WooCommerce is not active
	 */
	public function woocommerce_missing_notice() {
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'MasterSpa WP Plugin', 'masterspa-wp-plugin' ); ?>:</strong>
				<?php esc_html_e( 'Acest plugin necesită WooCommerce pentru a funcționa. Te rugăm să instalezi și activezi WooCommerce.', 'masterspa-wp-plugin' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Load plugin dependencies
	 */
	private function load_dependencies() {
		// Core.
		require_once MASTERSPA_PLUGIN_DIR . 'includes/class-logger.php';
		require_once MASTERSPA_PLUGIN_DIR . 'includes/MasterSpaLogHelper.php';
		require_once MASTERSPA_PLUGIN_DIR . 'includes/MasterSpaCurlHelper.php';
		require_once MASTERSPA_PLUGIN_DIR . 'includes/class-importer.php';
		require_once MASTERSPA_PLUGIN_DIR . 'includes/class-admin-settings.php';

		// Frontend + checkout.
		require_once MASTERSPA_PLUGIN_DIR . 'includes/frontend-product.php';
		require_once MASTERSPA_PLUGIN_DIR . 'includes/web-sale.php';
		require_once MASTERSPA_PLUGIN_DIR . 'includes/class-storefront.php';
		require_once MASTERSPA_PLUGIN_DIR . 'includes/checkout-fields.php';

		// Orders sent to the MasterSpa server.
		require_once MASTERSPA_PLUGIN_DIR . 'finaliseOrder/finalise.php';

		// Admin pages.
		if ( is_admin() ) {
			require_once MASTERSPA_PLUGIN_DIR . 'admin/logs-list-page.php';
			require_once MASTERSPA_PLUGIN_DIR . 'admin/order-sync-admin.php';
			require_once MASTERSPA_PLUGIN_DIR . 'admin/dashboard-widget.php';
		}

		// Beneficiary (subscriber) editing in cart — optional, off by default.
		$settings = get_option( 'masterspa_settings', array() );
		if ( ! empty( $settings['cart_beneficiary_enabled'] ) ) {
			require_once MASTERSPA_PLUGIN_DIR . 'custom-cart/subscribers-common.php';
			require_once MASTERSPA_PLUGIN_DIR . 'custom-cart/subscription-users-cart.php';
			require_once MASTERSPA_PLUGIN_DIR . 'custom-cart/cart-block-integration.php';
			require_once MASTERSPA_PLUGIN_DIR . 'custom-cart/subscriber-validation.php';
		}
	}

	/**
	 * Initialize plugin components
	 */
	private function init_components() {
		if ( is_admin() ) {
			MasterSpa_Admin_Settings::get_instance();
		}

		// Storefront design (shop + single product templates).
		MasterSpa_Storefront::get_instance();

		// Scheduled import (WP-Cron) starts a batched import; batches run in Action Scheduler.
		add_action( 'masterspa_scheduled_import', array( $this, 'run_scheduled_import' ) );
		add_action( MasterSpa_Importer::BATCH_HOOK, array( $this, 'run_import_batch' ) );

		// Daily log cleanup (retention from the settings).
		add_action( 'masterspa_cleanup_logs', array( $this, 'cleanup_logs' ) );

		// Products imported with webSale = 0 cannot be bought online.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_web_sale_purchasable' ), 10, 2 );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		// DB/schedule upgrades run once Action Scheduler is ready.
		add_action( 'init', array( $this, 'maybe_upgrade' ), 20 );
	}

	/**
	 * Mark products imported with webSale = 0 as not purchasable
	 *
	 * @param bool       $purchasable Current purchasable state
	 * @param WC_Product $product     Product
	 * @return bool
	 */
	public function filter_web_sale_purchasable( $purchasable, $product ) {
		if ( ! $purchasable || ! $product ) {
			return $purchasable;
		}
		$product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		if ( 'no' === get_post_meta( $product_id, '_masterspa_web_sale', true ) ) {
			return false;
		}
		return $purchasable;
	}

	/**
	 * Cart/subscriber styles, only on WooCommerce pages.
	 */
	public function enqueue_styles() {
		$is_wc_page = ( function_exists( 'is_woocommerce' ) && is_woocommerce() )
			|| ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() );
		if ( ! $is_wc_page ) {
			return;
		}
		$file = MASTERSPA_PLUGIN_DIR . 'assets/css/style.css';
		wp_enqueue_style(
			'masterspa-style',
			MASTERSPA_PLUGIN_URL . 'assets/css/style.css',
			array(),
			file_exists( $file ) ? (string) filemtime( $file ) : MASTERSPA_VERSION
		);
	}

	/**
	 * Run scheduled import via cron
	 */
	public function run_scheduled_import() {
		$importer = new MasterSpa_Importer();
		$importer->start();
	}

	/**
	 * Process the next batch of a running import (Action Scheduler).
	 */
	public function run_import_batch() {
		$importer = new MasterSpa_Importer();
		$importer->process_batch();
	}

	/**
	 * Delete log entries older than the configured retention.
	 */
	public function cleanup_logs() {
		$settings = get_option( 'masterspa_settings', array() );
		$days     = isset( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 30;
		if ( $days <= 0 ) {
			return;
		}
		MasterSpa_Logger::clear_old_logs( $days );

		// Legacy table (before 1.2.0): full order payloads, no longer shown in the admin.
		global $wpdb;
		$legacy = $wpdb->prefix . 'masterspa_logs';
		if ( $legacy === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $legacy WHERE log_time < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * One-time upgrades between plugin versions.
	 */
	public function maybe_upgrade() {
		$installed = (int) get_option( 'masterspa_db_version', 1 );
		if ( $installed >= MASTERSPA_DB_VERSION ) {
			return;
		}

		if ( $installed < 2 ) {
			// v2: log table with longtext messages (unified logger), order sync moved to Action Scheduler.
			MasterSpa_Logger::create_table();
			wp_clear_scheduled_hook( 'masterspa_retry_order_sync' );
			if ( function_exists( 'masterspa_requeue_failed_orders' ) ) {
				masterspa_requeue_failed_orders();
			}
		}

		if ( $installed < 3 ) {
			// v3: no secrets in the logs, daily log cleanup.
			$this->redact_secret_in_logs();
			if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'masterspa_cleanup_logs', array(), MASTERSPA_AS_GROUP ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'masterspa_cleanup_logs', array(), MASTERSPA_AS_GROUP );
			}
		}

		update_option( 'masterspa_db_version', MASTERSPA_DB_VERSION );
	}

	/**
	 * Mask the API secret in log entries written before it was masked.
	 */
	private function redact_secret_in_logs() {
		global $wpdb;
		$settings = get_option( 'masterspa_settings', array() );
		$secret   = isset( $settings['auth_header'] ) ? (string) $settings['auth_header'] : '';
		if ( strlen( $secret ) < 4 ) {
			return;
		}
		foreach ( array( $wpdb->prefix . 'masterspa_import_logs', $wpdb->prefix . 'masterspa_logs' ) as $table ) {
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET message = REPLACE(message, %s, '***') WHERE message LIKE %s", $secret, '%' . $wpdb->esc_like( $secret ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * Plugin activation
	 */
	public function activate() {
		if ( false === get_option( 'masterspa_settings' ) ) {
			$default_settings = array(
				'api_endpoint'                => 'http://laravel-app/api/genprod/spa/only',
				'request_method'              => 'GET',
				'timeout'                     => 30,
				'batch_size'                  => 100,
				'import_mode'                 => 'create_update',
				'delete_missing'              => false,
				'dry_run'                     => false,
				'product_status'              => 'publish',
				'order_completed_webhook_url' => '',
				'cron_enabled'                => false,
				'cron_frequency'              => 'daily',
				'cart_beneficiary_enabled'    => false,
				'support_email'               => 'support@masterspa.ro',
				'order_timeout'               => 15,
				'judet_map'                   => array(),
				'web_sale_message'            => '',
				'log_retention_days'          => 30,
			);
			add_option( 'masterspa_settings', $default_settings );
		}

		require_once MASTERSPA_PLUGIN_DIR . 'includes/class-logger.php';
		MasterSpa_Logger::create_table();

		// Upgrades (and re-queueing failed orders) run on the next init, when Action Scheduler is ready.
		if ( false === get_option( 'masterspa_db_version' ) ) {
			add_option( 'masterspa_db_version', 1 );
		} else {
			update_option( 'masterspa_db_version', 1 );
		}
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'masterspa_scheduled_import' );
		wp_clear_scheduled_hook( 'masterspa_retry_order_sync' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), MASTERSPA_AS_GROUP );
		}
		delete_option( 'masterspa_import_queue' );
	}
}

MasterSpa_WP_Plugin::get_instance();
