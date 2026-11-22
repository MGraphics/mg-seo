/* MG SEO admin scripts. No build step: plain ES2017 + jQuery from WordPress core. */
( function ( $ ) {
	'use strict';

	var cfg = window.mgseoAdmin || {};

	/* Tabs inside the SEO box: full ARIA tab pattern (arrow keys, Home/End, labelled panels). */
	function selectTab( $tab, focus ) {
		var $box = $tab.closest( '.mgseo-box' );
		$box.find( '.mgseo-box-tab' ).removeClass( 'is-active' ).attr( { 'aria-selected': 'false', tabindex: '-1' } );
		$tab.addClass( 'is-active' ).attr( { 'aria-selected': 'true', tabindex: '0' } );
		$box.find( '.mgseo-box-panel' ).removeClass( 'is-active' ).attr( 'hidden', 'hidden' );
		$box.find( '.mgseo-box-panel[data-panel="' + $tab.data( 'tab' ) + '"]' ).addClass( 'is-active' ).removeAttr( 'hidden' );
		if ( focus ) {
			$tab.trigger( 'focus' );
		}
	}
	$( document ).on( 'click', '.mgseo-box-tab', function () {
		selectTab( $( this ), false );
	} );
	$( document ).on( 'keydown', '.mgseo-box-tab', function ( e ) {
		var $tabs = $( this ).closest( '.mgseo-box-tabs' ).find( '.mgseo-box-tab' );
		var i = $tabs.index( this );
		var next = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: $tabs.length - 1 }[ e.key ];
		if ( undefined === next ) {
			return;
		}
		e.preventDefault();
		selectTab( $tabs.eq( ( next + $tabs.length ) % $tabs.length ), true );
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

	/* ---------- Social preview (P2) ---------- */

	$( '.mgseo-social' ).each( function () {
		var $root = $( this );
		var fallback = $root.data( 'fallback' ) || {};
		function render() {
			$root.find( '.mgseo-social-card' ).each( function () {
				var $card = $( this );
				var net = $card.data( 'network' );
				var title = $( '#mgseo-' + net + '-title' ).val() || $( '#mgseo-title' ).val() || fallback.title || '';
				var desc = $( '#mgseo-' + net + '-description' ).val() || $( '#mgseo-description' ).val() || fallback.description || '';
				var img = $( '#mgseo-' + net + '-image' ).val() || ( 'twitter' === net ? $( '#mgseo-og-image' ).val() : '' ) || fallback.image || '';
				title = title.replace( /%sitename%/g, fallback.site || '' ).replace( /%sep%/g, fallback.sep || '-' ).replace( /%title%/g, fallback.postTitle || '' ).replace( /%\w+%/g, '' ).trim();
				desc = desc.replace( /%excerpt%/g, fallback.description || '' ).replace( /%\w+%/g, '' ).trim();
				$card.find( '.mgseo-social-title' ).text( title );
				$card.find( '.mgseo-social-desc' ).text( desc.length > 110 ? desc.slice( 0, 107 ) + '…' : desc );
				var $img = $card.find( '.mgseo-social-img' ).empty().toggleClass( 'is-empty', ! img );
				if ( img ) {
					$( '<img alt="" />' ).attr( 'src', img ).appendTo( $img );
				} else {
					$img.text( cfg.i18n ? cfg.i18n.noImage : '' );
				}
			} );
			$root.find( '.mgseo-image-pick' ).each( function () {
				var $pick = $( this );
				var val = $( '#' + $pick.data( 'target' ) ).val();
				$pick.find( '.mgseo-image-thumb' ).empty().append( val ? $( '<img alt="" />' ).attr( 'src', val ) : null );
				$pick.find( '.mgseo-image-remove' ).prop( 'hidden', ! val );
			} );
		}
		$root.on( 'click', '.mgseo-social-switch button', function () {
			var net = $( this ).data( 'network' );
			$root.find( '.mgseo-social-switch button' ).attr( 'aria-pressed', 'false' );
			$( this ).attr( 'aria-pressed', 'true' );
			$root.find( '.mgseo-social-net' ).attr( 'hidden', 'hidden' ).filter( '[data-network="' + net + '"]' ).removeAttr( 'hidden' );
		} );
		$root.on( 'click', '.mgseo-image-remove', function ( e ) {
			e.preventDefault();
			$( '#' + $( this ).closest( '.mgseo-image-pick' ).data( 'target' ) ).val( '' ).trigger( 'change' );
		} );
		$root.on( 'input change', 'input, textarea', render );
		$( document ).on( 'input change', '#mgseo-title, #mgseo-description', render );
		render();
	} );

	/* ---------- SEO box: shared store, live analysis, Publish box line ---------- */

	var $box = $( '.mgseo-box' );
	var ui = window.mgseoUI;
	var postCfg = window.mgseoEditor && window.mgseoEditor.postId ? window.mgseoEditor : window.mgseoPost;
	if ( ! $box.length || ! ui || ! postCfg || ! postCfg.postId ) {
		return;
	}
	var store = ui.postStore( postCfg );
	var isBlockEditor = document.body.classList.contains( 'block-editor-page' );

	/* Title and description fields (named, saved with the form) and the Google preview. */
	var $snippet = $box.find( '.mgseo-snippet' );
	if ( $snippet.length ) {
		ui.bindSnippet( $snippet[ 0 ], store, {
			postTitle: function () {
				if ( isBlockEditor && wp.data && wp.data.select( 'core/editor' ) ) {
					return wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
				}
				return $( '#title' ).val() || '';
			},
			postUrl: function () {
				return $( '#sample-permalink a' ).attr( 'href' ) || ( postCfg.site && postCfg.site.url ) || '';
			}
		} );
		$snippet.find( '.mgseo-insert-var' ).each( function () {
			ui.variablesToggle( this, document.getElementById( $( this ).data( 'for' ) ) );
		} );
	}

	/* Hints tab: score, the keyword field (named, saved with the form) and grouped hints. */
	var host = $box.find( '.mgseo-hints-host' )[ 0 ];
	var kw = $box.find( '.mgseo-kw' )[ 0 ];
	if ( host ) {
		ui.panel( host, store, {
			keywordEl: kw,
			idPrefix: 'mgseo-box',
			focus: function ( target ) {
				var tab = 'title' === target || 'description' === target ? 'snippet' : 'general';
				selectTab( $box.find( '.mgseo-box-tab[data-tab="' + tab + '"]' ), false );
				var field = { title: 'mgseo-title', description: 'mgseo-description', keyword: 'mgseo-focus-keyword', slug: isBlockEditor ? '' : 'edit-slug-buttons' }[ target ];
				var node = field ? document.getElementById( field ) : null;
				if ( node ) {
					node.scrollIntoView( { block: 'center', behavior: 'smooth' } );
					$( node ).find( 'button' ).addBack( 'input,textarea' ).first().trigger( 'focus' );
				}
			}
		} );
	}

	/* Score in the box header (visible when the box is collapsed) and in the classic Publish box. */
	var $hndle = $( '#mgseo-box .hndle, #mgseo-box .postbox-header h2' ).first();
	var $hd = $( '<span class="mgseo-hd-rating" aria-hidden="true"></span>' );
	$hndle.append( $hd );
	function renderBadges( st ) {
		var v = ui.view( st );
		$hd.empty().append( ui.mark( v.band ) );
		if ( null !== v.score ) {
			$hd.append( $( '<b/>' ).text( v.score + '/100' ), ' ', document.createTextNode( v.word + ( v.fix > 0 ? ' · ' + v.fix + ' ' + ( cfg.i18n ? cfg.i18n.toImprove : '' ) : '' ) ) );
		} else {
			$hd.append( document.createTextNode( v.word ) );
		}
		var $pub = $( '.mgseo-pub-score' );
		if ( $pub.length ) {
			$pub.find( '.mgseo-mark' ).attr( 'class', 'mgseo-mark is-' + v.band );
			var $line = $pub.find( '.mgseo-live-line' ).empty();
			if ( null !== v.score ) {
				$line.append( document.createTextNode( ( cfg.i18n ? cfg.i18n.seo : 'SEO' ) + ': ' ), $( '<b/>' ).text( v.score + '/100' ), document.createTextNode( ', ' + v.word.toLowerCase() ) );
				if ( v.fix > 0 ) {
					$line.append( '<br/>', document.createTextNode( v.sub ) );
				}
			} else {
				$line.text( ( cfg.i18n ? cfg.i18n.seo : 'SEO' ) + ': ' + v.word );
			}
		}
		$( '#wp-admin-bar-seo-score > .ab-item .seo-score-value' ).text( null !== v.score ? String( v.score ) : v.word );
		$( '#wp-admin-bar-seo-score > .ab-item .mgseo-mark' ).attr( 'class', 'mgseo-mark is-' + v.band );
		$( document ).trigger( 'mgseo:analysis', [ st.result ] );
	}
	renderBadges( store.get() );
	store.subscribe( renderBadges );

	/* Classic editor: run the analysis from the editor content (the block editor runs it in editor.js). */
	if ( ! isBlockEditor ) {
		var runner = ui.analysis( store, {
			delay: 800,
			getContent: function () {
				var content = '';
				if ( window.tinymce && tinymce.get( 'content' ) && ! tinymce.get( 'content' ).isHidden() ) {
					content = tinymce.get( 'content' ).getContent();
				} else {
					content = $( '#content' ).val() || '';
				}
				return {
					content: content,
					post_title: $( '#title' ).val() || '',
					slug: $( '#post_name' ).val() || $( '#editable-post-name-full' ).text() || ''
				};
			}
		} );
		$( '#title, #content' ).on( 'input change', function () {
			runner.schedule();
		} );
		$( document ).on( 'tinymce-editor-init', function ( event, editor ) {
			if ( editor.id === 'content' ) {
				editor.on( 'keyup change', function () {
					runner.schedule();
				} );
			}
		} );
		runner.schedule( 300 );
	}

	/*
	 * Show the SEO box (the block editor collapses the meta box pane), open a tab and focus it.
	 * Used by "Set a focus keyword" links, the Publish box "Show" link and the block editor sidebar.
	 */
	window.mgseoRevealBox = function ( focusKeyword, tab ) {
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
			var target = focusKeyword ? document.getElementById( 'mgseo-focus-keyword' ) : postbox;
			if ( ! target || ! target.offsetParent ) {
				if ( tries++ < 40 ) {
					setTimeout( reveal, 250 );
				}
				return;
			}
			var $tab = $box.find( '.mgseo-box-tab[data-tab="' + ( tab || 'general' ) + '"]' );
			selectTab( $tab.length ? $tab : $box.find( '.mgseo-box-tab' ).first(), ! focusKeyword );
			if ( focusKeyword ) {
				target.scrollIntoView( { block: 'center' } );
				target.focus( { preventScroll: true } );
				$( target ).closest( '.mgseo-field' ).addClass( 'is-highlighted' );
			} else if ( postbox ) {
				postbox.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		};
		reveal();
	};
	$( document ).on( 'click', '.mgseo-show-hints', function ( e ) {
		e.preventDefault();
		window.mgseoRevealBox( false, 'general' );
	} );
	if ( /[?&]mgseo_focus=keyword\b/.test( window.location.search ) && ! isBlockEditor ) {
		setTimeout( function () {
			window.mgseoRevealBox( true );
		}, 300 );
	}
}( jQuery ) );
