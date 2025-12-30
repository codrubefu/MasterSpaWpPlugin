<?php
/**
 * Importer class for MasterSpa WP Plugin
 *
 * Handles product import from API to WooCommerce
 *
 * @package MasterSpaWpPlugin
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MasterSpa Importer class
 */
class MasterSpa_Importer {
	
	/**
	 * Settings
	 *
	 * @var array
	 */
	private $settings;
	
	/**
	 * Import date/time identifier
	 *
	 * @var string
	 */
	private $import_date;
	
	/**
	 * Import statistics
	 *
	 * @var array
	 */
	private $stats = array(
		'created' => 0,
		'updated' => 0,
		'errors'  => 0,
		'total'   => 0,
	);
	
	/**
	 * Constructor
	 */
	public function __construct() {
		$this->settings    = get_option( 'masterspa_settings', array() );
		$this->import_date = current_time( 'mysql' );
	}
	
	/**
	 * Run import process
	 *
	 * @return array Import statistics
	 */
	public function import() {
		// Log start
		MasterSpa_Logger::log( 'info', 'Import started', null, null, $this->import_date );
		
		// Fetch products from API
		$products = $this->fetch_products();
		
		if ( is_wp_error( $products ) ) {
			$error_message = $products->get_error_message();
			MasterSpa_Logger::log( 'error', 'API request failed: ' . $error_message, null, null, $this->import_date );
			return array(
				'success' => false,
				'message' => $error_message,
				'stats'   => $this->stats,
			);
		}
		
		if ( empty( $products ) ) {
			MasterSpa_Logger::log( 'warning', 'No products received from API', null, null, $this->import_date );
			return array(
				'success' => false,
				'message' => __( 'Nu s-au primit produse din API.', 'masterspa-wp-plugin' ),
				'stats'   => $this->stats,
			);
		}
		
		// Track SKUs from API
		$api_skus = array();
		
		// Process each product
		foreach ( $products as $product_data ) {
			$result = $this->process_product( $product_data );
			
			if ( ! empty( $result['sku'] ) ) {
				$api_skus[] = $result['sku'];
			}
		}
		
		// Handle delete missing products option
		if ( ! empty( $this->settings['delete_missing'] ) && ! empty( $this->settings['import_mode'] ) && 'create_update' === $this->settings['import_mode'] ) {
			$this->delete_missing_products( $api_skus );
		}
		
		// Save import summary
		$this->save_import_summary();
		
		// Log completion
		MasterSpa_Logger::log( 'info', sprintf( 'Import completed: %d created, %d updated, %d errors', $this->stats['created'], $this->stats['updated'], $this->stats['errors'] ), null, null, $this->import_date );
		
		return array(
			'success' => true,
			'message' => __( 'Import finalizat cu succes.', 'masterspa-wp-plugin' ),
			'stats'   => $this->stats,
		);
	}
	
	/**
	 * Fetch products from API
	 *
	 * @return array|WP_Error Products array or WP_Error on failure
	 */
	private function fetch_products() {
		$endpoint = ! empty( $this->settings['api_endpoint'] ) ? $this->settings['api_endpoint'] : 'http://localhost:8082/api/genprod/spa/only';
		$timeout  = ! empty( $this->settings['timeout'] ) ? absint( $this->settings['timeout'] ) : 30;
		$method   = ! empty( $this->settings['request_method'] ) ? $this->settings['request_method'] : 'GET';
		
		$args = array(
			'timeout' => $timeout,
			'headers' => array(
				'Accept' => 'application/json',
			),
		);
		
		// Add authorization header if configured
		if ( ! empty( $this->settings['auth_header'] ) ) {
			$args['headers']['X-API-Secret'] = $this->settings['auth_header'];
		}
		
		// Make request
		if ( 'POST' === strtoupper( $method ) ) {
			$response = wp_remote_post( $endpoint, $args );
		} else {
			$response = wp_remote_get( $endpoint, $args );
		}
		
		// Check for errors
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new WP_Error( 'api_error', sprintf( 'API returned status code %d', $response_code ) );
		}
		
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'json_error', 'Invalid JSON response from API' );
		}
		
		// Support different response formats
		// Assume array of products, but check if wrapped in 'data' or 'products' key
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			return $data['data'];
		} elseif ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
			return $data['products'];
		} elseif ( is_array( $data ) ) {
			return $data;
		}
		
		return array();
	}
	
	/**
	 * Process single product
	 *
	 * @param array $product_data Product data from API
	 * @return array Result with SKU and status
	 */
	private function process_product( $product_data ) {
		$this->stats['total']++;
		
		// Extract product fields
		$title       = ! empty( $product_data['art'] ) ? sanitize_text_field( $product_data['art'] ) : '';
		$description = ! empty( $product_data['desc1'] ) ? wp_kses_post( $product_data['desc1'] ) : '';
		$clasa       = ! empty( $product_data['clasa'] ) ? sanitize_text_field( $product_data['clasa'] ) : '';
		$grupa       = ! empty( $product_data['grupa'] ) ? sanitize_text_field( $product_data['grupa'] ) : '';
		
		// Validate required fields
		if ( empty( $title ) ) {
			MasterSpa_Logger::log( 'error', 'Product missing title (art field)', null, null, $this->import_date );
			$this->stats['errors']++;
			return array( 'success' => false );
		}
		
		// Generate or extract SKU
		$sku = $this->generate_sku( $product_data, $title, $clasa, $grupa );
		
		// Check if product exists
		$existing_product_id = $this->get_product_by_sku( $sku );
		
		// Check import mode
		$import_mode = ! empty( $this->settings['import_mode'] ) ? $this->settings['import_mode'] : 'create_update';
		
		if ( 'update_only' === $import_mode && ! $existing_product_id ) {
			// Skip creating new products in update-only mode
			return array( 'success' => false, 'sku' => $sku );
		}
		
		// Check dry run mode
		$dry_run = ! empty( $this->settings['dry_run'] );
		
		// Process prices
		$prices = $this->extract_prices( $product_data );
		
		// Prepare product data
		$product_args = array(
			'post_title'   => $title,
			'post_content' => $description,
			'post_status'  => ! empty( $this->settings['product_status'] ) ? $this->settings['product_status'] : 'publish',
			'post_type'    => 'product',
		);
		
		if ( $existing_product_id ) {
			// Update existing product
			$product_args['ID'] = $existing_product_id;
			
			if ( ! $dry_run ) {
				$product_id = wp_update_post( $product_args );
				
				if ( is_wp_error( $product_id ) ) {
					MasterSpa_Logger::log( 'error', 'Failed to update product: ' . $product_id->get_error_message(), $sku, $existing_product_id, $this->import_date );
					$this->stats['errors']++;
					return array( 'success' => false, 'sku' => $sku );
				}
			} else {
				$product_id = $existing_product_id;
			}
			
			$this->stats['updated']++;
			MasterSpa_Logger::log( 'updated', $dry_run ? 'Would update product: ' . $title : 'Updated product: ' . $title, $sku, $product_id, $this->import_date );
		} else {
			// Create new product
			if ( ! $dry_run ) {
				$product_id = wp_insert_post( $product_args );
				
				if ( is_wp_error( $product_id ) || ! $product_id ) {
					$error_msg = is_wp_error( $product_id ) ? $product_id->get_error_message() : 'Unknown error';
					MasterSpa_Logger::log( 'error', 'Failed to create product: ' . $error_msg, $sku, null, $this->import_date );
					$this->stats['errors']++;
					return array( 'success' => false, 'sku' => $sku );
				}
			} else {
				$product_id = 0; // Placeholder for dry run
			}
			
			$this->stats['created']++;
			MasterSpa_Logger::log( 'created', $dry_run ? 'Would create product: ' . $title : 'Created product: ' . $title, $sku, $product_id, $this->import_date );
		}
		
		// Set product meta and attributes
		if ( ! $dry_run && $product_id ) {
			// Set SKU
			update_post_meta( $product_id, '_sku', $sku );

			// Set prices
			if ( ! empty( $prices['regular'] ) ) {
				update_post_meta( $product_id, '_regular_price', $prices['regular'] );
				update_post_meta( $product_id, '_price', $prices['regular'] );
			}

			if ( ! empty( $prices['sale'] ) && $prices['sale'] < $prices['regular'] ) {
				update_post_meta( $product_id, '_sale_price', $prices['sale'] );
				update_post_meta( $product_id, '_price', $prices['sale'] );
			}

			// Set as simple product
			wp_set_object_terms( $product_id, 'simple', 'product_type' );

			// Stock management off
			update_post_meta( $product_id, '_manage_stock', 'no' );
			update_post_meta( $product_id, '_stock_status', 'instock' );

			// Set categories
			$this->set_product_categories( $product_id, $clasa, $grupa );

			// Add 'spa' product tag
			$spa_tag = get_term_by('name', 'spa', 'product_tag');
			if (!$spa_tag) {
				$spa_tag_result = wp_insert_term('spa', 'product_tag');
				if (!is_wp_error($spa_tag_result)) {
					$spa_tag_id = $spa_tag_result['term_id'];
				}
			} else {
				$spa_tag_id = $spa_tag->term_id;
			}
			if (isset($spa_tag_id)) {
				wp_set_post_terms($product_id, array($spa_tag_id), 'product_tag', true);
			}
		}
		
		return array( 'success' => true, 'sku' => $sku, 'product_id' => $product_id );
	}
	
	/**
	 * Generate SKU for product
	 *
	 * @param array  $product_data Product data
	 * @param string $title Product title
	 * @param string $clasa Category
	 * @param string $grupa Subcategory
	 * @return string SKU
	 */
	private function generate_sku( $product_data, $title, $clasa, $grupa ) {
		// Check if API provides SKU or ID
		if ( ! empty( $product_data['sku'] ) ) {
			return sanitize_text_field( $product_data['sku'] );
		}
		
		if ( ! empty( $product_data['id'] ) ) {
			return 'MSPA-' . sanitize_text_field( $product_data['id'] );
		}
		
		if ( ! empty( $product_data['cod'] ) ) {
			return 'MSPA-' . sanitize_text_field( $product_data['cod'] );
		}
		
		// Generate deterministic SKU from product data
		$sku_base = $title . '-' . $clasa . '-' . $grupa;
		$sku_hash = substr( md5( $sku_base ), 0, 10 );
		
		return 'MSPA-' . strtoupper( $sku_hash );
	}
	
	/**
	 * Get product ID by SKU
	 *
	 * @param string $sku Product SKU
	 * @return int|false Product ID or false if not found
	 */
	private function get_product_by_sku( $sku ) {
		global $wpdb;
		
		$product_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
				$sku
			)
		);
		
		return $product_id ? (int) $product_id : false;
	}
	
	/**
	 * Extract and process prices from product data
	 *
	 * @param array $product_data Product data
	 * @return array Array with 'regular' and 'sale' prices
	 */
	private function extract_prices( $product_data ) {
		$prices = array(
			'regular' => 0,
			'sale'    => 0,
		);
		
		if ( empty( $product_data['pret'] ) || ! is_array( $product_data['pret'] ) ) {
			return $prices;
		}
		
		$price_values = array();
		
		// Extract all price values
		foreach ( $product_data['pret'] as $price_item ) {
			if ( isset( $price_item['pret'] ) ) {
				$price = $this->normalize_price( $price_item['pret'] );
				if ( $price > 0 ) {
					$price_values[] = $price;
				}
			}
		}
		
		// Sort prices
		sort( $price_values, SORT_NUMERIC );
		
		if ( empty( $price_values ) ) {
			return $prices;
		}
		
		// Apply pricing rules
		if ( count( $price_values ) === 1 ) {
			// Single price: set as regular price
			$prices['regular'] = $price_values[0];
		} else {
			// Multiple prices: min = sale, max = regular
			$prices['sale']    = min( $price_values );
			$prices['regular'] = max( $price_values );
		}
		
		return $prices;
	}
	
	/**
	 * Normalize price value
	 *
	 * @param mixed $price Price value
	 * @return float Normalized price
	 */
	private function normalize_price( $price ) {
		// Convert comma to dot
		if ( is_string( $price ) ) {
			$price = str_replace( ',', '.', $price );
		}
		
		// Cast to float
		$price = (float) $price;
		
		// Round to 2 decimals
		return round( $price, 2 );
	}
	
	/**
	 * Set product categories
	 *
	 * @param int    $product_id Product ID
	 * @param string $clasa Parent category name
	 * @param string $grupa Child category name
	 */
	private function set_product_categories( $product_id, $clasa, $grupa ) {
		// Always assign to 'spa' category as top-level
		$category_ids = array();

		// Get or create 'spa' category
		$spa_term = term_exists( 'spa', 'product_cat' );
		if ( ! $spa_term ) {
			$spa_term = wp_insert_term( 'spa', 'product_cat' );
		}
		if ( is_wp_error( $spa_term ) ) {
			MasterSpa_Logger::log( 'error', 'Failed to create category: spa', null, $product_id, $this->import_date );
			return;
		}
		$spa_term_id = is_array( $spa_term ) ? $spa_term['term_id'] : $spa_term;
		$category_ids[] = (int) $spa_term_id;

		// Get or create parent category (clasa) under 'spa'
		if ( empty( $clasa ) ) {
			// Only assign to 'spa' if no clasa
			wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
			return;
		}
		$parent_term = term_exists( $clasa, 'product_cat', $spa_term_id );
		if ( ! $parent_term ) {
			$parent_term = wp_insert_term( $clasa, 'product_cat', array( 'parent' => $spa_term_id ) );
		}
		if ( is_wp_error( $parent_term ) ) {
			MasterSpa_Logger::log( 'error', 'Failed to create category: ' . $clasa, null, $product_id, $this->import_date );
			wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
			return;
		}
		$parent_term_id = is_array( $parent_term ) ? $parent_term['term_id'] : $parent_term;
		$category_ids[] = (int) $parent_term_id;

		// Get or create child category if grupa is provided, under clasa
		if ( ! empty( $grupa ) ) {
			$child_term = term_exists( $grupa, 'product_cat', $parent_term_id );
			if ( ! $child_term ) {
				$child_term = wp_insert_term(
					$grupa,
					'product_cat',
					array( 'parent' => $parent_term_id )
				);
			}
			if ( ! is_wp_error( $child_term ) ) {
				$child_term_id  = is_array( $child_term ) ? $child_term['term_id'] : $child_term;
				$category_ids[] = (int) $child_term_id;
			}
		}

		// Assign categories to product
		wp_set_object_terms( $product_id, $category_ids, 'product_cat' );
	}
	
	/**
	 * Delete products not in API feed
	 *
	 * @param array $api_skus Array of SKUs from API
	 */
	private function delete_missing_products( $api_skus ) {
		if ( empty( $api_skus ) ) {
			return;
		}
		
		global $wpdb;
		
		// Get all product SKUs with MSPA prefix
		$all_skus = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE 'MSPA-%'"
		);
		
		$missing_skus = array_diff( $all_skus, $api_skus );
		
		foreach ( $missing_skus as $sku ) {
			$product_id = $this->get_product_by_sku( $sku );
			
			if ( $product_id ) {
				if ( empty( $this->settings['dry_run'] ) ) {
					wp_delete_post( $product_id, true );
					MasterSpa_Logger::log( 'info', 'Deleted missing product', $sku, $product_id, $this->import_date );
				} else {
					MasterSpa_Logger::log( 'info', 'Would delete missing product', $sku, $product_id, $this->import_date );
				}
			}
		}
	}
	
	/**
	 * Save import summary to options
	 */
	private function save_import_summary() {
		$summary = array(
			'timestamp' => $this->import_date,
			'stats'     => $this->stats,
		);
		
		update_option( 'masterspa_last_import', $summary );
	}
}
