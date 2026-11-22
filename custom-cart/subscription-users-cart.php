<?php
/**
 * Subscriber (beneficiar) editing in the classic cart (shortcode / PHP template).
 *
 * Under each cart item: one summary + "Modifică beneficiar" button per quantity, each opening a modal.
 * The modal inputs are part of the cart form; "Salvează" submits the form through "Update cart".
 * Script: assets/js/cart-subscribers.js, styles: assets/css/cart-subscribers.css.
 *
 * @package MasterSpaWpPlugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assets, only on the cart page.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}
		$js  = MASTERSPA_PLUGIN_DIR . 'assets/js/cart-subscribers.js';
		$css = MASTERSPA_PLUGIN_DIR . 'assets/css/cart-subscribers.css';
		wp_enqueue_script( 'masterspa-cart-subscribers', MASTERSPA_PLUGIN_URL . 'assets/js/cart-subscribers.js', array( 'jquery' ), file_exists( $js ) ? (string) filemtime( $js ) : MASTERSPA_VERSION, true );
		wp_enqueue_style( 'masterspa-cart-subscribers', MASTERSPA_PLUGIN_URL . 'assets/css/cart-subscribers.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : MASTERSPA_VERSION );
	}
);

/**
 * Display subscriber summary + modal under each cart item (one per quantity).
 */
add_action(
	'woocommerce_after_cart_item_name',
	function ( $cart_item, $cart_item_key ) {
		if ( empty( $cart_item['data'] ) ) {
			return;
		}

		$list = masterspa_get_cart_item_subscribers( $cart_item );
		$qty  = count( $list );
		$spa  = masterspa_is_spa_product( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0 );

		foreach ( $list as $i => $saved ) {
			$form_id = $cart_item_key . '-' . $i;
			$base    = 'subscription_user[' . $cart_item_key . '][' . $i . ']';
			$counter = ( $i + 1 ) . '/' . $qty;
			$missing = $spa ? masterspa_subscriber_missing_fields( $saved ) : array();
			$fields  = array(
				'prenume' => array( __( 'Prenume', 'masterspa-wp-plugin' ), 'text' ),
				'nume'    => array( __( 'Nume', 'masterspa-wp-plugin' ), 'text' ),
				'email'   => array( __( 'Email', 'masterspa-wp-plugin' ), 'email' ),
				'phone'   => array( __( 'Telefon', 'masterspa-wp-plugin' ), 'tel' ),
			);
			?>
			<div class="subscription-user-under-title">
				<div class="subscription-user-info" id="subscription-user-info-<?php echo esc_attr( $form_id ); ?>">
					<?php
					$tags = array();
					foreach ( $fields as $key => $field ) {
						if ( '' !== $saved[ $key ] ) {
							$tags[] = '<span class="subscriber-tag">' . esc_html( $field[0] ) . ': ' . esc_html( $saved[ $key ] ) . '</span>';
						}
					}
					if ( $tags ) {
						echo '<strong>' . esc_html__( 'Abonat:', 'masterspa-wp-plugin' ) . '</strong> ' . implode( ' ', $tags ); // phpcs:ignore WordPress.Security.EscapeOutput -- tags escaped above.
					}
					if ( $missing ) {
						/* translators: %s: missing fields */
						echo '<span class="subscriber-missing">' . esc_html( sprintf( __( 'De completat: %s', 'masterspa-wp-plugin' ), implode( ', ', $missing ) ) ) . '</span>';
					}
					?>
				</div>

				<button type="button" class="open-subscription-modal" data-form-id="<?php echo esc_attr( $form_id ); ?>">
					<?php
					/* translators: %s: index/quantity, e.g. 1/2 */
					echo esc_html( sprintf( __( 'Modifică beneficiar (%s)', 'masterspa-wp-plugin' ), $counter ) );
					?>
				</button>

				<div class="subscription-modal" id="modal-<?php echo esc_attr( $form_id ); ?>" role="dialog" aria-modal="true" hidden>
					<div class="subscription-modal__box">
						<button type="button" class="close-subscription-modal" aria-label="<?php esc_attr_e( 'Închide', 'masterspa-wp-plugin' ); ?>">&times;</button>
						<h4>
							<?php
							/* translators: %s: index/quantity, e.g. 1/2 */
							echo esc_html( sprintf( __( 'Utilizator abonament (%s)', 'masterspa-wp-plugin' ), $counter ) );
							?>
						</h4>
						<div class="subscription-user-form">
							<?php foreach ( $fields as $key => $field ) : ?>
								<p>
									<label>
										<?php echo esc_html( $field[0] ); ?><?php echo $spa && 'phone' !== $key ? ' <abbr class="required" title="' . esc_attr__( 'obligatoriu', 'masterspa-wp-plugin' ) . '">*</abbr>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?><br>
										<input type="<?php echo esc_attr( $field[1] ); ?>" class="subscription-user-input" name="<?php echo esc_attr( $base . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $saved[ $key ] ); ?>">
									</label>
								</p>
							<?php endforeach; ?>
							<p class="subscription-user-form__actions">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $base . '[send_email]' ); ?>" value="1" <?php checked( '' !== $saved['send_email'] ); ?>>
									<?php esc_html_e( 'Trimite email cu informațiile', 'masterspa-wp-plugin' ); ?>
								</label>
								<button type="button" class="update-subscription-user"><?php esc_html_e( 'Salvează', 'masterspa-wp-plugin' ); ?></button>
							</p>
						</div>
					</div>
				</div>
			</div>
			<?php
		}
	},
	10,
	2
);

/**
 * Nonce, once per cart form.
 */
add_action(
	'woocommerce_after_cart_contents',
	function () {
		wp_nonce_field( 'save_subscription_users', 'save_subscription_users_nonce' );
	}
);

/**
 * Save subscription_user data when the cart form is submitted (Update cart).
 */
add_action(
	'wp_loaded',
	function () {
		if ( ! isset( $_POST['update_cart'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		if ( ! isset( $_POST['save_subscription_users_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['save_subscription_users_nonce'] ) ), 'save_subscription_users' ) ) {
			return;
		}

		$posted = isset( $_POST['subscription_user'] ) ? wp_unslash( $_POST['subscription_user'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per field below.
		if ( empty( $posted ) || ! is_array( $posted ) ) {
			return;
		}

		$invalid_email = false;
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! isset( $posted[ $cart_item_key ] ) || ! is_array( $posted[ $cart_item_key ] ) ) {
				continue;
			}
			$subscription_users = array();
			foreach ( $posted[ $cart_item_key ] as $user_data ) {
				if ( masterspa_subscriber_email_invalid( $user_data ) ) {
					$invalid_email = true;
				}
				$subscription_users[] = masterspa_sanitize_subscriber( $user_data );
			}
			WC()->cart->cart_contents[ $cart_item_key ]['subscription_user'] = $subscription_users;
		}

		if ( $invalid_email ) {
			wc_add_notice( __( 'Una dintre adresele de email ale beneficiarilor nu este validă și nu a fost salvată.', 'masterspa-wp-plugin' ), 'error' );
		}

		WC()->cart->set_session();
	},
	20
);

/**
 * Add subscription users as order item meta so they appear in admin and emails.
 */
add_action(
	'woocommerce_checkout_create_order_line_item',
	function ( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['subscription_user'] ) || ! is_array( $values['subscription_user'] ) ) {
			return;
		}

		$qty         = max( 1, (int) ( isset( $values['quantity'] ) ? $values['quantity'] : 1 ) );
		$subscribers = array_slice( array_values( $values['subscription_user'] ), 0, $qty );

		foreach ( $subscribers as $idx => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$row = masterspa_sanitize_subscriber( $data );
			$n   = ' [' . ( $idx + 1 ) . ']';

			if ( '' !== $row['prenume'] ) {
				$item->add_meta_data( 'Abonat - Prenume' . $n, $row['prenume'], true );
			}
			if ( '' !== $row['nume'] ) {
				$item->add_meta_data( 'Abonat - Nume' . $n, $row['nume'], true );
			}
			if ( '' !== $row['email'] ) {
				$item->add_meta_data( 'Abonat - Email' . $n, $row['email'], true );
			}
			if ( '' !== $row['phone'] ) {
				$item->add_meta_data( 'Abonat - Telefon' . $n, $row['phone'], true );
			}
			$item->add_meta_data( 'Abonat - Trimite email' . $n, '1' === $row['send_email'] ? 'Da' : 'Nu', true );
		}
	},
	10,
	4
);
