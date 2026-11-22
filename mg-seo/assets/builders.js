/*
 * MG SEO in front-end page builders (Beaver Builder, Divi, Bricks, WPBakery front-end editor):
 * a floating "SEO 68" button with the same panel as in the other editors. The live score is calculated
 * from the page the builder renders on screen; fields are saved right away.
 */
/* global wp */
( function () {
	'use strict';
	const cfg = window.mgseoBuilder;
	const ui = window.mgseoUI;
	if ( ! cfg || ! ui || ! cfg.postId ) {
		return;
	}
	const { __ } = wp.i18n;
	const store = ui.postStore( cfg );
	const getHtml = () => ui.canvasHtml( cfg.canvas );
	const runner = ui.analysis( store, {
		delay: 600,
		getContent() {
			const html = getHtml();
			return html ? { html } : {};
		},
	} );
	ui.autosave( store, getHtml );

	function start() {
		ui.floating( store, {
			open: /[?&]mgseo=open\b/.test( window.location.search ),
			note: cfg.tested ? __( 'Changes in this panel are saved right away.', 'mg-seo' ) : __( 'Changes in this panel are saved right away. The score is calculated from the page as shown here; it is updated after you save the layout.', 'mg-seo' ),
			panel: { postUrl: () => ( cfg.site && cfg.site.url ) || '' },
		} );
		ui.watchCanvas( cfg.canvas, () => runner.schedule( 0 ) );
		runner.schedule( 1000 );

		/* Builders that publish through their own requests: refresh after a save (documented hooks). */
		if ( window.FLBuilder && window.FLBuilder.addHook ) {
			[ 'didSaveLayoutComplete', 'didPublishLayout', 'didSaveDraftLayout' ].forEach( ( hook ) => window.FLBuilder.addHook( hook, () => runner.schedule( 500 ) ) );
		}
		document.addEventListener( 'ETBuilderSaved', () => runner.schedule( 500 ) );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
