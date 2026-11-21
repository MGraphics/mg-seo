/* global mgseoImport, wp */
( function () {
	'use strict';
	const cfg = window.mgseoImport || {};
	const { __, sprintf } = wp.i18n;
	const form = document.getElementById( 'mgseo-import-form' );
	if ( ! form ) {
		return;
	}
	const progress = document.getElementById( 'mgseo-import-progress' );
	const bar = progress.querySelector( '.mgseo-import-bar span' );
	const status = progress.querySelector( '.mgseo-import-status' );
	const button = document.getElementById( 'mgseo-import-start' );

	function syncScopes() {
		const source = form.querySelector( 'input[name="source"]:checked' );
		const allowed = source ? source.dataset.scopes.split( ',' ) : [];
		form.querySelectorAll( '.mgseo-scope' ).forEach( ( label ) => {
			const ok = allowed.includes( label.dataset.scope );
			label.hidden = ! ok;
			label.querySelector( 'input' ).disabled = ! ok;
		} );
	}
	form.querySelectorAll( 'input[name="source"]' ).forEach( ( r ) => r.addEventListener( 'change', syncScopes ) );
	syncScopes();

	async function runScope( base, scope, index, count, first ) {
		let after = 0;
		let done = false;
		let total = null;
		let processed = 0;
		let start = first;
		while ( ! done ) {
			const res = await wp.apiFetch( {
				path: cfg.path + '/run',
				method: 'POST',
				data: Object.assign( {}, base, { scope, after, limit: cfg.batch, start } ),
			} );
			start = false;
			if ( null !== res.total && undefined !== res.total ) {
				total = res.total;
			}
			processed += res.processed;
			const share = total ? Math.min( 1, processed / total ) : ( res.done ? 1 : 0.5 );
			bar.style.width = Math.round( ( ( index + share ) / count ) * 100 ) + '%';
			status.textContent = sprintf(
				/* translators: 1: scope name, 2: processed count, 3: total count */
				__( '%1$s: %2$d of %3$s', 'mg-seo' ),
				cfg.scopes[ scope ] || scope,
				processed,
				null === total ? '?' : String( total )
			);
			if ( res.last === after && ! res.done ) {
				break;
			}
			after = res.last;
			done = res.done;
		}
	}

	form.addEventListener( 'submit', async ( e ) => {
		e.preventDefault();
		const source = form.querySelector( 'input[name="source"]:checked' );
		if ( ! source ) {
			return;
		}
		const scopes = Array.from( form.querySelectorAll( 'input[name="scopes[]"]:checked:not(:disabled)' ) ).map( ( i ) => i.value );
		if ( ! scopes.length ) {
			return;
		}
		const base = {
			source: source.value,
			overwrite: form.querySelector( 'input[name="overwrite"]' ).checked,
			dry_run: form.querySelector( 'input[name="dry_run"]' ).checked,
		};
		button.disabled = true;
		progress.hidden = false;
		try {
			for ( let i = 0; i < scopes.length; i++ ) {
				await runScope( base, scopes[ i ], i, scopes.length, 0 === i );
			}
			await wp.apiFetch( { path: cfg.path + '/finish', method: 'POST' } );
			// Imported focus keywords: calculate SEO scores right away (a background pass is the fallback).
			if ( ! base.dry_run && window.mgseoRecalc ) {
				try {
					await window.mgseoRecalc( {
						onProgress( done, total ) {
							bar.style.width = '100%';
							/* translators: 1: posts done, 2: total posts */
							status.textContent = sprintf( __( 'Calculating SEO scores: %1$d of %2$d', 'mg-seo' ), done, total );
						},
					} );
				} catch ( err ) {
					// Scores are also calculated in the background.
				}
			}
			bar.style.width = '100%';
			status.textContent = __( 'Finished. Reloading…', 'mg-seo' );
			window.location.reload();
		} catch ( err ) {
			status.textContent = ( err && err.message ) ? err.message : __( 'The import failed.', 'mg-seo' );
			button.disabled = false;
		}
	} );

	document.querySelectorAll( '.mgseo-import-cleanup' ).forEach( ( f ) => {
		const btn = f.querySelector( 'button' );
		if ( ! btn ) {
			return;
		}
		const check = () => {
			btn.disabled = ! ( f.understand.checked && 'DELETE' === f.confirm.value.trim() );
		};
		f.understand.addEventListener( 'change', check );
		f.confirm.addEventListener( 'input', check );
		f.addEventListener( 'submit', async ( e ) => {
			e.preventDefault();
			check();
			if ( btn.disabled ) {
				return;
			}
			btn.disabled = true;
			try {
				const res = await wp.apiFetch( {
					path: cfg.path + '/cleanup',
					method: 'POST',
					data: { source: f.dataset.source, confirm: f.confirm.value.trim() },
				} );
				const parts = Object.keys( res ).map( ( k ) => k + ': ' + res[ k ] );
				f.insertAdjacentHTML( 'beforeend', '<p class="description"></p>' );
				f.lastElementChild.textContent = __( 'Deleted.', 'mg-seo' ) + ' ' + parts.join( ', ' );
			} catch ( err ) {
				f.insertAdjacentHTML( 'beforeend', '<p class="description"></p>' );
				f.lastElementChild.textContent = ( err && err.message ) ? err.message : __( 'Error', 'mg-seo' );
				btn.disabled = false;
			}
		} );
	} );
}() );
