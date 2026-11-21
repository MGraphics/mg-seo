/* MG SEO: "Recalculate all" (post lists, overview, after imports) and "Calculate score" in list cells. */
/* global mgseoScores, wp */
( function () {
	'use strict';
	const cfg = window.mgseoScores || {};
	if ( ! window.wp || ! wp.apiFetch || ! wp.i18n ) {
		return;
	}
	const { __, sprintf } = wp.i18n;

	/**
	 * Recalculate scores in batches.
	 *
	 * @param {Object}   opts            Options.
	 * @param {string}   opts.postType   Post type ('' = all).
	 * @param {Function} opts.onProgress Called with ( done, total ).
	 * @return {Promise<number>} Number of posts checked.
	 */
	async function recalc( opts ) {
		const o = Object.assign( { postType: '', onProgress: null }, opts || {} );
		let after = 0;
		let total = 0;
		let done = 0;
		for ( ;; ) {
			const res = await wp.apiFetch( {
				path: cfg.path + '/recalc',
				method: 'POST',
				data: { after, limit: cfg.batch || 20, post_type: o.postType },
			} );
			if ( 0 === after ) {
				total = res.total;
			}
			done += res.processed;
			if ( o.onProgress ) {
				o.onProgress( done, total );
			}
			if ( res.done || res.last === after ) {
				return done;
			}
			after = res.last;
		}
	}
	window.mgseoRecalc = recalc;

	document.addEventListener( 'click', async ( e ) => {
		const btn = e.target.closest( '.mgseo-recalc' );
		if ( btn ) {
			e.preventDefault();
			const status = btn.parentNode.querySelector( '.mgseo-recalc-status' );
			btn.disabled = true;
			try {
				await recalc( {
					postType: btn.dataset.postType || '',
					onProgress( done, total ) {
						if ( status ) {
							/* translators: 1: posts done, 2: total posts */
							status.textContent = sprintf( __( 'Scored %1$d of %2$d…', 'mg-seo' ), done, total );
						}
					},
				} );
				if ( status ) {
					status.textContent = __( 'Done. Reloading…', 'mg-seo' );
				}
				window.location.reload();
			} catch ( err ) {
				if ( status ) {
					status.textContent = ( err && err.message ) ? err.message : __( 'Error', 'mg-seo' );
				}
				btn.disabled = false;
			}
			return;
		}

		const one = e.target.closest( '.mgseo-score-now' );
		if ( one ) {
			e.preventDefault();
			const cell = one.closest( '.mgseo-cell' );
			one.disabled = true;
			one.textContent = __( 'Calculating…', 'mg-seo' );
			try {
				const res = await wp.apiFetch( { path: cfg.path + '/post/' + one.dataset.postId, method: 'POST' } );
				if ( cell ) {
					cell.innerHTML = res.html;
				}
			} catch ( err ) {
				one.disabled = false;
				one.textContent = ( err && err.message ) ? err.message : __( 'Error', 'mg-seo' );
			}
		}
	} );
}() );
