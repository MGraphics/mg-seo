/**
 * MG SEO Tools screens: robots.txt suggestion and confirmations.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var suggestion = event.target.closest( '.mgseo-tools-use-suggestion' );
		if ( suggestion ) {
			var target = document.getElementById( suggestion.getAttribute( 'data-target' ) );
			var pre = document.querySelector( '.mgseo-tools-suggestion' );
			if ( target && pre ) {
				target.value = pre.textContent;
				target.focus();
			}
			return;
		}
		var confirmButton = event.target.closest( '.mgseo-confirm' );
		if ( confirmButton && ! window.confirm( confirmButton.getAttribute( 'data-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
}() );
