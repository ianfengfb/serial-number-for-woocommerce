( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '.snw-resend-email-link', function ( e ) {
		e.preventDefault();

		var $link = $( this );
		var label = $link.text();

		if ( ! window.confirm( SNWResendEmail.confirmText ) ) {
			return;
		}

		$link.text( SNWResendEmail.sendingText );

		$.post( SNWAdmin.ajaxUrl, {
			action:     'snw_resend_email',
			nonce:      SNWAdmin.nonce,
			serial_id:  $link.data( 'serial-id' ),
			email_type: $link.data( 'email-type' ),
		} )
			.done( function ( response ) {
				window.alert( response && response.data && response.data.message ? response.data.message : SNWResendEmail.sentText );
			} )
			.fail( function () {
				window.alert( SNWResendEmail.errorText );
			} )
			.always( function () {
				$link.text( label );
			} );
	} );
} )( jQuery );
