jQuery( function ( $ ) {
	$( document ).on( 'click', '.snw-add-serial-btn', function ( e ) {
		e.preventDefault();

		var $btn    = $( this );
		var $wrap   = $btn.closest( '.snw-order-item-add-serial' );
		var $input  = $wrap.find( '.snw-add-serial-input' );
		var $result = $wrap.find( '.snw-add-serial-result' );
		var serial  = $.trim( $input.val() );

		if ( ! serial ) {
			return;
		}

		$btn.prop( 'disabled', true );
		$result.removeClass( 'snw-add-serial-error' ).text( '' );

		$.post( SNWOrderItemSerials.ajaxUrl, {
			action:         'snw_add_order_item_serial',
			nonce:          SNWOrderItemSerials.nonce,
			order_id:       $btn.data( 'order-id' ),
			item_id:        $btn.data( 'item-id' ),
			serial_number:  serial
		} ).done( function ( response ) {
			if ( response.success ) {
				window.location.reload();
				return;
			}

			$btn.prop( 'disabled', false );
			$result.addClass( 'snw-add-serial-error' ).text( response.data && response.data.message ? response.data.message : 'Error' );
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			$result.addClass( 'snw-add-serial-error' ).text( 'Request failed.' );
		} );
	} );
} );
