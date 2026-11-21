/* MG SEO block editor integration (no build step: wp.element.createElement). */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.plugins || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var PanelRow = wp.components.PanelRow;
	var PanelBody = wp.components.PanelBody;
	var Button = wp.components.Button;
	var editorPkg = wp.editor || {};
	var editPostPkg = wp.editPost || {};
	var DocPanel = editorPkg.PluginDocumentSettingPanel || editPostPkg.PluginDocumentSettingPanel;
	var Sidebar = editorPkg.PluginSidebar || editPostPkg.PluginSidebar;
	var SidebarItem = editorPkg.PluginSidebarMoreMenuItem || editPostPkg.PluginSidebarMoreMenuItem;
	var PrePublish = editorPkg.PluginPrePublishPanel || editPostPkg.PluginPrePublishPanel;
	var boot = window.mgseoEditor || {};

	/* Shared live state: filled from the stored score, then by every live analysis (admin.js or below). */
	var state = {
		score: null === boot.score || undefined === boot.score ? null : parseInt( boot.score, 10 ),
		hasKeyword: !! boot.hasKeyword,
		issues: boot.issues || []
	};
	var listeners = [];
	function publish( next ) {
		state = Object.assign( {}, state, next );
		listeners.forEach( function ( fn ) { fn( state ); } );
	}
	/* Called by admin.js after each analysis: ( score, result ). */
	window.mgseoScoreStore = function ( score, result ) {
		var next = { score: parseInt( score, 10 ) };
		if ( result ) {
			next.hasKeyword = !! result.has_keyword;
			next.issues = result.issues || [];
		}
		publish( next );
	};

	function useScore() {
		var s = useState( state );
		useEffect( function () {
			var fn = s[ 1 ];
			listeners.push( fn );
			fn( state );
			return function () {
				listeners = listeners.filter( function ( f ) { return f !== fn; } );
			};
		}, [] );
		return s[ 0 ];
	}

	function band( st ) {
		if ( ! st.hasKeyword ) {
			return 'is-none';
		}
		if ( null === st.score || isNaN( st.score ) ) {
			return 'is-none';
		}
		return st.score >= 80 ? 'is-good' : ( st.score >= 50 ? 'is-ok' : 'is-bad' );
	}

	function label( st ) {
		if ( ! st.hasKeyword ) {
			return __( 'No keyword', 'mg-seo' );
		}
		return null === st.score || isNaN( st.score ) ? '–' : st.score + '/100';
	}

	function scrollToBox() {
		if ( window.mgseoRevealBox ) {
			window.mgseoRevealBox( ! state.hasKeyword );
			return;
		}
		var box = document.getElementById( 'mgseo-box' );
		if ( box ) {
			box.scrollIntoView( { behavior: 'smooth' } );
			var field = document.getElementById( 'mgseo-focus-keyword' );
			if ( field && ! state.hasKeyword ) {
				field.focus( { preventScroll: true } );
			}
		}
	}

	function Badge( props ) {
		var st = props.st;
		return el( 'span', { className: 'mgseo-score ' + band( st ) }, label( st ) );
	}

	function Issues( props ) {
		var issues = ( props.st.issues || [] ).filter( function ( i ) { return 'no_keyword' !== i.id; } );
		if ( ! issues.length ) {
			return props.st.hasKeyword ? el( 'p', null, __( 'Nothing important left to fix.', 'mg-seo' ) ) : null;
		}
		return el( 'ul', { className: 'mgseo-issues' }, issues.map( function ( issue ) {
			return el( 'li', { key: issue.id, className: 'is-' + issue.status }, issue.label );
		} ) );
	}

	function Body() {
		var st = useScore();
		return el( 'div', { className: 'mgseo-sidebar-body' },
			el( 'p', { className: 'mgseo-sidebar-score' }, el( Badge, { st: st } ) ),
			! st.hasKeyword ? el( 'p', null, __( 'Set a focus keyword in the SEO box to get a score.', 'mg-seo' ) ) : null,
			el( Issues, { st: st } ),
			el( 'p', null,
				el( Button, { variant: 'secondary', onClick: scrollToBox }, st.hasKeyword ? __( 'Edit SEO settings', 'mg-seo' ) : __( 'Set a focus keyword', 'mg-seo' ) )
			),
			boot.overviewUrl ? el( 'p', null, el( 'a', { href: boot.overviewUrl }, __( 'SEO overview of all pages', 'mg-seo' ) ) ) : null
		);
	}

	/* Header button: the score itself is the icon, so it is always visible while editing. */
	function HeaderIcon() {
		var st = useScore();
		var text = ! st.hasKeyword ? '?' : ( null === st.score || isNaN( st.score ) ? '–' : String( st.score ) );
		return el( 'span', { className: 'mgseo-pin-score ' + band( st ), 'aria-label': __( 'SEO score', 'mg-seo' ) + ': ' + label( st ) }, text );
	}

	function Plugin() {
		var st = useScore();
		var title = __( 'SEO', 'mg-seo' ) + ': ' + label( st );
		return el( wp.element.Fragment, null,
			Sidebar ? el( Sidebar, { name: 'mgseo-sidebar', title: title, icon: el( HeaderIcon ) }, el( PanelBody, { initialOpen: true, title: __( 'SEO score', 'mg-seo' ) }, el( Body ) ) ) : null,
			SidebarItem ? el( SidebarItem, { target: 'mgseo-sidebar' }, __( 'SEO score', 'mg-seo' ) ) : null,
			PrePublish ? el( PrePublish, { title: title, initialOpen: ! st.hasKeyword || ( null !== st.score && st.score < 80 ), className: 'mgseo-prepublish' }, el( Body ) ) : null,
			DocPanel ? el( DocPanel, { name: 'mgseo-score', title: __( 'SEO', 'mg-seo' ), className: 'mgseo-doc-panel' },
				el( PanelRow, null, el( 'span', null, __( 'SEO score', 'mg-seo' ) ), el( Badge, { st: st } ) ),
				el( PanelRow, null, el( Button, { variant: 'secondary', onClick: scrollToBox }, __( 'Edit SEO settings', 'mg-seo' ) ) )
			) : null
		);
	}

	wp.plugins.registerPlugin( 'mgseo-score', { render: Plugin } );

	/*
	 * Live analysis when the SEO box is not on the screen (hidden for this post type): admin.js
	 * does it otherwise. Uses the stored focus keyword and the edited content.
	 */
	if ( boot.postId && wp.data && wp.apiFetch ) {
		var timer = null;
		var last = null;
		var run = function () {
			if ( document.querySelector( '.mgseo-box' ) ) {
				return;
			}
			var ed = wp.data.select( 'core/editor' );
			wp.apiFetch( {
				path: '/mg-seo/v1/analyze',
				method: 'POST',
				data: {
					post_id: boot.postId,
					content: ed.getEditedPostContent() || '',
					post_title: ed.getEditedPostAttribute( 'title' ) || '',
					slug: ed.getEditedPostAttribute( 'slug' ) || ''
				}
			} ).then( function ( result ) {
				window.mgseoScoreStore( result.score, result );
			} ).catch( function () {} );
		};
		wp.data.subscribe( function () {
			var ed = wp.data.select( 'core/editor' );
			if ( ! ed || typeof ed.getEditedPostContent !== 'function' ) {
				return;
			}
			var c = ed.getEditedPostContent();
			if ( c !== last ) {
				var first = null === last;
				last = c;
				clearTimeout( timer );
				timer = setTimeout( run, first ? 1500 : 1200 );
			}
		} );
	}

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
}( window.wp ) );
