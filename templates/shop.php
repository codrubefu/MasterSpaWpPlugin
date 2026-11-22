<?php
/**
 * Shop page (MasterSpa storefront).
 *
 * The product loop itself comes from WooCommerce (the_content on the shop page),
 * so sorting and pagination keep working; this template only frames it.
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$shop_id = wc_get_page_id( 'shop' );
$lede    = $shop_id > 0 ? get_post_field( 'post_excerpt', $shop_id ) : '';
if ( '' === trim( (string) $lede ) ) {
	$lede = 'Pachete de sejur cu terapii incluse la Mirage MedSpa Hotel. Alege pachetul și perioada care ți se potrivesc.';
}
?>
<div class="msp-shop">
	<header class="msp-shop__head">
		<div class="msp-wrap">
			<h1 class="msp-shop__title"><?php echo esc_html( $shop_id > 0 ? get_the_title( $shop_id ) : 'Magazin' ); ?></h1>
			<p class="msp-shop__lede"><?php echo esc_html( wp_strip_all_tags( $lede ) ); ?></p>
		</div>
	</header>

	<div class="msp-wrap msp-shop__body">
		<?php
		while ( have_posts() ) :
			the_post();
			the_content();
		endwhile;
		?>
	</div>
</div>
<?php
get_footer();
