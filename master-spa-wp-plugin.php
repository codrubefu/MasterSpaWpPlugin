<?php
/**
 * Plugin Name: MasterSpa WP Plugin
 * Plugin URI: https://example.com/masterspa-wp-plugin
 * Description: Importă produse în WooCommerce din API-ul MasterSpa. Suportă import manual și automat cu WP Cron.
 * Version: 1.0.0
 * Author: MasterSpa Team
 * Author URI: https://example.com
 * Text Domain: masterspa-wp-plugin
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 8.5
 *
 * @package MasterSpaWpPlugin
 *
 * INSTALARE:
 * 1. Încarcă folder-ul MasterSpaWpPlugin în /wp-content/plugins/
 * 2. Activează plugin-ul din WordPress Admin > Plugins
 * 3. Asigură-te că WooCommerce este instalat și activ
 * 4. Accesează Settings > MasterSpa Import pentru configurare
 * 5. Configurează endpoint-ul API și opțiunile de import
 * 6. Rulează importul manual sau activează importul automat cu Cron
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'MASTERSPA_VERSION', '1.0.0' );
define( 'MASTERSPA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MASTERSPA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MASTERSPA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

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
		// Check if WooCommerce is active
		add_action( 'plugins_loaded', array( $this, 'init' ) );
		
		// Activation/Deactivation hooks
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}
	
	/**
	 * Initialize plugin
	 */
	public function init() {
		// Check if WooCommerce is active
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}
		
		// Load dependencies
		$this->load_dependencies();
		
		// Initialize components
		$this->init_components();
		
		// Load textdomain
		load_plugin_textdomain( 'masterspa-wp-plugin', false, dirname( MASTERSPA_PLUGIN_BASENAME ) . '/languages' );
	}
	
	/**
	 * Check if WooCommerce is active
	 *
	 * @return bool
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
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
		   require_once MASTERSPA_PLUGIN_DIR . 'includes/class-logger.php';
		   require_once MASTERSPA_PLUGIN_DIR . 'includes/class-admin-settings.php';
		   require_once MASTERSPA_PLUGIN_DIR . 'custom-cart/admin-subscription-users-display.php';
		   $custom_cart_file = MASTERSPA_PLUGIN_DIR . 'custom-cart/subscription-users-cart.php';

		   if ( file_exists( $custom_cart_file ) ) {
			   require_once $custom_cart_file;
		   }
	   }
	
	/**
	 * Initialize plugin components
	 */
	private function init_components() {
		// Initialize admin settings
		if ( is_admin() ) {
			MasterSpa_Admin_Settings::get_instance();
		}
		
		// Register cron hooks
		add_action( 'masterspa_scheduled_import', array( $this, 'run_scheduled_import' ) );
	}
	
	/**
	 * Run scheduled import via cron
	 */
	public function run_scheduled_import() {
		$importer = new MasterSpa_Importer();
		$importer->import();
	}
	
	/**
	 * Plugin activation
	 */
	public function activate() {
		// Set default options if not exists
		if ( false === get_option( 'masterspa_settings' ) ) {
			$default_settings = array(
				'api_endpoint'     => 'http://localhost:8082/api/genprod/spa/only',
				'request_method'   => 'GET',
				'timeout'          => 30,
				'batch_size'       => 100,
				'import_mode'      => 'create_update',
				'delete_missing'   => false,
				'dry_run'          => false,
				'product_status'   => 'publish',
				'cron_enabled'     => false,
				'cron_frequency'   => 'daily',
			);
			add_option( 'masterspa_settings', $default_settings );
		}

		// Explicit include pentru logger la activare
		require_once MASTERSPA_PLUGIN_DIR . 'includes/class-logger.php';
		// Initialize logger table
		MasterSpa_Logger::create_table();
	}
	
	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		// Clear scheduled cron
		$timestamp = wp_next_scheduled( 'masterspa_scheduled_import' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'masterspa_scheduled_import' );
		}
		wp_clear_scheduled_hook( 'masterspa_scheduled_import' );
	}
}

// Initialize plugin
MasterSpa_WP_Plugin::get_instance();
