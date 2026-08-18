/**
 * RankReady — post-edit meta box Generate controls (Summary + FAQ).
 *
 * Summary: POST /rankready/v1/regenerate/{id}
 * FAQ:     POST /rankready/v1/faq/generate/{id}
 * Same 60s cooldown as the Gutenberg blocks / Elementor widgets.
 * Lean file: post-edit screens must not load assets/admin.js.
 */
( function () {
	'use strict';

	var cfg = window.rnrdMetabox || {};
	var i18n = cfg.i18n || {};
	var COOLDOWN = parseInt( cfg.cooldown, 10 ) || 60;

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
	}

	function remaining( generatedUnix ) {
		generatedUnix = parseInt( generatedUnix, 10 ) || 0;
		if ( generatedUnix < 1 ) {
			return 0;
		}
		var left = COOLDOWN - ( Math.floor( Date.now() / 1000 ) - generatedUnix );
		return left > 0 ? left : 0;
	}

	function restError( body, fallback ) {
		if ( body && body.message ) {
			return String( body.message );
		}
		return fallback || t( 'failed', 'Generation failed.' );
	}

	function decodeBullets( raw ) {
		if ( ! raw ) {
			return [];
		}
		try {
			var parsed = JSON.parse( raw );
			if ( parsed && Array.isArray( parsed.bullets ) && parsed.bullets.length ) {
				return parsed.bullets.filter( function ( b ) {
					return String( b || '' ).trim() !== '';
				} );
			}
		} catch ( e ) { /* not JSON */ }
		var text = String( raw ).trim();
		return text ? [ text ] : [];
	}

	function normalizeFaq( rows ) {
		if ( ! Array.isArray( rows ) ) {
			return [];
		}
		return rows.filter( function ( row ) {
			return row && String( row.question || '' ).trim() !== '';
		} );
	}

	function kindConfig( kind ) {
		if ( kind === 'faq' ) {
			return {
				path: 'faq/generate/',
				body: { keyword: '', count: 0 },
				idle: 'generateFaq',
				idleFb: 'Generate FAQ',
				done: 'regenerateFaq',
				doneFb: 'Regenerate FAQ',
				busy: 'generatingFaq',
				busyFb: 'Generating FAQ…',
				busyDone: 'regeneratingFaq',
				busyDoneFb: 'Regenerating FAQ…',
				just: 'generatedFaqJust',
				justFb: 'FAQ generated just now',
				parse: function ( body ) {
					return normalizeFaq( body && body.faq );
				},
				fill: fillFaq,
			};
		}
		return {
			path: 'regenerate/',
			body: null,
			idle: 'generate',
			idleFb: 'Generate Summary',
			done: 'regenerate',
			doneFb: 'Regenerate Summary',
			busy: 'generating',
			busyFb: 'Generating Summary…',
			busyDone: 'regenerating',
			busyDoneFb: 'Regenerating Summary…',
			just: 'generatedJust',
			justFb: 'Summary generated just now',
			parse: function ( body ) {
				return decodeBullets( body && body.summary );
			},
			fill: fillSummary,
		};
	}

	function fillSummary( list, items ) {
		list.textContent = '';
		items.forEach( function ( bullet ) {
			var li = document.createElement( 'li' );
			li.textContent = bullet;
			list.appendChild( li );
		} );
	}

	function fillFaq( list, items ) {
		list.textContent = '';
		items.forEach( function ( row ) {
			var li = document.createElement( 'li' );
			var q = document.createElement( 'strong' );
			q.textContent = String( row.question || '' );
			li.appendChild( q );
			var answer = String( row.answer || '' ).replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
			if ( answer ) {
				var a = document.createElement( 'span' );
				a.className = 'rnrd-mb__faq-a';
				a.textContent = answer;
				li.appendChild( a );
			}
			list.appendChild( li );
		} );
	}

	function init( wrap ) {
		var kind = wrap.getAttribute( 'data-rnrd-mb-gen' ) || 'summary';
		var spec = kindConfig( kind );
		var btn = wrap.querySelector( '[data-rnrd-gen]' );
		var errEl = wrap.querySelector( '.rnrd-mb__error' );
		var details = wrap.querySelector( '.rnrd-mb__reveal' );
		var list = wrap.querySelector( '[data-rnrd-mb-list]' );
		var generatedEl = wrap.querySelector( '.rnrd-mb__generated' );
		if ( ! btn ) {
			return;
		}

		var postId = parseInt( wrap.getAttribute( 'data-post-id' ), 10 ) || 0;
		var hasKey = wrap.getAttribute( 'data-has-key' ) === '1';
		var typeEnabled = wrap.getAttribute( 'data-type-enabled' ) === '1';
		var hasContent = wrap.getAttribute( 'data-has-content' ) === '1';
		var generated = parseInt( wrap.getAttribute( 'data-generated' ), 10 ) || 0;
		var loading = false;
		var cooldownTimer = null;

		function canClick() {
			return postId > 0 && hasKey && typeEnabled && ! loading && remaining( generated ) < 1;
		}

		function setError( msg ) {
			if ( ! errEl ) {
				return;
			}
			if ( msg ) {
				errEl.textContent = msg;
				errEl.hidden = false;
			} else {
				errEl.textContent = '';
				errEl.hidden = true;
			}
		}

		function paintButton() {
			var left = remaining( generated );
			if ( loading ) {
				btn.textContent = hasContent
					? t( spec.busyDone, spec.busyDoneFb )
					: t( spec.busy, spec.busyFb );
			} else if ( left > 0 ) {
				btn.textContent = t( 'wait', 'Wait %ds' ).replace( '%d', String( left ) );
			} else {
				btn.textContent = hasContent
					? t( spec.done, spec.doneFb )
					: t( spec.idle, spec.idleFb );
			}
			btn.disabled = ! canClick();
			btn.setAttribute( 'aria-busy', loading ? 'true' : 'false' );
		}

		function tickCooldown() {
			if ( cooldownTimer ) {
				clearInterval( cooldownTimer );
				cooldownTimer = null;
			}
			if ( remaining( generated ) < 1 ) {
				paintButton();
				return;
			}
			paintButton();
			cooldownTimer = setInterval( function () {
				if ( remaining( generated ) < 1 ) {
					clearInterval( cooldownTimer );
					cooldownTimer = null;
				}
				paintButton();
			}, 1000 );
		}

		function applyItems( items ) {
			if ( ! list || ! details ) {
				return;
			}
			spec.fill( list, items );
			var show = items.length > 0;
			details.hidden = ! show;
			if ( show ) {
				details.open = true;
			}
			hasContent = show;
			wrap.setAttribute( 'data-has-content', show ? '1' : '0' );
		}

		function markGenerated() {
			generated = Math.floor( Date.now() / 1000 );
			wrap.setAttribute( 'data-generated', String( generated ) );
			if ( generatedEl ) {
				generatedEl.textContent = t( spec.just, spec.justFb );
				generatedEl.hidden = false;
			}
		}

		function generate() {
			if ( ! canClick() ) {
				if ( postId < 1 ) {
					setError( t( 'saveFirst', 'Save the post first, then generate.' ) );
				}
				return;
			}
			loading = true;
			setError( '' );
			paintButton();

			var opts = {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce || '',
				},
			};
			if ( spec.body ) {
				opts.body = JSON.stringify( spec.body );
			}

			fetch( String( cfg.restUrl || '' ) + spec.path + postId, opts )
				.then( function ( r ) {
					return r.json().then( function ( body ) {
						return { ok: r.ok, status: r.status, body: body || {} };
					} );
				} )
				.then( function ( res ) {
					loading = false;
					var body = res.body;
					var failed = ! res.ok || body.success === false;
					if ( failed ) {
						var msg = restError( body, t( 'failed', 'Generation failed.' ) );
						if ( body.code === 'rnrd_rate_limited' ) {
							var secs = parseInt( String( msg ).match( /\d+/ ), 10 );
							if ( secs ) {
								generated = Math.floor( Date.now() / 1000 ) - ( COOLDOWN - secs );
								wrap.setAttribute( 'data-generated', String( generated ) );
							}
						}
						setError( msg );
						tickCooldown();
						return;
					}
					applyItems( spec.parse( body ) );
					markGenerated();
					tickCooldown();
				} )
				.catch( function () {
					loading = false;
					setError( t( 'failed', 'Generation failed.' ) );
					paintButton();
				} );
		}

		btn.addEventListener( 'click', generate );
		tickCooldown();
	}

	function boot() {
		var wraps = document.querySelectorAll( '[data-rnrd-mb-gen]' );
		Array.prototype.forEach.call( wraps, init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
