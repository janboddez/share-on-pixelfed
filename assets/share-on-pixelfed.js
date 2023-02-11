jQuery( document ).ready( function ( $ ) {
	$( '#share-on-pixelfed .unlink' ).click( function( e ) {
		e.preventDefault();

		if ( ! confirm( share_on_pixelfed_obj.message ) ) {
			return false;
		}

		var button = $( this );
		var data = {
			'action': 'share_on_pixelfed_unlink_url',
			'post_id': share_on_pixelfed_obj.post_id, // Current post ID.
			'share_on_pixelfed_nonce': $( this ).parent().siblings( '#share_on_pixelfed_nonce' ).val() // Nonce.
		};

		$.post( ajaxurl, data, function( response ) {
			// On success, remove extra paragraph.
			button.closest( '.description' ).remove();
		} );
	} );

	$( '#share-on-pixelfed [for="share_on_pixelfed_status"]' ).click( function() {
		$( '#share-on-pixelfed details' ).attr( 'open', 'open' );
	} );

	$( '.settings_page_share-on-pixelfed .button-reset-settings' ).click( function( e ) {
		if ( ! confirm( share_on_pixelfed_obj.message ) ) {
			e.preventDefault();
		}
	} );
} );
