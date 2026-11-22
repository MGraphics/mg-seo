/*
 * MG SEO in the Elementor editor: an "SEO · 68" tab next to Widgets and Globals (the same panel as in the
 * block editor) and the score in the top bar. The live score is calculated from the page rendered in the
 * preview, so it matches what visitors and Google see. Fields are saved right away (no need to publish).
 */
/* global wp, $e, elementor */
( function () {
	'use strict';
	const cfg = window.mgseoBuilder;
	const ui = window.mgseoUI;
	const React = window.React;
	if ( ! cfg || ! ui || ! cfg.postId ) {
		return;
	}
	const { __ } = wp.i18n;
	const store = ui.postStore( cfg );
	const TAB = 'mgseo';

	function docTitle() {
		try {
			const doc = elementor.documents.getCurrent();
			return ( doc && doc.container && doc.container.settings && doc.container.settings.get( 'post_title' ) ) || '';
		} catch ( e ) {
			return '';
		}
	}
	const getHtml = () => ui.canvasHtml( cfg.canvas );
	const runner = ui.analysis( store, {
		delay: 600,
		getContent() {
			const data = {};
			const html = getHtml();
			if ( html ) {
				data.html = html;
			}
			const title = docTitle();
			if ( title ) {
				data.post_title = title;
			}
			return data;
		},
	} );
	ui.autosave( store, getHtml );
	ui.watchCanvas( cfg.canvas, () => runner.schedule( 0 ) );
	setTimeout( () => runner.schedule( 0 ), 4000 );

	function openTab() {
		try {
			$e.route( 'panel/elements/' + TAB );
		} catch ( e ) {
			// Older Elementor: the floating panel below.
			if ( fallback ) {
				fallback.toggle( true );
			}
		}
	}

	const panelHost = {
		snippet: true,
		idPrefix: 'mgseo-el',
		postTitle: docTitle,
		postUrl: () => ( cfg.site && cfg.site.url ) || '',
		focus( target ) {
			if ( 'slug' === target ) {
				try {
					$e.route( 'panel/page-settings/settings' );
				} catch ( e ) {}
			}
		},
		extra: [ ui.h( 'p', { className: 'mgseo-help mgseo-el-note' }, __( 'Changes in this tab are saved right away.', 'mg-seo' ) ) ],
	};

	/* Panel tab (Elementor 3.2x+ / 4.x editor packages). */
	let fallback = null;
	const v2 = window.elementorV2 || {};
	if ( React && v2.editorElementsPanel && v2.editorElementsPanel.injectTab ) {
		const SeoTab = function () {
			const ref = React.useRef( null );
			React.useEffect( () => {
				const txt = getComputedStyle( document.body ).getPropertyValue( '--e-a-color-txt' ).trim();
				const hex = /^#([0-9a-f]{6})$/i.exec( txt );
				const light = !! hex && parseInt( hex[ 1 ].slice( 0, 2 ), 16 ) + parseInt( hex[ 1 ].slice( 2, 4 ), 16 ) + parseInt( hex[ 1 ].slice( 4, 6 ), 16 ) > 450;
				ref.current.classList.toggle( 'mgseo-dark', light );
				const p = ui.panel( ref.current, store, panelHost );
				return p.destroy;
			}, [] );
			return React.createElement( 'div', { ref, className: 'mgseo-el-panel' } );
		};
		v2.editorElementsPanel.injectTab( { id: TAB, label: __( 'SEO', 'mg-seo' ), component: SeoTab } );
	} else {
		window.addEventListener( 'load', () => {
			fallback = ui.floating( store, { panel: panelHost } );
		} );
	}

	/* Score in the top bar: "SEO 68" opens the tab. */
	if ( React && v2.editorAppBar && v2.editorAppBar.utilitiesMenu && v2.editorAppBar.utilitiesMenu.registerAction ) {
		const useView = () => {
			const [ st, set ] = React.useState( store.get() );
			React.useEffect( () => store.subscribe( ( s ) => set( s ) ), [] );
			return ui.view( st );
		};
		const ScoreIcon = function () {
			const v = useView();
			const value = null === v.score ? ( v.hasKeyword ? '–' : '?' ) : String( v.score );
			return React.createElement( 'span', { className: 'mgseo-pin mgseo-el-pin mgseo-dark is-' + v.band },
				React.createElement( 'span', { className: 'mgseo-mark is-' + v.band, 'aria-hidden': 'true' } ),
				React.createElement( 'span', { 'aria-hidden': 'true' }, __( 'SEO', 'mg-seo' ) + ' ' + value )
			);
		};
		v2.editorAppBar.utilitiesMenu.registerAction( {
			id: 'mgseo-score',
			priority: 1,
			useProps() {
				const v = useView();
				return { title: v.spoken, icon: ScoreIcon, onClick: openTab };
			},
		} );
	}

	/* Tab label "SEO · 68". */
	function label() {
		const v = store.view();
		const text = __( 'SEO', 'mg-seo' ) + ( null !== v.score ? ' · ' + v.score : '' );
		document.querySelectorAll( '.elementor-component-tab[data-tab="' + TAB + '"]' ).forEach( ( tab ) => {
			if ( tab.textContent !== text ) {
				tab.textContent = text;
				tab.setAttribute( 'title', v.spoken );
			}
		} );
	}
	store.subscribe( label );
	setInterval( label, 2000 );

	/* "Open SEO hints" from the admin bar: ?mgseo=open. */
	if ( /[?&]mgseo=open\b/.test( window.location.search ) ) {
		const wait = setInterval( () => {
			if ( document.querySelector( '.elementor-component-tab[data-tab="' + TAB + '"]' ) ) {
				clearInterval( wait );
				openTab();
			}
		}, 500 );
		setTimeout( () => clearInterval( wait ), 30000 );
	}
}() );
