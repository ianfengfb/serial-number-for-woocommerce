jQuery( function ( $ ) {
	$( document ).on( 'click', '.snw-admin-activate-license-btn', function ( e ) {
		e.preventDefault();

		var $btn    = $( this );
		var $result = $btn.siblings( '.snw-admin-activate-license-result' );

		$btn.prop( 'disabled', true );
		$result.removeClass( 'snw-admin-activate-license-error' ).text( '' );

		$.post( SNWOrderItemSerials.ajaxUrl, {
			action:    'snw_admin_activate_license',
			nonce:     SNWOrderItemSerials.nonce,
			serial_id: $btn.data( 'serial-id' )
		} ).done( function ( response ) {
			if ( response.success ) {
				window.location.reload();
				return;
			}

			$btn.prop( 'disabled', false );
			$result.addClass( 'snw-admin-activate-license-error' ).text( response.data && response.data.message ? response.data.message : 'Error' );
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			$result.addClass( 'snw-admin-activate-license-error' ).text( 'Request failed.' );
		} );
	} );
} );
