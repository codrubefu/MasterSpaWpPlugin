<?php
/**
 * Storefront design: shop page and single product page overrides.
 *
 * The active theme (Hotello) does not declare WooCommerce support, so products
 * are rendered through the blog single.php (author, comments, ...). This class
 * replaces those templates with plugin templates and restyles the product loop.
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MasterSpa_Storefront {

	/** @var MasterSpa_Storefront */
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_filter( 'template_include', array( $this, 'template_include' ), 100 );
		add_filter( 'hotel_site_container', array( $this, 'site_container' ), 100 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 30 );

		// Product loop cards (shop, related products, shortcodes).
		remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );
		add_action( 'woocommerce_shop_loop_item_title', array( $this, 'loop_title' ), 10 );
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'loop_media_open' ), 9 );
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'loop_media_close' ), 11 );

		add_filter( 'woocommerce_output_related_products_args', array( $this, 'related_args' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Context                                                             */
	/* ------------------------------------------------------------------ */

	private function is_storefront_page() {
		return function_exists( 'is_woocommerce' ) && ( is_shop() || is_product() || is_product_taxonomy() );
	}

	public function template_include( $template ) {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$custom = MASTERSPA_PLUGIN_DIR . 'templates/single-product.php';
		} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
			$custom = MASTERSPA_PLUGIN_DIR . 'templates/shop.php';
		}
		if ( ! empty( $custom ) && file_exists( $custom ) ) {
			return $custom;
		}
		return $template;
	}

	public function site_container( $container ) {
		return $this->is_storefront_page() && ( is_product() || is_shop() ) ? 'msp-site' : $container;
	}

	public function body_class( $classes ) {
		if ( $this->is_storefront_page() ) {
			$classes[] = 'msp-storefront';
		}
		return $classes;
	}

	public function enqueue() {
		if ( ! $this->is_storefront_page() ) {
			return;
		}
		wp_enqueue_style(
			'msp-font-manrope',
			'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap',
			array(),
			null
		);
		$css = MASTERSPA_PLUGIN_DIR . 'assets/css/storefront.css';
		wp_enqueue_style(
			'msp-storefront',
			MASTERSPA_PLUGIN_URL . 'assets/css/storefront.css',
			array( 'woocommerce-general' ),
			file_exists( $css ) ? (string) filemtime( $css ) : MASTERSPA_VERSION
		);
		if ( is_product() ) {
			$js = MASTERSPA_PLUGIN_DIR . 'assets/js/storefront.js';
			wp_enqueue_script(
				'msp-storefront',
				MASTERSPA_PLUGIN_URL . 'assets/js/storefront.js',
				array(),
				file_exists( $js ) ? (string) filemtime( $js ) : MASTERSPA_VERSION,
				true
			);
		}
	}

	public function related_args( $args ) {
		$args['posts_per_page'] = 3;
		$args['columns']        = 3;
		return $args;
	}

	/* ------------------------------------------------------------------ */
	/* Loop card                                                           */
	/* ------------------------------------------------------------------ */

	public function loop_media_open() {
		echo '<div class="msp-card__media">';
	}

	public function loop_media_close() {
		echo '</div>';
	}

	public function loop_title() {
		$info = self::parse_title( get_the_title() );
		echo '<h2 class="woocommerce-loop-product__title msp-card__title">' . esc_html( $info['name'] ) . '</h2>';
		$meta = self::meta_line( $info );
		if ( $meta ) {
			echo '<p class="msp-card__meta">' . esc_html( $meta ) . '</p>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helpers used by the templates                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Split an imported title such as
	 * "MIRAGE VITAL SPA – 7 nopti – Sejur 01-12-2026 31-12-2026"
	 * into name / nights / stay period. Unknown formats fall back to the raw title.
	 *
	 * @param string $title Raw title (may contain HTML entities).
	 * @return array{name:string,nights:int,from:int,to:int,raw:string}
	 */
	public static function parse_title( $title ) {
		$raw  = trim( wp_specialchars_decode( html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) ) );
		$rest = $raw;
		$info = array(
			'name'   => $raw,
			'nights' => 0,
			'from'   => 0,
			'to'     => 0,
			'raw'    => $raw,
		);

		if ( preg_match( '/sejur\s*:?\s*(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})\s*(?:-|–|—|\s)\s*(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/iu', $rest, $m ) ) {
			$info['from'] = mktime( 12, 0, 0, (int) $m[2], (int) $m[1], (int) $m[3] );
			$info['to']   = mktime( 12, 0, 0, (int) $m[5], (int) $m[4], (int) $m[6] );
			$rest         = str_replace( $m[0], ' ', $rest );
		}
		if ( preg_match( '/(\d+)\s*(?:nop[tțţ]i|noapte)/iu', $rest, $m ) ) {
			$info['nights'] = (int) $m[1];
			$rest           = str_replace( $m[0], ' ', $rest );
		}

		// Drop the separators left behind: "MIRAGE VITAL SPA –  – " -> "MIRAGE VITAL SPA".
		$pieces = array_filter( array_map( 'trim', preg_split( '/(?:^|\s)[–—-](?=\s|$)/u', $rest ) ), 'strlen' );
		$name   = trim( preg_replace( '/\s+/u', ' ', implode( ' – ', $pieces ) ) );
		if ( '' !== $name ) {
			// Imported names are uppercase; present them in title case.
			if ( function_exists( 'mb_strtoupper' ) && mb_strtoupper( $name, 'UTF-8' ) === $name ) {
				$name = mb_convert_case( mb_strtolower( $name, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
			}
			$info['name'] = $name;
		}
		return $info;
	}

	/**
	 * Human period: "1 – 31 decembrie 2026" / "28 noiembrie – 5 decembrie 2026".
	 */
	public static function format_period( $from, $to ) {
		if ( ! $from || ! $to ) {
			return '';
		}
		if ( gmdate( 'Y', $from ) !== gmdate( 'Y', $to ) ) {
			return date_i18n( 'j F Y', $from ) . ' – ' . date_i18n( 'j F Y', $to );
		}
		if ( gmdate( 'm', $from ) !== gmdate( 'm', $to ) ) {
			return date_i18n( 'j F', $from ) . ' – ' . date_i18n( 'j F Y', $to );
		}
		return date_i18n( 'j', $from ) . ' – ' . date_i18n( 'j F Y', $to );
	}

	public static function nights_label( $nights ) {
		if ( ! $nights ) {
			return '';
		}
		return 1 === $nights ? '1 noapte' : sprintf( '%d nopți', $nights );
	}

	public static function meta_line( $info ) {
		$parts = array_filter( array( self::nights_label( $info['nights'] ), self::format_period( $info['from'], $info['to'] ) ) );
		return implode( ', ', $parts );
	}

	public static function image_url( $product, $size = 'full' ) {
		$id = $product->get_image_id();
		if ( $id ) {
			$src = wp_get_attachment_image_url( $id, $size );
			if ( $src ) {
				return $src;
			}
		}
		return wc_placeholder_img_src( $size );
	}
}
