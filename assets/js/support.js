jQuery( function ( $ ) {
	$( '#snw-support-form' ).on( 'submit', function ( e ) {
		e.preventDefault();

		var $form   = $( this );
		var $submit = $form.find( 'button[type="submit"]' );
		var $result = $( '#snw-support-result' );

		$submit.prop( 'disabled', true );
		$result.removeClass( 'notice notice-success notice-error' ).empty();

		$.post( SNWAdmin.ajaxUrl, {
			action:  'snw_submit_support_request',
			nonce:   SNWAdmin.nonce,
			name:    $( '#snw-support-name' ).val(),
			email:   $( '#snw-support-email' ).val(),
			type:    $( '#snw-support-type' ).val(),
			message: $( '#snw-support-message' ).val()
		} ).done( function ( response ) {
			var message = response.data && response.data.message ? response.data.message : '';

			if ( response.success ) {
				$result.addClass( 'notice notice-success' ).html( '<p>' + message + '</p>' );
				$form.trigger( 'reset' );
			} else {
				$result.addClass( 'notice notice-error' ).html( '<p>' + message + '</p>' );
			}
		} ).fail( function () {
			$result.addClass( 'notice notice-error' ).html( '<p>Request failed. Please try again, or email us directly.</p>' );
		} ).always( function () {
			$submit.prop( 'disabled', false );
		} );
	} );
} );
