( function ( $ ) {
	'use strict';

	function renumberViews() {
		$( '.mlab-mf-rule-row' ).each( function ( index ) {
			$( this ).find( '[name^="dynamic_views["]' ).each( function () {
				this.name = this.name.replace( /dynamic_views\[\d+\]/, 'dynamic_views[' + index + ']' );
			} );
		} );
	}

	$( function () {
		$( document )
			.on( 'click', '.mlab-mf-add-view', function () {
				var $rows = $( '.mlab-mf-dynamic-views' );
				var $source = $rows.find( '.mlab-mf-rule-row' ).last();
				if ( ! $source.length ) {
					return;
				}
				var $copy = $source.clone();
				$copy.find( 'input' ).val( '' );
				$copy.find( 'input[type="number"]' ).val( '0' );
				$copy.find( 'select' ).prop( 'selectedIndex', 0 );
				$copy.find( 'input[type="hidden"]' ).val( '' );
				$rows.append( $copy );
				renumberViews();
				$copy.find( 'input[type="text"]' ).first().trigger( 'focus' );
			} )
			.on( 'click', '.mlab-mf-remove-view', function () {
				var $rows = $( '.mlab-mf-dynamic-views .mlab-mf-rule-row' );
				if ( 1 === $rows.length ) {
					$rows.find( 'input' ).val( '' );
					$rows.find( 'select' ).prop( 'selectedIndex', 0 );
					return;
				}
				$( this ).closest( '.mlab-mf-rule-row' ).remove();
				renumberViews();
			} );
	} );
} )( jQuery );
