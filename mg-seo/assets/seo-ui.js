/*
 * MG SEO shared editor UI: one store, one live analysis and one renderer for the score and hints,
 * used by the SEO box (classic and block editor), the block editor sidebar, Elementor and other page builders.
 * No build step and no jQuery: plain ES2017 + wp.apiFetch + wp.i18n.
 */
/* global wp */
( function () {
	'use strict';
	if ( window.mgseoUI || ! window.wp || ! wp.apiFetch || ! wp.i18n ) {
		return;
	}
	const { __, _n, sprintf } = wp.i18n;

	/* ---------- helpers ---------- */

	function h( tag, attrs, children ) {
		const el = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( ( key ) => {
			const val = attrs[ key ];
			if ( null === val || undefined === val || false === val ) {
				return;
			}
			if ( 'className' === key ) {
				el.className = val;
			} else if ( 'text' === key ) {
				el.textContent = val;
			} else if ( key.startsWith( 'on' ) && 'function' === typeof val ) {
				el.addEventListener( key.slice( 2 ).toLowerCase(), val );
			} else {
				el.setAttribute( key, true === val ? '' : String( val ) );
			}
		} );
		( Array.isArray( children ) ? children : [ children ] ).forEach( ( child ) => {
			if ( null === child || undefined === child || false === child ) {
				return;
			}
			el.appendChild( 'string' === typeof child || 'number' === typeof child ? document.createTextNode( String( child ) ) : child );
		} );
		return el;
	}

	function bandOf( score, hasKeyword ) {
		if ( ! hasKeyword ) {
			return 'none';
		}
		if ( null === score || undefined === score || isNaN( score ) ) {
			return 'pending';
		}
		return score >= 80 ? 'good' : ( score >= 50 ? 'ok' : 'poor' );
	}

	function bandWord( band ) {
		return {
			good: __( 'Good', 'mg-seo' ),
			ok: __( 'Needs work', 'mg-seo' ),
			poor: __( 'Poor', 'mg-seo' ),
			pending: __( 'Not calculated yet', 'mg-seo' ),
		}[ band ] || __( 'No keyword', 'mg-seo' );
	}

	function summary( fix, hasKeyword ) {
		if ( ! hasKeyword ) {
			return __( 'Enter a keyword to calculate the score', 'mg-seo' );
		}
		if ( fix < 0 ) {
			return '';
		}
		if ( 0 === fix ) {
			return __( 'Nothing important left to fix', 'mg-seo' );
		}
		/* translators: %d: number of things to improve */
		return sprintf( _n( '%d thing to improve', '%d things to improve', fix, 'mg-seo' ), fix );
	}

	/* Normalised view of the state for every badge: { score, band, word, fix, hasKeyword, spoken }. */
	function view( state ) {
		const r = state.result || {};
		const hasKeyword = '' !== String( state.keyword || '' ).trim();
		const live = !! state.result && !! r.groups;
		const score = live ? parseInt( r.score, 10 ) : ( null === state.score || undefined === state.score ? null : parseInt( state.score, 10 ) );
		const band = hasKeyword ? ( live ? r.band : bandOf( score, true ) ) : 'none';
		const fix = live ? r.fix_count : ( undefined === state.fixCount ? -1 : state.fixCount );
		const word = bandWord( band );
		let spoken;
		if ( 'none' === band || 'pending' === band || null === score ) {
			/* translators: %s: state, e.g. "No keyword" */
			spoken = sprintf( __( 'SEO score: %s', 'mg-seo' ), word );
		} else {
			/* translators: 1: score, 2: band word */
			spoken = sprintf( __( 'SEO score: %1$d out of 100, %2$s', 'mg-seo' ), score, word.toLowerCase() ) + ( fix >= 0 ? '. ' + summary( fix, true ) : '' );
		}
		return { score: 'none' === band ? null : score, band, word, fix, hasKeyword, spoken, sub: summary( fix, hasKeyword ) };
	}

	/* ---------- store ---------- */

	const stores = {};

	/**
	 * Shared state of one post: keyword, title, description, last analysis result.
	 *
	 * @param {Object} cfg Config from PHP (Module::client_config()).
	 */
	function postStore( cfg ) {
		const id = cfg.postId;
		if ( stores[ id ] ) {
			return stores[ id ];
		}
		const values = cfg.values || {};
		let state = {
			postId: id,
			keyword: values.focus_keyword || '',
			title: values.title || '',
			description: values.description || '',
			score: cfg.score,
			fixCount: cfg.fixCount,
			result: null,
			loading: false,
			saving: '',
		};
		const listeners = [];
		const store = {
			cfg,
			get: () => state,
			set( next, source ) {
				const prev = state;
				state = Object.assign( {}, state, next );
				listeners.slice().forEach( ( fn ) => fn( state, prev, source ) );
			},
			subscribe( fn ) {
				listeners.push( fn );
				return () => {
					const i = listeners.indexOf( fn );
					if ( i >= 0 ) {
						listeners.splice( i, 1 );
					}
				};
			},
			view: () => view( state ),
		};
		stores[ id ] = store;
		return store;
	}

	/* ---------- live analysis ---------- */

	/**
	 * Run the analysis whenever fields or content change. One runner per store; the first caller provides the content.
	 *
	 * @param {Object}   store            Store.
	 * @param {Object}   opts             Options.
	 * @param {Function} opts.getContent  Returns { content } (raw editor content) or { html } (rendered), plus post_title, slug.
	 * @param {number}   opts.delay       Debounce in ms.
	 */
	function analysis( store, opts ) {
		if ( store.runner ) {
			return store.runner;
		}
		const o = Object.assign( { getContent: () => ( {} ), delay: 800 }, opts || {} );
		let timer = null;
		let controller = null;
		let lastPayload = '';
		const run = () => {
			const st = store.get();
			const data = Object.assign(
				{
					post_id: st.postId,
					keywords: st.keyword,
					title: st.title,
					description: st.description,
				},
				o.getContent() || {}
			);
			const payload = JSON.stringify( data );
			if ( payload === lastPayload && st.result ) {
				return Promise.resolve( st.result );
			}
			lastPayload = payload;
			if ( controller && controller.abort ) {
				controller.abort();
			}
			controller = window.AbortController ? new AbortController() : null;
			store.set( { loading: true } );
			return wp.apiFetch( {
				path: store.cfg.restPath + '/analyze',
				method: 'POST',
				data,
				signal: controller ? controller.signal : undefined,
			} ).then( ( result ) => {
				store.set( { result, loading: false, score: result.score, fixCount: result.fix_count }, 'analysis' );
				return result;
			} ).catch( () => {
				store.set( { loading: false } );
			} );
		};
		const schedule = ( delay ) => {
			clearTimeout( timer );
			timer = setTimeout( run, undefined === delay ? o.delay : delay );
		};
		store.subscribe( ( st, prev, source ) => {
			if ( 'analysis' !== source && ( st.keyword !== prev.keyword || st.title !== prev.title || st.description !== prev.description ) ) {
				schedule();
			}
		} );
		store.runner = { run, schedule };
		return store.runner;
	}

	/**
	 * Save keyword, title and description over REST (editors without the SEO box form), debounced.
	 *
	 * @param {Object}   store   Store.
	 * @param {Function} getHtml Returns rendered HTML for the returned analysis (optional).
	 */
	function autosave( store, getHtml ) {
		let timer = null;
		let saved = JSON.stringify( [ store.get().keyword, store.get().title, store.get().description ] );
		store.subscribe( ( st, prev, source ) => {
			if ( 'analysis' === source || 'saved' === source ) {
				return;
			}
			const now = JSON.stringify( [ st.keyword, st.title, st.description ] );
			if ( now === saved ) {
				return;
			}
			clearTimeout( timer );
			timer = setTimeout( () => {
				const data = { focus_keyword: st.keyword, title: st.title, description: st.description };
				const html = getHtml ? getHtml() : '';
				if ( html ) {
					data.html = html;
				}
				store.set( { saving: 'saving' }, 'saved' );
				wp.apiFetch( { path: store.cfg.restPath + '/post/' + st.postId + '/seo', method: 'POST', data } ).then( ( result ) => {
					saved = now;
					store.set( { saving: 'saved', result, score: result.score, fixCount: result.fix_count }, 'saved' );
				} ).catch( () => store.set( { saving: 'error' }, 'saved' ) );
			}, 1200 );
		} );
	}

	/* ---------- rendering ---------- */

	function mark( band ) {
		return h( 'span', { className: 'mgseo-mark is-' + band, 'aria-hidden': 'true' } );
	}

	/** Small badge: mark + "SEO 21" (top bars, pinned buttons). */
	function pin( v, label ) {
		const value = null === v.score ? ( v.hasKeyword ? '–' : '?' ) : String( v.score );
		return h( 'span', { className: 'mgseo-pin is-' + v.band, title: v.spoken }, [
			mark( v.band ),
			h( 'span', { 'aria-hidden': 'true' }, ( false === label ? '' : __( 'SEO', 'mg-seo' ) + ' ' ) + value ),
			h( 'span', { className: 'screen-reader-text' }, v.spoken ),
		] );
	}

	/** Score block: big number, word and "4 things to improve". */
	function scoreBlock( v, extra ) {
		let num;
		if ( null !== v.score ) {
			num = h( 'span', { className: 'mgseo-big' }, [ String( v.score ), h( 'small', null, '/100' ) ] );
		} else {
			num = mark( v.band );
		}
		return h( 'div', { className: 'mgseo-scoreblock' }, [
			h( 'div', { className: 'mgseo-scoreline' + ( null === v.score ? ' is-empty' : '' ), 'aria-hidden': 'true' }, [ num, h( 'span', { className: 'mgseo-word is-' + v.band }, v.word ) ] ),
			v.sub ? h( 'div', { className: 'mgseo-sub', 'aria-hidden': 'true' }, v.sub + ( v.hasKeyword && v.fix > 0 && extra ? '. ' + __( 'Start with the first one.', 'mg-seo' ) : '' ) ) : null,
		] );
	}

	const groupOpen = {};

	/**
	 * Hints grouped as "To improve" (open), "Worth considering", "All good" (closed).
	 *
	 * @param {Object} result Analysis result.
	 * @param {Object} host   focus( target ), postId, restPath, links.
	 */
	function hints( result, host ) {
		const wrap = h( 'div', { className: 'mgseo-hints' } );
		const groups = ( result && result.groups ) || [];
		const fixEmpty = ! groups.length || ! groups[ 0 ].items.length;
		groups.forEach( ( group ) => {
			if ( ! group.items.length ) {
				return;
			}
			const key = group.id;
			const open = undefined !== groupOpen[ key ] ? groupOpen[ key ] : ( 'fix' === key || ( 'consider' === key && fixEmpty ) );
			const status = 'fix' === key ? 'fail' : ( 'consider' === key ? 'warn' : 'pass' );
			const details = h( 'details', { className: 'mgseo-group is-' + key, open: open || null }, [
				h( 'summary', null, [ group.label, ' ', h( 'span', { className: 'mgseo-count' }, '(' + group.items.length + ')' ) ] ),
				h( 'ul', null, group.items.map( ( item ) => hint( item, status, group.label, host ) ) ),
			] );
			details.addEventListener( 'toggle', () => {
				groupOpen[ key ] = details.open;
			} );
			wrap.appendChild( details );
		} );
		return wrap;
	}

	function hint( item, status, groupLabel, host ) {
		const body = h( 'div', null, [
			h( 'b', null, [ h( 'span', { className: 'screen-reader-text' }, groupLabel + ': ' ), item.title ] ),
			item.help ? h( 'p', null, item.help ) : null,
		] );
		const acts = h( 'div', { className: 'mgseo-acts' } );
		if ( item.places && item.places.length ) {
			const list = h( 'div', { className: 'mgseo-places', hidden: true }, item.places.map( ( place ) => h( 'span', null, place ) ) );
			/* translators: %d: number of places */
			const btn = h( 'button', { type: 'button', className: 'button-link', 'aria-expanded': 'false' }, sprintf( _n( 'Show %d place', 'Show %d places', item.places.length, 'mg-seo' ), item.places.length ) );
			btn.addEventListener( 'click', () => {
				list.hidden = ! list.hidden;
				btn.setAttribute( 'aria-expanded', list.hidden ? 'false' : 'true' );
			} );
			acts.appendChild( btn );
			body.appendChild( acts );
			body.appendChild( list );
		}
		( item.actions || [] ).forEach( ( action ) => {
			if ( 'url' === action.type ) {
				acts.appendChild( h( 'a', { href: action.url, target: '_blank', rel: 'noopener' }, action.label ) );
			} else if ( 'focus' === action.type && host.focus ) {
				acts.appendChild( h( 'button', { type: 'button', className: 'button-link', onClick: () => host.focus( action.target ) }, action.label ) );
			} else if ( 'links' === action.type ) {
				const out = h( 'div', { className: 'mgseo-suggest', 'aria-live': 'polite' } );
				acts.appendChild( h( 'button', { type: 'button', className: 'button-link', onClick: () => suggestLinks( out, host ) }, action.label ) );
				body.appendChild( out );
			}
		} );
		if ( acts.childNodes.length && ! acts.parentNode ) {
			body.appendChild( acts );
		}
		return h( 'li', { className: 'mgseo-hint is-' + item.status + ( 'ok' === item.status ? ' is-simple' : '' ) }, [ mark( 'ok' === item.status ? 'pass' : status ), body ] );
	}

	function suggestLinks( out, host ) {
		out.textContent = __( 'Looking for matching pages…', 'mg-seo' );
		const st = host.store.get();
		wp.apiFetch( { path: host.store.cfg.restPath + '/links/suggest', method: 'POST', data: { post_id: st.postId, keywords: st.keyword, limit: 5 } } ).then( ( res ) => {
			out.textContent = '';
			const items = ( res && res.suggestions ) || [];
			if ( ! items.length ) {
				out.textContent = __( 'No matching pages found yet.', 'mg-seo' );
				return;
			}
			out.appendChild( h( 'ul', null, items.map( ( item ) => {
				const copy = h( 'button', { type: 'button', className: 'button-link' }, __( 'Copy link', 'mg-seo' ) );
				copy.addEventListener( 'click', () => {
					if ( navigator.clipboard ) {
						navigator.clipboard.writeText( item.url ).then( () => {
							copy.textContent = __( 'Copied', 'mg-seo' );
						} );
					}
				} );
				return h( 'li', null, [ h( 'a', { href: item.url, target: '_blank', rel: 'noopener' }, item.title ), ' ', copy ] );
			} ) ) );
		} ).catch( () => {
			out.textContent = __( 'Could not load suggestions.', 'mg-seo' );
		} );
	}

	/* ---------- keyword field ---------- */

	function keywordField( store, idPrefix ) {
		const id = idPrefix + '-keyword';
		const wrap = h( 'div', { className: 'mgseo-field mgseo-kw' }, [
			h( 'label', { for: id, className: 'mgseo-label' }, __( 'Keyword', 'mg-seo' ) ),
			h( 'input', { type: 'text', id, className: 'mgseo-input mgseo-kw-input', autocomplete: 'off' } ),
			h( 'p', { className: 'mgseo-help', 'data-help': 'keyword' } ),
		] );
		const suggestion = store.cfg.suggestion;
		if ( suggestion ) {
			wrap.appendChild( h( 'p', { className: 'mgseo-help mgseo-kw-suggestion' }, [
				__( 'Suggestion from the title:', 'mg-seo' ) + ' ',
				h( 'button', { type: 'button', className: 'button-link mgseo-use-suggestion', 'data-keyword': suggestion }, suggestion ),
			] ) );
		}
		bindKeyword( wrap, store );
		return wrap;
	}

	/**
	 * Wire a keyword field (JS or PHP markup: input, [data-help="keyword"], .mgseo-use-suggestion) to the store.
	 *
	 * @param {Element} wrap  Field wrapper.
	 * @param {Object}  store Store.
	 */
	function bindKeyword( wrap, store ) {
		const input = wrap.querySelector( 'input' );
		const help = wrap.querySelector( '[data-help="keyword"]' );
		const sug = wrap.querySelector( '.mgseo-kw-suggestion' );
		input.value = store.get().keyword;
		input.classList.add( 'mgseo-kw-input' );
		input.addEventListener( 'input', () => store.set( { keyword: input.value }, 'field' ) );
		wrap.querySelectorAll( '.mgseo-use-suggestion' ).forEach( ( btn ) => btn.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			input.value = btn.dataset.keyword;
			store.set( { keyword: input.value }, 'field' );
			input.focus();
		} ) );
		const update = ( s ) => {
			if ( document.activeElement !== input && input.value !== s.keyword ) {
				input.value = s.keyword;
			}
			const empty = '' === String( s.keyword ).trim();
			if ( help ) {
				help.textContent = empty ?
					__( 'Enter the words someone would type into Google to find this page. Then we will check the text for them and calculate the score.', 'mg-seo' ) :
					__( 'The words someone types into Google to find this page. Separate more keywords with commas.', 'mg-seo' );
			}
			if ( sug ) {
				sug.hidden = ! empty;
			}
		};
		update( store.get() );
		store.subscribe( update );
	}

	/* ---------- Google preview and fields (P1) ---------- */

	function counterText( len, max ) {
		if ( len > max ) {
			/* translators: 1: number of characters, 2: characters over the limit */
			return sprintf( _n( '%1$d character, %2$d too many', '%1$d characters, %2$d too many', len, 'mg-seo' ), len, len - max );
		}
		/* translators: 1: number of characters, 2: recommended maximum */
		return sprintf( __( '%1$d of about %2$d characters', 'mg-seo' ), len, max );
	}

	function escapeRe( s ) {
		return s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	/** Description with the keyword words in bold, like Google does. */
	function boldWords( el, text, keyword ) {
		el.textContent = '';
		const words = String( keyword || '' ).split( /[\s,]+/ ).filter( ( w ) => w.length >= 3 ).map( ( w ) => escapeRe( w.length > 4 ? w.slice( 0, w.length - 1 ) : w ) );
		if ( ! words.length ) {
			el.textContent = text;
			return;
		}
		let re;
		try {
			re = new RegExp( '(' + words.join( '|' ) + ')[\\p{L}]*', 'giu' );
		} catch ( e ) {
			el.textContent = text;
			return;
		}
		let last = 0;
		text.replace( re, ( match, g, offset ) => {
			el.appendChild( document.createTextNode( text.slice( last, offset ) ) );
			el.appendChild( h( 'b', null, match ) );
			last = offset + match.length;
			return match;
		} );
		el.appendChild( document.createTextNode( text.slice( last ) ) );
	}

	function fillVars( text, store, ctx ) {
		const cfg = store.cfg;
		const site = ( cfg.site && cfg.site.name ) || '';
		return String( text )
			.replace( /%title%/g, ( ctx && ctx.postTitle ? ctx.postTitle() : '' ) || '' )
			.replace( /%sitename%/g, site )
			.replace( /%sep%/g, cfg.sep || '-' )
			.replace( /%page%/g, '' )
			.replace( /%currentyear%/g, String( new Date().getFullYear() ) )
			.replace( /%excerpt%/g, ( cfg.auto && cfg.auto.description ) || '' )
			.replace( /\s{2,}/g, ' ' )
			.trim();
	}

	/**
	 * Markup of the Google preview and the title and description fields (used where PHP did not render it).
	 *
	 * @param {string} idPrefix Id prefix.
	 */
	function snippetMarkup( idPrefix ) {
		return h( 'div', { className: 'mgseo-snippet' }, [
			serpMarkup(),
			h( 'div', { className: 'mgseo-field' }, [
				h( 'label', { for: idPrefix + '-title', className: 'mgseo-label' }, [ __( 'Title in Google', 'mg-seo' ), ' ', h( 'span', { className: 'mgseo-counter', 'data-for': 'title' } ) ] ),
				h( 'input', { type: 'text', id: idPrefix + '-title', className: 'mgseo-input', 'data-field': 'title' } ),
				h( 'p', { className: 'mgseo-help', 'data-help': 'title' } ),
			] ),
			h( 'div', { className: 'mgseo-field' }, [
				h( 'label', { for: idPrefix + '-description', className: 'mgseo-label' }, [ __( 'Description in Google', 'mg-seo' ), ' ', h( 'span', { className: 'mgseo-counter', 'data-for': 'description' } ) ] ),
				h( 'textarea', { id: idPrefix + '-description', rows: 3, className: 'mgseo-input', 'data-field': 'description' } ),
				h( 'p', { className: 'mgseo-help', 'data-help': 'description' } ),
			] ),
		] );
	}

	function serpMarkup() {
		return h( 'div', { className: 'mgseo-serp-wrap' }, [
			h( 'div', { className: 'mgseo-device', role: 'group', 'aria-label': __( 'Preview size', 'mg-seo' ) }, [
				h( 'button', { type: 'button', className: 'mgseo-device-btn', 'data-device': 'mobile', 'aria-pressed': 'true' }, __( 'Phone', 'mg-seo' ) ),
				h( 'button', { type: 'button', className: 'mgseo-device-btn', 'data-device': 'desktop', 'aria-pressed': 'false' }, __( 'Desktop', 'mg-seo' ) ),
			] ),
			h( 'div', { className: 'mgseo-serp is-mobile' }, [
				h( 'div', { className: 'mgseo-serp-site' }, [
					h( 'span', { className: 'mgseo-serp-icon', 'aria-hidden': 'true' } ),
					h( 'span', null, [ h( 'span', { className: 'mgseo-serp-name' } ), h( 'span', { className: 'mgseo-serp-url' } ) ] ),
				] ),
				h( 'div', { className: 'mgseo-serp-title' } ),
				h( 'div', { className: 'mgseo-serp-desc' } ),
			] ),
		] );
	}

	/**
	 * Wire a snippet block (PHP or JS markup) to the store: counters in words, preview, automatic text, variables.
	 *
	 * @param {Element} root  Element containing .mgseo-serp and [data-field] inputs.
	 * @param {Object}  store Store.
	 * @param {Object}  ctx   postTitle(): current post title; postUrl(): current URL.
	 */
	function bindSnippet( root, store, ctx ) {
		const cfg = store.cfg;
		const fields = {
			title: root.querySelector( '[data-field="title"]' ),
			description: root.querySelector( '[data-field="description"]' ),
		};
		const auto = () => ( {
			title: ( store.get().result && '' === store.get().title && store.get().result.title ) || fillVars( ( cfg.titleTemplate || '%title% %page% %sep% %sitename%' ), store, ctx ),
			description: ( store.get().result && '' === store.get().description && store.get().result.desc ) || ( cfg.auto && cfg.auto.description ) || '',
		} );
		Object.keys( fields ).forEach( ( key ) => {
			const field = fields[ key ];
			if ( ! field ) {
				return;
			}
			field.value = store.get()[ key ];
			field.addEventListener( 'input', () => store.set( { [ key ]: field.value }, 'field' ) );
		} );
		root.querySelectorAll( '.mgseo-device-btn' ).forEach( ( btn ) => {
			btn.addEventListener( 'click', () => {
				root.querySelectorAll( '.mgseo-device-btn' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', b === btn ? 'true' : 'false' ) );
				const serp = root.querySelector( '.mgseo-serp' );
				serp.classList.toggle( 'is-mobile', 'mobile' === btn.dataset.device );
				serp.classList.toggle( 'is-desktop', 'desktop' === btn.dataset.device );
			} );
		} );
		const site = cfg.site || {};
		const icon = root.querySelector( '.mgseo-serp-icon' );
		if ( icon ) {
			if ( site.icon ) {
				icon.appendChild( h( 'img', { src: site.icon, alt: '', width: 18, height: 18 } ) );
			} else {
				icon.textContent = ( site.name || 'W' ).trim().charAt( 0 ).toUpperCase();
			}
		}
		const render = ( st ) => {
			const a = auto();
			Object.keys( fields ).forEach( ( key ) => {
				const field = fields[ key ];
				if ( ! field ) {
					return;
				}
				if ( document.activeElement !== field && field.value !== st[ key ] ) {
					field.value = st[ key ];
				}
				field.placeholder = a[ key ];
				field.classList.toggle( 'is-auto', '' === field.value );
				const shown = '' !== st[ key ] ? fillVars( st[ key ], store, ctx ) : a[ key ];
				const counter = root.querySelector( '.mgseo-counter[data-for="' + key + '"]' );
				const max = 'title' === key ? 60 : 160;
				if ( counter ) {
					counter.textContent = counterText( shown.length, max );
					counter.classList.toggle( 'is-over', shown.length > max );
				}
				const help = root.querySelector( '[data-help="' + key + '"]' );
				if ( help ) {
					if ( 'title' === key ) {
						help.textContent = '' === st.title ? __( 'Created automatically from the page title and the site name. Start typing to set your own.', 'mg-seo' ) : '';
					} else {
						help.textContent = '' === st.description ? __( 'Google will now show the beginning of the text. Write 1 or 2 sentences that make people want to click.', 'mg-seo' ) : '';
					}
				}
			} );
			const title = '' !== st.title ? fillVars( st.title, store, ctx ) : a.title;
			const desc = '' !== st.description ? fillVars( st.description, store, ctx ) : a.description;
			const set = ( sel, text ) => {
				const el = root.querySelector( sel );
				if ( el ) {
					el.textContent = text;
				}
			};
			set( '.mgseo-serp-name', site.name || '' );
			let path = '';
			try {
				const url = new URL( ( ctx && ctx.postUrl && ctx.postUrl() ) || site.url || '' );
				path = url.host + url.pathname.replace( /\/$/, '' ).split( '/' ).filter( Boolean ).map( ( p ) => ' › ' + decodeURIComponent( p ) ).join( '' );
			} catch ( e ) {
				path = site.host || '';
			}
			set( '.mgseo-serp-url', path );
			set( '.mgseo-serp-title', title.length > 62 ? title.slice( 0, 60 ).trim() + '…' : title );
			const descEl = root.querySelector( '.mgseo-serp-desc' );
			if ( descEl ) {
				boldWords( descEl, desc.length > 160 ? desc.slice( 0, 157 ).trim() + '…' : desc, st.keyword.split( ',' )[ 0 ] );
			}
		};
		render( store.get() );
		store.subscribe( render );
		return { render };
	}

	/**
	 * Variable chips under "Insert variable" (advanced; the preview shows the result).
	 *
	 * @param {Element} button The "Insert variable" button.
	 * @param {Element} field  Field to insert into.
	 */
	function variablesToggle( button, field ) {
		const vars = [ '%title%', '%sitename%', '%sep%', '%excerpt%', '%category%', '%currentyear%', '%page%' ];
		const list = h( 'div', { className: 'mgseo-vars', hidden: true }, vars.map( ( v ) => h( 'button', { type: 'button', className: 'mgseo-var', onClick: () => {
			const start = field.selectionStart || field.value.length;
			field.value = field.value.slice( 0, start ) + ( start > 0 && ' ' !== field.value.charAt( start - 1 ) ? ' ' : '' ) + v + field.value.slice( field.selectionEnd || start );
			field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			field.focus();
		} }, v ) ) );
		button.setAttribute( 'aria-expanded', 'false' );
		button.addEventListener( 'click', () => {
			list.hidden = ! list.hidden;
			button.setAttribute( 'aria-expanded', list.hidden ? 'false' : 'true' );
		} );
		button.parentNode.insertBefore( list, button.nextSibling );
	}

	/**
	 * The full panel: score, keyword, hints, optional Google fields and links.
	 *
	 * @param {Element} container Where to render.
	 * @param {Object}  store     Store.
	 * @param {Object}  host      keyword (bool), snippet (bool), focus( target ), postTitle(), postUrl(), extra (Element[]), idPrefix.
	 */
	function panel( container, store, host ) {
		const ho = Object.assign( { keyword: true, snippet: false, idPrefix: 'mgseo-p' + store.get().postId }, host || {} );
		ho.store = store;
		const root = h( 'div', { className: 'mgseo-panel' } );
		const live = h( 'div', { className: 'screen-reader-text', 'aria-live': 'polite', role: 'status' } );
		const scoreBox = h( 'div', { className: 'mgseo-panel-score' } );
		const hintBox = h( 'div', { className: 'mgseo-panel-hints' } );
		const status = h( 'p', { className: 'mgseo-status' } );
		root.appendChild( live );
		root.appendChild( scoreBox );
		if ( ho.keywordEl ) {
			root.appendChild( ho.keywordEl );
			bindKeyword( ho.keywordEl, store );
		} else if ( ho.keyword ) {
			root.appendChild( keywordField( store, ho.idPrefix ) );
		}
		root.appendChild( hintBox );
		let snippetFocus = null;
		if ( ho.snippet ) {
			const details = h( 'details', { className: 'mgseo-fold' }, [ h( 'summary', null, __( 'Title and description in Google', 'mg-seo' ) ) ] );
			const snip = snippetMarkup( ho.idPrefix );
			details.appendChild( snip );
			root.appendChild( details );
			bindSnippet( snip, store, ho );
			snippetFocus = ( target ) => {
				details.open = true;
				const f = snip.querySelector( '[data-field="' + target + '"]' );
				if ( f ) {
					f.focus();
					f.scrollIntoView( { block: 'center', behavior: 'smooth' } );
				}
			};
		}
		( ho.extra || [] ).forEach( ( el ) => root.appendChild( el ) );
		root.appendChild( status );
		const userFocus = ho.focus;
		ho.focus = ( target ) => {
			if ( 'keyword' === target ) {
				const kw = root.querySelector( '.mgseo-kw-input' );
				if ( kw ) {
					kw.focus();
					return;
				}
			}
			if ( snippetFocus && ( 'title' === target || 'description' === target ) ) {
				snippetFocus( target );
				return;
			}
			if ( userFocus ) {
				userFocus( target );
			}
		};
		let lastSpoken = '';
		let lastResult = null;
		const render = ( st ) => {
			const v = view( st );
			scoreBox.textContent = '';
			scoreBox.appendChild( scoreBlock( v, true ) );
			if ( v.spoken !== lastSpoken ) {
				live.textContent = v.spoken;
				lastSpoken = v.spoken;
			}
			if ( st.result !== lastResult ) {
				lastResult = st.result;
				hintBox.textContent = '';
				if ( st.result && st.result.groups ) {
					hintBox.appendChild( hints( st.result, ho ) );
				} else {
					hintBox.appendChild( h( 'p', { className: 'mgseo-help' }, __( 'Checking the page…', 'mg-seo' ) ) );
				}
			}
			root.classList.toggle( 'is-loading', !! st.loading );
			status.textContent = { saving: __( 'Saving…', 'mg-seo' ), saved: __( 'Saved', 'mg-seo' ), error: __( 'Could not save. Check your connection and try again.', 'mg-seo' ) }[ st.saving ] || '';
		};
		render( store.get() );
		const off = store.subscribe( render );
		container.appendChild( root );
		return { root, destroy: () => {
			off();
			root.remove();
		} };
	}

	/**
	 * Rendered HTML of a page builder canvas without the builder's own controls.
	 *
	 * @param {Object} canvas { frame, content, strip } selectors.
	 */
	function canvasHtml( canvas ) {
		if ( ! canvas || ! canvas.content ) {
			return '';
		}
		let doc = document;
		if ( canvas.frame ) {
			const frame = document.querySelector( canvas.frame );
			try {
				doc = frame && frame.contentDocument;
			} catch ( e ) {
				doc = null;
			}
		}
		const node = doc && doc.querySelector( canvas.content );
		if ( ! node ) {
			return '';
		}
		const clone = node.cloneNode( true );
		if ( canvas.strip ) {
			clone.querySelectorAll( canvas.strip ).forEach( ( el ) => el.remove() );
		}
		clone.querySelectorAll( 'script, style, template, noscript, svg' ).forEach( ( el ) => el.remove() );
		return clone.innerHTML;
	}

	/**
	 * Watch a builder canvas and call back (debounced) when its content changes.
	 *
	 * @param {Object}   canvas Selectors.
	 * @param {Function} cb     Callback.
	 */
	function watchCanvas( canvas, cb ) {
		let observer = null;
		let watched = null;
		let timer = null;
		const fire = () => {
			clearTimeout( timer );
			timer = setTimeout( cb, 1500 );
		};
		const attach = () => {
			let doc = document;
			if ( canvas.frame ) {
				const frame = document.querySelector( canvas.frame );
				try {
					doc = frame && frame.contentDocument;
				} catch ( e ) {
					doc = null;
				}
			}
			const node = doc && doc.querySelector( canvas.content );
			if ( node && node !== watched ) {
				if ( observer ) {
					observer.disconnect();
				}
				watched = node;
				observer = new window.MutationObserver( fire );
				observer.observe( node, { childList: true, subtree: true, characterData: true } );
				fire();
			}
		};
		attach();
		setInterval( attach, 3000 );
	}

	/**
	 * Floating "SEO 68" button with a slide-over panel, for editors that hide the WordPress admin bar.
	 *
	 * @param {Object} store Store.
	 * @param {Object} opts  note (string shown at the bottom), open (bool), panel (host options for panel()).
	 */
	function floating( store, opts ) {
		const o = Object.assign( { note: '', open: false, panel: {} }, opts || {} );
		const btn = h( 'button', { type: 'button', className: 'mgseo-float-btn', 'aria-expanded': 'false', 'aria-controls': 'mgseo-float-panel' } );
		const close = h( 'button', { type: 'button', className: 'mgseo-float-close', 'aria-label': __( 'Close', 'mg-seo' ) }, '×' );
		const body = h( 'div', { className: 'mgseo-float-body' } );
		const box = h( 'div', { className: 'mgseo-float-panel', id: 'mgseo-float-panel', role: 'dialog', 'aria-label': __( 'SEO', 'mg-seo' ), hidden: true }, [
			h( 'div', { className: 'mgseo-float-head' }, [ h( 'b', null, __( 'SEO', 'mg-seo' ) ), close ] ),
			body,
		] );
		const wrap = h( 'div', { className: 'mgseo-float' }, [ box, btn ] );
		document.body.appendChild( wrap );
		panel( body, store, Object.assign( { snippet: true, idPrefix: 'mgseo-fl' }, o.panel ) );
		if ( o.note ) {
			body.appendChild( h( 'p', { className: 'mgseo-help mgseo-float-note' }, o.note ) );
		}
		const toggle = ( show ) => {
			box.hidden = ! show;
			btn.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
			if ( show ) {
				const first = box.querySelector( 'input, button' );
				if ( first ) {
					first.focus();
				}
			}
		};
		btn.addEventListener( 'click', () => toggle( box.hidden ) );
		close.addEventListener( 'click', () => {
			toggle( false );
			btn.focus();
		} );
		box.addEventListener( 'keydown', ( e ) => {
			if ( 'Escape' === e.key ) {
				toggle( false );
				btn.focus();
			}
		} );
		const render = ( st ) => {
			btn.textContent = '';
			btn.appendChild( pin( view( st ) ) );
		};
		render( store.get() );
		store.subscribe( render );
		if ( o.open ) {
			toggle( true );
		}
		return { toggle };
	}

	window.mgseoUI = {
		h,
		mark,
		pin,
		view,
		bandOf,
		bandWord,
		postStore,
		analysis,
		autosave,
		panel,
		hints,
		scoreBlock,
		snippetMarkup,
		bindSnippet,
		bindKeyword,
		variablesToggle,
		counterText,
		canvasHtml,
		watchCanvas,
		floating,
	};
}() );
