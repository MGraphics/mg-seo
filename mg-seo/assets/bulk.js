/* global mgseoBulk, wp, inlineEditPost, jQuery */
( function ( $ ) {
	'use strict';
	const cfg = window.mgseoBulk || {};
	const { __, sprintf } = wp.i18n;

	// Bulk editor page.
	const table = document.getElementById( 'mgseo-bulk-table' );
	if ( table ) {
		const rowData = ( row ) => {
			const item = { id: parseInt( row.dataset.id, 10 ) };
			row.querySelectorAll( '.mgseo-bulk-field' ).forEach( ( field ) => {
				if ( ! field.disabled ) {
					item[ field.dataset.field ] = field.value;
				}
			} );
			return item;
		};
		const setStatus = ( row, text, error ) => {
			const el = row.querySelector( '.mgseo-bulk-status' );
			if ( el ) {
				el.textContent = text;
				el.classList.toggle( 'is-error', !! error );
			}
		};
		const save = async ( rows ) => {
			const res = await wp.apiFetch( {
				path: cfg.path,
				method: 'POST',
				data: { items: rows.map( rowData ) },
			} );
			( res.items || [] ).forEach( ( item ) => {
				const row = table.querySelector( 'tr[data-id="' + item.id + '"]' );
				if ( ! row ) {
					return;
				}
				if ( item.error ) {
					setStatus( row, item.error, true );
					return;
				}
				row.classList.remove( 'is-dirty' );
				setStatus( row, __( 'Saved', 'mg-seo' ), false );
			} );
		};
		table.addEventListener( 'input', ( e ) => {
			const row = e.target.closest( '.mgseo-bulk-row' );
			if ( row && e.target.classList.contains( 'mgseo-bulk-field' ) ) {
				row.classList.add( 'is-dirty' );
				setStatus( row, '', false );
			}
		} );
		table.addEventListener( 'change', ( e ) => {
			const row = e.target.closest( '.mgseo-bulk-row' );
			if ( row && 'SELECT' === e.target.tagName ) {
				row.classList.add( 'is-dirty' );
			}
		} );
		table.addEventListener( 'click', async ( e ) => {
			const button = e.target.closest( '.mgseo-bulk-save-row' );
			if ( ! button ) {
				return;
			}
			const row = button.closest( '.mgseo-bulk-row' );
			button.disabled = true;
			try {
				await save( [ row ] );
			} catch ( err ) {
				setStatus( row, err.message || __( 'Error', 'mg-seo' ), true );
			}
			button.disabled = false;
		} );
		const all = document.getElementById( 'mgseo-bulk-save-all' );
		const global = document.getElementById( 'mgseo-bulk-global-status' );
		all.addEventListener( 'click', async () => {
			const dirty = Array.from( table.querySelectorAll( '.mgseo-bulk-row.is-dirty' ) );
			if ( ! dirty.length ) {
				global.textContent = __( 'No changes to save.', 'mg-seo' );
				return;
			}
			all.disabled = true;
			const size = cfg.batch || 20;
			try {
				for ( let i = 0; i < dirty.length; i += size ) {
					await save( dirty.slice( i, i + size ) );
					/* translators: 1: saved rows, 2: total rows */
					global.textContent = sprintf( __( 'Saved %1$d of %2$d', 'mg-seo' ), Math.min( i + size, dirty.length ), dirty.length );
				}
			} catch ( err ) {
				global.textContent = err.message || __( 'Error', 'mg-seo' );
			}
			all.disabled = false;
		} );
		window.addEventListener( 'beforeunload', ( e ) => {
			if ( table.querySelector( '.mgseo-bulk-row.is-dirty' ) ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );
	}

	// Quick edit on the core posts list.
	if ( typeof inlineEditPost !== 'undefined' ) {
		const original = inlineEditPost.edit;
		inlineEditPost.edit = function ( id ) {
			original.apply( this, arguments );
			const postId = typeof id === 'object' ? parseInt( this.getId( id ), 10 ) : parseInt( id, 10 );
			if ( ! postId ) {
				return;
			}
			const data = $( '#post-' + postId ).find( '.mgseo-inline-data' );
			const row = $( '#edit-' + postId );
			if ( ! data.length || ! row.length ) {
				return;
			}
			row.find( '.mgseo-quick-title' ).val( data.attr( 'data-title' ) || '' );
			row.find( '.mgseo-quick-description' ).val( data.attr( 'data-description' ) || '' );
			row.find( '.mgseo-quick-keyword' ).val( data.attr( 'data-keyword' ) || '' );
			row.find( '.mgseo-quick-index' ).val( data.attr( 'data-index' ) || '' );
		};
	}
}( jQuery ) );
