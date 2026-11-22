/**
 * MasterSpa – subscriber (beneficiar) modals in the classic cart.
 * Delegated handlers, so they keep working after WooCommerce replaces the cart form via AJAX.
 */
( function ( $ ) {
	'use strict';

	function modalFor( formId ) {
		return $( document.getElementById( 'modal-' + formId ) );
	}

	function openModal( $modal ) {
		$modal.prop( 'hidden', false );
		$modal.find( 'input' ).first().trigger( 'focus' );
	}

	function closeModal( $modal ) {
		$modal.prop( 'hidden', true );
	}

	function save( $modal ) {
		var $form = $modal.closest( 'form.woocommerce-cart-form' );
		closeModal( $modal );
		if ( ! $form.length ) {
			return;
		}
		var $update = $form.find( 'button[name="update_cart"], input[name="update_cart"]' ).first();
		if ( $update.length ) {
			// WooCommerce keeps the button disabled until a quantity changes.
			$update.prop( 'disabled', false ).attr( 'aria-disabled', 'false' ).trigger( 'click' );
		} else if ( $form[ 0 ].requestSubmit ) {
			$form[ 0 ].requestSubmit();
		} else {
			$form.trigger( 'submit' );
		}
	}

	$( document )
		.on( 'click', '.open-subscription-modal', function () {
			openModal( modalFor( $( this ).data( 'form-id' ) ) );
		} )
		.on( 'click', '.close-subscription-modal', function () {
			closeModal( $( this ).closest( '.subscription-modal' ) );
		} )
		.on( 'click', '.subscription-modal', function ( e ) {
			if ( e.target === this ) {
				closeModal( $( this ) );
			}
		} )
		.on( 'click', '.update-subscription-user', function () {
			save( $( this ).closest( '.subscription-modal' ) );
		} )
		.on( 'keydown', '.subscription-modal input', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				save( $( this ).closest( '.subscription-modal' ) );
			}
		} )
		.on( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				$( '.subscription-modal:not([hidden])' ).each( function () {
					closeModal( $( this ) );
				} );
			}
		} );
} )( jQuery );
