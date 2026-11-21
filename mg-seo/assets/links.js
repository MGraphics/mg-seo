/* MG SEO – Internal links: suggestions in the editor, copy buttons, index rebuild. Plain ES2017 + jQuery. */
( function ( $ ) {
	'use strict';

	var cfg = window.mgseoLinks || { i18n: {} };
	var t = cfg.i18n;

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var $tmp = $( '<textarea readonly>' ).val( text ).css( { position: 'fixed', left: '-9999px' } ).appendTo( document.body );
			$tmp[ 0 ].select();
			try {
				document.execCommand( 'copy' );
				resolve();
			} catch ( e ) {
				reject( e );
			}
			$tmp.remove();
		} );
	}

	$( document ).on( 'click', '.mgseo-links-copy, .mgseo-links-anchor', function () {
		var $btn = $( this );
		copyText( String( $btn.data( 'copy' ) ) ).then( function () {
			var label = $btn.text();
			$btn.addClass( 'is-copied' ).text( t.copied );
			setTimeout( function () {
				$btn.removeClass( 'is-copied' ).text( label );
			}, 1200 );
		} );
	} );

	function editorState() {
		var be = window.wp && wp.data && wp.data.select && wp.data.select( 'core/editor' );
		var state = { keywords: $( '#mgseo-focus-keyword' ).val() };
		if ( be && typeof be.getEditedPostContent === 'function' ) {
			state.content = be.getEditedPostContent();
			state.title = be.getEditedPostAttribute( 'title' );
		} else {
			var ed = window.tinymce && tinymce.get( 'content' );
			state.content = ed && ! ed.isHidden() ? ed.getContent() : $( '#content' ).val();
			state.title = $( '#title' ).val();
		}
		return state;
	}

	function render( items ) {
		if ( ! items.length ) {
			return $( '<p class="mgseo-links-none">' ).text( t.none );
		}
		var $ul = $( '<ul class="mgseo-links-list">' );
		items.forEach( function ( item ) {
			var $li = $( '<li class="mgseo-links-item">' );
			$li.append( $( '<a class="mgseo-links-title" target="_blank" rel="noopener">' ).attr( 'href', item.url ).text( item.title ) );
			if ( item.pillar ) {
				$li.append( ' ', $( '<span class="mgseo-links-badge">' ).text( t.pillar ) );
			}
			$li.append( ' ', $( '<button type="button" class="button-link mgseo-links-copy">' ).attr( 'data-copy', item.url ).text( t.copy ) );
			if ( item.in_content ) {
				$li.append( $( '<div class="mgseo-links-hint">' ).text( t.inText + ' ' ).append( $( '<mark>' ).text( item.in_content ) ) );
			}
			var $anchors = $( '<div class="mgseo-links-anchors">' );
			item.anchors.forEach( function ( anchor ) {
				var html = $( '<a>' ).attr( 'href', item.url ).text( anchor ).prop( 'outerHTML' );
				$anchors.append( $( '<button type="button" class="mgseo-links-anchor">' ).attr( { 'data-copy': html, title: t.copyHtml } ).text( anchor ), ' ' );
			} );
			$ul.append( $li.append( $anchors ) );
		} );
		return $ul;
	}

	$( document ).on( 'click', '.mgseo-links-refresh', function () {
		var $box = $( this ).closest( '.mgseo-links-box' );
		var $out = $box.find( '.mgseo-links-suggestions' );
		var data = editorState();
		data.post_id = parseInt( $box.data( 'post-id' ), 10 );
		$out.attr( 'aria-busy', 'true' ).css( 'opacity', 0.5 );
		wp.apiFetch( { path: '/mg-seo/v1/links/suggest', method: 'POST', data: data } ).then( function ( res ) {
			$out.empty().append( render( res.suggestions || [] ) );
		} ).catch( function ( err ) {
			$out.empty().append( $( '<p class="mgseo-links-none">' ).text( ( err && err.message ) || t.error ) );
		} ).finally( function () {
			$out.removeAttr( 'aria-busy' ).css( 'opacity', '' );
		} );
	} );

	$( document ).on( 'click', '.mgseo-links-rebuild', function () {
		var $btn = $( this ).prop( 'disabled', true );
		var $progress = $btn.siblings( '.mgseo-links-progress' );
		function step( offset ) {
			wp.apiFetch( { path: '/mg-seo/v1/links/rebuild', method: 'POST', data: { offset: offset } } ).then( function ( res ) {
				if ( res.done ) {
					$progress.text( t.rebuilt );
					$btn.prop( 'disabled', false );
					return;
				}
				$progress.text( t.progress.replace( '%1$d', res.next ).replace( '%2$d', res.total ) );
				step( res.next );
			} ).catch( function ( err ) {
				$progress.text( ( err && err.message ) || t.error );
				$btn.prop( 'disabled', false );
			} );
		}
		$progress.text( t.loading );
		step( 0 );
	} );
}( jQuery ) );
