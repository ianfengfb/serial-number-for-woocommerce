( function ( $ ) {
	'use strict';

	$( function () {
		var $tbody = $( '#snw-bulk-rows tbody' );
		var template = $( '#snw-bulk-row-template' ).html();
		var rowIndex = 1;

		function initRow( $row ) {
			if ( window.snwInitSearchSelects ) {
				window.snwInitSearchSelects( $row.find( '.snw-search-select' ) );
			}
		}

		initRow( $tbody.find( 'tr' ) );

		$( '#snw-add-row' ).on( 'click', function ( e ) {
			e.preventDefault();

			var $row = $( template.replace( /__INDEX__/g, rowIndex ) );

			$tbody.append( $row );
			initRow( $row );

			rowIndex++;
		} );

		$tbody.on( 'click', '.snw-remove-row', function ( e ) {
			e.preventDefault();

			if ( $tbody.find( 'tr' ).length > 1 ) {
				$( this ).closest( 'tr' ).remove();
			}
		} );
	} );
} )( jQuery );
