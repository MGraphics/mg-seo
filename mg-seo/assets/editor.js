/* MG SEO block editor integration (no build step: wp.element.createElement). */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.plugins || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;
	var PanelRow = wp.components.PanelRow;
	var Button = wp.components.Button;
	var editorPkg = wp.editor || {};
	var editPostPkg = wp.editPost || {};
	var DocPanel = editorPkg.PluginDocumentSettingPanel || editPostPkg.PluginDocumentSettingPanel;
	var Sidebar = editorPkg.PluginSidebar || editPostPkg.PluginSidebar;
	var SidebarItem = editorPkg.PluginSidebarMoreMenuItem || editPostPkg.PluginSidebarMoreMenuItem;
	var PrePublish = editorPkg.PluginPrePublishPanel || editPostPkg.PluginPrePublishPanel;
	var cfg = window.mgseoEditor || {};
	var ui = window.mgseoUI;
	var SIDEBAR = 'mgseo-score/mgseo-sidebar';

	/* Breadcrumbs block: server-rendered. */
	if ( wp.blocks && wp.serverSideRender && ! wp.blocks.getBlockType( 'mg-seo/breadcrumbs' ) ) {
		wp.blocks.registerBlockType( 'mg-seo/breadcrumbs', {
			apiVersion: 3,
			title: __( 'Breadcrumbs', 'mg-seo' ),
			icon: 'arrow-right-alt2',
			category: 'theme',
			edit: function () {
				var blockProps = wp.blockEditor && wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps() : {};
				return el( 'div', blockProps, el( wp.serverSideRender, { block: 'mg-seo/breadcrumbs' } ) );
			},
			save: function () { return null; }
		} );
	}

	if ( ! ui || ! cfg.postId ) {
		return;
	}
	var store = ui.postStore( cfg );

	function editor() {
		var ed = wp.data.select( 'core/editor' );
		return ed && typeof ed.getEditedPostContent === 'function' ? ed : null;
	}

	/* Live analysis of the edited content (one runner for the sidebar and the SEO box). */
	var runner = ui.analysis( store, {
		delay: 900,
		getContent: function () {
			var ed = editor();
			return ed ? {
				content: ed.getEditedPostContent() || '',
				post_title: ed.getEditedPostAttribute( 'title' ) || '',
				slug: ed.getEditedPostAttribute( 'slug' ) || ''
			} : {};
		}
	} );
	var lastEdited = null;
	wp.data.subscribe( function () {
		var ed = editor();
		if ( ! ed ) {
			return;
		}
		var now = ( ed.getEditedPostContent() || '' ) + '\u0000' + ( ed.getEditedPostAttribute( 'title' ) || '' ) + '\u0000' + ( ed.getEditedPostAttribute( 'slug' ) || '' );
		if ( now !== lastEdited ) {
			var first = null === lastEdited;
			lastEdited = now;
			runner.schedule( first ? 500 : 1200 );
		}
	} );

	/* Fields edited in the sidebar are saved with the post (REST meta); the SEO box form gets the same values. */
	store.subscribe( function ( st, prev, source ) {
		if ( 'field' !== source && 'box' !== source ) {
			return;
		}
		var meta = {};
		if ( st.keyword !== prev.keyword ) {
			meta._mgseo_focus_keyword = st.keyword;
		}
		if ( st.title !== prev.title ) {
			meta._mgseo_title = st.title;
		}
		if ( st.description !== prev.description ) {
			meta._mgseo_description = st.description;
		}
		if ( Object.keys( meta ).length ) {
			wp.data.dispatch( 'core/editor' ).editPost( { meta: meta } );
		}
	} );

	function useStore() {
		var s = useState( store.get() );
		useEffect( function () {
			return store.subscribe( function ( st ) {
				s[ 1 ]( st );
			} );
		}, [] );
		return ui.view( s[ 0 ] );
	}

	function openSidebar() {
		var iface = wp.data.dispatch( 'core/interface' );
		if ( iface && iface.enableComplementaryArea ) {
			iface.enableComplementaryArea( 'core', SIDEBAR );
			return;
		}
		var ep = wp.data.dispatch( 'core/edit-post' );
		if ( ep && ep.openGeneralSidebar ) {
			ep.openGeneralSidebar( SIDEBAR );
		}
	}

	function Mark( props ) {
		return el( 'span', { className: 'mgseo-mark is-' + props.band, 'aria-hidden': 'true' } );
	}

	function Rating( props ) {
		var v = props.v;
		return el( 'span', { className: 'mgseo-rating is-' + v.band, title: v.spoken },
			el( Mark, { band: v.band } ),
			el( 'span', { className: 'screen-reader-text' }, v.spoken ),
			el( 'span', { 'aria-hidden': 'true' },
				null !== v.score ? el( 'b', { className: 'mgseo-rating-num' }, String( v.score ), el( 'small', null, '/100' ) ) : null,
				null !== v.score ? ' ' : null,
				el( 'span', { className: 'mgseo-rating-word' }, v.word )
			)
		);
	}

	/* Header button: "SEO 21" with the shape mark, always visible while editing. */
	function HeaderIcon() {
		var v = useStore();
		var value = null === v.score ? ( v.hasKeyword ? '–' : '?' ) : String( v.score );
		return el( 'span', { className: 'mgseo-pin is-' + v.band },
			el( Mark, { band: v.band } ),
			el( 'span', { 'aria-hidden': 'true' }, __( 'SEO', 'mg-seo' ) + ' ' + value ),
			el( 'span', { className: 'screen-reader-text' }, v.spoken )
		);
	}

	/* Open the SEO box in the "Meta boxes" pane on a given tab (social, advanced, schema …). */
	function boxTab( tab ) {
		if ( window.mgseoRevealBox ) {
			window.mgseoRevealBox( false, tab );
		}
	}

	function foldButton( title, text, tab ) {
		var wrap = ui.h( 'details', { className: 'mgseo-fold' }, [ ui.h( 'summary', null, title ) ] );
		var body = ui.h( 'div', { className: 'mgseo-fold-body' }, [ ui.h( 'p', { className: 'mgseo-help' }, text ) ] );
		if ( document.getElementById( 'mgseo-box' ) ) {
			body.appendChild( ui.h( 'button', { type: 'button', className: 'components-button is-secondary', onClick: function () { boxTab( tab ); } }, __( 'Open in the SEO box', 'mg-seo' ) ) );
		}
		wrap.appendChild( body );
		return wrap;
	}

	/* The sidebar body is the shared panel (same markup as Elementor and the SEO box). */
	function PanelHost() {
		var ref = useRef( null );
		useEffect( function () {
			var extra = [
				foldButton( __( 'Social media', 'mg-seo' ), __( 'How the link looks when shared on Facebook, LinkedIn or X.', 'mg-seo' ), 'social' ),
				foldButton( __( 'Advanced', 'mg-seo' ), __( 'Indexing, canonical address, breadcrumbs.', 'mg-seo' ), 'advanced' )
			];
			if ( cfg.overviewUrl ) {
				extra.push( ui.h( 'p', { className: 'mgseo-overview-link' }, ui.h( 'a', { href: cfg.overviewUrl }, __( 'SEO overview of all pages', 'mg-seo' ) ) ) );
			}
			var p = ui.panel( ref.current, store, {
				snippet: true,
				extra: extra,
				idPrefix: 'mgseo-sb',
				postTitle: function () {
					var ed = editor();
					return ed ? ed.getEditedPostAttribute( 'title' ) : '';
				},
				postUrl: function () {
					var ed = editor();
					return ed ? ed.getPermalink() : '';
				},
				focus: function ( target ) {
					if ( 'slug' === target ) {
						var iface = wp.data.dispatch( 'core/interface' );
						if ( iface && iface.enableComplementaryArea ) {
							iface.enableComplementaryArea( 'core', 'edit-post/document' );
						}
					}
				}
			} );
			return p.destroy;
		}, [] );
		return el( 'div', { ref: ref, className: 'mgseo-sidebar-panel' } );
	}

	function PrePublishBody() {
		var v = useStore();
		var issues = ( ( store.get().result && store.get().result.issues ) || cfg.issues || [] ).filter( function ( i ) { return 'no_keyword' !== i.id; } );
		return el( 'div', { className: 'mgseo-prepublish-body' },
			el( 'p', null, el( Rating, { v: v } ) ),
			v.sub ? el( 'p', { className: 'mgseo-help' }, v.sub ) : null,
			issues.length ? el( 'ol', { className: 'mgseo-top' }, issues.map( function ( i ) { return el( 'li', { key: i.id }, i.label ); } ) ) : null,
			el( Button, { variant: 'secondary', onClick: openSidebar }, __( 'Show hints', 'mg-seo' ) )
		);
	}

	function Plugin() {
		var v = useStore();
		var title = __( 'SEO', 'mg-seo' );
		return el( wp.element.Fragment, null,
			Sidebar ? el( Sidebar, { name: 'mgseo-sidebar', title: title, icon: el( HeaderIcon ), className: 'mgseo-plugin-sidebar' }, el( PanelHost ) ) : null,
			SidebarItem ? el( SidebarItem, { target: 'mgseo-sidebar' }, __( 'SEO', 'mg-seo' ) ) : null,
			PrePublish ? el( PrePublish, { title: title + ': ' + ( null !== v.score ? v.score + '/100, ' + v.word.toLowerCase() : v.word ), initialOpen: v.band !== 'good', className: 'mgseo-prepublish' }, el( PrePublishBody ) ) : null,
			DocPanel ? el( DocPanel, { name: 'mgseo-score', title: __( 'SEO', 'mg-seo' ), className: 'mgseo-doc-panel' },
				el( PanelRow, null, el( Rating, { v: v } ) ),
				v.sub ? el( 'p', { className: 'mgseo-help' }, v.sub ) : null,
				el( PanelRow, null, el( Button, { variant: 'secondary', onClick: openSidebar }, __( 'Show hints', 'mg-seo' ) ) )
			) : null
		);
	}

	wp.plugins.registerPlugin( 'mgseo-score', { render: Plugin } );

	/* Links from lists, the overview and the admin bar ("#mgseo-box", "?mgseo_focus=keyword") open the sidebar. */
	if ( /[?&]mgseo_focus=keyword\b/.test( window.location.search ) || '#mgseo-box' === window.location.hash ) {
		setTimeout( function () {
			openSidebar();
			if ( /mgseo_focus=keyword/.test( window.location.search ) ) {
				setTimeout( function () {
					var kw = document.getElementById( 'mgseo-sb-keyword' );
					if ( kw ) {
						kw.focus();
					}
				}, 400 );
			}
		}, 800 );
	}
}( window.wp ) );
