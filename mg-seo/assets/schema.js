/* MG SEO schema: generator tab, templates editor, contact points and FAQ / How-to blocks. No build step. */
( function () {
	'use strict';

	var cfg = window.mgseoSchema || {};
	var i18n = cfg.i18n || {};
	var types = cfg.types || {};
	var counter = 0;

	function t( key, fallback ) {
		return i18n[ key ] || fallback || key;
	}

	function uid() {
		counter++;
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		return 'e' + Date.now().toString( 36 ) + counter + Math.random().toString( 36 ).slice( 2, 8 );
	}

	function isObj( v ) {
		return v !== null && typeof v === 'object' && ! Array.isArray( v );
	}

	function clone( v ) {
		return v === undefined ? v : JSON.parse( JSON.stringify( v ) );
	}

	function parse( text, fallback ) {
		try {
			var v = JSON.parse( text );
			return v === null ? fallback : v;
		} catch ( e ) {
			return fallback;
		}
	}

	/* ====================================================================
	 * Admin forms (jQuery)
	 * ================================================================== */
	function initAdmin( $ ) {
		var fieldId = 0;

		function nextId() {
			fieldId++;
			return 'mgseo-sf-' + fieldId;
		}

		function hasVariable( v ) {
			return typeof v === 'string' && /%[a-z0-9_]+(\([^)%]*\))?%/i.test( v );
		}

		/* Media picker for image fields rendered by this script. */
		function pickImage( $input ) {
			if ( ! window.wp || ! wp.media ) {
				return;
			}
			var frame = wp.media( {
				title: t( 'chooseImage', 'Choose image' ),
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				$input.val( att.url ).trigger( 'input' );
			} );
			frame.open();
		}

		function renderFields( $wrap, fields, obj, ctx ) {
			$wrap.empty();
			var current = obj[ '@type' ];
			( fields || [] ).forEach( function ( field ) {
				if ( field.only && field.only.indexOf( current ) === -1 ) {
					return;
				}
				$wrap.append( renderField( field, obj, ctx ) );
			} );
		}

		function renderField( field, obj, ctx ) {
			if ( field.type === 'group' ) {
				return renderGroup( field, obj, ctx );
			}
			if ( field.type === 'repeater' ) {
				return renderRepeater( field, obj, ctx );
			}
			var id = nextId();
			var value = obj[ field.key ];
			var $row = $( '<div class="mgseo-sf-row"></div>' ).addClass( 'mgseo-sf-type-' + field.type );
			var $label = $( '<label></label>' ).attr( 'for', id ).text( field.label );
			var $input;
			var set = function ( v ) {
				if ( v === '' || v === null || ( Array.isArray( v ) && ! v.length ) || v === false ) {
					delete obj[ field.key ];
				} else {
					obj[ field.key ] = v;
				}
				ctx.changed();
			};

			switch ( field.type ) {
				case 'textarea':
					$input = $( '<textarea rows="3" class="widefat"></textarea>' ).val( value || '' );
					$input.on( 'input', function () { set( this.value ); } );
					break;
				case 'list':
					$input = $( '<textarea rows="3" class="widefat"></textarea>' ).val( Array.isArray( value ) ? value.join( '\n' ) : ( value || '' ) );
					$input.on( 'input', function () {
						set( this.value.split( /\r?\n/ ).map( function ( s ) { return s.trim(); } ).filter( Boolean ) );
					} );
					$row.append( $label, $input.attr( 'id', id ), $( '<span class="description"></span>' ).text( t( 'onePerLine' ) ) );
					return $row;
				case 'select':
					$input = $( '<select></select>' );
					Object.keys( field.options || {} ).forEach( function ( key ) {
						$input.append( $( '<option></option>' ).attr( 'value', key ).text( field.options[ key ] ) );
					} );
					if ( value !== undefined && value !== '' && ! ( value in ( field.options || {} ) ) ) {
						$input.append( $( '<option></option>' ).attr( 'value', value ).text( value ) );
					}
					$input.val( value === undefined ? Object.keys( field.options || {} )[ 0 ] : value );
					$input.on( 'change', function () {
						set( this.value );
						if ( field.key === '@type' && ctx.retype ) {
							ctx.retype();
						}
					} );
					break;
				case 'checks':
					$input = $( '<fieldset class="mgseo-sf-checks"></fieldset>' ).attr( 'id', id ).append( $( '<legend class="screen-reader-text"></legend>' ).text( field.label ) );
					var selected = Array.isArray( value ) ? value : ( value ? [ value ] : [] );
					Object.keys( field.options || {} ).forEach( function ( key ) {
						var $cb = $( '<input type="checkbox" />' ).attr( 'value', key ).prop( 'checked', selected.indexOf( key ) !== -1 );
						$input.append( $( '<label class="mgseo-inline"></label>' ).append( $cb, ' ', document.createTextNode( field.options[ key ] ) ) );
					} );
					$input.on( 'change', 'input', function () {
						set( $input.find( 'input:checked' ).map( function () { return this.value; } ).get() );
					} );
					$row.append( $( '<span class="mgseo-sf-label"></span>' ).text( field.label ), $input );
					return $row;
				case 'toggle':
					$input = $( '<input type="checkbox" />' ).attr( 'id', id ).prop( 'checked', !! value );
					$input.on( 'change', function () { set( this.checked ); } );
					$row.append( $( '<label></label>' ).attr( 'for', id ).append( $input, ' ', document.createTextNode( field.label ) ) );
					return $row;
				case 'image':
					$input = $( '<input type="text" class="regular-text" />' ).val( value || '' ).attr( 'placeholder', 'https://' );
					$input.on( 'input change', function () { set( this.value.trim() ); } );
					var $btn = $( '<button type="button" class="button"></button>' ).text( t( 'chooseImage', 'Choose image' ) ).on( 'click', function () { pickImage( $input ); } );
					$input.attr( 'id', id );
					$row.append( $label, $( '<span class="mgseo-sf-image"></span>' ).append( $input, ' ', $btn ) );
					if ( field.help ) {
						$row.append( $( '<span class="description"></span>' ).text( field.help ) );
					}
					return $row;
				case 'date':
				case 'datetime':
				case 'time':
					var html = { date: 'date', datetime: 'datetime-local', time: 'time' }[ field.type ];
					var patterns = { date: /^\d{4}-\d{2}-\d{2}$/, datetime: /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/, time: /^\d{2}:\d{2}(:\d{2})?$/ };
					var useText = value && ! patterns[ field.type ].test( value );
					$input = $( '<input />' ).attr( 'type', useText ? 'text' : html ).val( value || '' );
					$input.on( 'input change', function () { set( this.value ); } );
					break;
				case 'duration':
					$input = $( '<input type="text" class="regular-text" />' ).val( value || '' ).attr( 'placeholder', 'PT1H30M' );
					$input.on( 'input', function () { set( this.value.trim() ); } );
					$row.append( $label.attr( 'for', id ), $input.attr( 'id', id ), $( '<span class="description"></span>' ).text( t( 'durationHint' ) ) );
					return $row;
				case 'number':
					$input = $( '<input type="text" inputmode="decimal" class="small-text" />' ).val( value === undefined ? '' : value );
					$input.on( 'input', function () { set( this.value.trim() ); } );
					break;
				default:
					$input = $( '<input type="text" class="widefat" />' ).val( value === undefined ? '' : value );
					if ( field.type === 'url' ) {
						$input.attr( 'placeholder', 'https://' );
					}
					$input.on( 'input', function () { set( this.value ); } );
			}
			if ( field.placeholder ) {
				$input.attr( 'placeholder', field.placeholder );
			}
			$input.attr( 'id', id );
			$row.append( $label, $input );
			if ( field.help ) {
				$row.append( $( '<span class="description"></span>' ).text( field.help ) );
			}
			return $row;
		}

		function renderGroup( field, obj, ctx ) {
			var allowed = field.types || ( field.schema_type ? [ field.schema_type ] : [] );
			if ( ! isObj( obj[ field.key ] ) ) {
				var first = field.fields && field.fields[ 0 ] ? field.fields[ 0 ].key : 'name';
				var seed = {};
				if ( typeof obj[ field.key ] === 'string' && obj[ field.key ] !== '' ) {
					seed[ first ] = obj[ field.key ];
				}
				obj[ field.key ] = seed;
			}
			var sub = obj[ field.key ];
			if ( ! sub[ '@type' ] && allowed.length ) {
				sub[ '@type' ] = allowed[ 0 ];
			}
			var $inner = $( '<div class="mgseo-sf-fields"></div>' );
			if ( field.inline ) {
				renderFields( $inner, field.fields, sub, ctx );
				return $inner.addClass( 'mgseo-sf-inline' );
			}
			var $box = $( '<fieldset class="mgseo-sf-group"></fieldset>' ).append( $( '<legend></legend>' ).text( field.label ) );
			if ( allowed.length > 1 ) {
				var id = nextId();
				var $sel = $( '<select></select>' ).attr( 'id', id );
				allowed.forEach( function ( type ) {
					$sel.append( $( '<option></option>' ).attr( 'value', type ).text( type ) );
				} );
				$sel.val( sub[ '@type' ] );
				$sel.on( 'change', function () {
					sub[ '@type' ] = this.value;
					renderFields( $inner, field.fields, sub, ctx );
					ctx.changed();
				} );
				$box.append( $( '<div class="mgseo-sf-row"></div>' ).append( $( '<label></label>' ).attr( 'for', id ).text( t( 'type', 'Type' ) ), $sel ) );
			}
			renderFields( $inner, field.fields, sub, ctx );
			return $box.append( $inner );
		}

		function renderRepeater( field, obj, ctx ) {
			if ( ! Array.isArray( obj[ field.key ] ) ) {
				obj[ field.key ] = isObj( obj[ field.key ] ) ? [ obj[ field.key ] ] : [];
			}
			var list = obj[ field.key ];
			var $box = $( '<fieldset class="mgseo-sf-repeater"></fieldset>' ).append( $( '<legend></legend>' ).text( field.label ) );
			var $items = $( '<div class="mgseo-sf-items"></div>' );

			function draw( focusLast ) {
				$items.empty();
				list.forEach( function ( item, idx ) {
					if ( ! isObj( item ) ) {
						item = list[ idx ] = {};
					}
					if ( field.schema_type && ! item[ '@type' ] ) {
						item[ '@type' ] = field.schema_type;
					}
					var $item = $( '<div class="mgseo-sf-item"></div>' );
					var $head = $( '<div class="mgseo-sf-item-head"></div>' ).append( $( '<span class="mgseo-sf-item-num"></span>' ).text( idx + 1 + '.' ) );
					var tool = function ( label, icon, fn, disabled ) {
						return $( '<button type="button" class="button-link"></button>' ).attr( 'aria-label', label ).attr( 'title', label ).html( '<span class="dashicons dashicons-' + icon + '" aria-hidden="true"></span>' ).prop( 'disabled', !! disabled ).on( 'click', fn );
					};
					$head.append(
						tool( t( 'moveUp' ), 'arrow-up-alt2', function () {
							list.splice( idx - 1, 0, list.splice( idx, 1 )[ 0 ] );
							draw();
							ctx.changed();
						}, idx === 0 ),
						tool( t( 'moveDown' ), 'arrow-down-alt2', function () {
							list.splice( idx + 1, 0, list.splice( idx, 1 )[ 0 ] );
							draw();
							ctx.changed();
						}, idx === list.length - 1 ),
						tool( t( 'remove' ), 'trash', function () {
							list.splice( idx, 1 );
							draw();
							ctx.changed();
						} )
					);
					var $fields = $( '<div class="mgseo-sf-fields"></div>' );
					renderFields( $fields, field.fields, item, ctx );
					$items.append( $item.append( $head, $fields ) );
				} );
				if ( focusLast ) {
					$items.find( '.mgseo-sf-item' ).last().find( 'input, textarea, select' ).first().trigger( 'focus' );
				}
			}
			draw();
			var $add = $( '<button type="button" class="button button-small"></button>' ).text( t( 'addItem', 'Add item' ) + ': ' + field.label ).on( 'click', function () {
				var item = {};
				if ( field.schema_type ) {
					item[ '@type' ] = field.schema_type;
				}
				list.push( item );
				draw( true );
				ctx.changed();
			} );
			return $box.append( $items, $( '<p></p>' ).append( $add ) );
		}

		/* One entity card (generator entity or template). */
		function renderEntity( entity, ctx, opts ) {
			opts = opts || {};
			var def = types[ entity.type ];
			var $card = $( '<div class="mgseo-schema-entity"></div>' );
			var $head = $( '<div class="mgseo-schema-entity-head"></div>' );
			var bodyId = nextId();
			var title = entity.custom ? t( 'custom', 'Custom JSON-LD' ) : ( def ? def.label : entity.type );
			var sub = ! entity.custom && entity.data && entity.data[ '@type' ] && entity.data[ '@type' ] !== entity.type ? ' (' + entity.data[ '@type' ] + ')' : '';
			var $toggle = $( '<button type="button" class="mgseo-schema-entity-toggle" aria-expanded="true"></button>' ).attr( 'aria-controls', bodyId ).text( title + sub );
			$head.append( $toggle );
			if ( entity.id === 'default' || entity[ 'default' ] ) {
				$head.append( $( '<span class="mgseo-schema-badge"></span>' ).text( t( 'default', 'Default' ) ) );
			}
			if ( opts.remove ) {
				$head.append( $( '<button type="button" class="button-link mgseo-schema-remove"></button>' ).text( t( 'remove', 'Remove' ) ).on( 'click', opts.remove ) );
			}
			var $body = $( '<div class="mgseo-schema-entity-body"></div>' ).attr( 'id', bodyId );
			$toggle.on( 'click', function () {
				var open = $toggle.attr( 'aria-expanded' ) === 'true';
				$toggle.attr( 'aria-expanded', open ? 'false' : 'true' );
				$body.toggle( ! open );
			} );

			if ( entity.custom ) {
				var id = nextId();
				if ( entity._raw === undefined ) {
					entity._raw = JSON.stringify( entity.data || {}, null, 2 );
				}
				var $msg = $( '<p class="mgseo-schema-json-status" role="status"></p>' );
				var $ta = $( '<textarea rows="10" class="widefat code" spellcheck="false"></textarea>' ).attr( 'id', id ).val( entity._raw );
				var check = function () {
					entity._raw = $ta.val();
					try {
						var parsed = JSON.parse( entity._raw );
						if ( ! isObj( parsed ) && ! Array.isArray( parsed ) ) {
							throw new Error( 'JSON-LD must be an object' );
						}
						entity.data = parsed;
						entity._invalid = false;
						var ty = parsed[ '@type' ];
						entity.type = Array.isArray( ty ) ? String( ty[ 0 ] ) : ( ty ? String( ty ) : ( parsed[ '@graph' ] ? 'Graph' : 'Thing' ) );
						$msg.removeClass( 'is-error' ).addClass( 'is-ok' ).text( t( 'validJson', 'Valid JSON' ) );
						$ta.attr( 'aria-invalid', 'false' );
					} catch ( e ) {
						entity._invalid = true;
						$msg.removeClass( 'is-ok' ).addClass( 'is-error' ).text( t( 'invalidJson', 'Invalid JSON:' ) + ' ' + e.message );
						$ta.attr( 'aria-invalid', 'true' );
					}
				};
				$ta.on( 'input', function () {
					check();
					ctx.changed();
				} );
				$body.append( $( '<label></label>' ).attr( 'for', id ).text( t( 'custom' ) ), $ta, $msg, $( '<p class="description"></p>' ).text( t( 'customHelp' ) ) );
				check();
			} else if ( def ) {
				if ( ! isObj( entity.data ) ) {
					entity.data = {};
				}
				var $fields = $( '<div class="mgseo-sf-fields"></div>' );
				var local = {
					changed: ctx.changed,
					retype: function () {
						renderFields( $fields, def.fields, entity.data, local );
						var s = entity.data[ '@type' ] && entity.data[ '@type' ] !== entity.type ? ' (' + entity.data[ '@type' ] + ')' : '';
						$toggle.text( title + s );
					}
				};
				renderFields( $fields, def.fields, entity.data, local );
				$body.append( $fields, $( '<p class="description"></p>' ).text( t( 'variablesHint' ) ) );
			}
			return $card.append( $head, $body );
		}

		function serializeEntity( e ) {
			return {
				id: e.id,
				type: e.type,
				custom: !! e.custom,
				data: e.custom && e._invalid ? e._raw : e.data
			};
		}

		function newEntity( type ) {
			if ( type === 'custom' ) {
				var data = { '@type': 'Thing', name: '%title%' };
				return { id: uid(), type: 'Thing', custom: true, data: data, _raw: JSON.stringify( data, null, 2 ) };
			}
			return { id: uid(), type: type, custom: false, data: clone( ( types[ type ] || {} ).defaults ) || {} };
		}

		/* ---------- Generator tab ---------- */
		$( '.mgseo-schema' ).each( function () {
			var $root = $( this );
			var $json = $root.find( '.mgseo-schema-json' );
			var $list = $root.find( '.mgseo-schema-entities' );
			var entities = parse( $json.val(), [] );
			entities = Array.isArray( entities ) ? entities : [];
			var ctx = {
				changed: function () {
					$root.find( '.mgseo-schema-changed' ).val( '1' );
					sync();
				}
			};

			function sync() {
				$json.val( JSON.stringify( entities.map( serializeEntity ) ) );
			}

			function draw() {
				$list.empty();
				if ( ! entities.length ) {
					$list.append( $( '<p class="description"></p>' ).text( t( 'empty' ) ) );
				}
				entities.forEach( function ( entity, idx ) {
					$list.append( renderEntity( entity, ctx, {
						remove: function () {
							if ( ! window.confirm( t( 'removeConfirm' ) ) ) {
								return;
							}
							entities.splice( idx, 1 );
							draw();
							ctx.changed();
						}
					} ) );
				} );
			}

			$root.on( 'click', '.mgseo-schema-add-button', function () {
				entities.push( newEntity( $root.find( '.mgseo-schema-add-type' ).val() ) );
				$root.find( '.mgseo-schema-reset' ).val( '0' );
				draw();
				ctx.changed();
				$list.find( '.mgseo-schema-entity' ).last().find( 'input, textarea, select' ).first().trigger( 'focus' );
			} );

			$root.on( 'click', '.mgseo-schema-reset-button', function () {
				entities = [];
				draw();
				ctx.changed();
				$root.find( '.mgseo-schema-reset' ).val( '1' );
				$root.find( '.mgseo-schema-status' ).text( t( 'default' ) + ': ' + t( 'empty' ) );
				$( this ).remove();
			} );

			$root.on( 'click', '.mgseo-schema-validate', function () {
				var $out = $root.find( '.mgseo-schema-results' );
				$out.empty().append( $( '<p></p>' ).text( t( 'validating' ) ) );
				$.ajax( {
					url: cfg.restUrl,
					method: 'POST',
					contentType: 'application/json',
					data: JSON.stringify( { post_id: parseInt( $root.data( 'post-id' ), 10 ), entities: JSON.stringify( entities.map( serializeEntity ) ) } ),
					beforeSend: function ( xhr ) {
						xhr.setRequestHeader( 'X-WP-Nonce', cfg.nonce );
					}
				} ).done( function ( res ) {
					renderResults( $out, res );
				} ).fail( function () {
					$out.empty().append( $( '<div class="notice notice-error inline"></div>' ).append( $( '<p></p>' ).text( t( 'requestFailed' ) ) ) );
				} );
			} );

			draw();
			sync();
		} );

		function renderResults( $out, res ) {
			$out.empty();
			( res.errors || [] ).forEach( function ( msg ) {
				$out.append( $( '<div class="notice notice-error inline"></div>' ).append( $( '<p></p>' ).text( msg ) ) );
			} );
			( res.results || [] ).forEach( function ( r ) {
				var $box = $( '<div class="mgseo-schema-result"></div>' );
				var ok = ! r.errors.length && ! r.warnings.length;
				$box.addClass( r.errors.length ? 'has-errors' : ( r.warnings.length ? 'has-warnings' : 'is-ok' ) );
				$box.append( $( '<strong></strong>' ).text( r.type ), ' ', $( '<code></code>' ).text( r.id ) );
				if ( ok ) {
					$box.append( $( '<p></p>' ).text( t( 'noIssues' ) ) );
				}
				[ [ 'errors', r.errors ], [ 'warnings', r.warnings ] ].forEach( function ( pair ) {
					if ( ! pair[ 1 ].length ) {
						return;
					}
					var $ul = $( '<ul></ul>' );
					pair[ 1 ].forEach( function ( m ) {
						$ul.append( $( '<li></li>' ).text( m ) );
					} );
					$box.append( $( '<p class="mgseo-schema-result-label"></p>' ).text( t( pair[ 0 ] ) ), $ul );
				} );
				$out.append( $box );
			} );
			if ( res.json ) {
				$out.append( $( '<details class="mgseo-schema-preview"></details>' ).append( $( '<summary></summary>' ).text( t( 'preview' ) ), $( '<pre></pre>' ).text( res.json ) ) );
			}
		}

		/* ---------- Templates (settings) ---------- */
		$( '.mgseo-schema-templates' ).each( function () {
			var $root = $( this );
			var $json = $root.find( '.mgseo-schema-json' );
			var $list = $root.find( '.mgseo-schema-templates-list' );
			var templates = parse( $json.val(), [] );
			templates = Array.isArray( templates ) ? templates : [];
			var ctx = { changed: sync };

			function sync() {
				$json.val( JSON.stringify( templates.map( function ( tpl ) {
					var out = serializeEntity( tpl );
					out.name = tpl.name || '';
					out.conditions = tpl.conditions || [];
					return out;
				} ) ) );
			}

			function valueControl( cond, idx ) {
				var label = t( 'conditions' ) + ' ' + ( idx + 1 );
				if ( cond.kind === 'all' ) {
					return $( '<span></span>' );
				}
				if ( cond.kind === 'post_type' ) {
					var $sel = $( '<select></select>' ).attr( 'aria-label', label );
					Object.keys( cfg.postTypes || {} ).forEach( function ( key ) {
						$sel.append( $( '<option></option>' ).attr( 'value', key ).text( cfg.postTypes[ key ] ) );
					} );
					if ( ! cond.value ) {
						cond.value = Object.keys( cfg.postTypes || {} )[ 0 ] || '';
					}
					return $sel.val( cond.value ).on( 'change', function () {
						cond.value = this.value;
						sync();
					} );
				}
				return $( '<input type="text" class="regular-text" />' ).attr( 'aria-label', label ).attr( 'placeholder', cond.kind === 'taxonomy' ? t( 'taxonomyHint' ) : t( 'postIdsHint' ) ).val( cond.value || '' ).on( 'input', function () {
					cond.value = this.value;
					sync();
				} );
			}

			function drawConditions( $wrap, tpl ) {
				$wrap.empty();
				tpl.conditions = Array.isArray( tpl.conditions ) ? tpl.conditions : [];
				tpl.conditions.forEach( function ( cond, idx ) {
					var $row = $( '<div class="mgseo-schema-condition"></div>' );
					var $inc = $( '<select></select>' ).attr( 'aria-label', t( 'include' ) + ' / ' + t( 'exclude' ) )
						.append( $( '<option value="1"></option>' ).text( t( 'include' ) ), $( '<option value="0"></option>' ).text( t( 'exclude' ) ) )
						.val( cond.include ? '1' : '0' ).on( 'change', function () {
							cond.include = this.value === '1';
							sync();
						} );
					var kinds = { all: t( 'kindAll' ), post_type: t( 'kindPostType' ), post: t( 'kindPost' ), taxonomy: t( 'kindTaxonomy' ) };
					var $kind = $( '<select></select>' ).attr( 'aria-label', t( 'conditions' ) );
					Object.keys( kinds ).forEach( function ( k ) {
						$kind.append( $( '<option></option>' ).attr( 'value', k ).text( kinds[ k ] ) );
					} );
					$kind.val( cond.kind || 'all' ).on( 'change', function () {
						cond.kind = this.value;
						cond.value = '';
						drawConditions( $wrap, tpl );
						sync();
					} );
					var $remove = $( '<button type="button" class="button-link"></button>' ).text( t( 'remove' ) ).on( 'click', function () {
						tpl.conditions.splice( idx, 1 );
						drawConditions( $wrap, tpl );
						sync();
					} );
					$wrap.append( $row.append( $inc, ' ', $kind, ' ', valueControl( cond, idx ), ' ', $remove ) );
				} );
				if ( ! tpl.conditions.some( function ( c ) { return c.include; } ) ) {
					$wrap.append( $( '<p class="description"></p>' ).text( t( 'noConditions' ) ) );
				}
			}

			function draw() {
				$list.empty();
				templates.forEach( function ( tpl, idx ) {
					var $card = renderEntity( tpl, ctx, {
						remove: function () {
							if ( ! window.confirm( t( 'removeConfirm' ) ) ) {
								return;
							}
							templates.splice( idx, 1 );
							draw();
							sync();
						}
					} );
					var nameId = nextId();
					var $name = $( '<p class="mgseo-sf-row"></p>' ).append(
						$( '<label></label>' ).attr( 'for', nameId ).text( t( 'name' ) ),
						$( '<input type="text" class="regular-text" />' ).attr( 'id', nameId ).val( tpl.name || '' ).on( 'input', function () {
							tpl.name = this.value;
							sync();
						} )
					);
					var $conds = $( '<div class="mgseo-schema-conditions"></div>' );
					drawConditions( $conds, tpl );
					var $addCond = $( '<button type="button" class="button button-small"></button>' ).text( t( 'addCondition' ) ).on( 'click', function () {
						tpl.conditions.push( { include: true, kind: 'post_type', value: '' } );
						drawConditions( $conds, tpl );
						sync();
					} );
					$card.find( '.mgseo-schema-entity-body' ).prepend( $name ).append(
						$( '<fieldset class="mgseo-sf-group"></fieldset>' ).append( $( '<legend></legend>' ).text( t( 'conditions' ) ), $conds, $( '<p></p>' ).append( $addCond ) )
					);
					$list.append( $card );
				} );
			}

			var $type = $( '<select class="mgseo-schema-template-type"></select>' ).attr( 'aria-label', t( 'type' ) );
			Object.keys( types ).forEach( function ( key ) {
				$type.append( $( '<option></option>' ).attr( 'value', key ).text( types[ key ].label ) );
			} );
			$type.append( $( '<option value="custom"></option>' ).text( t( 'custom' ) ) );
			$root.find( '.mgseo-schema-add-template' ).before( $type, ' ' ).on( 'click', function () {
				var tpl = newEntity( $type.val() );
				tpl.name = tpl.custom ? t( 'custom' ) : ( types[ tpl.type ] || {} ).label;
				tpl.conditions = [ { include: true, kind: 'post_type', value: Object.keys( cfg.postTypes || {} )[ 0 ] || 'post' } ];
				templates.push( tpl );
				draw();
				sync();
			} );
			draw();
			sync();
		} );

		/* ---------- Contact points (settings) ---------- */
		$( document ).on( 'click', '.mgseo-schema-row-add', function () {
			var $table = $( this ).closest( 'td' ).find( '.mgseo-schema-contacts tbody' );
			var $last = $table.find( 'tr' ).last();
			var $row = $last.clone();
			var index = $table.find( 'tr' ).length;
			$row.find( 'input' ).each( function () {
				this.value = '';
				this.name = this.name.replace( /\[\d+\](\[[^\]]+\])$/, '[' + index + ']$1' );
			} );
			$table.append( $row );
			$row.find( 'input' ).first().trigger( 'focus' );
		} );
		$( document ).on( 'click', '.mgseo-schema-row-remove', function () {
			var $tr = $( this ).closest( 'tr' );
			if ( $tr.siblings().length ) {
				$tr.remove();
			} else {
				$tr.find( 'input' ).val( '' );
			}
		} );
	}

	/* ====================================================================
	 * Blocks (block editor)
	 * ================================================================== */
	function initBlocks( wp ) {
		var el = wp.element.createElement;
		var be = wp.blockEditor;
		var C = wp.components;
		var RichText = be.RichText;
		var useBlockProps = be.useBlockProps || function ( p ) { return p || {}; };
		var InspectorControls = be.InspectorControls;

		function headingControls( props ) {
			var level = props.attributes.headingLevel || 3;
			return el( C.SelectControl, {
				label: t( 'headingLevel' ),
				value: String( level ),
				options: [ 2, 3, 4, 5, 6 ].map( function ( n ) { return { label: 'H' + n, value: String( n ) }; } ),
				onChange: function ( v ) { props.setAttributes( { headingLevel: parseInt( v, 10 ) } ); }
			} );
		}

		function listTools( list, idx, onChange ) {
			function move( d ) {
				var copy = list.slice();
				copy.splice( idx + d, 0, copy.splice( idx, 1 )[ 0 ] );
				onChange( copy );
			}
			return el( 'div', { className: 'mgseo-block-tools' },
				el( C.Button, { icon: 'arrow-up-alt2', label: t( 'moveUp' ), disabled: idx === 0, onClick: function () { move( -1 ); } } ),
				el( C.Button, { icon: 'arrow-down-alt2', label: t( 'moveDown' ), disabled: idx === list.length - 1, onClick: function () { move( 1 ); } } ),
				el( C.Button, { icon: 'trash', label: t( 'remove' ), isDestructive: true, onClick: function () {
					var copy = list.slice();
					copy.splice( idx, 1 );
					onChange( copy );
				} } )
			);
		}

		var common = {
			apiVersion: 3,
			category: 'text',
			supports: { html: false, className: false, customClassName: true, anchor: true },
			save: function () { return null; }
		};

		if ( ! wp.blocks.getBlockType( 'mg-seo/faq' ) ) {
			wp.blocks.registerBlockType( 'mg-seo/faq', Object.assign( {}, common, {
				title: t( 'faq', 'FAQ' ),
				icon: 'editor-help',
				keywords: [ 'faq', 'questions', 'schema' ],
				attributes: {
					questions: { type: 'array', 'default': [] },
					headingLevel: { type: 'integer', 'default': 3 },
					accordion: { type: 'boolean', 'default': false },
					anchor: { type: 'string' }
				},
				edit: function ( props ) {
					var a = props.attributes;
					var list = Array.isArray( a.questions ) ? a.questions : [];
					var set = function ( next ) { props.setAttributes( { questions: next } ); };
					var update = function ( idx, key, value ) {
						var copy = list.map( function ( q ) { return Object.assign( {}, q ); } );
						copy[ idx ][ key ] = value;
						set( copy );
					};
					var tag = 'h' + ( a.headingLevel || 3 );
					return el( 'div', useBlockProps( { className: 'faq' } ),
						el( InspectorControls, null,
							el( C.PanelBody, { title: t( 'settings' ) },
								headingControls( props ),
								el( C.ToggleControl, { label: t( 'accordion' ), checked: !! a.accordion, onChange: function ( v ) { props.setAttributes( { accordion: v } ); } } )
							)
						),
						list.map( function ( q, idx ) {
							return el( 'div', { className: 'faq-item', key: q.id || idx },
								el( RichText, { tagName: tag, className: 'faq-question', value: q.question || '', allowedFormats: [], placeholder: t( 'question' ), onChange: function ( v ) { update( idx, 'question', v ); } } ),
								el( RichText, { tagName: 'div', className: 'faq-answer', value: q.answer || '', placeholder: t( 'answer' ), onChange: function ( v ) { update( idx, 'answer', v ); } } ),
								listTools( list, idx, set )
							);
						} ),
						el( C.Button, { variant: 'secondary', onClick: function () {
							set( list.concat( [ { id: 'faq-' + uid().slice( 0, 8 ), question: '', answer: '' } ] ) );
						} }, t( 'addQuestion' ) )
					);
				}
			} ) );
		}

		function stringList( label, addLabel, list, onChange ) {
			list = Array.isArray( list ) ? list : [];
			return el( 'div', { className: 'howto-list-editor' },
				el( 'p', null, el( 'strong', null, label ) ),
				list.map( function ( value, idx ) {
					return el( 'div', { className: 'mgseo-block-row', key: idx },
						el( C.TextControl, { label: label + ' ' + ( idx + 1 ), hideLabelFromVision: true, value: value, onChange: function ( v ) {
							var copy = list.slice();
							copy[ idx ] = v;
							onChange( copy );
						} } ),
						listTools( list, idx, onChange )
					);
				} ),
				el( C.Button, { variant: 'secondary', onClick: function () { onChange( list.concat( [ '' ] ) ); } }, addLabel )
			);
		}

		if ( ! wp.blocks.getBlockType( 'mg-seo/howto' ) ) {
			wp.blocks.registerBlockType( 'mg-seo/howto', Object.assign( {}, common, {
				title: t( 'howto', 'How-to' ),
				icon: 'list-view',
				keywords: [ 'howto', 'steps', 'schema' ],
				attributes: {
					title: { type: 'string', 'default': '' },
					description: { type: 'string', 'default': '' },
					hours: { type: 'integer', 'default': 0 },
					minutes: { type: 'integer', 'default': 0 },
					supplies: { type: 'array', 'default': [] },
					tools: { type: 'array', 'default': [] },
					steps: { type: 'array', 'default': [] },
					headingLevel: { type: 'integer', 'default': 3 },
					anchor: { type: 'string' }
				},
				edit: function ( props ) {
					var a = props.attributes;
					var steps = Array.isArray( a.steps ) ? a.steps : [];
					var setSteps = function ( next ) { props.setAttributes( { steps: next } ); };
					var update = function ( idx, patch ) {
						var copy = steps.map( function ( s ) { return Object.assign( {}, s ); } );
						Object.assign( copy[ idx ], patch );
						setSteps( copy );
					};
					var tag = 'h' + ( a.headingLevel || 3 );
					var MediaUpload = be.MediaUpload;
					var MediaUploadCheck = be.MediaUploadCheck || function ( p ) { return p.children; };
					return el( 'div', useBlockProps( { className: 'howto' } ),
						el( InspectorControls, null,
							el( C.PanelBody, { title: t( 'settings' ) },
								el( C.TextControl, { label: t( 'howtoTitle' ), value: a.title || '', onChange: function ( v ) { props.setAttributes( { title: v } ); } } ),
								headingControls( props ),
								el( C.TextControl, { type: 'number', min: 0, label: t( 'totalTime' ) + ' – ' + t( 'hours' ), value: a.hours || 0, onChange: function ( v ) { props.setAttributes( { hours: Math.max( 0, parseInt( v, 10 ) || 0 ) } ); } } ),
								el( C.TextControl, { type: 'number', min: 0, max: 59, label: t( 'totalTime' ) + ' – ' + t( 'minutes' ), value: a.minutes || 0, onChange: function ( v ) { props.setAttributes( { minutes: Math.max( 0, parseInt( v, 10 ) || 0 ) } ); } } )
							)
						),
						el( RichText, { tagName: 'div', className: 'howto-description', value: a.description || '', placeholder: t( 'description' ), onChange: function ( v ) { props.setAttributes( { description: v } ); } } ),
						stringList( t( 'supplies' ), t( 'addSupply' ), a.supplies, function ( v ) { props.setAttributes( { supplies: v } ); } ),
						stringList( t( 'tools' ), t( 'addTool' ), a.tools, function ( v ) { props.setAttributes( { tools: v } ); } ),
						el( 'ol', { className: 'howto-steps' },
							steps.map( function ( step, idx ) {
								return el( 'li', { className: 'howto-step', key: idx },
									el( RichText, { tagName: tag, className: 'howto-step-title', value: step.title || '', allowedFormats: [], placeholder: t( 'stepTitle' ), onChange: function ( v ) { update( idx, { title: v } ); } } ),
									el( RichText, { tagName: 'div', className: 'howto-step-text', value: step.text || '', placeholder: t( 'stepText' ), onChange: function ( v ) { update( idx, { text: v } ); } } ),
									step.image ? el( 'img', { src: step.image, alt: step.alt || '', className: 'howto-step-image' } ) : null,
									MediaUpload ? el( MediaUploadCheck, null,
										el( MediaUpload, {
											allowedTypes: [ 'image' ],
											value: step.imageId,
											onSelect: function ( media ) { update( idx, { image: media.url, imageId: media.id, alt: media.alt || '' } ); },
											render: function ( o ) {
												return el( 'div', { className: 'mgseo-block-tools' },
													el( C.Button, { variant: 'secondary', onClick: o.open }, t( 'image' ) ),
													step.image ? el( C.Button, { variant: 'link', isDestructive: true, onClick: function () { update( idx, { image: '', imageId: 0, alt: '' } ); } }, t( 'removeImage' ) ) : null
												);
											}
										} )
									) : null,
									listTools( steps, idx, setSteps )
								);
							} )
						),
						el( C.Button, { variant: 'secondary', onClick: function () { setSteps( steps.concat( [ { title: '', text: '' } ] ) ); } }, t( 'addStep' ) )
					);
				}
			} ) );
		}
	}

	if ( window.jQuery ) {
		window.jQuery( function () {
			initAdmin( window.jQuery );
		} );
	}
	if ( window.wp && window.wp.blocks && window.wp.element && window.wp.blockEditor && window.wp.components ) {
		initBlocks( window.wp );
	}
}() );
