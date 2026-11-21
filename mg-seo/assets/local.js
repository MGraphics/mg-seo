/**
 * Business info block (server-side rendered).
 *
 * @package MGSEO
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! wp.serverSideRender || wp.blocks.getBlockType( 'mg-seo/local' ) ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;

	wp.blocks.registerBlockType( 'mg-seo/local', {
		apiVersion: 3,
		title: __( 'Business info', 'mg-seo' ),
		description: __( 'Address, contact details, opening hours and map from the Local SEO settings or a location.', 'mg-seo' ),
		icon: 'location',
		category: 'widgets',
		attributes: {
			show: { type: 'string', default: 'all' },
			location: { type: 'string', default: '' },
			map: { type: 'string', default: '' }
		},
		edit: function ( props ) {
			var a = props.attributes;
			var blockProps = be && be.useBlockProps ? be.useBlockProps() : {};
			return el(
				'div',
				blockProps,
				el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Settings', 'mg-seo' ) },
						el( c.TextControl, {
							label: __( 'Parts', 'mg-seo' ),
							help: __( 'Comma separated: name, address, contact, hours, ids, social, map – or all.', 'mg-seo' ),
							value: a.show,
							onChange: function ( v ) { props.setAttributes( { show: v } ); }
						} ),
						el( c.TextControl, {
							label: __( 'Location', 'mg-seo' ),
							help: __( 'Location ID(s), "all", or empty for the main business / current location.', 'mg-seo' ),
							value: a.location,
							onChange: function ( v ) { props.setAttributes( { location: v } ); }
						} ),
						el( c.SelectControl, {
							label: __( 'Map', 'mg-seo' ),
							value: a.map,
							options: [
								{ value: '', label: __( 'Default', 'mg-seo' ) },
								{ value: 'none', label: __( 'No map', 'mg-seo' ) },
								{ value: 'link', label: __( 'Link', 'mg-seo' ) },
								{ value: 'embed', label: __( 'Embedded map', 'mg-seo' ) }
							],
							onChange: function ( v ) { props.setAttributes( { map: v } ); }
						} )
					)
				),
				el( wp.serverSideRender, { block: 'mg-seo/local', attributes: a } )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );
