/**
 * RankReady — post-edit meta box Generate controls (Summary + FAQ).
 *
 * Summary: POST /rankready/v1/regenerate/{id}  DELETE /summary/{id}
 * FAQ:     POST /rankready/v1/faq/generate/{id} DELETE /faq/{id}
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
				deletePath: 'faq/',
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
				justFb: 'FAQ generated just now.',
				deleted: 'deletedFaq',
				deletedFb: 'FAQ removed.',
				confirm: 'confirmFaq',
				confirmFb: 'Remove the generated FAQ for this post?',
				deleteLabel: 'deleteFaq',
				deleteLabelFb: 'Delete FAQ',
				parse: function ( body ) {
					return normalizeFaq( body && body.faq );
				},
				fill: fillFaq,
			};
		}
		return {
			path: 'regenerate/',
			deletePath: 'summary/',
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
			justFb: 'Summary generated just now.',
			deleted: 'deletedSummary',
			deletedFb: 'Summary removed.',
			confirm: 'confirmSummary',
			confirmFb: 'Remove the generated summary for this post?',
			deleteLabel: 'deleteSummary',
			deleteLabelFb: 'Delete summary',
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
		var statusTextEl = generatedEl ? generatedEl.querySelector( '[data-rnrd-mb-status-text]' ) : null;
		var deleteWrap = generatedEl ? generatedEl.querySelector( '[data-rnrd-mb-delete-wrap]' ) : null;
		var delBtn = deleteWrap ? deleteWrap.querySelector( '[data-rnrd-delete]' ) : null;
		if ( ! btn ) {
			return;
		}

		var postId = parseInt( wrap.getAttribute( 'data-post-id' ), 10 ) || 0;
		var hasKey = wrap.getAttribute( 'data-has-key' ) === '1';
		var typeEnabled = wrap.getAttribute( 'data-type-enabled' ) === '1';
		var hasContent = wrap.getAttribute( 'data-has-content' ) === '1';
		var generated = parseInt( wrap.getAttribute( 'data-generated' ), 10 ) || 0;
		var loading = false;
		var deleting = false;
		var cooldownTimer = null;
		var statusMode = 'idle'; // idle | just | cooldown

		function canGenerate() {
			return postId > 0 && hasKey && typeEnabled && ! loading && ! deleting && remaining( generated ) < 1;
		}

		function canDelete() {
			return postId > 0 && hasContent && ! loading && ! deleting;
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

		function actionLabel() {
			return hasContent ? t( spec.done, spec.doneFb ) : t( spec.idle, spec.idleFb );
		}

		function regenInShort( left ) {
			return t( 'regenInShort', 'Regenerate in %ds.' ).replace( '%d', String( left ) );
		}

		function ensureDeleteControl() {
			if ( ! generatedEl || ! hasContent ) {
				return;
			}
			if ( ! deleteWrap ) {
				deleteWrap = document.createElement( 'span' );
				deleteWrap.setAttribute( 'data-rnrd-mb-delete-wrap', '' );
				deleteWrap.appendChild( document.createTextNode( ' ' ) );
				generatedEl.appendChild( deleteWrap );
			}
			if ( ! delBtn ) {
				delBtn = document.createElement( 'button' );
				delBtn.type = 'button';
				delBtn.className = 'rnrd-mb__delete';
				delBtn.setAttribute( 'data-rnrd-delete', '' );
				delBtn.textContent = t( spec.deleteLabel, spec.deleteLabelFb );
				delBtn.addEventListener( 'click', deleteContent );
				deleteWrap.appendChild( delBtn );
			}
		}

		function setStatusText( text ) {
			if ( statusTextEl ) {
				statusTextEl.textContent = text;
			}
		}

		function setDeleteVisible( show ) {
			if ( show ) {
				ensureDeleteControl();
			}
			if ( deleteWrap ) {
				deleteWrap.hidden = ! show;
			}
			if ( delBtn ) {
				delBtn.disabled = ! canDelete();
			}
		}

		function statusPrefix() {
			if ( ! statusTextEl ) {
				return '';
			}
			return String( statusTextEl.textContent || '' )
				.replace( /\s*Regenerate in \d+s\.?\s*$/i, '' )
				.trim();
		}

		function paintButton() {
			if ( loading ) {
				btn.textContent = hasContent
					? t( spec.busyDone, spec.busyDoneFb )
					: t( spec.busy, spec.busyFb );
			} else {
				btn.textContent = actionLabel();
			}
			btn.disabled = ! canGenerate();
			btn.setAttribute( 'aria-busy', loading ? 'true' : 'false' );

			var left = remaining( generated );
			if ( ! loading && left > 0 ) {
				btn.setAttribute(
					'title',
					t( 'regenIn', 'Regenerate available in %ds.' ).replace( '%d', String( left ) )
				);
			} else {
				btn.removeAttribute( 'title' );
			}
		}

		function paintStatus() {
			if ( ! generatedEl ) {
				return;
			}
			var left = remaining( generated );

			if ( deleting ) {
				generatedEl.hidden = false;
				setDeleteVisible( false );
				setStatusText( t( 'deleting', 'Deleting…' ) );
				return;
			}

			if ( statusMode === 'deleted' ) {
				generatedEl.hidden = false;
				setDeleteVisible( false );
				setStatusText( t( spec.deleted, spec.deletedFb ) );
				return;
			}

			if ( ! hasContent && generated <= 0 ) {
				generatedEl.hidden = true;
				return;
			}

			generatedEl.hidden = false;
			setDeleteVisible( hasContent );

			if ( left > 0 ) {
				if ( statusMode === 'just' ) {
					setStatusText( t( spec.just, spec.justFb ) + ' ' + regenInShort( left ) );
					return;
				}
				var prefix = statusPrefix();
				if ( prefix ) {
					setStatusText( prefix + ( prefix.slice( -1 ) === '.' ? ' ' : '. ' ) + regenInShort( left ) );
				} else {
					setStatusText( regenInShort( left ) );
				}
				return;
			}

			if ( statusMode === 'just' ) {
				setStatusText( t( spec.just, spec.justFb ) );
				return;
			}

			// idle: keep server-rendered age text in statusTextEl
		}

		function refreshUi() {
			paintButton();
			paintStatus();
		}

		function tickCooldown() {
			if ( cooldownTimer ) {
				clearInterval( cooldownTimer );
				cooldownTimer = null;
			}
			refreshUi();
			if ( remaining( generated ) < 1 ) {
				if ( statusMode === 'just' ) {
					statusMode = 'idle';
				}
				refreshUi();
				return;
			}
			cooldownTimer = setInterval( function () {
				if ( remaining( generated ) < 1 ) {
					clearInterval( cooldownTimer );
					cooldownTimer = null;
					if ( statusMode === 'just' ) {
						statusMode = 'idle';
					}
				}
				refreshUi();
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
			statusMode = 'just';
		}

		function clearContent() {
			applyItems( [] );
			generated = 0;
			wrap.setAttribute( 'data-generated', '0' );
			statusMode = 'deleted';
		}

		function apiRequest( method, pathSuffix, body ) {
			var opts = {
				method: method,
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': cfg.nonce || '',
				},
			};
			if ( body !== null && body !== undefined ) {
				opts.headers[ 'Content-Type' ] = 'application/json';
				opts.body = JSON.stringify( body );
			}
			return fetch( String( cfg.restUrl || '' ) + pathSuffix + postId, opts )
				.then( function ( r ) {
					return r.json().then( function ( payload ) {
						return { ok: r.ok, status: r.status, body: payload || {} };
					} );
				} );
		}

		function generate() {
			if ( ! canGenerate() ) {
				if ( postId < 1 ) {
					setError( t( 'saveFirst', 'Save the post first, then generate.' ) );
				}
				return;
			}
			loading = true;
			setError( '' );
			refreshUi();

			apiRequest( 'POST', spec.path, spec.body )
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
								statusMode = 'cooldown';
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
					refreshUi();
				} );
		}

		function deleteContent() {
			if ( ! canDelete() ) {
				return;
			}
			if ( ! window.confirm( t( spec.confirm, spec.confirmFb ) ) ) {
				return;
			}
			deleting = true;
			setError( '' );
			refreshUi();

			apiRequest( 'DELETE', spec.deletePath, null )
				.then( function ( res ) {
					deleting = false;
					if ( ! res.ok || res.body.success === false ) {
						setError( restError( res.body, t( 'deleteFailed', 'Could not delete. Try again.' ) ) );
						refreshUi();
						return;
					}
					clearContent();
					tickCooldown();
				} )
				.catch( function () {
					deleting = false;
					setError( t( 'deleteFailed', 'Could not delete. Try again.' ) );
					refreshUi();
				} );
		}

		btn.addEventListener( 'click', generate );
		if ( delBtn ) {
			delBtn.addEventListener( 'click', deleteContent );
		}
		refreshUi();
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
