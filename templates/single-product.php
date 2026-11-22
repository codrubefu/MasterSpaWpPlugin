<?php
/**
 * Single product page (MasterSpa storefront).
 *
 * Replaces the theme's blog-post rendering of WooCommerce products.
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	global $product;
	if ( ! $product instanceof WC_Product ) {
		$product = wc_get_product( get_the_ID() );
	}
	if ( ! $product ) {
		continue;
	}

	$info     = MasterSpa_Storefront::parse_title( get_the_title() );
	$period   = MasterSpa_Storefront::format_period( $info['from'], $info['to'] );
	$nights   = MasterSpa_Storefront::nights_label( $info['nights'] );
	$description = $product->get_description();
	$excerpt  = $product->get_short_description();
	$shop_url = wc_get_page_permalink( 'shop' );
	?>
	<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'msp-product', $product ); ?>>

		<section class="msp-hero" style="--msp-hero-img: url('<?php echo esc_url( MasterSpa_Storefront::image_url( $product, 'full' ) ); ?>');">
			<div class="msp-wrap msp-hero__inner">
				<nav class="msp-crumbs" aria-label="Navigare">
					<a href="<?php echo esc_url( $shop_url ); ?>"><?php echo esc_html( get_the_title( wc_get_page_id( 'shop' ) ) ); ?></a>
					<span aria-hidden="true">/</span>
					<span aria-current="page"><?php echo esc_html( $info['name'] ); ?></span>
				</nav>
				<h1 class="msp-hero__title"><?php echo esc_html( $info['name'] ); ?></h1>
				<?php if ( $nights || $period ) : ?>
					<ul class="msp-facts">
						<?php if ( $nights ) : ?>
							<li class="msp-facts__item">
								<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M20 15.5A8.5 8.5 0 0 1 8.5 4a8.5 8.5 0 1 0 11.5 11.5Z"/></svg>
								<?php echo esc_html( $nights ); ?>
							</li>
						<?php endif; ?>
						<?php if ( $period ) : ?>
							<li class="msp-facts__item">
								<svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>
								<?php echo esc_html( 'Sejur ' . $period ); ?>
							</li>
						<?php endif; ?>
					</ul>
				<?php endif; ?>
			</div>
		</section>

		<div class="msp-wrap msp-product__body">
			<?php do_action( 'woocommerce_before_single_product' ); ?>

			<div class="msp-product__grid">
				<div class="msp-product__content">
					<?php if ( $excerpt ) : ?>
						<div class="msp-lede"><?php echo wp_kses_post( wpautop( $excerpt ) ); ?></div>
					<?php endif; ?>

					<?php if ( '' !== trim( (string) $description ) ) : ?>
						<section class="msp-section msp-description">
							<h2 class="msp-section__title">Descriere</h2>
							<div class="msp-prose"><?php echo apply_filters( 'woocommerce_short_description', $description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- same filters WooCommerce applies to product content. ?></div>
						</section>
					<?php endif; ?>
				</div>

				<aside class="msp-book" aria-label="Rezervare">
					<div class="msp-book__card">
						<p class="msp-book__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></p>
						<p class="msp-book__unit">preț pe pachet<?php echo $nights ? esc_html( ', ' . $nights ) : ''; ?></p>

						<?php if ( $period ) : ?>
							<dl class="msp-book__dl">
								<dt>Perioada sejurului</dt>
								<dd><?php echo esc_html( $period ); ?></dd>
							</dl>
						<?php endif; ?>

						<?php if ( $product->is_purchasable() && $product->is_in_stock() ) : ?>
							<div class="msp-book__form">
								<?php woocommerce_template_single_add_to_cart(); ?>
							</div>
						<?php elseif ( ! $product->is_in_stock() ) : ?>
							<p class="msp-book__note">Pachetul nu mai este disponibil pentru această perioadă.</p>
						<?php else : ?>
							<p class="msp-book__note"><?php echo esc_html( function_exists( 'masterspa_web_sale_message' ) ? masterspa_web_sale_message() : 'Acest pachet nu se poate cumpăra online. Pentru rezervare, contactează recepția hotelului.' ); ?></p>
						<?php endif; ?>
					</div>
				</aside>
			</div>

			<?php
			if ( isset( WC()->structured_data ) ) {
				WC()->structured_data->generate_product_data( $product );
			}
			?>
		</div>

		<?php
		ob_start();
		woocommerce_output_related_products();
		$related = trim( ob_get_clean() );
		if ( $related ) :
			?>
			<div class="msp-related">
				<div class="msp-wrap"><?php echo $related; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce template output. ?></div>
			</div>
		<?php endif; ?>

		<?php do_action( 'woocommerce_after_single_product' ); ?>
	</div>
	<?php
endwhile;

get_footer();
