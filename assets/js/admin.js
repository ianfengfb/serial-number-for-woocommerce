( function ( $ ) {
	'use strict';

	$( function () {
		$( '.snw-search-select' ).each( function () {
			var $el = $( this );

			$el.selectWoo( {
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
				width: '100%',
			} );
		} );
	} );
} )( jQuery );
