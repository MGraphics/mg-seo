/**
 * Image SEO tools: batched find & replace, inline alt editing, fill missing alt.
 *
 * @package MGSEO
 */
( function ( wp, cfg ) {
	'use strict';
	if ( ! wp || ! wp.apiFetch || ! cfg ) {
		return;
	}
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var api = function ( route, data ) {
		return wp.apiFetch( { path: cfg.path + route, method: 'POST', data: data } );
	};

	var form = document.getElementById( 'mgseo-image-replace' );
	if ( form ) {
		var status = form.querySelector( '.mgseo-image-status' );
		var table = form.querySelector( '.mgseo-image-preview' );
		var tbody = table.querySelector( 'tbody' );
		var buttons = form.querySelectorAll( 'button[data-action]' );
		var busy = false;

		var params = function () {
			var fields = [];
			form.querySelectorAll( 'input[name="fields"]:checked' ).forEach( function ( el ) {
				fields.push( el.value );
			} );
			return {
				find: form.elements.find.value,
				replace: form.elements.replace.value,
				fields: fields,
				case_sensitive: form.elements.case_sensitive.checked
			};
		};
		var cell = function ( row, text ) {
			var td = document.createElement( 'td' );
			td.textContent = text;
			row.appendChild( td );
			return td;
		};
		var addRows = function ( matches ) {
			matches.forEach( function ( m ) {
				if ( tbody.children.length >= 500 ) {
					return;
				}
				var tr = document.createElement( 'tr' );
				var td = document.createElement( 'td' );
				var a = document.createElement( 'a' );
				a.href = m.edit;
				if ( m.thumb ) {
					var img = document.createElement( 'img' );
					img.src = m.thumb;
					img.alt = '';
					img.width = 40;
					a.appendChild( img );
				} else {
					a.textContent = '#' + m.id;
				}
				td.appendChild( a );
				tr.appendChild( td );
				cell( tr, m.field );
				cell( tr, m.before );
				cell( tr, m.after );
				tbody.appendChild( tr );
			} );
		};
		var run = function ( dry ) {
			var p = params();
			if ( busy || ! p.find || ! p.fields.length ) {
				return;
			}
			if ( ! dry && ! window.confirm( __( 'Replace in the media library? This cannot be undone.', 'mg-seo' ) ) ) {
				return;
			}
			busy = true;
			buttons.forEach( function ( b ) { b.disabled = true; } );
			tbody.textContent = '';
			table.hidden = false;
			var total = 0;
			var changed = 0;
			var step = function ( after ) {
				p.dry_run = dry;
				p.after_id = after;
				p.limit = 50;
				api( 'replace', p ).then( function ( res ) {
					total += res.matches.length;
					changed += res.changed;
					addRows( res.matches );
					status.textContent = dry
						? /* translators: %d: number of matches or values */ sprintf( __( 'Found %d matches…', 'mg-seo' ), total )
						: /* translators: %d: number of matches or values */ sprintf( __( 'Changed %d values…', 'mg-seo' ), changed );
					if ( res.done ) {
						busy = false;
						buttons.forEach( function ( b ) { b.disabled = false; } );
						status.textContent = dry
							? /* translators: %d: number of matches or values */ sprintf( __( 'Preview: %d matches.', 'mg-seo' ), total )
							: /* translators: %d: number of matches or values */ sprintf( __( 'Done: %d values changed.', 'mg-seo' ), changed );
						return;
					}
					step( res.next_after_id );
				} ).catch( function ( err ) {
					busy = false;
					buttons.forEach( function ( b ) { b.disabled = false; } );
					status.textContent = ( err && err.message ) || __( 'Error', 'mg-seo' );
				} );
			};
			step( 0 );
		};
		form.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( 'button[data-action]' );
			if ( btn ) {
				run( 'preview' === btn.getAttribute( 'data-action' ) );
			}
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.mgseo-save-alt' );
		if ( ! btn ) {
			return;
		}
		var row = btn.closest( 'tr' );
		var input = row.querySelector( 'input' );
		var alt = input.value.trim() || input.placeholder;
		if ( ! alt ) {
			return;
		}
		btn.disabled = true;
		api( 'alt', { id: parseInt( row.getAttribute( 'data-id' ), 10 ), alt: alt } ).then( function () {
			input.value = alt;
			btn.textContent = __( 'Saved', 'mg-seo' );
		} ).catch( function () {
			btn.disabled = false;
			btn.textContent = __( 'Error', 'mg-seo' );
		} );
	} );

	var fill = document.getElementById( 'mgseo-image-fill' );
	if ( fill ) {
		var fillStatus = document.querySelector( '.mgseo-image-fill-status' );
		fill.addEventListener( 'click', function () {
			if ( ! window.confirm( fill.getAttribute( 'data-confirm' ) ) ) {
				return;
			}
			fill.disabled = true;
			var done = 0;
			var step = function () {
				api( 'fill-missing', { dry_run: false, limit: 50 } ).then( function ( res ) {
					done += res.changed;
					/* translators: 1: images saved, 2: images still without alt text */
					fillStatus.textContent = sprintf( __( '%1$d saved, %2$d without alt text left.', 'mg-seo' ), done, res.remaining );
					if ( res.changed > 0 && res.remaining > 0 ) {
						step();
					} else {
						fill.disabled = false;
					}
				} ).catch( function ( err ) {
					fill.disabled = false;
					fillStatus.textContent = ( err && err.message ) || __( 'Error', 'mg-seo' );
				} );
			};
			step();
		} );
	}
}( window.wp, window.mgseoImage ) );
