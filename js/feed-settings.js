/**
 * Feed settings enhancements for the Gravity Forms to Monday add-on.
 *
 * The board <select> submits the feed form on change (via the PHP `onchange`
 * setting) so the server can re-render group and column fields for the chosen
 * board. This file is a placeholder for future client-side refinements such as
 * an AJAX "Refresh boards" control.
 */
( function ( $ ) {
	'use strict';

	$( document ).on( 'gform_load_field_settings', function () {
		// Reserved for future dynamic behavior.
	} );
} )( jQuery );
