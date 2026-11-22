<?php
/**
 * Șterge TOATE produsele WooCommerce (inclusiv variații, ciorne, coș de gunoi)
 * și TOATE categoriile de produse (product_cat).
 *
 * Rulare (doar din linia de comandă):
 *   docker exec wordpress-wordpress-1 php /var/www/html/wp-content/plugins/MasterSpaWpPlugin/bin/purge-products.php --dry-run
 *   docker exec wordpress-wordpress-1 php /var/www/html/wp-content/plugins/MasterSpaWpPlugin/bin/purge-products.php --yes
 *
 * Opțiuni:
 *   --dry-run  Afișează doar ce s-ar șterge, fără să modifice nimic.
 *   --yes      Confirmă ștergerea (obligatoriu pentru a șterge efectiv).
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( "Doar din linia de comandă.\n" );
}

$args    = array_slice( $argv, 1 );
$dry_run = in_array( '--dry-run', $args, true );
$confirm = in_array( '--yes', $args, true );

if ( ! $dry_run && ! $confirm ) {
	fwrite( STDERR, "Refuz să șterg fără confirmare. Folosește --dry-run pentru previzualizare sau --yes pentru ștergere.\n" );
	exit( 1 );
}

// Încarcă WordPress (bin/ -> plugin -> plugins -> wp-content -> root).
$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "Nu găsesc wp-load.php la {$wp_load}\n" );
	exit( 1 );
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require_once $wp_load;

if ( ! function_exists( 'wc_get_product' ) ) {
	fwrite( STDERR, "WooCommerce nu este activ.\n" );
	exit( 1 );
}

wp_suspend_cache_invalidation( true );
wp_defer_term_counting( true );

// ---- Produse ----
$product_ids = get_posts(
	array(
		'post_type'        => array( 'product', 'product_variation' ),
		'post_status'      => 'any,trash,auto-draft',
		'numberposts'      => -1,
		'fields'           => 'ids',
		'suppress_filters' => true,
	)
);

echo 'Produse/variații găsite: ' . count( $product_ids ) . "\n";

$deleted_products = 0;
foreach ( $product_ids as $id ) {
	if ( $dry_run ) {
		echo "  [dry-run] #{$id} " . get_the_title( $id ) . "\n";
		continue;
	}
	$product = wc_get_product( $id );
	$ok      = $product ? $product->delete( true ) : (bool) wp_delete_post( $id, true );
	if ( $ok ) {
		$deleted_products++;
	} else {
		fwrite( STDERR, "  Eșec la ștergerea produsului #{$id}\n" );
	}
}

// ---- Categorii de produse ----
$default_cat = (int) get_option( 'default_product_cat', 0 );
$term_ids    = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
if ( is_wp_error( $term_ids ) ) {
	$term_ids = array();
}

echo 'Categorii de produse găsite: ' . count( $term_ids ) . "\n";

$deleted_terms = 0;
foreach ( $term_ids as $term_id ) {
	$term = get_term( $term_id, 'product_cat' );
	if ( $dry_run ) {
		echo "  [dry-run] #{$term_id} {$term->name}\n";
		continue;
	}
	// WooCommerce nu permite ștergerea categoriei implicite; o eliminăm din opțiune
	// ca să poată fi ștearsă (WooCommerce o recreează automat dacă e nevoie).
	if ( (int) $term_id === $default_cat ) {
		delete_option( 'default_product_cat' );
	}
	$res = wp_delete_term( $term_id, 'product_cat' );
	if ( true === $res ) {
		$deleted_terms++;
	} else {
		$msg = is_wp_error( $res ) ? $res->get_error_message() : 'necunoscut';
		fwrite( STDERR, "  Eșec la ștergerea categoriei #{$term_id} ({$term->name}): {$msg}\n" );
	}
}

wp_defer_term_counting( false );
wp_suspend_cache_invalidation( false );

if ( ! $dry_run ) {
	// Curăță transient-urile WooCommerce și cache-ul.
	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}
	wp_cache_flush();
	echo "Șterse: {$deleted_products} produse, {$deleted_terms} categorii.\n";
} else {
	echo "Dry-run: nimic nu a fost modificat.\n";
}
