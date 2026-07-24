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
	} );
} )( jQuery );
