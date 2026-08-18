/**
 * RankReady — post-edit meta box Generate control.
 *
 * POST /rankready/v1/regenerate/{id} with the same 60s cooldown as the
 * Gutenberg block and Elementor widget. Lean file: post-edit screens must
 * not load assets/admin.js.
 */
( function () {
	'use strict';

	var cfg = window.rnrdMetabox || {};
	var i18n = cfg.i18n || {};
	var COOLDOWN = parseInt( cfg.cooldown, 10 ) || 60;

	function t( key, fallback ) {
		return i18n[ key ] || fallback;
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

	function init( wrap ) {
		var btn = wrap.querySelector( '[data-rnrd-gen-summary]' );
		var errEl = wrap.querySelector( '.rnrd-mb__error' );
		var details = wrap.querySelector( '.rnrd-mb__summary-details' );
		var list = wrap.querySelector( '.rnrd-mb__preview ul' );
		var generatedEl = wrap.querySelector( '.rnrd-mb__generated' );
		if ( ! btn ) {
			return;
		}

		var postId = parseInt( wrap.getAttribute( 'data-post-id' ), 10 ) || 0;
		var hasKey = wrap.getAttribute( 'data-has-key' ) === '1';
		var typeEnabled = wrap.getAttribute( 'data-type-enabled' ) === '1';
		var hasSummary = wrap.getAttribute( 'data-has-summary' ) === '1';
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
				btn.textContent = hasSummary
					? t( 'regenerating', 'Regenerating…' )
					: t( 'generating', 'Generating…' );
			} else if ( left > 0 ) {
				btn.textContent = t( 'wait', 'Wait %ds' ).replace( '%d', String( left ) );
			} else {
				btn.textContent = hasSummary
					? t( 'regenerate', 'Regenerate' )
					: t( 'generate', 'Generate' );
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

		function fillSummary( bullets ) {
			if ( ! list || ! details ) {
				return;
			}
			list.textContent = '';
			bullets.forEach( function ( bullet ) {
				var li = document.createElement( 'li' );
				li.textContent = bullet;
				list.appendChild( li );
			} );
			var show = bullets.length > 0;
			details.hidden = ! show;
			if ( show ) {
				details.open = true;
			}
			hasSummary = show;
			wrap.setAttribute( 'data-has-summary', show ? '1' : '0' );
		}

		function markGenerated() {
			generated = Math.floor( Date.now() / 1000 );
			wrap.setAttribute( 'data-generated', String( generated ) );
			if ( generatedEl ) {
				generatedEl.textContent = t( 'generatedJust', 'Summary generated just now' );
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

			fetch( String( cfg.restUrl || '' ) + 'regenerate/' + postId, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce || '',
				},
			} )
				.then( function ( r ) {
					return r.json().then( function ( body ) {
						return { ok: r.ok, status: r.status, body: body || {} };
					} );
				} )
				.then( function ( res ) {
					loading = false;
					if ( ! res.ok ) {
						var msg = restError( res.body, t( 'failed', 'Generation failed.' ) );
						if ( res.body && res.body.code === 'rnrd_rate_limited' ) {
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
					fillSummary( decodeBullets( res.body.summary ) );
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
		var wraps = document.querySelectorAll( '[data-rnrd-mb-summary]' );
		Array.prototype.forEach.call( wraps, init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
