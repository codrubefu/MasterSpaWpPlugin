/**
 * MasterSpa – subscriber (beneficiar) editor for the WooCommerce Cart Block.
 * Plain JS (no build step): uses wp.* and wc.* globals.
 */
( function ( wp, wc ) {
	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.components || ! wc || ! wc.blocksCheckout ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var createPortal = wp.element.createPortal;
	var Fragment = wp.element.Fragment;
	var C = wp.components;
	var OrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;
	var extensionCartUpdate = wc.blocksCheckout.extensionCartUpdate;
	var NAMESPACE = 'masterspa';

	function summary( row ) {
		var name = ( ( row.prenume || '' ) + ' ' + ( row.nume || '' ) ).trim();
		var parts = [ name, row.email, row.phone ].filter( Boolean );
		return parts.length ? parts.join( ' · ' ) : 'Necompletat';
	}

	function SubscriberModal( props ) {
		var init = props.row || {};
		var s = useState( {
			prenume: init.prenume || '',
			nume: init.nume || '',
			email: init.email || '',
			phone: init.phone || '',
			send_email: init.send_email === '1',
		} );
		var values = s[ 0 ];
		var setValues = s[ 1 ];
		var b = useState( false );
		var busy = b[ 0 ];
		var setBusy = b[ 1 ];
		var e = useState( '' );
		var error = e[ 0 ];
		var setError = e[ 1 ];

		function set( field ) {
			return function ( v ) {
				var next = Object.assign( {}, values );
				next[ field ] = v;
				setValues( next );
			};
		}

		function save() {
			setBusy( true );
			setError( '' );
			extensionCartUpdate( {
				namespace: NAMESPACE,
				data: {
					cart_item_key: props.itemKey,
					index: props.index,
					prenume: values.prenume.trim(),
					nume: values.nume.trim(),
					email: values.email.trim(),
					phone: values.phone.trim(),
					send_email: values.send_email ? '1' : '',
				},
			} )
				.then( function () {
					setBusy( false );
					props.onClose();
				} )
				.catch( function ( err ) {
					setBusy( false );
					setError( ( err && err.message ) || 'Salvarea a eșuat. Încearcă din nou.' );
				} );
		}

		function onKeyDown( ev ) {
			if ( ev.key === 'Enter' && ! busy ) {
				ev.preventDefault();
				save();
			}
		}

		return el(
			C.Modal,
			{
				title: 'Utilizator abonament (' + props.label + ')',
				onRequestClose: props.onClose,
				className: 'masterspa-subscriber-modal',
			},
			el(
				'div',
				{ onKeyDown: onKeyDown },
				error && el( C.Notice, { status: 'error', isDismissible: false }, error ),
				el( C.TextControl, { label: 'Prenume', value: values.prenume, onChange: set( 'prenume' ), __nextHasNoMarginBottom: true } ),
				el( C.TextControl, { label: 'Nume', value: values.nume, onChange: set( 'nume' ), __nextHasNoMarginBottom: true } ),
				el( C.TextControl, { label: 'Email', type: 'email', value: values.email, onChange: set( 'email' ), __nextHasNoMarginBottom: true } ),
				el( C.TextControl, { label: 'Telefon', type: 'tel', value: values.phone, onChange: set( 'phone' ), __nextHasNoMarginBottom: true } ),
				el( C.CheckboxControl, {
					label: 'Trimite email cu informațiile',
					checked: values.send_email,
					onChange: set( 'send_email' ),
					__nextHasNoMarginBottom: true,
				} ),
				el(
					'div',
					{ className: 'masterspa-subscriber-modal__actions' },
					el( C.Button, { variant: 'tertiary', onClick: props.onClose, disabled: busy }, 'Anulează' ),
					el( C.Button, { variant: 'primary', onClick: save, isBusy: busy, disabled: busy }, 'Update' )
				)
			)
		);
	}

	/**
	 * Buttons rendered under one cart line item (one per quantity unit).
	 * Saved values are shown above them by the block itself (item_data: "Abonat (i/qty)").
	 */
	function ItemSubscribers( props ) {
		var o = useState( null );
		var open = o[ 0 ];
		var setOpen = o[ 1 ];
		var item = props.item;
		var list = props.list;

		return el(
			'div',
			{ className: 'masterspa-item-subscribers__inner' },
			list.map( function ( row, i ) {
				var label = i + 1 + '/' + list.length;
				var filled = summary( row ) !== 'Necompletat';
				return el(
					Fragment,
					{ key: i },
					el(
						C.Button,
						{
							variant: 'secondary',
							size: 'small',
							className: 'masterspa-item-subscribers__button' + ( filled ? ' is-filled' : '' ),
							onClick: function () { setOpen( i ); },
						},
						( filled ? 'Modifică' : 'Adaugă' ) + ' beneficiar (' + label + ')'
					),
					open === i &&
						el( SubscriberModal, {
							itemKey: item.key,
							index: i,
							label: label,
							row: row,
							onClose: function () { setOpen( null ); },
						} )
				);
			} )
		);
	}

	/**
	 * Find (or create) a container inside each cart row: tr[data-cart-item-key] .wc-block-cart-item__wrap.
	 * Re-syncs with a MutationObserver because the block re-renders rows on cart updates.
	 */
	function useRowContainers( keys ) {
		var st = useState( {} );
		var map = st[ 0 ];
		var setMap = st[ 1 ];
		var keysStr = keys.join( ',' );

		useEffect( function () {
			var root = document.querySelector( '.wp-block-woocommerce-cart' ) || document.body;

			function sync() {
				var next = {};
				keys.forEach( function ( k ) {
					var row = root.querySelector( 'tr.wc-block-cart-items__row[data-cart-item-key="' + k.replace( /"/g, '' ) + '"]' );
					var wrap = row && row.querySelector( '.wc-block-cart-item__wrap' );
					if ( ! wrap ) {
						return;
					}
					var c = null;
					for ( var n = 0; n < wrap.children.length; n++ ) {
						if ( wrap.children[ n ].classList.contains( 'masterspa-item-subscribers' ) ) {
							c = wrap.children[ n ];
						}
					}
					if ( ! c ) {
						c = document.createElement( 'div' );
						c.className = 'masterspa-item-subscribers';
						wrap.appendChild( c );
					}
					next[ k ] = c;
				} );
				setMap( function ( prev ) {
					var pk = Object.keys( prev );
					var nk = Object.keys( next );
					var same = pk.length === nk.length && nk.every( function ( k ) { return prev[ k ] === next[ k ]; } );
					return same ? prev : next;
				} );
			}

			sync();
			var mo = new MutationObserver( sync );
			mo.observe( root, { childList: true, subtree: true } );
			return function () { mo.disconnect(); };
		}, [ keysStr ] );

		return map;
	}

	function CartItemsSubscribers( props ) {
		var items = ( ( props.cart && props.cart.cartItems ) || [] ).filter( function ( item ) {
			var ext = item.extensions && item.extensions[ NAMESPACE ];
			return ext && Array.isArray( ext.subscription_user ) && ext.subscription_user.length;
		} );
		var containers = useRowContainers( items.map( function ( item ) { return item.key; } ) );

		return el(
			Fragment,
			null,
			items.map( function ( item ) {
				var target = containers[ item.key ];
				if ( ! target ) {
					return null;
				}
				return createPortal(
					el( ItemSubscribers, { item: item, list: item.extensions[ NAMESPACE ].subscription_user } ),
					target,
					item.key
				);
			} )
		);
	}

	// Fill children receive { cart, extensions, context } via cloneElement.
	// The fill renders nothing in the sidebar; the UI is portaled into each cart row.
	function CartOnly( fillProps ) {
		if ( fillProps.context !== 'woocommerce/cart' ) {
			return null;
		}
		return el( CartItemsSubscribers, fillProps );
	}

	wp.plugins.registerPlugin( 'masterspa-subscribers', {
		scope: 'woocommerce-checkout',
		render: function () {
			return el( OrderMeta, null, el( CartOnly ) );
		},
	} );
} )( window.wp, window.wc );
