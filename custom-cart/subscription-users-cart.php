<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Display subscriber forms and tags under each cart item (per quantity).
 */
add_action( 'woocommerce_after_cart_item_name', function( $cart_item, $cart_item_key ) {
    $product = $cart_item['data'] ?? null;
    if ( ! $product ) {
        return;
    }

    $qty = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
    $saved_all = is_array( $cart_item['subscription_user'] ?? null ) ? $cart_item['subscription_user'] : [];

    // Render hidden inputs once per quantity so data is always submitted with cart form
    for ( $i = 0; $i < $qty; $i++ ) {
        $saved = $saved_all[ $i ] ?? [ 'name' => '', 'email' => '', 'phone' => '', 'send_email' => '' ];
        ?>
        <input type="hidden" name="subscription_user[<?php echo esc_attr( $cart_item_key ); ?>][<?php echo $i; ?>][name]" value="<?php echo esc_attr( $saved['name'] ?? '' ); ?>">
        <input type="hidden" name="subscription_user[<?php echo esc_attr( $cart_item_key ); ?>][<?php echo $i; ?>][email]" value="<?php echo esc_attr( $saved['email'] ?? '' ); ?>">
        <input type="hidden" name="subscription_user[<?php echo esc_attr( $cart_item_key ); ?>][<?php echo $i; ?>][phone]" value="<?php echo esc_attr( $saved['phone'] ?? '' ); ?>">
        <input type="hidden" name="subscription_user[<?php echo esc_attr( $cart_item_key ); ?>][<?php echo $i; ?>][send_email]" value="<?php echo esc_attr( $saved['send_email'] ?? '' ); ?>">
        <?php
    }

    // Render UI (tags + modal trigger) for each quantity
    for ( $i = 0; $i < $qty; $i++ ) {
        $saved = $saved_all[ $i ] ?? [ 'name' => '', 'email' => '', 'phone' => '' ];
        $form_id = esc_attr( $cart_item_key . '-' . $i );
        ?>
        <div class="subscription-user-under-title" style="margin-top:8px;">
            <div class="subscription-user-info" id="subscription-user-info-<?php echo $form_id; ?>" style="margin-bottom:8px;">
                <?php
                if ( $saved['name'] || $saved['email'] || $saved['phone'] ) {
                    echo '<strong>Abonat:</strong> ';
                    $tags = [];
                    if ( $saved['name'] ) {
                        $tags[] = '<span class="subscriber-tag" style="background:#e0e7ff;color:#222;padding:2px 8px;border-radius:10px;margin-right:4px;">Nume: ' . esc_html( $saved['name'] ) . '</span>';
                    }
                    if ( $saved['email'] ) {
                        $tags[] = '<span class="subscriber-tag" style="background:#e0ffe0;color:#222;padding:2px 8px;border-radius:10px;margin-right:4px;">Email: ' . esc_html( $saved['email'] ) . '</span>';
                    }
                    if ( $saved['phone'] ) {
                        $tags[] = '<span class="subscriber-tag" style="background:#ffe0e0;color:#222;padding:2px 8px;border-radius:10px;margin-right:4px;">Telefon: ' . esc_html( $saved['phone'] ) . '</span>';
                    }
                    echo implode( ' ', $tags );
                }
                ?>
            </div>

            <button type="button" class="open-subscription-modal" data-form-id="<?php echo $form_id; ?>" style="margin-bottom:8px;">Adaugă/Editează utilizator pentru acest abonament (<?php echo ($i+1) . '/' . $qty; ?>)</button>

            <!-- Modal -->
            <div class="subscription-modal" id="modal-<?php echo $form_id; ?>" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100vw; height:100vh; background:rgba(0,0,0,0.4);">
                <div style="background:#fff; max-width:420px; margin:5% auto; padding:20px; border-radius:8px; position:relative;">
                    <button type="button" class="close-subscription-modal" data-form-id="<?php echo $form_id; ?>" style="position:absolute; top:8px; right:8px; font-size:20px; background:none; border:none; cursor:pointer;">&times;</button>
                    <h4 style="margin-top:0;">Utilizator abonament (<?php echo ($i+1) . '/' . $qty; ?>)</h4>
                    <div class="subscription-user-form" id="subscription-form-<?php echo $form_id; ?>">
                        <p style="margin:0 0 8px;">
                            <label>Nume<br>
                                <input type="text" class="subscription-user-input-name" value="<?php echo esc_attr( $saved['name'] ?? '' ); ?>" style="width:100%; max-width:360px;">
                            </label>
                        </p>
                        <p style="margin:0 0 8px;">
                            <label>Email<br>
                                <input type="email" class="subscription-user-input-email" value="<?php echo esc_attr( $saved['email'] ?? '' ); ?>" style="width:100%; max-width:360px;">
                            </label>
                        </p>
                        <p style="margin:0;">
                            <label>Telefon<br>
                                <input type="text" class="subscription-user-input-phone" value="<?php echo esc_attr( $saved['phone'] ?? '' ); ?>" style="width:100%; max-width:360px;">
                            </label>
                        </p>
                        <p style="margin-top:12px;">
                            <label style="display:inline-block; margin-right:12px;">
                                <input type="checkbox" class="subscription-user-input-sendemail" value="1" <?php echo ! empty( $saved['send_email'] ) ? 'checked' : ''; ?>> Trimite email cu informațiile
                            </label>
                            <button type="button" class="update-subscription-user" data-form-id="<?php echo $form_id; ?>">Update</button>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- per-item inline JS removed; using centralized jQuery initializer below -->
        <?php
    }
}, 10, 2 );

/**
 * Add nonce field once in cart so updates are secure
 */
add_action( 'woocommerce_after_cart_contents', function() {
    wp_nonce_field( 'save_subscription_users', 'save_subscription_users_nonce' );

    // Global initializer to (re)bind modal JS after AJAX/cart updates — jQuery-based
    ?>
    <script>
    (function(){
        function whenJQuery(cb){
            if (window.jQuery) return cb(window.jQuery);
            var s = document.createElement('script');
            s.src = 'https://code.jquery.com/jquery-3.6.0.min.js';
            s.crossOrigin = 'anonymous';
            s.onload = function(){ cb(window.jQuery); };
            document.head.appendChild(s);
        }

        whenJQuery(function($){
            function escapeHtml(str){ return $('<div/>').text(str).html(); }

            function initSubscriptionModals(root){
                root = root || document;
                $(root).find('.subscription-modal').each(function(){
                    var $modal = $(this);
                    if ($modal.data('subInit')) return;
                    var formId = this.id.replace(/^modal-/, '');
                    var $openBtn = $('.open-subscription-modal[data-form-id="' + formId + '"]');
                    var $closeBtn = $modal.find('.close-subscription-modal');
                    var $name = $modal.find('.subscription-user-input-name');
                    var $email = $modal.find('.subscription-user-input-email');
                    var $phone = $modal.find('.subscription-user-input-phone');
                    var $send = $modal.find('.subscription-user-input-sendemail');
                    var $info = $('#subscription-user-info-' + formId);

                    function doUpdate(){
                        var name = $.trim($name.val() || '');
                        var email = $.trim($email.val() || '');
                        var phone = $.trim($phone.val() || '');
                        var send_email = ($send.length && $send.is(':checked')) ? '1' : '';
                       
                        var lastDash = formId.lastIndexOf('-');
                        var key = lastDash > -1 ? formId.substring(0, lastDash) : formId;
                        var idx = lastDash > -1 ? formId.substring(lastDash + 1) : '';
                        var $cartForm = $('form.woocommerce-cart-form');
                        if ($cartForm.length){
                            var $nameField = $cartForm.find('input[name="subscription_user[' + key + '][' + idx + '][name]"]');
                            var $emailField = $cartForm.find('input[name="subscription_user[' + key + '][' + idx + '][email]"]');
                            var $phoneField = $cartForm.find('input[name="subscription_user[' + key + '][' + idx + '][phone]"]');
                            var $sendField = $cartForm.find('input[name="subscription_user[' + key + '][' + idx + '][send_email]"]');
                            if ($nameField.length) $nameField.val(name);
                            if ($emailField.length) $emailField.val(email);
                            if ($phoneField.length) $phoneField.val(phone);
                            if ($sendField.length) $sendField.val(send_email);

                            $modal.hide();
                            var $upd = $cartForm.find('button[name="update_cart"], input[name="update_cart"]');
                            if ($upd.length){
                                try{ $upd.first().trigger('click'); }
                                catch(e){ if ($cartForm[0].requestSubmit) $cartForm[0].requestSubmit(); else $cartForm.submit(); }
                            } else {
                                if ($cartForm[0].requestSubmit) $cartForm[0].requestSubmit(); else $cartForm.submit();
                            }
                        } else {
                            $modal.hide();
                        }
                    }

                    function handleEnterKey(e){ if (e.which === 13){ e.preventDefault(); doUpdate(); } }

                    $openBtn.on('click.subscription', function(){ $modal.show(); });
                    $closeBtn.on('click.subscription', function(){ $modal.hide(); });
                    $modal.on('click.subscription', function(e){ if (e.target === this) $modal.hide(); });
                    $modal.find('.update-subscription-user').on('click.subscription', doUpdate);
                    $name.on('keydown.subscription', handleEnterKey);
                    $email.on('keydown.subscription', handleEnterKey);
                    $phone.on('keydown.subscription', handleEnterKey);

                    $modal.data('subInit', 1);
                });
            }

            $(function(){ initSubscriptionModals(document); });

            // MutationObserver fallback + WooCommerce events
            var cartFormEl = document.querySelector('form.woocommerce-cart-form');
            if (cartFormEl){
                var mo = new MutationObserver(function(){ initSubscriptionModals(document); });
                mo.observe(cartFormEl, { childList: true, subtree: true });
            }
            $(document).on('updated_wc_div updated_cart_totals wc_fragments_refreshed updated_cart', function(){ initSubscriptionModals(document); });
        });
    })();
    </script>
    <?php
} );


/**
 * Save subscription_user data when customer clicks Update cart
 */
add_action( 'wp_loaded', function() {
    if ( ! isset( $_POST['update_cart'] ) ) {
        return;
    }
    if ( ! WC()->cart ) {
        return;
    }
    if ( ! isset( $_POST['save_subscription_users_nonce'] ) || ! wp_verify_nonce( $_POST['save_subscription_users_nonce'], 'save_subscription_users' ) ) {
        return;
    }

    $posted = $_POST['subscription_user'] ?? [];
    if ( empty( $posted ) || ! is_array( $posted ) ) {
        return;
    }

    foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
        if ( ! isset( $posted[ $cart_item_key ] ) || ! is_array( $posted[ $cart_item_key ] ) ) {
            continue;
        }
        $user_data_list = $posted[ $cart_item_key ];
        $subscription_users = [];
        foreach ( $user_data_list as $user_data ) {
            $subscription_users[] = [
                'name'       => sanitize_text_field( $user_data['name'] ?? '' ),
                'email'      => sanitize_email( $user_data['email'] ?? '' ),
                'phone'      => sanitize_text_field( $user_data['phone'] ?? '' ),
                'send_email' => isset( $user_data['send_email'] ) && $user_data['send_email'] !== '' ? '1' : '',
            ];
        }
        WC()->cart->cart_contents[ $cart_item_key ]['subscription_user'] = $subscription_users;
    }

    // Persist to session
    WC()->cart->set_session();

}, 20 );


/**
 * Add subscription users as order item meta so they appear in admin and emails
 */
add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) {
    if ( empty( $values['subscription_user'] ) || ! is_array( $values['subscription_user'] ) ) {
        return;
    }

    foreach ( $values['subscription_user'] as $idx => $data ) {
        $name      = sanitize_text_field( $data['name'] ?? '' );
        $email     = sanitize_email( $data['email'] ?? '' );
        $phone     = sanitize_text_field( $data['phone'] ?? '' );
        $send_email = isset( $data['send_email'] ) && $data['send_email'] === '1' ? true : false;

        if ( $name !== '' )  $item->add_meta_data( 'Abonat - Nume [' . ( $idx + 1 ) . ']', $name, true );
        if ( $email !== '' ) $item->add_meta_data( 'Abonat - Email [' . ( $idx + 1 ) . ']', $email, true );
        if ( $phone !== '' ) $item->add_meta_data( 'Abonat - Telefon [' . ( $idx + 1 ) . ']', $phone, true );
        if ( $send_email )      $item->add_meta_data( 'Abonat - Trimite email [' . ( $idx + 1 ) . ']', 'Da', true );
        else                   $item->add_meta_data( 'Abonat - Trimite email [' . ( $idx + 1 ) . ']', 'Nu', true );
    }

}, 10, 4 );

