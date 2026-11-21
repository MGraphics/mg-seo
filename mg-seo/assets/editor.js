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
	var Button = wp.components.Button;
	var DocPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	var listeners = [];
	window.mgseoScoreStore = function ( score ) {
		listeners.forEach( function ( fn ) { fn( score ); } );
	};

	function ScorePanel() {
		var state = useState( null );
		var score = state[ 0 ];
		var setScore = state[ 1 ];
		useEffect( function () {
			listeners.push( setScore );
			return function () {
				listeners = listeners.filter( function ( fn ) { return fn !== setScore; } );
			};
		}, [] );
		var cls = score === null ? '' : ( score >= 80 ? 'is-good' : ( score >= 50 ? 'is-ok' : 'is-bad' ) );
		return el(
			DocPanel,
			{ name: 'mgseo-score', title: __( 'SEO', 'mg-seo' ), className: 'mgseo-doc-panel' },
			el( PanelRow, null,
				el( 'span', null, __( 'SEO score', 'mg-seo' ) ),
				el( 'span', { className: 'mgseo-score ' + cls }, score === null ? '–' : score + '/100' )
			),
			el( PanelRow, null,
				el( Button, {
					variant: 'secondary',
					onClick: function () {
						var box = document.getElementById( 'mgseo-box' );
						if ( box ) {
							box.scrollIntoView( { behavior: 'smooth' } );
						}
					}
				}, __( 'Edit SEO settings', 'mg-seo' ) )
			)
		);
	}

	if ( DocPanel ) {
		wp.plugins.registerPlugin( 'mgseo-score', { render: ScorePanel } );
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
