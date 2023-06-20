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
			// On success, untick the checkbox, and remove the link (and the `button` with it).
			$( 'input[name="share_on_pixelfed"]' ).prop( 'checked', false );
			button.closest( '.description' ).remove();
		} );
	} );

	$( '.settings_page_share-on-pixelfed .button-reset-settings' ).click( function( e ) {
		if ( ! confirm( share_on_pixelfed_obj.message ) ) {
			e.preventDefault();
		}
	} );
} );
