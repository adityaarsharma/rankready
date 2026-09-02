/**
 * RankReady — Admin UI
 * Handles: bulk summary generation, bulk author changer, toggle fields, cache flush.
 * No build step required. Vanilla JS.
 */
( function () {
	'use strict';

	var nonce   = rnrdAdmin.nonce;
	var apiBase = rnrdAdmin.apiBase;
	var i18n    = window.rnrdI18n.bind( ( window.rnrdAdmin && window.rnrdAdmin.i18n ) || {} );
	var t       = i18n.t;
	var tr      = i18n.tr;

	function rnrdFetch( path, method, body ) {
		// Defensive URL construction — works whether the site uses pretty
		// permalinks (apiBase = "/wp-json/rankready/v1") or plain permalinks
		// (apiBase = "/?rest_route=/rankready/v1"). Naive concat with "?"
		// would create two query separators on plain-permalink sites and
		// the server would return 404. Split path into [pathPart, queryPart].
		var url = apiBase;
		var pathPart  = path;
		var queryPart = '';
		var qIdx = path.indexOf( '?' );
		if ( qIdx >= 0 ) {
			pathPart  = path.slice( 0, qIdx );
			queryPart = path.slice( qIdx + 1 );
		}
		url += pathPart;
		if ( queryPart ) {
			url += ( url.indexOf( '?' ) >= 0 ? '&' : '?' ) + queryPart;
		}
		return fetch( url, {
			method:      method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   nonce,
			},
			body: body ? JSON.stringify( body ) : undefined,
		} ).then( function ( r ) { return r.json(); } );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * TOGGLE FIELDS (data-toggle-target)
	 * ═══════════════════════════════════════════════════════════════════════ */

	document.querySelectorAll( '[data-toggle-target]' ).forEach( function ( checkbox ) {
		checkbox.addEventListener( 'change', function () {
			var target = document.getElementById( checkbox.getAttribute( 'data-toggle-target' ) );
			if ( target ) {
				target.style.display = checkbox.checked ? '' : 'none';
			}
		} );
	} );

	/* ═══════════════════════════════════════════════════════════════════════
	 * BULK SUMMARY GENERATION — moved to RankReady Pro (assets/pro-admin.js).
	 * The /bulk/* REST routes ship only in the Pro add-on, so the matching
	 * handlers live there too. The Free build renders no bulk-summary card.
	 * ═══════════════════════════════════════════════════════════════════════ */

	/* ═══════════════════════════════════════════════════════════════════════
	 * BULK AUTHOR CHANGER
	 * ═══════════════════════════════════════════════════════════════════════ */

	var bacPreview   = document.getElementById( 'rnrd-bac-preview' );
	var bacExecute   = document.getElementById( 'rnrd-bac-execute' );
	var bacStop      = document.getElementById( 'rnrd-bac-stop' );
	var bacPrevResult = document.getElementById( 'rnrd-bac-preview-result' );
	var bacProgress  = document.getElementById( 'rnrd-bac-progress' );
	var bacBar       = document.getElementById( 'rnrd-bac-bar' );
	var bacStatus    = document.getElementById( 'rnrd-bac-status' );
	var bacDone      = document.getElementById( 'rnrd-bac-done' );
	var bacRunning   = false;

	function bacGetParams() {
		var types = [];
		document.querySelectorAll( '.rnrd-bac-pt:checked' ).forEach( function ( cb ) {
			types.push( cb.value );
		} );
		return {
			post_types  : types,
			to_author   : parseInt( document.getElementById( 'rnrd-bac-to' ).value ) || 0,
			from_author : parseInt( document.getElementById( 'rnrd-bac-from' ).value ) || 0,
			date_from   : document.getElementById( 'rnrd-bac-date-from' ).value,
			date_to     : document.getElementById( 'rnrd-bac-date-to' ).value,
		};
	}

	function bacValidate( params ) {
		if ( ! params.post_types.length ) {
			alert( t( 'minOnePostType', 'Select at least one post type.' ) );
			return false;
		}
		if ( ! params.to_author ) {
			alert( t( 'selectTargetAuthor', 'Select a target author (To).' ) );
			return false;
		}
		return true;
	}

	if ( bacPreview ) {
		bacPreview.addEventListener( 'click', function () {
			var params = bacGetParams();
			if ( ! bacValidate( params ) ) return;

			bacPreview.disabled    = true;
			bacPreview.textContent = t( 'checking', 'Checking…' );
			if ( bacPrevResult ) bacPrevResult.style.display = 'none';
			if ( bacDone ) bacDone.style.display = 'none';
			bacExecute.disabled = true;

			rnrdFetch( '/author/preview', 'POST', params )
				.then( function ( data ) {
					bacPreview.disabled    = false;
					bacPreview.textContent = t( 'previewCount', 'Preview Count' );

					if ( data.code ) {
						bacPrevResult.textContent  = t( 'errorPrefix', 'Error:' ) + ' ' + ( data.message || t( 'unknownError', 'Unknown error' ) );
						bacPrevResult.className    = 'rnrd-notice rnrd-notice--error';
						bacPrevResult.style.display = 'block';
						return;
					}

					bacPrevResult.textContent   = data.message;
					bacPrevResult.className     = data.count > 0 ? 'rnrd-notice rnrd-notice--info' : 'rnrd-notice rnrd-notice--warn';
					bacPrevResult.style.display = 'block';
					bacExecute.disabled         = data.count < 1;
				} )
				.catch( function () {
					bacPreview.disabled    = false;
					bacPreview.textContent = t( 'previewCount', 'Preview Count' );
					bacPrevResult.textContent  = t( 'previewRequestFailed', 'Preview request failed.' );
					bacPrevResult.className    = 'rnrd-notice rnrd-notice--error';
					bacPrevResult.style.display = 'block';
				} );
		} );

		bacExecute.addEventListener( 'click', function () {
			var params = bacGetParams();
			if ( ! bacValidate( params ) ) return;
			if ( ! confirm( t( 'confirmReassignAuthors', 'This will permanently reassign post authors. Continue?' ) ) ) return;

			bacRunning              = true;
			bacExecute.disabled     = true;
			bacExecute.textContent  = t( 'running', 'Running…' );
			bacPreview.disabled     = true;
			bacStop.style.display   = 'inline-block';
			bacProgress.style.display = 'block';
			bacBar.style.width      = '0%';
			bacStatus.textContent   = t( 'starting', 'Starting…' );
			if ( bacDone ) bacDone.style.display = 'none';

			rnrdFetch( '/author/execute', 'POST', params )
				.then( function ( data ) {
					if ( data.code ) {
						bacSetFinished( t( 'errorPrefix', 'Error:' ) + ' ' + ( ( data && data.message ) || t( 'unknownError', 'Unknown error' ) ) );
						return;
					}
					if ( data.total === 0 ) {
						bacDone.textContent   = t( 'noMatchingPosts', 'No matching posts found.' );
						bacDone.style.display = 'block';
						bacSetFinished();
						return;
					}
					bacUpdateProgress( data );
					bacProcessNext();
				} )
				.catch( function () {
					bacSetFinished( t( 'failedToStart', 'Failed to start.' ) );
				} );
		} );

		bacStop.addEventListener( 'click', function () {
			bacRunning = false;
			rnrdFetch( '/author/stop', 'POST' );
			bacStatus.textContent = t( 'stopped', 'Stopped.' );
			bacSetFinished();
		} );
	}

	function bacUpdateProgress( data ) {
		var pct = data.total > 0 ? Math.round( ( data.done / data.total ) * 100 ) : 0;
		bacBar.style.width     = pct + '%';
		bacStatus.textContent  = tr( 'postsUpdatedProgress', '%1$d / %2$d posts updated (%3$d%%)', data.done, data.total, pct );
	}

	function bacProcessNext() {
		if ( ! bacRunning ) return;
		rnrdFetch( '/author/process', 'POST' )
			.then( function ( data ) {
				if ( data.code ) {
					bacSetFinished( t( 'errorPrefix', 'Error:' ) + ' ' + ( data.message || t( 'unknownError', 'Unknown error' ) ) );
					return;
				}
				bacUpdateProgress( data );
				if ( data.running ) {
					setTimeout( bacProcessNext, 200 );
				} else {
					bacDone.textContent  = data.done === 1
						? t( 'postsReassignedOne', 'Done! 1 post reassigned.' )
						: tr( 'postsReassignedMany', 'Done! %d posts reassigned.', data.done );
					bacDone.style.display = 'block';
					bacSetFinished();
				}
			} )
			.catch( function () {
				if ( bacRunning ) {
					bacStatus.textContent = t( 'retryIn3s', 'Request failed — retrying in 3s…' );
					setTimeout( bacProcessNext, 3000 );
				}
			} );
	}

	function bacSetFinished( errorMsg ) {
		bacRunning              = false;
		bacExecute.disabled     = false;
		bacExecute.textContent  = t( 'execute', 'Execute' );
		bacStop.style.display   = 'none';
		bacPreview.disabled     = false;

		if ( errorMsg ) {
			bacPrevResult.textContent  = errorMsg;
			bacPrevResult.className    = 'rnrd-notice rnrd-notice--error';
			bacPrevResult.style.display = 'block';
		}
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * LLMS CACHE FLUSH
	 * ═══════════════════════════════════════════════════════════════════════ */

	var flushBtn    = document.getElementById( 'rnrd-flush-llms-cache' );
	var flushStatus = document.getElementById( 'rnrd-flush-status' );

	if ( flushBtn ) {
		var flushLabelDefault = flushBtn.getAttribute( 'data-label-default' ) || flushBtn.textContent || t( 'clearCache', 'Clear cache' );
		var flushLabelBusy    = flushBtn.getAttribute( 'data-label-busy' ) || t( 'clearing', 'Clearing…' );
		var flushSuccessMsg   = ( flushStatus && flushStatus.getAttribute( 'data-success-msg' ) ) || t( 'cacheCleared', 'Cache cleared.' );

		flushBtn.addEventListener( 'click', function () {
			flushBtn.disabled    = true;
			flushBtn.textContent = flushLabelBusy;
			if ( flushStatus ) {
				flushStatus.textContent = '';
				flushStatus.classList.remove( 'is-success' );
			}

			rnrdFetch( '/llms/flush-cache', 'POST' )
				.then( function () {
					flushBtn.disabled    = false;
					flushBtn.textContent = flushLabelDefault;
					if ( flushStatus ) {
						flushStatus.classList.add( 'is-success' );
						flushStatus.textContent = '✓ ' + flushSuccessMsg;
						setTimeout( function () {
							flushStatus.textContent = '';
							flushStatus.classList.remove( 'is-success' );
						}, 3000 );
					}
				} )
				.catch( function () {
					flushBtn.disabled    = false;
					flushBtn.textContent = flushLabelDefault;
				} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * BULK FAQ GENERATION — moved to RankReady Pro (assets/pro-admin.js).
	 * The /faq-bulk/* REST routes ship only in the Pro add-on, so the matching
	 * handlers live there too. The Free build renders no bulk-FAQ card.
	 * ═══════════════════════════════════════════════════════════════════════ */

	/* ═══════════════════════════════════════════════════════════════════════
	 * FAQ POSTS LIST
	 * ═══════════════════════════════════════════════════════════════════════ */

	var faqLoadBtn   = document.getElementById( 'rnrd-faq-load-posts' );
	var faqPostsList = document.getElementById( 'rnrd-faq-posts-list' );
	var faqPostsTbody = document.getElementById( 'rnrd-faq-posts-tbody' );
	var faqPostsCount = document.getElementById( 'rnrd-faq-posts-count' );

	if ( faqLoadBtn ) {
		faqLoadBtn.addEventListener( 'click', function () {
			faqLoadBtn.disabled    = true;
			faqLoadBtn.textContent = t( 'loading', 'Loading…' );

			rnrdFetch( '/faq/posts', 'GET' )
				.then( function ( data ) {
					faqLoadBtn.disabled    = false;
					faqLoadBtn.textContent = t( 'refreshListBtn', 'Refresh List' );

					if ( ! data.posts || ! data.posts.length ) {
						faqPostsCount.textContent   = t( 'noFaqPosts', 'No posts with FAQ found.' );
						faqPostsCount.style.display = 'inline';
						faqPostsList.style.display  = 'none';
						return;
					}

					faqPostsCount.textContent   = data.total === 1
						? t( 'faqPostsOne', '1 post with FAQ' )
						: tr( 'faqPostsMany', '%d posts with FAQ', data.total );
					faqPostsCount.style.display = 'inline';

					var html = '';
					data.posts.forEach( function ( post ) {
						html += '<tr>';
						html += '<td><strong>' + escHtml( post.title ) + '</strong></td>';
						html += '<td><code style="font-size:12px;">' + escHtml( post.type ) + '</code></td>';
						html += '<td>' + escHtml( post.generated || '—' ) + '</td>';
						html += '<td>';
						html += '<a href="#" onclick="rrEditFaq(' + post.id + ',\'' + escHtml( post.title ).replace( /'/g, '\\&#39;' ) + '\');return false;" style="margin-right:8px;">' + escHtml( t( 'editFaq', 'Edit FAQ' ) ) + '</a>';
						if ( post.edit_url ) {
							html += '<a href="' + post.edit_url + '" target="_blank" style="margin-right:8px;">' + escHtml( t( 'editPost', 'Edit Post' ) ) + '</a>';
						}
						if ( post.view_url ) {
							html += '<a href="' + post.view_url + '" target="_blank">' + escHtml( t( 'view', 'View' ) ) + '</a>';
						}
						html += '</td>';
						html += '</tr>';
					} );

					faqPostsTbody.innerHTML    = html;
					faqPostsList.style.display = 'block';
				} )
				.catch( function () {
					faqLoadBtn.disabled    = false;
					faqLoadBtn.textContent = t( 'loadFaqPosts', 'Load FAQ Posts' );
					faqPostsCount.textContent   = t( 'failedToLoad', 'Failed to load.' );
					faqPostsCount.style.display = 'inline';
				} );
		} );
	}

	function escHtml( str ) {
		var div       = document.createElement( 'div' );
		div.textContent = str || '';
		return div.innerHTML;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * FAQ INLINE EDIT MODAL
	 * ═══════════════════════════════════════════════════════════════════════ */

	var faqModal    = null;
	var faqEditId   = 0;
	var faqEditData = [];

	function openFaqEditor( postId, postTitle ) {
		faqEditId = postId;

		if ( ! faqModal ) {
			faqModal = document.createElement( 'div' );
			faqModal.id = 'rnrd-faq-modal';
			faqModal.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;';
			document.body.appendChild( faqModal );
		}

		faqModal.innerHTML = '<div style="background:#fff;border-radius:8px;max-width:700px;width:95%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 8px 30px rgba(0,0,0,0.2);">'
			+ '<div style="padding:16px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">'
			+ '<h3 style="margin:0;font-size:15px;">' + escHtml( tr( 'editFaqTitle', 'Edit FAQ — %s', postTitle ) ) + '</h3>'
			+ '<button id="rnrd-faq-modal-close" type="button" style="background:none;border:none;font-size:20px;cursor:pointer;color:#666;">&times;</button>'
			+ '</div>'
			+ '<div id="rnrd-faq-modal-body" style="padding:20px;overflow-y:auto;flex:1;">' + escHtml( t( 'loading', 'Loading…' ) ) + '</div>'
			+ '<div style="padding:12px 20px;border-top:1px solid #ddd;display:flex;gap:8px;justify-content:flex-end;">'
			+ '<button id="rnrd-faq-modal-save" class="button button-primary" disabled>' + escHtml( t( 'saveChanges', 'Save Changes' ) ) + '</button>'
			+ '<span id="rnrd-faq-modal-status" style="font-size:13px;line-height:30px;margin-right:auto;"></span>'
			+ '</div>'
			+ '</div>';
		faqModal.style.display = 'flex';

		document.getElementById( 'rnrd-faq-modal-close' ).addEventListener( 'click', closeFaqEditor );
		faqModal.addEventListener( 'click', function ( e ) {
			if ( e.target === faqModal ) closeFaqEditor();
		} );

		rnrdFetch( '/faq/get/' + postId, 'GET' ).then( function ( data ) {
			if ( ! data || ! data.faq || ! data.faq.length ) {
				document.getElementById( 'rnrd-faq-modal-body' ).innerHTML = '<p style="color:#999;">' + escHtml( t( 'noFaqData', 'No FAQ data found.' ) ) + '</p>';
				return;
			}
			faqEditData = data.faq;
			renderFaqEditor();
		} );
	}

	function renderFaqEditor() {
		var body = document.getElementById( 'rnrd-faq-modal-body' );
		if ( ! body ) return;

		var html = '';
		faqEditData.forEach( function ( item, i ) {
			html += '<div class="rnrd-faq-edit-item" style="margin-bottom:16px;padding:12px;border:1px solid #e0e0e0;border-radius:4px;">';
			html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">';
			html += '<strong style="font-size:12px;color:#666;">Q' + ( i + 1 ) + '</strong>';
			html += '<button type="button" class="rnrd-faq-delete-btn" data-index="' + i + '" style="background:none;border:none;color:#B42318;cursor:pointer;font-size:13px;">' + escHtml( t( 'remove', 'Remove' ) ) + '</button>';
			html += '</div>';
			html += '<input type="text" class="rnrd-faq-q-input" data-index="' + i + '" value="' + escHtml( item.question ) + '" style="width:100%;padding:6px 8px;margin-bottom:8px;border:1px solid #ddd;border-radius:3px;font-weight:600;" />';
			html += '<textarea class="rnrd-faq-a-input" data-index="' + i + '" rows="3" style="width:100%;padding:6px 8px;border:1px solid #ddd;border-radius:3px;resize:vertical;">' + escHtml( item.answer ) + '</textarea>';
			html += '</div>';
		} );
		body.innerHTML = html;

		document.getElementById( 'rnrd-faq-modal-save' ).disabled = false;

		body.querySelectorAll( '.rnrd-faq-q-input' ).forEach( function ( el ) {
			el.addEventListener( 'input', function () {
				faqEditData[ parseInt( el.dataset.index ) ].question = el.value;
			} );
		} );
		body.querySelectorAll( '.rnrd-faq-a-input' ).forEach( function ( el ) {
			el.addEventListener( 'input', function () {
				faqEditData[ parseInt( el.dataset.index ) ].answer = el.value;
			} );
		} );
		body.querySelectorAll( '.rnrd-faq-delete-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				faqEditData.splice( parseInt( btn.dataset.index ), 1 );
				renderFaqEditor();
			} );
		} );

		document.getElementById( 'rnrd-faq-modal-save' ).onclick = saveFaqEdits;
	}

	function saveFaqEdits() {
		var saveBtn = document.getElementById( 'rnrd-faq-modal-save' );
		var status  = document.getElementById( 'rnrd-faq-modal-status' );
		saveBtn.disabled    = true;
		saveBtn.textContent = t( 'saving', 'Saving…' );
		status.textContent  = '';

		rnrdFetch( '/faq/save/' + faqEditId, 'POST', { faq: faqEditData } )
			.then( function ( data ) {
				saveBtn.disabled    = false;
				saveBtn.textContent = t( 'saveChanges', 'Save Changes' );
				if ( data && data.success ) {
					status.textContent = t( 'savedExclaim', 'Saved!' );
					status.style.color = '#0F9C70';
					setTimeout( closeFaqEditor, 800 );
				} else {
					status.textContent = t( 'saveFailed', 'Save failed' );
					status.style.color = '#B42318';
				}
			} )
			.catch( function () {
				saveBtn.disabled    = false;
				saveBtn.textContent = t( 'saveChanges', 'Save Changes' );
				status.textContent  = t( 'requestFailed', 'Request failed.' );
				status.style.color  = '#B42318';
			} );
	}

	function closeFaqEditor() {
		if ( faqModal ) faqModal.style.display = 'none';
	}

	// Expose for inline onclick
	window.rrEditFaq = openFaqEditor;

	/* ═══════════════════════════════════════════════════════════════════════
	 * PER-POST TOKEN USAGE
	 * ═══════════════════════════════════════════════════════════════════════ */

	var tokensLoad  = document.getElementById( 'rnrd-tokens-load' );
	var tokensList  = document.getElementById( 'rnrd-tokens-list' );
	var tokensTbody = document.getElementById( 'rnrd-tokens-tbody' );
	var tokensCount = document.getElementById( 'rnrd-tokens-count' );

	if ( tokensLoad ) {
		tokensLoad.addEventListener( 'click', function () {
			tokensLoad.disabled    = true;
			tokensLoad.textContent = t( 'loading', 'Loading…' );
			tokensCount.style.display = 'none';

			rnrdFetch( '/token-usage', 'GET' ).then( function ( data ) {
				tokensLoad.disabled    = false;
				tokensLoad.textContent = t( 'refreshDetails', 'Refresh Details' );

				if ( ! data.posts || ! data.posts.length ) {
					tokensCount.textContent   = t( 'noTokenUsage', 'No token usage recorded yet.' );
					tokensCount.style.color   = '#999';
					tokensCount.style.display = 'inline';
					tokensList.style.display  = 'none';
					return;
				}

				var totalTokens = 0;
				var html = '';
				data.posts.forEach( function ( post ) {
					totalTokens += post.tokens;
					html += '<tr>';
					html += '<td><a href="' + escHtml( post.link ) + '" target="_blank" style="text-decoration:none;">' + escHtml( post.title ) + '</a></td>';
					html += '<td><code style="font-size:11px;">' + escHtml( post.type ) + '</code></td>';
					html += '<td style="font-weight:600;">' + post.tokens.toLocaleString() + '</td>';
					html += '<td>';
					if ( post.edit ) {
						html += '<a href="' + escHtml( post.edit ) + '" target="_blank" style="font-size:12px;">' + escHtml( t( 'edit', 'Edit' ) ) + '</a>';
					}
					html += '</td>';
					html += '</tr>';
				} );

				tokensTbody.innerHTML    = html;
				tokensList.style.display = 'block';
				tokensCount.textContent   = data.posts.length === 1
					? tr( 'tokenUsageOne', '1 post | %s total tokens', totalTokens.toLocaleString() )
					: tr( 'tokenUsageSummary', '%1$d posts | %2$s total tokens', data.posts.length, totalTokens.toLocaleString() );
				tokensCount.style.color   = '#2271b1';
				tokensCount.style.display = 'inline';
			} ).catch( function () {
				tokensLoad.disabled    = false;
				tokensLoad.textContent = t( 'loadPerPostDetails', 'Load Per-Post Details' );
			} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * ERROR LOG
	 * ═══════════════════════════════════════════════════════════════════════ */

	var errorsLoad  = document.getElementById( 'rnrd-errors-load' );
	var errorsClear = document.getElementById( 'rnrd-errors-clear' );
	var errorsList  = document.getElementById( 'rnrd-errors-list' );
	var errorsTbody = document.getElementById( 'rnrd-errors-tbody' );
	var errorsStatus = document.getElementById( 'rnrd-errors-status' );

	if ( errorsLoad ) {
		errorsLoad.addEventListener( 'click', function () {
			errorsLoad.disabled    = true;
			errorsLoad.textContent = t( 'loading', 'Loading…' );
			errorsStatus.style.display = 'none';

			rnrdFetch( '/errors', 'GET' ).then( function ( data ) {
				errorsLoad.disabled    = false;
				errorsLoad.textContent = t( 'refreshLog', 'Refresh Log' );

				if ( ! data.errors || ! data.errors.length ) {
					errorsStatus.textContent   = t( 'noErrorsLogged', 'No errors logged.' );
					errorsStatus.style.color   = '#0F9C70';
					errorsStatus.style.display = 'inline';
					errorsList.style.display   = 'none';
					return;
				}

				var html = '';
				data.errors.forEach( function ( err ) {
					html += '<tr>';
					html += '<td style="font-size:12px;white-space:nowrap;">' + escHtml( err.time_ago ) + '</td>';
					html += '<td><code style="font-size:11px;">' + escHtml( err.source ) + '</code></td>';
					html += '<td style="font-size:12px;word-break:break-word;">' + escHtml( err.message ) + '</td>';
					html += '<td>' + ( err.post_id > 0 ? '#' + err.post_id : '—' ) + '</td>';
					html += '</tr>';
				} );

				errorsTbody.innerHTML    = html;
				errorsList.style.display = 'block';
				errorsStatus.textContent   = data.errors.length === 1
					? t( 'errorsOne', '1 error' )
					: tr( 'errorsMany', '%d errors', data.errors.length );
				errorsStatus.style.color   = '#B42318';
				errorsStatus.style.display = 'inline';
			} ).catch( function () {
				errorsLoad.disabled    = false;
				errorsLoad.textContent = t( 'loadErrorLog', 'Load Error Log' );
			} );
		} );

		errorsClear.addEventListener( 'click', function () {
			rnrdFetch( '/errors/clear', 'POST' ).then( function () {
				errorsTbody.innerHTML  = '';
				errorsList.style.display   = 'none';
				errorsStatus.textContent   = t( 'logCleared', 'Log cleared.' );
				errorsStatus.style.color   = '#0F9C70';
				errorsStatus.style.display = 'inline';
			} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * VERIFY DATAFORSEO KEY
	 * ═══════════════════════════════════════════════════════════════════════ */

	var dfsVerifyBtn    = document.getElementById( 'rnrd-verify-dfs' );
	var dfsVerifyStatus = document.getElementById( 'rnrd-verify-dfs-status' );

	if ( dfsVerifyBtn ) {
		dfsVerifyBtn.addEventListener( 'click', function () {
			var loginField = document.getElementById( 'rnrd_dfs_login' );
			var pwField    = document.getElementById( 'rnrd_dfs_password' );
			var login = loginField ? loginField.value : '';
			var pw    = pwField ? pwField.value : '';

			// If password contains bullet chars, it's the masked display — use stored value.
			var payload = {};
			if ( login ) payload.login = login;
			if ( pw && pw.indexOf( '\u2022' ) === -1 ) {
				payload.password = pw;
			}

			dfsVerifyBtn.disabled    = true;
			dfsVerifyBtn.textContent = t( 'verifying', 'Verifying…' );
			dfsVerifyStatus.style.display = 'none';

			rnrdFetch( '/verify-dfs', 'POST', payload )
				.then( function ( data ) {
					dfsVerifyBtn.disabled    = false;
					dfsVerifyBtn.textContent = t( 'verifyDfs', 'Verify DataForSEO' );

					if ( data.valid ) {
						dfsVerifyStatus.textContent   = '\u2713 ' + data.message;
						dfsVerifyStatus.style.color   = '#0F9C70';
					} else {
						dfsVerifyStatus.textContent   = '\u2717 ' + data.message;
						dfsVerifyStatus.style.color   = '#B42318';
						if ( data.login ) {
							dfsVerifyStatus.textContent += ' (login: ' + data.login + ')';
						}
						if ( data.debug ) {
							dfsVerifyStatus.textContent += ' [' + data.debug + ']';
						}
					}
					dfsVerifyStatus.style.display = 'inline';
				} )
				.catch( function () {
					dfsVerifyBtn.disabled    = false;
					dfsVerifyBtn.textContent = t( 'verifyDfs', 'Verify DataForSEO' );
					dfsVerifyStatus.textContent   = '\u2717 ' + t( 'requestFailed', 'Request failed.' );
					dfsVerifyStatus.style.color   = '#B42318';
					dfsVerifyStatus.style.display = 'inline';
				} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * CRAWLER SELECT ALL
	 * ═══════════════════════════════════════════════════════════════════════ */

	/* v1.2.1 — The Allow / Block checkbox pair became one Allow / Default / Block
	 * radio per crawler, so the old "select all" checkbox and .rnrd-crawler-checkbox
	 * are gone. These buttons set every row to one state at once.
	 *
	 * Progressive enhancement only: without JS the radios still work one by one,
	 * and every row always submits exactly one value. */
	var setAllButtons = document.querySelectorAll( '.rnrd-crawler-setall' );
	if ( setAllButtons.length ) {
		setAllButtons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var state = btn.getAttribute( 'data-rnrd-state' );
				if ( ! state ) {
					return;
				}
				document.querySelectorAll( '.rnrd-crawler-state' ).forEach( function ( radio ) {
					if ( radio.value === state ) {
						radio.checked = true;
					}
				} );
			} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * START OVER — BULK REGENERATE (clear + regenerate all)
	 * ═══════════════════════════════════════════════════════════════════════ */

	var soStart   = document.getElementById( 'rnrd-startover-btn' );
	var soResume  = document.getElementById( 'rnrd-startover-resume' );
	var soStop    = document.getElementById( 'rnrd-startover-stop' );
	var soProg    = document.getElementById( 'rnrd-startover-progress' );
	var soBar     = document.getElementById( 'rnrd-startover-bar' );
	var soStat    = document.getElementById( 'rnrd-startover-status' );
	var soRunning = false;

	if ( soStart ) {
		function soBegin( isResume ) {
			var payload = isResume ? { resume: true } : {};

			if ( ! isResume ) {
				var types = [];
				document.querySelectorAll( '.rnrd-startover-type:checked' ).forEach( function ( cb ) {
					types.push( cb.value );
				} );
				if ( ! types.length ) {
					alert( t( 'minOnePostType', 'Select at least one post type.' ) );
					return;
				}
				payload.post_types = types;
			}

			soRunning           = true;
			soStart.disabled    = true;
			if ( soResume ) soResume.disabled = true;
			soStart.textContent = t( 'running', 'Running…' );
			soStop.style.display  = 'inline-block';
			soProg.style.display  = 'block';
			if ( ! isResume ) {
				soBar.style.width = '0%';
			}
			soStat.textContent    = isResume ? t( 'resuming', 'Resuming…' ) : t( 'starting', 'Starting…' );
			soStat.style.display  = 'block';
			soStat.style.color    = '';

			rnrdFetch( '/startover-bulk/start', 'POST', payload ).then( function ( data ) {
				if ( data.code ) {
					soStat.textContent = t( 'errorPrefix', 'Error:' ) + ' ' + ( data.message || t( 'unknownError', 'Unknown error' ) );
					soFinish();
					return;
				}
				soUpdate( data );
				if ( data.total === 0 ) {
					soStat.textContent = t( 'noPublishedPosts', 'No published posts found.' );
					soFinish();
				} else {
					soNext();
				}
			} ).catch( function () {
				soStat.textContent = t( 'requestFailed', 'Request failed.' );
				soFinish();
			} );
		}

		function soNext() {
			if ( ! soRunning ) return;
			rnrdFetch( '/startover-bulk/process', 'POST' ).then( function ( data ) {
				if ( data.code ) {
					soStat.textContent = t( 'errorPrefix', 'Error:' ) + ' ' + ( data.message || t( 'unknownError', 'Unknown error' ) );
					soFinish();
					return;
				}
				soUpdate( data );

				// Append log entries.
				if ( data.log && data.log.length ) {
					var log = document.getElementById( 'rnrd-startover-log' );
					if ( ! log ) {
						log = document.createElement( 'div' );
						log.id = 'rnrd-startover-log';
						log.style.cssText = 'margin-top:12px;max-height:300px;overflow-y:auto;font-size:13px;border:1px solid #ddd;border-radius:4px;padding:8px;background:#fafafa;';
						soProg.parentNode.insertBefore( log, soProg.nextSibling );
					}
					data.log.forEach( function ( entry ) {
						var p = document.createElement( 'p' );
						p.style.margin = '2px 0';
						var color = entry.summary === 'generated' ? '#0F9C70' : '#dba617';
						p.innerHTML = '<a href="' + entry.edit_link + '" target="_blank">' + entry.title + '</a> '
							+ '<span style="color:' + color + '">Summary: ' + entry.summary + '</span>'
							+ ' | <span>FAQ: ' + entry.faq + '</span>';
						log.appendChild( p );
						log.scrollTop = log.scrollHeight;
					} );
				}

				if ( data.done >= data.total ) {
					soStat.textContent = tr( 'bulkRegenDone', 'Done! %1$d/%2$d posts regenerated.', data.done, data.total );
					soStat.style.color = '#0F9C70';
					soFinish();
				} else {
					setTimeout( soNext, 500 );
				}
			} ).catch( function () {
				soStat.textContent = t( 'retryIn5s', 'Request failed — retrying in 5s…' );
				if ( soRunning ) setTimeout( soNext, 5000 );
			} );
		}

		function soUpdate( data ) {
			var pct = data.total > 0 ? Math.round( ( data.done / data.total ) * 100 ) : 0;
			soBar.style.width  = pct + '%';
			soStat.textContent = tr( 'bulkProgress', '%1$d / %2$d (%3$d%%)', data.done, data.total, pct );
		}

		function soFinish() {
			soRunning           = false;
			soStart.disabled    = false;
			soStart.textContent = t( 'startOverBulk', 'Start Over — Bulk Regenerate' );
			soStop.style.display = 'none';
			if ( soResume ) soResume.disabled = false;
		}

		soStart.addEventListener( 'click', function () { soBegin( false ); } );
		if ( soResume ) {
			soResume.addEventListener( 'click', function () { soBegin( true ); } );
		}

		soStop.addEventListener( 'click', function () {
			soRunning = false;
			rnrdFetch( '/startover-bulk/stop', 'POST' ).then( function ( data ) {
				var msg = tr( 'stoppedAtProgress', 'Stopped at %1$d / %2$d.', data.done, data.total );
				if ( data.queue_remaining > 0 ) {
					msg += ' ' + tr( 'queueRemainingResume', '%d remaining — click Resume to continue.', data.queue_remaining );
				}
				soStat.textContent = msg;
			} );
			soFinish();
		} );
	}

	/* Old "Health Check" handler removed in rc.15 — replaced by the live
	 * 22-probe Diagnostics handler below. The rnrd-health-check DOM element
	 * was removed in rc.5; the JS handler became orphan code. */

	/* ═══════════════════════════════════════════════════════════════════════
	 * DIAGNOSTICS (v1.2.0-rc.5)
	 * ─────────────────────────────────────────────────────────────────────
	 * Live endpoint probes + conflict detection + 1-click copy report.
	 * Endpoint: GET /rankready/v1/diagnostics?include_api=0|1
	 *           GET /rankready/v1/diagnostics/report?include_api=0|1
	 * ═══════════════════════════════════════════════════════════════════════ */

	var diagRunBtn       = document.getElementById( 'rnrd-diag-run' );
	var diagIncludeApi   = document.getElementById( 'rnrd-diag-include-api' );
	var diagStatus       = document.getElementById( 'rnrd-diag-status' );
	var diagSummary      = document.getElementById( 'rnrd-diag-summary' );
	var diagResults      = document.getElementById( 'rnrd-diag-results' );
	var diagTbody        = document.getElementById( 'rnrd-diag-tbody' );
	var diagCopyRow      = document.getElementById( 'rnrd-diag-copy-row' );
	var diagCopyBtn      = document.getElementById( 'rnrd-diag-copy' );
	var diagCopyStatus   = document.getElementById( 'rnrd-diag-copy-status' );
	var diagReportPreview = document.getElementById( 'rnrd-diag-report-preview' );

	if ( diagRunBtn ) {
		diagRunBtn.addEventListener( 'click', function () {
			var includeApi = diagIncludeApi && diagIncludeApi.checked ? 1 : 0;

			diagRunBtn.disabled    = true;
			diagRunBtn.textContent = t( 'probingEndpoints', 'Probing endpoints…' );
			diagStatus.style.display = 'none';
			diagSummary.style.display = 'none';
			diagResults.style.display = 'none';
			diagCopyRow.style.display = 'none';

			rnrdFetch( '/diagnostics?include_api=' + includeApi, 'GET' ).then( function ( data ) {
				diagRunBtn.disabled    = false;
				diagRunBtn.textContent = t( 'runDiagnostics', 'Run Diagnostics' );

				if ( ! data.checks || ! data.checks.length ) {
					diagStatus.textContent   = t( 'noResults', 'No results.' );
					diagStatus.style.color   = '#999';
					diagStatus.style.display = 'inline';
					return;
				}

				var totals = data.totals || { pass: 0, warn: 0, fail: 0, info: 0 };

				// Summary chips
				diagSummary.innerHTML =
					'<span style="display:inline-block;padding:4px 12px;background:#d1ecdf;color:#0a6c39;border-radius:12px;font-weight:600;margin-right:8px;">✓ ' + totals.pass + ' pass</span>' +
					( totals.warn ? '<span style="display:inline-block;padding:4px 12px;background:#fcf4d6;color:#7a5d00;border-radius:12px;font-weight:600;margin-right:8px;">⚠ ' + totals.warn + ' warn</span>' : '' ) +
					( totals.fail ? '<span style="display:inline-block;padding:4px 12px;background:#f9d7d8;color:#8a1f1f;border-radius:12px;font-weight:600;margin-right:8px;">✗ ' + totals.fail + ' fail</span>' : '' ) +
					( totals.info ? '<span style="display:inline-block;padding:4px 12px;background:#e5f1f9;color:#0b4b75;border-radius:12px;font-weight:600;margin-right:8px;">ℹ ' + totals.info + ' info</span>' : '' );
				diagSummary.style.display = 'block';

				// Results table
				var rows = '';
				data.checks.forEach( function ( c ) {
					var icon, rowStyle = '';
					if ( c.status === 'pass' ) {
						icon = '<span style="color:#0F9C70;font-size:18px;">✓</span>';
					} else if ( c.status === 'warn' ) {
						icon = '<span style="color:#dba617;font-size:18px;">⚠</span>';
						rowStyle = 'background:#fffbe6;';
					} else if ( c.status === 'fail' ) {
						icon = '<span style="color:#B42318;font-size:18px;">✗</span>';
						rowStyle = 'background:#fef0f0;';
					} else {
						icon = '<span style="color:#646970;font-size:18px;">ℹ</span>';
					}

					rows += '<tr style="' + rowStyle + '">';
					rows += '<td style="text-align:center;vertical-align:top;padding-top:10px;">' + icon + '</td>';
					rows += '<td style="font-weight:600;vertical-align:top;padding-top:10px;">' + escHtml( c.label ) + '</td>';
					rows += '<td>';
					rows += '<div>' + escHtml( c.detail ) + '</div>';
					if ( c.fix ) {
						rows += '<div style="margin-top:4px;font-size:12px;color:#5d6770;"><strong>' + escHtml( t( 'fixLabel', 'Fix:' ) ) + '</strong> ' + escHtml( c.fix ) + '</div>';
					}
					if ( c.meta && c.meta.url ) {
						rows += '<div style="margin-top:4px;font-size:11px;color:#8c8f94;font-family:Menlo,Consolas,monospace;">' + escHtml( c.meta.url ) + '</div>';
					}
					rows += '</td></tr>';
				} );

				diagTbody.innerHTML       = rows;
				diagResults.style.display = 'block';
				diagCopyRow.style.display = 'block';

				var summaryColor = totals.fail > 0 ? '#B42318' : ( totals.warn > 0 ? '#dba617' : '#0F9C70' );
				diagStatus.textContent   = t( 'diagnosticsCompleted', 'Completed.' );
				diagStatus.style.color   = summaryColor;
				diagStatus.style.display = 'inline';
			} ).catch( function () {
				diagRunBtn.disabled    = false;
				diagRunBtn.textContent = t( 'runDiagnostics', 'Run Diagnostics' );
				diagStatus.textContent   = t( 'requestFailed', 'Request failed.' );
				diagStatus.style.color   = '#B42318';
				diagStatus.style.display = 'inline';
			} );
		} );
	}

	if ( diagCopyBtn ) {
		diagCopyBtn.addEventListener( 'click', function () {
			var includeApi = diagIncludeApi && diagIncludeApi.checked ? 1 : 0;
			diagCopyBtn.disabled = true;
			diagCopyStatus.style.display = 'none';

			rnrdFetch( '/diagnostics/report?include_api=' + includeApi, 'GET' ).then( function ( data ) {
				diagCopyBtn.disabled = false;
				var report = data.report || '';
				if ( diagReportPreview ) {
					diagReportPreview.value = report;
				}

				// Copy to clipboard. Mint-700 for success (DESIGN.md §1).
				// Friendly message — no byte counts (engineery jargon).
				function showOk() {
					diagCopyStatus.textContent   = '✓ ' + t( 'copiedToClipboard', 'Copied to clipboard' );
					diagCopyStatus.style.color   = '#0F9C70'; // mint-700
					diagCopyStatus.style.display = 'inline';
					// Auto-dismiss after 4s — copy feedback shouldn't linger.
					setTimeout( function () {
						diagCopyStatus.style.display = 'none';
						diagCopyStatus.textContent = '';
					}, 4000 );
				}

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( report ).then( showOk ).catch( fallbackCopy );
				} else {
					fallbackCopy();
				}

				function fallbackCopy() {
					if ( diagReportPreview ) {
						diagReportPreview.select();
						try {
							document.execCommand( 'copy' );
							showOk();
						} catch ( e ) {
							diagCopyStatus.textContent   = t( 'copyFailedManual', 'Copy failed — select text manually from preview below.' );
							diagCopyStatus.style.color   = '#B42318'; // error
							diagCopyStatus.style.display = 'inline';
						}
					}
				}
			} ).catch( function () {
				diagCopyBtn.disabled = false;
				diagCopyStatus.textContent   = t( 'reportGenerationFailed', 'Report generation failed. Please try again.' );
				diagCopyStatus.style.color   = '#B42318'; // error
				diagCopyStatus.style.display = 'inline';
			} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * CONTENT FRESHNESS ALERTS
	 * ═══════════════════════════════════════════════════════════════════════ */

	var freshBtn     = document.getElementById( 'rnrd-freshness-scan' );
	var freshDays    = document.getElementById( 'rnrd-freshness-days' );
	var freshStatus  = document.getElementById( 'rnrd-freshness-status' );
	var freshSummary = document.getElementById( 'rnrd-freshness-summary' );
	var freshResults = document.getElementById( 'rnrd-freshness-results' );
	var freshTbody   = document.getElementById( 'rnrd-freshness-tbody' );

	if ( freshBtn ) {
		freshBtn.addEventListener( 'click', function () {
			freshBtn.disabled    = true;
			freshBtn.textContent = t( 'scanning', 'Scanning…' );
			// v1.1.5 — class-driven visibility (no inline style flicker)
			freshStatus.className      = 'rnrd-fw-status';
			freshSummary.style.display = 'none';
			freshResults.style.display = 'none';

			var days = freshDays ? freshDays.value : 90;

			wp.apiFetch( { path: '/rankready/v1/freshness?days=' + days } )
				.then( function ( data ) {
					freshBtn.disabled    = false;
					freshBtn.textContent = t( 'scanContentFreshness', 'Scan Content Freshness' );

					var s = data.summary;
					var freshPct = s.fresh_pct;

					freshSummary.innerHTML =
						'<div class="rnrd-kpi-row">' +
						'<div class="rnrd-kpi" data-intent="citation">' +
						'<div class="rnrd-kpi__label">' + escHtml( t( 'freshnessKpiLabel', 'Content fresh' ) ) + '</div>' +
						'<div class="rnrd-kpi__period">' + escHtml( t( 'freshnessKpiPeriod', 'Share of catalog' ) ) + '</div>' +
						'<div class="rnrd-kpi__value">' + freshPct + '%</div>' +
						'<div class="rnrd-kpi__foot">' + escHtml( tr( 'freshnessKpiFoot', 'last modified within %d days', s.threshold_days ) ) + '</div>' +
						'</div>' +
						'<div class="rnrd-kpi">' +
						'<div class="rnrd-kpi__label">' + escHtml( t( 'stalePostsLabel', 'Stale posts' ) ) + '</div>' +
						'<div class="rnrd-kpi__period">' + escHtml( tr( 'stalePostsPeriod', 'Over %d days old', s.threshold_days ) ) + '</div>' +
						'<div class="rnrd-kpi__value">' + s.total_stale + '</div>' +
						'<div class="rnrd-kpi__foot">' + escHtml( t( 'stalePostsFoot', 'needs a refresh for AI citations' ) ) + '</div>' +
						'</div>' +
						'<div class="rnrd-kpi">' +
						'<div class="rnrd-kpi__label">' + escHtml( t( 'totalPublishedLabel', 'Total published' ) ) + '</div>' +
						'<div class="rnrd-kpi__period">' + escHtml( t( 'totalPublishedPeriod', 'All post types' ) ) + '</div>' +
						'<div class="rnrd-kpi__value">' + s.total_published + '</div>' +
						'<div class="rnrd-kpi__foot">' + escHtml( t( 'totalPublishedFoot', 'indexed for freshness scan' ) ) + '</div>' +
						'</div>' +
						'</div>';
					freshSummary.style.display = 'block';

					if ( data.stale.length === 0 ) {
						freshStatus.textContent = t( 'allContentFresh', 'All content is fresh.' );
						freshStatus.className   = 'rnrd-fw-status is-visible is-ok';
						return;
					}

					var html = '';
					data.stale.forEach( function ( p ) {
						var urgIcon  = p.urgency === 'critical' ? '\u26d4' : ( p.urgency === 'high' ? '\u26a0\ufe0f' : '\u23f3' );
						var urgColor = p.urgency === 'critical' ? '#B42318' : ( p.urgency === 'high' ? '#dba617' : '#646970' );
						var modDate  = p.modified.substring( 0, 10 );

						html += '<tr>';
						html += '<td style="text-align:center;color:' + urgColor + ';font-size:16px;">' + urgIcon + '</td>';
						html += '<td><strong>' + escHtml( p.title ) + '</strong></td>';
						html += '<td>' + escHtml( p.type ) + '</td>';
						html += '<td>' + modDate + '</td>';
						html += '<td style="color:' + urgColor + ';font-weight:600;">' + p.days_ago + 'd</td>';
						html += '<td>' + ( p.has_summary ? '\u2705' : '\u274c' ) + '</td>';
						html += '<td>' + ( p.has_faq ? '\u2705' : '\u274c' ) + '</td>';
						html += '<td><a href="' + escHtml( p.edit_url ) + '" target="_blank" class="button button-small">' + escHtml( t( 'edit', 'Edit' ) ) + '</a></td>';
						html += '</tr>';
					} );

					freshTbody.innerHTML       = html;
					freshResults.style.display = 'block';

					freshStatus.textContent = tr( 'stalePostsFound', '%d stale posts found (showing top 50)', data.stale.length );
					freshStatus.className   = 'rnrd-fw-status is-visible';
				} ).catch( function () {
					freshBtn.disabled    = false;
					freshBtn.textContent = t( 'scanContentFreshness', 'Scan Content Freshness' );
					freshStatus.textContent = t( 'requestFailed', 'Request failed.' );
					freshStatus.className   = 'rnrd-fw-status is-visible is-err';
				} );
		} );
	}

	// ── Progressive disclosure: toggle reveals child settings ────────────────
	//
	// Pattern: a master checkbox marked with `data-rnrd-toggle-master`
	// controls visibility of every element marked with the matching
	// `data-rnrd-toggle-target="<id>"`. Two attributes only — no class
	// hardcoding, works inside any tab.
	//
	// Usage in PHP:
	//   <input type="checkbox" data-rnrd-toggle-master="headless">
	//   <div data-rnrd-toggle-target="headless"> ... settings ... </div>
	//
	function bindToggleDisclosure() {
		var masters = document.querySelectorAll( '[data-rnrd-toggle-master]' );
		if ( ! masters.length ) {
			return;
		}
		masters.forEach( function ( master ) {
			var key      = master.getAttribute( 'data-rnrd-toggle-master' );
			var targets  = document.querySelectorAll( '[data-rnrd-toggle-target="' + key + '"]' );
			if ( ! targets.length ) {
				return;
			}
			var sync = function () {
				var on = master.checked;
				targets.forEach( function ( t ) {
					t.style.display = on ? '' : 'none';
				} );
			};
			master.addEventListener( 'change', sync );
			sync();
		} );
	}
	bindToggleDisclosure();

	// ── Verify Key — provider-aware ──────────────────────────────────────────
	//
	// Each provider card has its own [data-rnrd-verify-provider] button. Pulls
	// the matching key field's value (or the saved key if masked) and pings
	// the REST verify endpoint with the chosen provider.
	//
	function rebuildModelSelect( select, models, selectedValue ) {
		if ( ! select ) { return; }
		var keep = selectedValue || select.value;
		select.innerHTML = '';
		Object.keys( models ).forEach( function ( id ) {
			var opt = document.createElement( 'option' );
			opt.value = id;
			opt.textContent = models[ id ];
			if ( id === keep ) {
				opt.selected = true;
			}
			select.appendChild( opt );
		} );
		if ( keep && ! models[ keep ] ) {
			var deprecated = document.createElement( 'option' );
			deprecated.value = keep;
			deprecated.textContent = keep;
			deprecated.selected = true;
			select.appendChild( deprecated );
		}
	}

	function refreshModelsForProvider( provider, key, statusEl, btn ) {
		var select = document.querySelector( '[data-rnrd-model-for="' + provider + '"]' );
		var label  = btn ? ( btn.textContent || t( 'refreshList', 'Refresh list' ) ) : t( 'refreshList', 'Refresh list' );

		if ( btn ) {
			btn.disabled    = true;
			btn.textContent = t( 'refreshing', 'Refreshing…' );
		}
		if ( statusEl ) {
			statusEl.style.display = 'inline';
			statusEl.style.color   = '#646970';
			statusEl.textContent   = t( 'refreshing', 'Refreshing…' );
		}

		return rnrdFetch( '/models/refresh', 'POST', { provider: provider, key: key || '' } )
			.then( function ( data ) {
				if ( btn ) {
					btn.disabled    = false;
					btn.textContent = label;
				}
				if ( data && data.models ) {
					rebuildModelSelect( select, data.models, select ? select.value : '' );
				}
				if ( statusEl ) {
					if ( data && data.ok ) {
						statusEl.style.color = '#0F9C70';
						statusEl.textContent = '✓ ' + ( data.count === 1
							? t( 'modelsUpdatedOne', '1 model updated' )
							: tr( 'modelsUpdatedMany', '%d models updated', data.count ) );
					} else {
						statusEl.style.color = '#B42318';
						statusEl.textContent = '✗ ' + ( ( data && data.message ) ? data.message : t( 'couldNotRefreshModels', 'Could not refresh models.' ) );
					}
				}
				return data;
			} )
			.catch( function () {
				if ( btn ) {
					btn.disabled    = false;
					btn.textContent = label;
				}
				if ( statusEl ) {
					statusEl.style.color = '#B42318';
					statusEl.textContent = '✗ ' + t( 'requestFailed', 'Request failed.' );
				}
			} );
	}

	function bindVerifyButtons() {
		var buttons = document.querySelectorAll( '[data-rnrd-verify-provider]' );
		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var provider = btn.getAttribute( 'data-rnrd-verify-provider' );
				var keyInput = document.querySelector( '[data-rnrd-key-for="' + provider + '"]' );
				var status   = document.querySelector( '[data-rnrd-verify-status="' + provider + '"]' );
				if ( ! keyInput || ! status ) { return; }

				var key = keyInput.value;
				var modelSelect = document.querySelector( '[data-rnrd-model-for="' + provider + '"]' );
				var model = modelSelect ? modelSelect.value : '';
				var label = btn.textContent || t( 'verifyKey', 'Verify Key' );
				status.style.display = 'inline';
				status.style.color   = '#646970';
				status.textContent   = t( 'verifying', 'Verifying…' );
				btn.disabled = true;

				rnrdFetch( '/verify-key', 'POST', { key: key, provider: provider, model: model } )
					.then( function ( data ) {
						btn.disabled       = false;
						btn.textContent    = label;
						status.style.color = data.valid ? '#0F9C70' : '#B42318';
						status.textContent = data.valid ? '✓ ' + data.message : '✗ ' + data.message;
						if ( data.valid ) {
							var modelsStatus = document.querySelector( '[data-rnrd-models-status="' + provider + '"]' );
							refreshModelsForProvider( provider, key, modelsStatus, null );
						}
					} )
					.catch( function () {
						btn.disabled       = false;
						btn.textContent    = label;
						status.style.color = '#B42318';
						status.textContent = '✗ ' + t( 'requestFailed', 'Request failed.' );
					} );
			} );
		} );
	}

	function bindRefreshModels() {
		var buttons = document.querySelectorAll( '[data-rnrd-refresh-models]' );
		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var provider = btn.getAttribute( 'data-rnrd-refresh-models' );
				var keyInput = document.querySelector( '[data-rnrd-key-for="' + provider + '"]' );
				var status   = document.querySelector( '[data-rnrd-models-status="' + provider + '"]' );
				var key      = keyInput ? keyInput.value : '';
				refreshModelsForProvider( provider, key, status, btn );
			} );
		} );
	}
	bindVerifyButtons();
	bindRefreshModels();

	/* ─────────────────────────────────────────────────────────────────────────
	 * AJAX form submit — no page reload on Save (rc.16).
	 *
	 * Captures every <form action="options.php"> inside .rnrd-wrap, posts the
	 * form data with fetch(), and updates the submit button to show:
	 *   idle → "Saving…" (disabled + spinner) → "Saved ✓" → idle (after 2s)
	 *
	 * WP options.php returns a 302 redirect to ?settings-updated=true on
	 * success. With redirect:'follow' fetch resolves to a 200 final response,
	 * so response.ok is the success check.
	 *
	 * No JS = standard form POST still works (we only preventDefault inside
	 * the handler). No new REST endpoints required.
	 * ───────────────────────────────────────────────────────────────────────── */
	function initAjaxForms() {
		var forms = document.querySelectorAll( '.rnrd-wrap form[action*="options.php"]' );
		Array.prototype.forEach.call( forms, function ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				// e.submitter is the actual button that triggered submit —
				// critical when the form has multiple <input type="submit">
				// (per-card Save buttons). Fallback to the first submit if
				// the browser doesn't expose .submitter (very old engines).
				var btn = e.submitter || form.querySelector( 'input[type="submit"], button[type="submit"]' );
				if ( ! btn ) return; // No submit element — let it fall through.

				e.preventDefault();

				var originalText = btn.tagName === 'INPUT' ? btn.value : btn.textContent;

				function setBtn( label, stateClass ) {
					if ( btn.tagName === 'INPUT' ) { btn.value = label; }
					else { btn.textContent = label; }
					btn.classList.remove( 'rnrd-saving', 'rnrd-saved', 'rnrd-save-error' );
					if ( stateClass ) { btn.classList.add( stateClass ); }
				}

				function restoreBtn() {
					btn.disabled = false;
					setBtn( originalText, '' );
				}

				btn.disabled = true;
				setBtn( t( 'saving', 'Saving…' ), 'rnrd-saving' );

				var formData = new FormData( form );

				// getAttribute('action') reads the HTML attribute directly. Without
				// this, any <input name="action"> inside the form shadows
				// form.action and we end up POSTing to [object HTMLInputElement].
				var actionUrl = form.getAttribute( 'action' );

				fetch( actionUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin',
					redirect: 'follow',
				} )
					.then( function ( response ) {
						if ( ! response.ok ) {
							throw new Error( 'HTTP ' + response.status );
						}
						// v1.2 — verify the save actually persisted (options.php adds settings-updated=true only on success; a stale nonce / admin_init fatal returns 200 without it). Fixes 'Saved but reverts to disabled' (Keith, 1/5).
							if ( response.url && response.url.indexOf( 'settings-updated=true' ) === -1 ) { throw new Error( 'not-saved' ); }
							setBtn( t( 'saved', 'Saved ✓' ), 'rnrd-saved' );
						setTimeout( restoreBtn, 2000 );
					} )
					.catch( function ( err ) {
						setBtn( ( err && err.message === 'not-saved' ) ? t( 'notSavedReload', 'Not saved — reload page' ) : t( 'saveFailed', 'Save failed' ), 'rnrd-save-error' );
						setTimeout( restoreBtn, 4500 );
					} );
			} );
		} );
	}
	initAjaxForms();

	/* ─────────────────────────────────────────────────────────────────────────
	 * Provider visibility toggler — relocated from inline <script> in rc.16
	 * (WP.org Rule #3: no inline scripts in PHP). When the user picks a new
	 * provider radio, hide the inner cards for the other 3 providers.
	 * ───────────────────────────────────────────────────────────────────────── */
	function initProviderToggler() {
		var radios = document.querySelectorAll( '[data-rnrd-provider-radio]' );
		var cards  = document.querySelectorAll( '[data-rnrd-provider]' );
		if ( ! radios.length || ! cards.length ) { return; }
		function sync() {
			var sel = document.querySelector( '[data-rnrd-provider-radio]:checked' );
			if ( ! sel ) { return; }
			Array.prototype.forEach.call( cards, function ( c ) {
				c.style.display = ( c.dataset.rnrdProvider === sel.value ) ? '' : 'none';
			} );
		}
		Array.prototype.forEach.call( radios, function ( r ) {
			r.addEventListener( 'change', sync );
		} );
	}
	initProviderToggler();

	/* ─────────────────────────────────────────────────────────────────────────
	 * Star rating widget — Dashboard aside (rc.16).
	 * Click 5 stars -> WP.org rating page. Click 1-4 -> mailto support with
	 * subject "Feedback for RankReady". Hover lights up cumulative stars.
	 * ───────────────────────────────────────────────────────────────────────── */
	function initRateWidget() {
		var widget = document.querySelector( '.rnrd-rate-widget' );
		if ( ! widget ) { return; }
		var stars   = widget.querySelectorAll( '.rnrd-rate-star' );
		var wpUrl   = widget.getAttribute( 'data-rate-wp' );
		var mailto  = widget.getAttribute( 'data-rate-mailto' );

		function highlight( count ) {
			Array.prototype.forEach.call( stars, function ( s, i ) {
				if ( i < count ) { s.classList.add( 'is-hovered' ); }
				else { s.classList.remove( 'is-hovered' ); }
			} );
		}

		Array.prototype.forEach.call( stars, function ( star ) {
			star.addEventListener( 'mouseenter', function () { highlight( parseInt( star.dataset.value, 10 ) ); } );
			star.addEventListener( 'focus', function () { highlight( parseInt( star.dataset.value, 10 ) ); } );
			star.addEventListener( 'click', function () {
				var v = parseInt( star.dataset.value, 10 );
				if ( v >= 5 ) {
					window.open( wpUrl, '_blank', 'noopener' );
				} else {
					var body = '%0A%0A%0ARating%3A ' + v + '%2F5%0A%0AWhat could we do better%3F';
					window.location.href = mailto + '&body=' + body;
				}
			} );
		} );

		widget.addEventListener( 'mouseleave', function () { highlight( 0 ); } );
	}
	initRateWidget();

	// ─────────────────────────────────────────────────────────────────────
	// FREE-100 — Content Freshness widget (relocated from inline <script> in
	// class-rnrd-freshness.php for WP.org compliance + clean CSS theming).
	// Hooks every .rnrd-freshness-widget on the page, manages tab switching,
	// list rendering, and bulk dateModified refresh via REST.
	// ─────────────────────────────────────────────────────────────────────
	function initFreshnessWidget() {
		var roots = document.querySelectorAll( '.rnrd-freshness-widget' );
		if ( ! roots.length ) return;

		roots.forEach( function ( root ) {
			var api      = root.getAttribute( 'data-rnrd-api' );
			var nonce    = root.getAttribute( 'data-rnrd-nonce' );
			var listEl   = root.querySelector( '.rnrd-fw-list' );
			var statusEl = root.querySelector( '.rnrd-fw-status' );
			var btnRefr  = root.querySelector( '.rnrd-fw-refresh' );
			var selAll   = root.querySelector( '.rnrd-fw-select-all' );
			var tabs     = root.querySelectorAll( '.rnrd-fw-tab' );
			var current  = 'stale';

			function updateButton() {
				var anyChecked = !! listEl.querySelector( 'input[type=checkbox]:checked' );
				btnRefr.disabled = ! anyChecked;
			}

			function setTab( bucket ) {
				current = bucket;
				tabs.forEach( function ( t ) {
					t.classList.toggle( 'is-active', t.getAttribute( 'data-bucket' ) === bucket );
				} );
				load();
			}

			function renderEmpty( msg ) {
				listEl.textContent = '';
				var p = document.createElement( 'p' );
				p.className = 'rnrd-fw-empty';
				p.textContent = msg;
				listEl.appendChild( p );
			}

			function renderError( msg ) {
				listEl.textContent = '';
				var p = document.createElement( 'p' );
				p.className = 'rnrd-fw-error';
				p.textContent = msg;
				listEl.appendChild( p );
			}

			function load() {
				renderEmpty( t( 'loading', 'Loading…' ) );
				selAll.checked = false;
				btnRefr.disabled = true;
				fetch( api + '/freshness/list?bucket=' + encodeURIComponent( current ), {
					headers: { 'X-WP-Nonce': nonce, Accept: 'application/json' },
					credentials: 'same-origin'
				} ).then( function ( r ) { return r.json(); } ).then( function ( data ) {
					if ( ! data || ! data.posts || ! data.posts.length ) {
						var msg = current === 'stale'
							? t( 'freshnessStaleEmpty', 'No stale posts. Every published post has been touched within the last 60 days.' )
							: ( current === 'going_stale'
								? t( 'freshnessGoingStaleEmpty', 'Nothing in the 30–60 day window. Plenty of time before any post goes stale.' )
								: t( 'freshnessFreshEmpty', 'Newly published or refreshed content shows up here.' ) );
						renderEmpty( msg );
						return;
					}
					// DOM-built to avoid stored XSS from post titles (audit beta.3 #1).
					listEl.textContent = '';
					var ul = document.createElement( 'ul' );
					ul.className = 'rnrd-fw-list-ul';
					data.posts.forEach( function ( p ) {
						var li      = document.createElement( 'li' );
						var label   = document.createElement( 'label' );
						var cb      = document.createElement( 'input' );
						cb.type     = 'checkbox';
						cb.value    = String( parseInt( p.id, 10 ) || 0 );
						var titleEl = document.createElement( 'span' );
						titleEl.className = 'rnrd-fw-title';
						var link    = document.createElement( 'a' );
						var editUrl = String( p.edit || '#' );
						if ( ! /^https?:\/\//.test( editUrl ) && editUrl !== '#' ) { editUrl = '#'; }
						link.href = editUrl;
						link.target = '_blank';
						link.rel = 'noopener noreferrer';
						link.textContent = String( p.title || t( 'noTitle', '(no title)' ) );
						titleEl.appendChild( link );
						label.appendChild( cb );
						label.appendChild( titleEl );
						var ageEl = document.createElement( 'span' );
						ageEl.className = 'rnrd-fw-age';
						ageEl.textContent = ( parseInt( p.age, 10 ) || 0 ) + 'd';
						li.appendChild( label );
						li.appendChild( ageEl );
						ul.appendChild( li );
					} );
					listEl.appendChild( ul );
				} ).catch( function () { renderError( t( 'failedToLoad', 'Failed to load.' ) ); } );
			}

			tabs.forEach( function ( t ) {
				t.addEventListener( 'click', function () { setTab( t.getAttribute( 'data-bucket' ) ); } );
			} );
			listEl.addEventListener( 'change', updateButton );
			selAll.addEventListener( 'change', function () {
				listEl.querySelectorAll( 'input[type=checkbox]' ).forEach( function ( cb ) { cb.checked = selAll.checked; } );
				updateButton();
			} );
			btnRefr.addEventListener( 'click', function () {
				var ids = Array.prototype.slice.call(
					listEl.querySelectorAll( 'input[type=checkbox]:checked' )
				).map( function ( cb ) { return parseInt( cb.value, 10 ); } );
				if ( ! ids.length ) return;
				btnRefr.disabled = true;
				statusEl.textContent = tr( 'freshnessRefreshing', 'Refreshing %d post(s)…', ids.length );
				fetch( api + '/freshness/refresh', {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json', Accept: 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify( { post_ids: ids } )
				} ).then( function ( r ) { return r.json(); } ).then( function ( data ) {
					statusEl.textContent = tr( 'freshnessRefreshed', 'Refreshed %d post(s). Reloading…', data.refreshed || 0 );
					setTimeout( load, 600 );
				} ).catch( function () {
					statusEl.textContent = t( 'refreshFailed', 'Refresh failed.' );
					btnRefr.disabled = false;
				} );
			} );

			load();
		} );
	}
	initFreshnessWidget();

	/* ═══════════════════════════════════════════════════════════════════════
	 * MIN-ONE POST TYPE CHECKBOX GROUPS
	 * Unchecking the last box is blocked immediately so Settings API
	 * sanitizers cannot silently re-check "Post" after save.
	 * ═══════════════════════════════════════════════════════════════════════ */

	function bindMinOneCheckboxGroups() {
		var fallback = t( 'minOnePostType', 'Select at least one post type.' );

		document.querySelectorAll( '[data-rnrd-min-one-checkboxes]' ).forEach( function ( group ) {
			var boxes = group.querySelectorAll( 'input[type="checkbox"][value]' );
			if ( ! boxes.length ) {
				return;
			}
			var hint = group.parentNode
				? group.parentNode.querySelector( '.rnrd-min-one-hint' )
				: null;
			if ( ! hint ) {
				hint = document.createElement( 'p' );
				hint.className = 'description rnrd-min-one-hint';
				hint.hidden = true;
				group.insertAdjacentElement( 'afterend', hint );
			}
			var msg = group.getAttribute( 'data-rnrd-min-one-hint' ) || fallback;

			boxes.forEach( function ( cb ) {
				cb.addEventListener( 'change', function () {
					var checked = group.querySelectorAll( 'input[type="checkbox"][value]:checked' );
					if ( checked.length ) {
						hint.hidden = true;
						hint.textContent = '';
						return;
					}
					cb.checked = true;
					hint.textContent = msg;
					hint.hidden = false;
				} );
			} );
		} );
	}
	bindMinOneCheckboxGroups();

} )();
