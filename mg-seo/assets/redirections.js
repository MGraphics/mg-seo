/**
 * MG SEO redirections admin screens: repeatable source rows, destination toggle, confirmations.
 */
( function () {
	'use strict';

	function reindex( container ) {
		container.querySelectorAll( '.mgseo-rd-source' ).forEach( function ( row, index ) {
			row.querySelectorAll( '[name^="sources["]' ).forEach( function ( field ) {
				field.name = field.name.replace( /^sources\[\d+\]/, 'sources[' + index + ']' );
			} );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var add = event.target.closest( '.mgseo-rd-add-source' );
		if ( add ) {
			var container = document.querySelector( '.mgseo-rd-sources' );
			var rows = container.querySelectorAll( '.mgseo-rd-source' );
			var clone = rows[ rows.length - 1 ].cloneNode( true );
			clone.querySelectorAll( 'input[type=text]' ).forEach( function ( input ) {
				input.value = '';
			} );
			clone.querySelectorAll( 'input[type=checkbox]' ).forEach( function ( input ) {
				input.checked = false;
			} );
			clone.querySelectorAll( 'select' ).forEach( function ( select ) {
				select.selectedIndex = 0;
			} );
			container.appendChild( clone );
			reindex( container );
			clone.querySelector( 'input[type=text]' ).focus();
			return;
		}
		var remove = event.target.closest( '.mgseo-rd-remove-source' );
		if ( remove ) {
			var wrap = remove.closest( '.mgseo-rd-sources' );
			var row = remove.closest( '.mgseo-rd-source' );
			if ( wrap.querySelectorAll( '.mgseo-rd-source' ).length > 1 ) {
				row.remove();
				reindex( wrap );
			} else {
				row.querySelector( 'input[type=text]' ).value = '';
			}
			return;
		}
		var confirmLink = event.target.closest( '.mgseo-confirm' );
		if ( confirmLink && ! window.confirm( confirmLink.getAttribute( 'data-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	function toggleTarget() {
		var checked = document.querySelector( '.mgseo-rd-types input:checked' );
		var target = document.querySelector( '.mgseo-rd-target' );
		if ( checked && target ) {
			target.style.display = ( '410' === checked.value || '451' === checked.value ) ? 'none' : '';
		}
	}
	document.addEventListener( 'change', function ( event ) {
		if ( event.target.closest( '.mgseo-rd-types' ) ) {
			toggleTarget();
		}
	} );
	toggleTarget();

	// Bulk delete confirmation in list tables.
	document.querySelectorAll( '.mgseo-rd form[method=get]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var submitter = event.submitter;
			if ( ! submitter || ( 'doaction' !== submitter.id && 'doaction2' !== submitter.id ) ) {
				return;
			}
			var select = form.querySelector( 'doaction2' === submitter.id ? '#bulk-action-selector-bottom' : '#bulk-action-selector-top' );
			if ( select && 'delete' === select.value && ! window.confirm( form.closest( '.mgseo-rd' ).getAttribute( 'data-confirm-delete' ) || 'OK?' ) ) {
				event.preventDefault();
			}
		} );
	} );
}() );
