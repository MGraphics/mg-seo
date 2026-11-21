/* MG SEO – Search Console admin screens and editor panel. Plain ES2017 + jQuery, no build step. */
( function ( $ ) {
	'use strict';

	var cfg = window.mgseoGsc || { i18n: {} };

	$( document ).on( 'focus', '.mgseo-gsc-select', function () {
		this.select();
	} );

	$( document ).on( 'click', '.mgseo-gsc-confirm', function ( e ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( $( this ).data( 'confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	function call( $btn, path, busyText, done ) {
		var $box = $btn.closest( '.mgseo-gsc-box' );
		var $msg = $box.find( '.mgseo-gsc-message' );
		var label = $btn.text();
		$btn.prop( 'disabled', true ).text( busyText );
		$msg.text( '' ).removeClass( 'is-error' );
		wp.apiFetch( {
			path: '/mg-seo/v1/' + path,
			method: 'POST',
			data: { post_id: parseInt( $box.data( 'post-id' ), 10 ) }
		} ).then( function ( res ) {
			done( res, $box, $msg );
		} ).catch( function ( err ) {
			$msg.text( ( err && err.message ) || cfg.i18n.error ).addClass( 'is-error' );
		} ).finally( function () {
			$btn.prop( 'disabled', false ).text( label );
		} );
	}

	$( document ).on( 'click', '.mgseo-gsc-inspect', function () {
		call( $( this ), 'gsc/inspect', cfg.i18n.checking, function ( res, $box ) {
			$box.find( '.mgseo-gsc-inspection' ).html( res.html );
		} );
	} );

	$( document ).on( 'click', '.mgseo-gsc-submit', function () {
		call( $( this ), 'gsc/indexing', cfg.i18n.submitting, function ( res, $box, $msg ) {
			$msg.text( res.message );
		} );
	} );
}( jQuery ) );
