jQuery( function ( $ ) {
	$( document ).on( 'click', '.snw-activate-license-btn', function ( e ) {
		e.preventDefault();

		var $btn    = $( this );
		var $result = $btn.siblings( '.snw-activate-license-result' );

		$btn.prop( 'disabled', true );
		$result.removeClass( 'snw-activate-license-error' ).text( '' );

		$.post( SNWLicenseActivation.ajaxUrl, {
			action:    'snw_activate_license',
			nonce:     SNWLicenseActivation.nonce,
			order_id:  $btn.data( 'order-id' ),
			serial_id: $btn.data( 'serial-id' )
		} ).done( function ( response ) {
			if ( response.success ) {
				window.location.reload();
				return;
			}

			$btn.prop( 'disabled', false );
			$result.addClass( 'snw-activate-license-error' ).text( response.data && response.data.message ? response.data.message : 'Error' );
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			$result.addClass( 'snw-activate-license-error' ).text( 'Request failed.' );
		} );
	} );
} );
