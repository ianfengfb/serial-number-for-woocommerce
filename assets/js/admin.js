( function ( $ ) {
	'use strict';

	$( function () {
		$( '.snw-search-select' ).each( function () {
			var $el = $( this );

			$el.select2( {
				ajax: {
					url: SNWAdmin.ajaxUrl,
					dataType: 'json',
					delay: 250,
					data: function ( params ) {
						return {
							action: 'snw_search_' + $el.data( 'type' ) + 's',
							term: params.term,
							nonce: SNWAdmin.nonce,
						};
					},
					processResults: function ( response ) {
						return { results: response.data || [] };
					},
				},
				minimumInputLength: 1,
				placeholder: $el.data( 'placeholder' ),
				allowClear: true,
				width: '25em',
			} );
		} );

		$( '#snw-generate-serial' ).on( 'click', function ( e ) {
			e.preventDefault();

			var $button = $( this );
			var $input = $( '#snw-serial-number' );

			$button.prop( 'disabled', true );

			$.getJSON( SNWAdmin.ajaxUrl, {
				action: 'snw_generate_serial',
				nonce: SNWAdmin.nonce,
			} )
				.done( function ( response ) {
					if ( response && response.success && response.data.serial_number ) {
						$input.val( response.data.serial_number ).trigger( 'change' );
					}
				} )
				.always( function () {
					$button.prop( 'disabled', false );
				} );
		} );
	} );
} )( jQuery );
