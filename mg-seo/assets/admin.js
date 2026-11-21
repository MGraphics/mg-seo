/* MG SEO admin scripts. No build step: plain ES2017 + jQuery from WordPress core. */
( function ( $ ) {
	'use strict';

	var cfg = window.mgseoAdmin || {};

	/* Tabs inside the SEO box. */
	$( document ).on( 'click', '.mgseo-box-tab', function () {
		var $tab = $( this );
		var $box = $tab.closest( '.mgseo-box' );
		$box.find( '.mgseo-box-tab' ).removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
		$tab.addClass( 'is-active' ).attr( 'aria-selected', 'true' );
		$box.find( '.mgseo-box-panel' ).removeClass( 'is-active' );
		$box.find( '.mgseo-box-panel[data-panel="' + $tab.data( 'tab' ) + '"]' ).addClass( 'is-active' );
	} );

	/* Media picker for image fields. */
	$( document ).on( 'click', '.mgseo-media', function ( e ) {
		e.preventDefault();
		var target = $( this ).data( 'target' );
		var frame = wp.media( {
			title: cfg.i18n ? cfg.i18n.chooseImage : 'Choose image',
			button: { text: cfg.i18n ? cfg.i18n.useImage : 'Use this image' },
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			$( '#' + target ).val( att.url ).trigger( 'change' );
		} );
		frame.open();
	} );

	/* show_if: hide a settings row unless the referenced toggle is checked. */
	function applyShowIf() {
		$( '[data-show-if]' ).each( function () {
			var $row = $( this );
			var $ref = $( '#' + $row.data( 'show-if' ) );
			var values = $row.data( 'show-value' );
			if ( ! $ref.length ) {
				$row.show();
			} else if ( values !== undefined && values !== null && values !== '' ) {
				$row.toggle( String( values ).split( '|' ).indexOf( String( $ref.val() ) ) !== -1 );
			} else {
				$row.toggle( $ref.is( ':checked' ) );
			}
		} );
	}
	$( document ).on( 'change', '.mgseo-settings-form input[type=checkbox], .mgseo-settings-form select', applyShowIf );
	$( applyShowIf );

	/* ---------- SEO box: counters, preview, live analysis ---------- */

	var $box = $( '.mgseo-box' );
	if ( ! $box.length ) {
		return;
	}
	var postId = parseInt( $box.data( 'post-id' ), 10 );

	function blockEditor() {
		return window.wp && wp.data && wp.data.select && wp.data.select( 'core/editor' ) && typeof wp.data.select( 'core/editor' ).getEditedPostContent === 'function' ? wp.data.select( 'core/editor' ) : null;
	}

	function editorState() {
		var be = blockEditor();
		if ( be ) {
			return {
				content: be.getEditedPostContent() || '',
				title: be.getEditedPostAttribute( 'title' ) || '',
				slug: be.getEditedPostAttribute( 'slug' ) || ''
			};
		}
		var content = '';
		if ( window.tinymce && tinymce.get( 'content' ) && ! tinymce.get( 'content' ).isHidden() ) {
			content = tinymce.get( 'content' ).getContent();
		} else {
			content = $( '#content' ).val() || '';
		}
		return {
			content: content,
			title: $( '#title' ).val() || '',
			slug: $( '#post_name' ).val() || $( '#editable-post-name-full' ).text() || ''
		};
	}

	function updateCounters() {
		$( '.mgseo-counter' ).each( function () {
			var $c = $( this );
			var $field = $( '#' + $c.data( 'for' ) );
			var val = $field.val() || $field.data( 'preview' ) || '';
			var max = parseInt( $c.data( 'max' ), 10 );
			$c.text( val.length + ' / ' + max ).toggleClass( 'is-over', val.length > max );
		} );
	}

	var lastResult = null;
	function renderPreview() {
		var title = ( lastResult && lastResult.title ) || $( '#mgseo-title' ).val() || $( '#mgseo-title' ).data( 'preview' ) || '';
		var desc = ( lastResult && lastResult.desc ) || $( '#mgseo-description' ).val() || $( '#mgseo-description' ).data( 'preview' ) || '';
		$box.find( '.mgseo-serp-title' ).text( title.length > 65 ? title.substr( 0, 62 ) + '…' : title );
		$box.find( '.mgseo-serp-desc' ).text( desc.length > 165 ? desc.substr( 0, 160 ) + '…' : desc );
	}

	function renderAnalysis( result ) {
		var $list = $box.find( '.mgseo-analysis-list' ).empty();
		var labels = { ok: '✓', warn: '!', fail: '✕' };
		( result.checks || [] ).forEach( function ( check ) {
			$( '<li/>' ).addClass( 'is-' + check.status ).append(
				$( '<span class="mgseo-check-icon" aria-hidden="true"/>' ).text( labels[ check.status ] || '' ),
				$( '<span/>' ).text( ' ' + check.message )
			).appendTo( $list );
		} );
		var score = parseInt( result.score, 10 ) || 0;
		var hasKeyword = result.has_keyword !== false;
		var cls = ! hasKeyword ? 'is-none' : ( score >= 80 ? 'is-good' : ( score >= 50 ? 'is-ok' : 'is-bad' ) );
		var text = hasKeyword ? score + '/100' : ( ( cfg.i18n && cfg.i18n.noKeyword ) || 'No keyword' );
		$box.find( '.mgseo-score-badge' ).removeClass( 'is-good is-ok is-bad is-none' ).addClass( cls ).text( text );
		/* Same badge in the Publish box (classic editor) and in the admin bar. */
		$( '.mgseo-live-score, #wp-admin-bar-seo-score .seo-score-value' ).removeClass( 'is-good is-ok is-bad is-none' ).addClass( cls ).text( text );
		$( document ).trigger( 'mgseo:analysis', [ result ] );
		if ( window.mgseoScoreStore ) {
			window.mgseoScoreStore( score, result );
		}
	}

	var timer = null;
	var inflight = null;
	function analyse() {
		if ( ! $box.find( '.mgseo-analysis' ).length || ! window.wp || ! wp.apiFetch ) {
			return;
		}
		var state = editorState();
		var data = {
			post_id: postId,
			title: $( '#mgseo-title' ).val() || '',
			description: $( '#mgseo-description' ).val() || '',
			keywords: $( '#mgseo-focus-keyword' ).val() || '',
			content: state.content,
			post_title: state.title,
			slug: state.slug
		};
		if ( inflight && inflight.abort ) {
			inflight.abort();
		}
		var controller = window.AbortController ? new AbortController() : null;
		inflight = controller;
		wp.apiFetch( {
			url: cfg.restUrl + 'analyze',
			method: 'POST',
			data: data,
			signal: controller ? controller.signal : undefined
		} ).then( function ( result ) {
			lastResult = result;
			renderAnalysis( result );
			renderPreview();
			updateCounters();
		} ).catch( function () {} );
	}

	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( analyse, 700 );
	}

	$box.on( 'input change', 'input, textarea, select', function () {
		lastResult = null;
		updateCounters();
		renderPreview();
		schedule();
	} );
	$( '#title, #content' ).on( 'input change', schedule );
	$( document ).on( 'tinymce-editor-init', function ( event, editor ) {
		if ( editor.id === 'content' ) {
			editor.on( 'keyup change', schedule );
		}
	} );
	if ( window.wp && wp.data && wp.data.subscribe ) {
		var lastContent = null;
		wp.data.subscribe( function () {
			var be = blockEditor();
			if ( ! be ) {
				return;
			}
			var c = be.getEditedPostContent();
			if ( c !== lastContent ) {
				if ( lastContent !== null ) {
					schedule();
				}
				lastContent = c;
			}
		} );
	}

	/* Keyword suggestion (shown while the focus keyword is empty). */
	$box.on( 'click', '.mgseo-use-suggestion', function ( e ) {
		e.preventDefault();
		$( '#mgseo-focus-keyword' ).val( $( this ).data( 'keyword' ) ).trigger( 'change' ).trigger( 'focus' );
		$( this ).closest( '.mgseo-keyword-suggestion' ).remove();
	} );

	/*
	 * Show the SEO box (the block editor collapses the meta box pane) and optionally focus the keyword field.
	 * Used by "Set a focus keyword" links (lists, overview, admin bar) and the block editor sidebar.
	 */
	window.mgseoRevealBox = function ( focusKeyword ) {
		var tries = 0;
		var opened = false;
		var reveal = function () {
			var pane = document.querySelector( '.edit-post-meta-boxes-main' );
			if ( pane && ! opened ) {
				var toggle = Array.prototype.find.call( pane.querySelectorAll( 'button[aria-expanded="false"]' ), function ( b ) {
					return ! b.closest( '.postbox' );
				} );
				if ( toggle ) {
					toggle.click();
				}
				opened = true;
			}
			var postbox = document.getElementById( 'mgseo-box' );
			if ( postbox && postbox.classList.contains( 'closed' ) ) {
				var handle = postbox.querySelector( '.handlediv' );
				if ( handle ) {
					handle.click();
				}
			}
			var field = document.getElementById( 'mgseo-focus-keyword' );
			if ( ! field || ! field.offsetParent ) {
				if ( tries++ < 40 ) {
					setTimeout( reveal, 250 );
				}
				return;
			}
			$box.find( '.mgseo-box-tab[data-tab="general"]' ).trigger( 'click' );
			if ( focusKeyword ) {
				field.scrollIntoView( { block: 'center' } );
				field.focus( { preventScroll: true } );
				$( field ).closest( '.mgseo-row' ).addClass( 'is-highlighted' );
			} else if ( postbox ) {
				postbox.scrollIntoView( { behavior: 'smooth' } );
			}
		};
		reveal();
	};
	if ( /[?&]mgseo_focus=keyword\b/.test( window.location.search ) ) {
		setTimeout( function () {
			window.mgseoRevealBox( true );
		}, 300 );
	}

	updateCounters();
	setTimeout( analyse, 400 );
}( jQuery ) );
