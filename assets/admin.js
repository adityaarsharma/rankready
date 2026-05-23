/**
 * RankReady — Admin UI
 * Handles: bulk summary generation, bulk author changer, toggle fields, cache flush.
 * No build step required. Vanilla JS.
 */
( function () {
	'use strict';

	var nonce   = rrAdmin.nonce;
	var apiBase = rrAdmin.apiBase;

	function rrFetch( path, method, body ) {
		return fetch( apiBase + path, {
			method:  method || 'GET',
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
	 * BULK SUMMARY GENERATION
	 * ═══════════════════════════════════════════════════════════════════════ */

	var bulkStart = document.getElementById( 'rr-bulk-start' );
	var bulkStop  = document.getElementById( 'rr-bulk-stop' );
	var bulkProg  = document.getElementById( 'rr-bulk-progress' );
	var bulkBar   = document.getElementById( 'rr-bulk-bar' );
	var bulkStat  = document.getElementById( 'rr-bulk-status' );
	var bulkRunning = false;

	if ( bulkStart ) {
		var bulkResume = document.getElementById( 'rr-bulk-resume' );

		function bulkBegin( isResume ) {
			var payload = isResume ? { resume: true } : {};

			if ( ! isResume ) {
				var types = [];
				document.querySelectorAll( '.rr-bulk-type:checked' ).forEach( function ( cb ) {
					types.push( cb.value );
				} );
				if ( ! types.length ) {
					alert( 'Select at least one post type.' );
					return;
				}
				payload.post_types = types;
			}

			bulkRunning           = true;
			bulkStart.disabled    = true;
			if ( bulkResume ) bulkResume.disabled = true;
			bulkStart.textContent = 'Running...';
			bulkStop.style.display  = 'inline-block';
			bulkProg.style.display  = 'block';
			if ( ! isResume ) {
				bulkBar.style.width = '0%';
			}
			bulkStat.textContent    = isResume ? 'Resuming...' : 'Starting...';

			rrFetch( '/bulk/start', 'POST', payload ).then( function ( data ) {
				if ( data.code ) {
					bulkStat.textContent = 'Error: ' + ( data.message || 'Unknown error' );
					bulkFinish();
					return;
				}
				bulkUpdate( data );
				if ( data.total === 0 ) {
					bulkStat.textContent = 'No published posts found.';
					bulkFinish();
				} else {
					bulkNext();
				}
			} ).catch( function () {
				bulkStat.textContent = 'Failed to start.';
				bulkFinish();
			} );
		}

		bulkStart.addEventListener( 'click', function () { bulkBegin( false ); } );
		if ( bulkResume ) {
			bulkResume.addEventListener( 'click', function () { bulkBegin( true ); } );
		}

		bulkStop.addEventListener( 'click', function () {
			bulkRunning = false;
			rrFetch( '/bulk/stop', 'POST' ).then( function ( data ) {
				var msg = 'Stopped at ' + data.done + ' / ' + data.total + '.';
				if ( data.queue_remaining > 0 ) {
					msg += ' ' + data.queue_remaining + ' remaining — click Resume to continue.';
				}
				bulkStat.textContent = msg;
			} );
			bulkFinish();
		} );
	}

	function bulkUpdate( data ) {
		var pct = data.total > 0 ? Math.round( ( data.done / data.total ) * 100 ) : 0;
		bulkBar.style.width = pct + '%';
		var msg = data.done + ' / ' + data.total + ' posts (' + pct + '%)';
		if ( data.skipped > 0 ) msg += ' | ' + data.skipped + ' unchanged (skipped)';
		if ( data.failed > 0 ) msg += ' | ' + data.failed + ' failed';
		bulkStat.textContent = msg;

		// Show per-post activity log.
		if ( data.processed && data.processed.length ) {
			var log = document.getElementById( 'rr-bulk-log' );
			if ( ! log ) {
				log = document.createElement( 'div' );
				log.id = 'rr-bulk-log';
				log.style.cssText = 'margin-top:8px;max-height:200px;overflow-y:auto;font-size:12px;border:1px solid #e0e0e0;border-radius:4px;padding:6px 10px;background:#fafafa;';
				bulkProg.appendChild( log );
			}
			data.processed.forEach( function ( p ) {
				var line = document.createElement( 'div' );
				line.style.cssText = 'padding:2px 0;border-bottom:1px solid #f0f0f0;';
				var statusColor = p.status === 'generated' ? '#00a32a' : ( p.status === 'failed' ? '#d63638' : '#999' );
				var tokensStr = p.tokens > 0 ? ' &middot; ' + p.tokens.toLocaleString() + ' tokens' : '';
				line.innerHTML = '<span style="color:' + statusColor + ';font-weight:600;">' + escHtml( p.status ) + '</span> '
					+ '<a href="' + escHtml( p.link ) + '" target="_blank" style="text-decoration:none;">' + escHtml( p.title ) + '</a>'
					+ tokensStr;
				log.appendChild( line );
				log.scrollTop = log.scrollHeight;
			} );
		}
	}

	function bulkNext() {
		if ( ! bulkRunning ) return;
		rrFetch( '/bulk/process', 'POST' ).then( function ( data ) {
			if ( data.code ) {
				bulkStat.textContent = 'Error: ' + ( data.message || 'Unknown' );
				bulkFinish();
				return;
			}
			bulkUpdate( data );
			if ( data.running ) {
				setTimeout( bulkNext, 300 );
			} else {
				var msg = 'Done! ' + data.done + ' processed';
				if ( data.skipped > 0 ) msg += ', ' + data.skipped + ' skipped (unchanged)';
				if ( data.failed > 0 ) msg += ', ' + data.failed + ' failed';
				msg += '.';
				bulkStat.textContent = msg;
				bulkFinish();
			}
		} ).catch( function () {
			bulkStat.textContent = 'Request failed — retrying in 5s...';
			if ( bulkRunning ) setTimeout( bulkNext, 5000 );
		} );
	}

	function bulkFinish() {
		bulkRunning            = false;
		bulkStart.disabled     = false;
		bulkStart.textContent  = 'Start Bulk Generate';
		bulkStop.style.display = 'none';
		var bulkResume = document.getElementById( 'rr-bulk-resume' );
		if ( bulkResume ) bulkResume.disabled = false;
		// Keep the log visible so user can review results.
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * BULK AUTHOR CHANGER
	 * ═══════════════════════════════════════════════════════════════════════ */

	var bacPreview   = document.getElementById( 'rr-bac-preview' );
	var bacExecute   = document.getElementById( 'rr-bac-execute' );
	var bacStop      = document.getElementById( 'rr-bac-stop' );
	var bacPrevResult = document.getElementById( 'rr-bac-preview-result' );
	var bacProgress  = document.getElementById( 'rr-bac-progress' );
	var bacBar       = document.getElementById( 'rr-bac-bar' );
	var bacStatus    = document.getElementById( 'rr-bac-status' );
	var bacDone      = document.getElementById( 'rr-bac-done' );
	var bacRunning   = false;

	function bacGetParams() {
		var types = [];
		document.querySelectorAll( '.rr-bac-pt:checked' ).forEach( function ( cb ) {
			types.push( cb.value );
		} );
		return {
			post_types  : types,
			to_author   : parseInt( document.getElementById( 'rr-bac-to' ).value ) || 0,
			from_author : parseInt( document.getElementById( 'rr-bac-from' ).value ) || 0,
			date_from   : document.getElementById( 'rr-bac-date-from' ).value,
			date_to     : document.getElementById( 'rr-bac-date-to' ).value,
		};
	}

	function bacValidate( params ) {
		if ( ! params.post_types.length ) {
			alert( 'Select at least one post type.' );
			return false;
		}
		if ( ! params.to_author ) {
			alert( 'Select a target author (To).' );
			return false;
		}
		return true;
	}

	if ( bacPreview ) {
		bacPreview.addEventListener( 'click', function () {
			var params = bacGetParams();
			if ( ! bacValidate( params ) ) return;

			bacPreview.disabled    = true;
			bacPreview.textContent = 'Checking...';
			if ( bacPrevResult ) bacPrevResult.style.display = 'none';
			if ( bacDone ) bacDone.style.display = 'none';
			bacExecute.disabled = true;

			rrFetch( '/author/preview', 'POST', params )
				.then( function ( data ) {
					bacPreview.disabled    = false;
					bacPreview.textContent = 'Preview Count';

					if ( data.code ) {
						bacPrevResult.textContent  = 'Error: ' + ( data.message || 'Unknown error' );
						bacPrevResult.className    = 'rr-notice rr-notice--error';
						bacPrevResult.style.display = 'block';
						return;
					}

					bacPrevResult.textContent   = data.message;
					bacPrevResult.className     = data.count > 0 ? 'rr-notice rr-notice--info' : 'rr-notice rr-notice--warn';
					bacPrevResult.style.display = 'block';
					bacExecute.disabled         = data.count < 1;
				} )
				.catch( function () {
					bacPreview.disabled    = false;
					bacPreview.textContent = 'Preview Count';
					bacPrevResult.textContent  = 'Preview request failed.';
					bacPrevResult.className    = 'rr-notice rr-notice--error';
					bacPrevResult.style.display = 'block';
				} );
		} );

		bacExecute.addEventListener( 'click', function () {
			var params = bacGetParams();
			if ( ! bacValidate( params ) ) return;
			if ( ! confirm( 'This will permanently reassign post authors. Continue?' ) ) return;

			bacRunning              = true;
			bacExecute.disabled     = true;
			bacExecute.textContent  = 'Running...';
			bacPreview.disabled     = true;
			bacStop.style.display   = 'inline-block';
			bacProgress.style.display = 'block';
			bacBar.style.width      = '0%';
			bacStatus.textContent   = 'Starting...';
			if ( bacDone ) bacDone.style.display = 'none';

			rrFetch( '/author/execute', 'POST', params )
				.then( function ( data ) {
					if ( data.code ) {
						bacSetFinished( 'Error: ' + ( data.message || 'Unknown error' ) );
						return;
					}
					if ( data.total === 0 ) {
						bacDone.textContent   = 'No matching posts found.';
						bacDone.style.display = 'block';
						bacSetFinished();
						return;
					}
					bacUpdateProgress( data );
					bacProcessNext();
				} )
				.catch( function () {
					bacSetFinished( 'Failed to start.' );
				} );
		} );

		bacStop.addEventListener( 'click', function () {
			bacRunning = false;
			rrFetch( '/author/stop', 'POST' );
			bacStatus.textContent = 'Stopped.';
			bacSetFinished();
		} );
	}

	function bacUpdateProgress( data ) {
		var pct = data.total > 0 ? Math.round( ( data.done / data.total ) * 100 ) : 0;
		bacBar.style.width     = pct + '%';
		bacStatus.textContent  = data.done + ' / ' + data.total + ' posts updated (' + pct + '%)';
	}

	function bacProcessNext() {
		if ( ! bacRunning ) return;
		rrFetch( '/author/process', 'POST' )
			.then( function ( data ) {
				if ( data.code ) {
					bacSetFinished( 'Error: ' + ( data.message || 'Unknown' ) );
					return;
				}
				bacUpdateProgress( data );
				if ( data.running ) {
					setTimeout( bacProcessNext, 200 );
				} else {
					bacDone.textContent  = 'Done! ' + data.done + ' post' + ( data.done !== 1 ? 's' : '' ) + ' reassigned.';
					bacDone.style.display = 'block';
					bacSetFinished();
				}
			} )
			.catch( function () {
				if ( bacRunning ) {
					bacStatus.textContent = 'Request failed — retrying in 3s...';
					setTimeout( bacProcessNext, 3000 );
				}
			} );
	}

	function bacSetFinished( errorMsg ) {
		bacRunning              = false;
		bacExecute.disabled     = false;
		bacExecute.textContent  = 'Execute';
		bacStop.style.display   = 'none';
		bacPreview.disabled     = false;

		if ( errorMsg ) {
			bacPrevResult.textContent  = errorMsg;
			bacPrevResult.className    = 'rr-notice rr-notice--error';
			bacPrevResult.style.display = 'block';
		}
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * LLMS CACHE FLUSH
	 * ═══════════════════════════════════════════════════════════════════════ */

	var flushBtn    = document.getElementById( 'rr-flush-llms-cache' );
	var flushStatus = document.getElementById( 'rr-flush-status' );

	if ( flushBtn ) {
		flushBtn.addEventListener( 'click', function () {
			flushBtn.disabled    = true;
			flushBtn.textContent = 'Flushing...';

			rrFetch( '/llms/flush-cache', 'POST' )
				.then( function () {
					flushBtn.disabled    = false;
					flushBtn.textContent = 'Flush LLMs.txt Cache';
					if ( flushStatus ) {
						flushStatus.style.display = 'inline';
						setTimeout( function () {
							flushStatus.style.display = 'none';
						}, 3000 );
					}
				} )
				.catch( function () {
					flushBtn.disabled    = false;
					flushBtn.textContent = 'Flush LLMs.txt Cache';
				} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * BULK FAQ GENERATION
	 * ═══════════════════════════════════════════════════════════════════════ */

	var faqStart   = document.getElementById( 'rr-faq-bulk-start' );
	var faqStop    = document.getElementById( 'rr-faq-bulk-stop' );
	var faqProg    = document.getElementById( 'rr-faq-bulk-progress' );
	var faqBar     = document.getElementById( 'rr-faq-bulk-bar' );
	var faqStat    = document.getElementById( 'rr-faq-bulk-status' );
	var faqRunning = false;

	if ( faqStart ) {
		var faqResume = document.getElementById( 'rr-faq-bulk-resume' );

		function faqBegin( isResume ) {
			var payload = isResume ? { resume: true } : {};

			if ( ! isResume ) {
				var types = [];
				document.querySelectorAll( '.rr-faq-bulk-type:checked' ).forEach( function ( cb ) {
					types.push( cb.value );
				} );
				if ( ! types.length ) {
					alert( 'Select at least one post type.' );
					return;
				}
				payload.post_types = types;
			}

			faqRunning           = true;
			faqStart.disabled    = true;
			if ( faqResume ) faqResume.disabled = true;
			faqStart.textContent = 'Running...';
			faqStop.style.display  = 'inline-block';
			faqProg.style.display  = 'block';
			if ( ! isResume ) {
				faqBar.style.width = '0%';
			}
			faqStat.textContent = isResume ? 'Resuming...' : 'Starting...';

			rrFetch( '/faq-bulk/start', 'POST', payload ).then( function ( data ) {
				if ( data.code ) {
					faqStat.textContent = 'Error: ' + ( data.message || 'Unknown error' );
					faqFinish();
					return;
				}
				faqUpdate( data );
				if ( data.total === 0 ) {
					faqStat.textContent = 'No published posts found.';
					faqFinish();
				} else {
					faqNext();
				}
			} ).catch( function () {
				faqStat.textContent = 'Failed to start.';
				faqFinish();
			} );
		}

		faqStart.addEventListener( 'click', function () { faqBegin( false ); } );
		if ( faqResume ) {
			faqResume.addEventListener( 'click', function () { faqBegin( true ); } );
		}

		faqStop.addEventListener( 'click', function () {
			faqRunning = false;
			rrFetch( '/faq-bulk/stop', 'POST' ).then( function ( data ) {
				var msg = 'Stopped at ' + data.done + ' / ' + data.total + '.';
				if ( data.queue_remaining > 0 ) {
					msg += ' ' + data.queue_remaining + ' remaining — click Resume to continue.';
				}
				faqStat.textContent = msg;
			} );
			faqFinish();
		} );
	}

	function faqUpdate( data ) {
		var pct = data.total > 0 ? Math.round( ( data.done / data.total ) * 100 ) : 0;
		faqBar.style.width = pct + '%';
		var msg = data.done + ' / ' + data.total + ' posts (' + pct + '%)';
		if ( data.skipped > 0 ) msg += ' | ' + data.skipped + ' unchanged (skipped)';
		if ( data.failed > 0 ) msg += ' | ' + data.failed + ' failed';
		faqStat.textContent = msg;

		// Show per-post activity log.
		if ( data.processed && data.processed.length ) {
			var log = document.getElementById( 'rr-faq-bulk-log' );
			if ( ! log ) {
				log = document.createElement( 'div' );
				log.id = 'rr-faq-bulk-log';
				log.style.cssText = 'margin-top:8px;max-height:200px;overflow-y:auto;font-size:12px;border:1px solid #e0e0e0;border-radius:4px;padding:6px 10px;background:#fafafa;';
				faqProg.appendChild( log );
			}
			data.processed.forEach( function ( p ) {
				var line = document.createElement( 'div' );
				line.style.cssText = 'padding:2px 0;border-bottom:1px solid #f0f0f0;';
				var statusColor = p.status === 'generated' ? '#00a32a' : ( p.status === 'failed' ? '#d63638' : '#999' );
				var tokensStr = p.tokens > 0 ? ' &middot; ' + p.tokens.toLocaleString() + ' tokens' : '';
				line.innerHTML = '<span style="color:' + statusColor + ';font-weight:600;">' + escHtml( p.status ) + '</span> '
					+ '<a href="' + escHtml( p.link ) + '" target="_blank" style="text-decoration:none;">' + escHtml( p.title ) + '</a>'
					+ tokensStr;
				log.appendChild( line );
				log.scrollTop = log.scrollHeight;
			} );
		}
	}

	function faqNext() {
		if ( ! faqRunning ) return;
		rrFetch( '/faq-bulk/process', 'POST' ).then( function ( data ) {
			if ( data.code ) {
				faqStat.textContent = 'Error: ' + ( data.message || 'Unknown' );
				faqFinish();
				return;
			}
			faqUpdate( data );
			if ( data.running ) {
				setTimeout( faqNext, 500 );
			} else {
				var msg = 'Done! ' + data.done + ' processed';
				if ( data.skipped > 0 ) msg += ', ' + data.skipped + ' skipped (unchanged)';
				if ( data.failed > 0 ) msg += ', ' + data.failed + ' failed';
				msg += '.';
				faqStat.textContent = msg;
				faqFinish();
			}
		} ).catch( function () {
			faqStat.textContent = 'Request failed — retrying in 5s...';
			if ( faqRunning ) setTimeout( faqNext, 5000 );
		} );
	}

	function faqFinish() {
		faqRunning            = false;
		faqStart.disabled     = false;
		faqStart.textContent  = 'Start Bulk FAQ Generate';
		faqStop.style.display = 'none';
		var faqResume = document.getElementById( 'rr-faq-bulk-resume' );
		if ( faqResume ) faqResume.disabled = false;
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * FAQ POSTS LIST
	 * ═══════════════════════════════════════════════════════════════════════ */

	var faqLoadBtn   = document.getElementById( 'rr-faq-load-posts' );
	var faqPostsList = document.getElementById( 'rr-faq-posts-list' );
	var faqPostsTbody = document.getElementById( 'rr-faq-posts-tbody' );
	var faqPostsCount = document.getElementById( 'rr-faq-posts-count' );

	if ( faqLoadBtn ) {
		faqLoadBtn.addEventListener( 'click', function () {
			faqLoadBtn.disabled    = true;
			faqLoadBtn.textContent = 'Loading...';

			rrFetch( '/faq/posts', 'GET' )
				.then( function ( data ) {
					faqLoadBtn.disabled    = false;
					faqLoadBtn.textContent = 'Refresh List';

					if ( ! data.posts || ! data.posts.length ) {
						faqPostsCount.textContent   = 'No posts with FAQ found.';
						faqPostsCount.style.display = 'inline';
						faqPostsList.style.display  = 'none';
						return;
					}

					faqPostsCount.textContent   = data.total + ' post' + ( data.total !== 1 ? 's' : '' ) + ' with FAQ';
					faqPostsCount.style.display = 'inline';

					var html = '';
					data.posts.forEach( function ( post ) {
						html += '<tr>';
						html += '<td><strong>' + escHtml( post.title ) + '</strong></td>';
						html += '<td><code style="font-size:12px;">' + escHtml( post.type ) + '</code></td>';
						html += '<td>' + escHtml( post.generated || '—' ) + '</td>';
						html += '<td>';
						html += '<a href="#" onclick="rrEditFaq(' + post.id + ',\'' + escHtml( post.title ).replace( /'/g, '\\&#39;' ) + '\');return false;" style="margin-right:8px;">Edit FAQ</a>';
						if ( post.edit_url ) {
							html += '<a href="' + post.edit_url + '" target="_blank" style="margin-right:8px;">Edit Post</a>';
						}
						if ( post.view_url ) {
							html += '<a href="' + post.view_url + '" target="_blank">View</a>';
						}
						html += '</td>';
						html += '</tr>';
					} );

					faqPostsTbody.innerHTML    = html;
					faqPostsList.style.display = 'block';
				} )
				.catch( function () {
					faqLoadBtn.disabled    = false;
					faqLoadBtn.textContent = 'Load FAQ Posts';
					faqPostsCount.textContent   = 'Failed to load.';
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
			faqModal.id = 'rr-faq-modal';
			faqModal.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;';
			document.body.appendChild( faqModal );
		}

		faqModal.innerHTML = '<div style="background:#fff;border-radius:8px;max-width:700px;width:95%;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 8px 30px rgba(0,0,0,0.2);">'
			+ '<div style="padding:16px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">'
			+ '<h3 style="margin:0;font-size:15px;">Edit FAQ — ' + escHtml( postTitle ) + '</h3>'
			+ '<button id="rr-faq-modal-close" type="button" style="background:none;border:none;font-size:20px;cursor:pointer;color:#666;">&times;</button>'
			+ '</div>'
			+ '<div id="rr-faq-modal-body" style="padding:20px;overflow-y:auto;flex:1;">Loading...</div>'
			+ '<div style="padding:12px 20px;border-top:1px solid #ddd;display:flex;gap:8px;justify-content:flex-end;">'
			+ '<button id="rr-faq-modal-save" class="button button-primary" disabled>Save Changes</button>'
			+ '<span id="rr-faq-modal-status" style="font-size:13px;line-height:30px;margin-right:auto;"></span>'
			+ '</div>'
			+ '</div>';
		faqModal.style.display = 'flex';

		document.getElementById( 'rr-faq-modal-close' ).addEventListener( 'click', closeFaqEditor );
		faqModal.addEventListener( 'click', function ( e ) {
			if ( e.target === faqModal ) closeFaqEditor();
		} );

		rrFetch( '/faq/get/' + postId, 'GET' ).then( function ( data ) {
			if ( ! data || ! data.faq || ! data.faq.length ) {
				document.getElementById( 'rr-faq-modal-body' ).innerHTML = '<p style="color:#999;">No FAQ data found.</p>';
				return;
			}
			faqEditData = data.faq;
			renderFaqEditor();
		} );
	}

	function renderFaqEditor() {
		var body = document.getElementById( 'rr-faq-modal-body' );
		if ( ! body ) return;

		var html = '';
		faqEditData.forEach( function ( item, i ) {
			html += '<div class="rr-faq-edit-item" style="margin-bottom:16px;padding:12px;border:1px solid #e0e0e0;border-radius:4px;">';
			html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">';
			html += '<strong style="font-size:12px;color:#666;">Q' + ( i + 1 ) + '</strong>';
			html += '<button type="button" class="rr-faq-delete-btn" data-index="' + i + '" style="background:none;border:none;color:#d63638;cursor:pointer;font-size:13px;">Remove</button>';
			html += '</div>';
			html += '<input type="text" class="rr-faq-q-input" data-index="' + i + '" value="' + escHtml( item.question ) + '" style="width:100%;padding:6px 8px;margin-bottom:8px;border:1px solid #ddd;border-radius:3px;font-weight:600;" />';
			html += '<textarea class="rr-faq-a-input" data-index="' + i + '" rows="3" style="width:100%;padding:6px 8px;border:1px solid #ddd;border-radius:3px;resize:vertical;">' + escHtml( item.answer ) + '</textarea>';
			html += '</div>';
		} );
		body.innerHTML = html;

		document.getElementById( 'rr-faq-modal-save' ).disabled = false;

		body.querySelectorAll( '.rr-faq-q-input' ).forEach( function ( el ) {
			el.addEventListener( 'input', function () {
				faqEditData[ parseInt( el.dataset.index ) ].question = el.value;
			} );
		} );
		body.querySelectorAll( '.rr-faq-a-input' ).forEach( function ( el ) {
			el.addEventListener( 'input', function () {
				faqEditData[ parseInt( el.dataset.index ) ].answer = el.value;
			} );
		} );
		body.querySelectorAll( '.rr-faq-delete-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				faqEditData.splice( parseInt( btn.dataset.index ), 1 );
				renderFaqEditor();
			} );
		} );

		document.getElementById( 'rr-faq-modal-save' ).onclick = saveFaqEdits;
	}

	function saveFaqEdits() {
		var saveBtn = document.getElementById( 'rr-faq-modal-save' );
		var status  = document.getElementById( 'rr-faq-modal-status' );
		saveBtn.disabled    = true;
		saveBtn.textContent = 'Saving...';
		status.textContent  = '';

		rrFetch( '/faq/save/' + faqEditId, 'POST', { faq: faqEditData } )
			.then( function ( data ) {
				saveBtn.disabled    = false;
				saveBtn.textContent = 'Save Changes';
				if ( data && data.success ) {
					status.textContent = 'Saved!';
					status.style.color = '#00a32a';
					setTimeout( closeFaqEditor, 800 );
				} else {
					status.textContent = 'Save failed.';
					status.style.color = '#d63638';
				}
			} )
			.catch( function () {
				saveBtn.disabled    = false;
				saveBtn.textContent = 'Save Changes';
				status.textContent  = 'Request failed.';
				status.style.color  = '#d63638';
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

	var tokensLoad  = document.getElementById( 'rr-tokens-load' );
	var tokensList  = document.getElementById( 'rr-tokens-list' );
	var tokensTbody = document.getElementById( 'rr-tokens-tbody' );
	var tokensCount = document.getElementById( 'rr-tokens-count' );

	if ( tokensLoad ) {
		tokensLoad.addEventListener( 'click', function () {
			tokensLoad.disabled    = true;
			tokensLoad.textContent = 'Loading...';
			tokensCount.style.display = 'none';

			rrFetch( '/token-usage', 'GET' ).then( function ( data ) {
				tokensLoad.disabled    = false;
				tokensLoad.textContent = 'Refresh Details';

				if ( ! data.posts || ! data.posts.length ) {
					tokensCount.textContent   = 'No token usage recorded yet.';
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
						html += '<a href="' + escHtml( post.edit ) + '" target="_blank" style="font-size:12px;">Edit</a>';
					}
					html += '</td>';
					html += '</tr>';
				} );

				tokensTbody.innerHTML    = html;
				tokensList.style.display = 'block';
				tokensCount.textContent   = data.posts.length + ' post' + ( data.posts.length !== 1 ? 's' : '' ) + ' | ' + totalTokens.toLocaleString() + ' total tokens';
				tokensCount.style.color   = '#2271b1';
				tokensCount.style.display = 'inline';
			} ).catch( function () {
				tokensLoad.disabled    = false;
				tokensLoad.textContent = 'Load Per-Post Details';
			} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * ERROR LOG
	 * ═══════════════════════════════════════════════════════════════════════ */

	var errorsLoad  = document.getElementById( 'rr-errors-load' );
	var errorsClear = document.getElementById( 'rr-errors-clear' );
	var errorsList  = document.getElementById( 'rr-errors-list' );
	var errorsTbody = document.getElementById( 'rr-errors-tbody' );
	var errorsStatus = document.getElementById( 'rr-errors-status' );

	if ( errorsLoad ) {
		errorsLoad.addEventListener( 'click', function () {
			errorsLoad.disabled    = true;
			errorsLoad.textContent = 'Loading...';
			errorsStatus.style.display = 'none';

			rrFetch( '/errors', 'GET' ).then( function ( data ) {
				errorsLoad.disabled    = false;
				errorsLoad.textContent = 'Refresh Log';

				if ( ! data.errors || ! data.errors.length ) {
					errorsStatus.textContent   = 'No errors logged.';
					errorsStatus.style.color   = '#00a32a';
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
				errorsStatus.textContent   = data.errors.length + ' error' + ( data.errors.length !== 1 ? 's' : '' );
				errorsStatus.style.color   = '#d63638';
				errorsStatus.style.display = 'inline';
			} ).catch( function () {
				errorsLoad.disabled    = false;
				errorsLoad.textContent = 'Load Error Log';
			} );
		} );

		errorsClear.addEventListener( 'click', function () {
			rrFetch( '/errors/clear', 'POST' ).then( function () {
				errorsTbody.innerHTML  = '';
				errorsList.style.display   = 'none';
				errorsStatus.textContent   = 'Log cleared.';
				errorsStatus.style.color   = '#00a32a';
				errorsStatus.style.display = 'inline';
			} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * VERIFY API KEY
	 * ═══════════════════════════════════════════════════════════════════════ */

	var verifyBtn    = document.getElementById( 'rr-verify-key' );
	var verifyStatus = document.getElementById( 'rr-verify-status' );

	if ( verifyBtn ) {
		verifyBtn.addEventListener( 'click', function () {
			var keyField = document.getElementById( 'rr_api_key' );
			var key      = keyField ? keyField.value : '';

			if ( ! key ) {
				verifyStatus.textContent    = 'Enter an API key first.';
				verifyStatus.style.color    = '#d63638';
				verifyStatus.style.display  = 'inline';
				return;
			}

			verifyBtn.disabled    = true;
			verifyBtn.textContent = 'Verifying...';
			verifyStatus.style.display = 'none';

			rrFetch( '/verify-key', 'POST', { key: key } )
				.then( function ( data ) {
					verifyBtn.disabled    = false;
					verifyBtn.textContent = 'Verify Key';

					if ( data.valid ) {
						verifyStatus.textContent   = '✓ ' + data.message;
						verifyStatus.style.color   = '#00a32a';
					} else {
						verifyStatus.textContent   = '✗ ' + data.message;
						verifyStatus.style.color   = '#d63638';
					}
					verifyStatus.style.display = 'inline';
				} )
				.catch( function () {
					verifyBtn.disabled    = false;
					verifyBtn.textContent = 'Verify Key';
					verifyStatus.textContent   = '✗ Request failed.';
					verifyStatus.style.color   = '#d63638';
					verifyStatus.style.display = 'inline';
				} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * VERIFY DATAFORSEO KEY
	 * ═══════════════════════════════════════════════════════════════════════ */

	var dfsVerifyBtn    = document.getElementById( 'rr-verify-dfs' );
	var dfsVerifyStatus = document.getElementById( 'rr-verify-dfs-status' );

	if ( dfsVerifyBtn ) {
		dfsVerifyBtn.addEventListener( 'click', function () {
			var loginField = document.getElementById( 'rr_dfs_login' );
			var pwField    = document.getElementById( 'rr_dfs_password' );
			var login = loginField ? loginField.value : '';
			var pw    = pwField ? pwField.value : '';

			// If password contains bullet chars, it's the masked display — use stored value.
			var payload = {};
			if ( login ) payload.login = login;
			if ( pw && pw.indexOf( '\u2022' ) === -1 ) {
				payload.password = pw;
			}

			dfsVerifyBtn.disabled    = true;
			dfsVerifyBtn.textContent = 'Verifying...';
			dfsVerifyStatus.style.display = 'none';

			rrFetch( '/verify-dfs', 'POST', payload )
				.then( function ( data ) {
					dfsVerifyBtn.disabled    = false;
					dfsVerifyBtn.textContent = 'Verify DataForSEO';

					if ( data.valid ) {
						dfsVerifyStatus.textContent   = '\u2713 ' + data.message;
						dfsVerifyStatus.style.color   = '#00a32a';
					} else {
						dfsVerifyStatus.textContent   = '\u2717 ' + data.message;
						dfsVerifyStatus.style.color   = '#d63638';
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
					dfsVerifyBtn.textContent = 'Verify DataForSEO';
					dfsVerifyStatus.textContent   = '\u2717 Request failed.';
					dfsVerifyStatus.style.color   = '#d63638';
					dfsVerifyStatus.style.display = 'inline';
				} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * CRAWLER SELECT ALL
	 * ═══════════════════════════════════════════════════════════════════════ */

	var selectAll = document.getElementById( 'rr-crawlers-select-all' );
	if ( selectAll ) {
		var crawlerBoxes = document.querySelectorAll( '.rr-crawler-checkbox' );

		selectAll.addEventListener( 'change', function () {
			crawlerBoxes.forEach( function ( cb ) {
				cb.checked = selectAll.checked;
			} );
		} );

		// Update select-all state when individual boxes change.
		crawlerBoxes.forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				var allChecked = Array.from( crawlerBoxes ).every( function ( c ) { return c.checked; } );
				selectAll.checked = allChecked;
			} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * START OVER — BULK REGENERATE (clear + regenerate all)
	 * ═══════════════════════════════════════════════════════════════════════ */

	var soStart   = document.getElementById( 'rr-startover-btn' );
	var soResume  = document.getElementById( 'rr-startover-resume' );
	var soStop    = document.getElementById( 'rr-startover-stop' );
	var soProg    = document.getElementById( 'rr-startover-progress' );
	var soBar     = document.getElementById( 'rr-startover-bar' );
	var soStat    = document.getElementById( 'rr-startover-status' );
	var soRunning = false;

	if ( soStart ) {
		function soBegin( isResume ) {
			var payload = isResume ? { resume: true } : {};

			if ( ! isResume ) {
				var types = [];
				document.querySelectorAll( '.rr-startover-type:checked' ).forEach( function ( cb ) {
					types.push( cb.value );
				} );
				if ( ! types.length ) {
					alert( 'Select at least one post type.' );
					return;
				}
				payload.post_types = types;
			}

			soRunning           = true;
			soStart.disabled    = true;
			if ( soResume ) soResume.disabled = true;
			soStart.textContent = 'Running...';
			soStop.style.display  = 'inline-block';
			soProg.style.display  = 'block';
			if ( ! isResume ) {
				soBar.style.width = '0%';
			}
			soStat.textContent    = isResume ? 'Resuming...' : 'Starting...';
			soStat.style.display  = 'block';
			soStat.style.color    = '';

			rrFetch( '/startover-bulk/start', 'POST', payload ).then( function ( data ) {
				if ( data.code ) {
					soStat.textContent = 'Error: ' + ( data.message || 'Unknown error' );
					soFinish();
					return;
				}
				soUpdate( data );
				if ( data.total === 0 ) {
					soStat.textContent = 'No published posts found.';
					soFinish();
				} else {
					soNext();
				}
			} ).catch( function () {
				soStat.textContent = 'Request failed.';
				soFinish();
			} );
		}

		function soNext() {
			if ( ! soRunning ) return;
			rrFetch( '/startover-bulk/process', 'POST' ).then( function ( data ) {
				if ( data.code ) {
					soStat.textContent = 'Error: ' + ( data.message || 'Unknown error' );
					soFinish();
					return;
				}
				soUpdate( data );

				// Append log entries.
				if ( data.log && data.log.length ) {
					var log = document.getElementById( 'rr-startover-log' );
					if ( ! log ) {
						log = document.createElement( 'div' );
						log.id = 'rr-startover-log';
						log.style.cssText = 'margin-top:12px;max-height:300px;overflow-y:auto;font-size:13px;border:1px solid #ddd;border-radius:4px;padding:8px;background:#fafafa;';
						soProg.parentNode.insertBefore( log, soProg.nextSibling );
					}
					data.log.forEach( function ( entry ) {
						var p = document.createElement( 'p' );
						p.style.margin = '2px 0';
						var color = entry.summary === 'generated' ? '#00a32a' : '#dba617';
						p.innerHTML = '<a href="' + entry.edit_link + '" target="_blank">' + entry.title + '</a> '
							+ '<span style="color:' + color + '">Summary: ' + entry.summary + '</span>'
							+ ' | <span>FAQ: ' + entry.faq + '</span>';
						log.appendChild( p );
						log.scrollTop = log.scrollHeight;
					} );
				}

				if ( data.done >= data.total ) {
					soStat.textContent = 'Done! ' + data.done + '/' + data.total + ' posts regenerated.';
					soStat.style.color = '#00a32a';
					soFinish();
				} else {
					setTimeout( soNext, 500 );
				}
			} ).catch( function () {
				soStat.textContent = 'Request failed — retrying in 5s...';
				if ( soRunning ) setTimeout( soNext, 5000 );
			} );
		}

		function soUpdate( data ) {
			var pct = data.total > 0 ? Math.round( ( data.done / data.total ) * 100 ) : 0;
			soBar.style.width  = pct + '%';
			soStat.textContent = data.done + ' / ' + data.total + ' (' + pct + '%)';
		}

		function soFinish() {
			soRunning           = false;
			soStart.disabled    = false;
			soStart.textContent = 'Start Over — Bulk Regenerate';
			soStop.style.display = 'none';
			if ( soResume ) soResume.disabled = false;
		}

		soStart.addEventListener( 'click', function () { soBegin( false ); } );
		if ( soResume ) {
			soResume.addEventListener( 'click', function () { soBegin( true ); } );
		}

		soStop.addEventListener( 'click', function () {
			soRunning = false;
			rrFetch( '/startover-bulk/stop', 'POST' ).then( function ( data ) {
				var msg = 'Stopped at ' + data.done + ' / ' + data.total + '.';
				if ( data.queue_remaining > 0 ) {
					msg += ' ' + data.queue_remaining + ' remaining — click Resume to continue.';
				}
				soStat.textContent = msg;
			} );
			soFinish();
		} );
	}

	/* Old "Health Check" handler removed in rc.15 — replaced by the live
	 * 22-probe Diagnostics handler below. The rr-health-check DOM element
	 * was removed in rc.5; the JS handler became orphan code. */

	/* ═══════════════════════════════════════════════════════════════════════
	 * DIAGNOSTICS (v1.2.0-rc.5)
	 * ─────────────────────────────────────────────────────────────────────
	 * Live endpoint probes + conflict detection + 1-click copy report.
	 * Endpoint: GET /rankready/v1/diagnostics?include_api=0|1
	 *           GET /rankready/v1/diagnostics/report?include_api=0|1
	 * ═══════════════════════════════════════════════════════════════════════ */

	var diagRunBtn       = document.getElementById( 'rr-diag-run' );
	var diagIncludeApi   = document.getElementById( 'rr-diag-include-api' );
	var diagStatus       = document.getElementById( 'rr-diag-status' );
	var diagSummary      = document.getElementById( 'rr-diag-summary' );
	var diagResults      = document.getElementById( 'rr-diag-results' );
	var diagTbody        = document.getElementById( 'rr-diag-tbody' );
	var diagCopyRow      = document.getElementById( 'rr-diag-copy-row' );
	var diagCopyBtn      = document.getElementById( 'rr-diag-copy' );
	var diagCopyStatus   = document.getElementById( 'rr-diag-copy-status' );
	var diagReportPreview = document.getElementById( 'rr-diag-report-preview' );

	if ( diagRunBtn ) {
		diagRunBtn.addEventListener( 'click', function () {
			var includeApi = diagIncludeApi && diagIncludeApi.checked ? 1 : 0;

			diagRunBtn.disabled    = true;
			diagRunBtn.textContent = 'Probing endpoints…';
			diagStatus.style.display = 'none';
			diagSummary.style.display = 'none';
			diagResults.style.display = 'none';
			diagCopyRow.style.display = 'none';

			rrFetch( '/diagnostics?include_api=' + includeApi, 'GET' ).then( function ( data ) {
				diagRunBtn.disabled    = false;
				diagRunBtn.textContent = 'Run Diagnostics';

				if ( ! data.checks || ! data.checks.length ) {
					diagStatus.textContent   = 'No results.';
					diagStatus.style.color   = '#999';
					diagStatus.style.display = 'inline';
					return;
				}

				var t = data.totals || { pass: 0, warn: 0, fail: 0, info: 0 };

				// Summary chips
				diagSummary.innerHTML =
					'<span style="display:inline-block;padding:4px 12px;background:#d1ecdf;color:#0a6c39;border-radius:12px;font-weight:600;margin-right:8px;">✓ ' + t.pass + ' pass</span>' +
					( t.warn ? '<span style="display:inline-block;padding:4px 12px;background:#fcf4d6;color:#7a5d00;border-radius:12px;font-weight:600;margin-right:8px;">⚠ ' + t.warn + ' warn</span>' : '' ) +
					( t.fail ? '<span style="display:inline-block;padding:4px 12px;background:#f9d7d8;color:#8a1f1f;border-radius:12px;font-weight:600;margin-right:8px;">✗ ' + t.fail + ' fail</span>' : '' ) +
					( t.info ? '<span style="display:inline-block;padding:4px 12px;background:#e5f1f9;color:#0b4b75;border-radius:12px;font-weight:600;margin-right:8px;">ℹ ' + t.info + ' info</span>' : '' );
				diagSummary.style.display = 'block';

				// Results table
				var rows = '';
				data.checks.forEach( function ( c ) {
					var icon, rowStyle = '';
					if ( c.status === 'pass' ) {
						icon = '<span style="color:#00a32a;font-size:18px;">✓</span>';
					} else if ( c.status === 'warn' ) {
						icon = '<span style="color:#dba617;font-size:18px;">⚠</span>';
						rowStyle = 'background:#fffbe6;';
					} else if ( c.status === 'fail' ) {
						icon = '<span style="color:#d63638;font-size:18px;">✗</span>';
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
						rows += '<div style="margin-top:4px;font-size:12px;color:#5d6770;"><strong>Fix:</strong> ' + escHtml( c.fix ) + '</div>';
					}
					if ( c.meta && c.meta.url ) {
						rows += '<div style="margin-top:4px;font-size:11px;color:#8c8f94;font-family:Menlo,Consolas,monospace;">' + escHtml( c.meta.url ) + '</div>';
					}
					rows += '</td></tr>';
				} );

				diagTbody.innerHTML       = rows;
				diagResults.style.display = 'block';
				diagCopyRow.style.display = 'block';

				var summaryColor = t.fail > 0 ? '#d63638' : ( t.warn > 0 ? '#dba617' : '#00a32a' );
				diagStatus.textContent   = 'Completed.';
				diagStatus.style.color   = summaryColor;
				diagStatus.style.display = 'inline';
			} ).catch( function () {
				diagRunBtn.disabled    = false;
				diagRunBtn.textContent = 'Run Diagnostics';
				diagStatus.textContent   = 'Request failed.';
				diagStatus.style.color   = '#d63638';
				diagStatus.style.display = 'inline';
			} );
		} );
	}

	if ( diagCopyBtn ) {
		diagCopyBtn.addEventListener( 'click', function () {
			var includeApi = diagIncludeApi && diagIncludeApi.checked ? 1 : 0;
			diagCopyBtn.disabled = true;
			diagCopyStatus.style.display = 'none';

			rrFetch( '/diagnostics/report?include_api=' + includeApi, 'GET' ).then( function ( data ) {
				diagCopyBtn.disabled = false;
				var report = data.report || '';
				if ( diagReportPreview ) {
					diagReportPreview.value = report;
				}

				// Copy to clipboard
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( report ).then( function () {
						diagCopyStatus.textContent   = '✓ Copied ' + data.length + ' chars';
						diagCopyStatus.style.color   = '#00a32a';
						diagCopyStatus.style.display = 'inline';
					} ).catch( function () {
						fallbackCopy();
					} );
				} else {
					fallbackCopy();
				}

				function fallbackCopy() {
					if ( diagReportPreview ) {
						diagReportPreview.select();
						try {
							document.execCommand( 'copy' );
							diagCopyStatus.textContent   = '✓ Copied (fallback)';
							diagCopyStatus.style.color   = '#00a32a';
						} catch ( e ) {
							diagCopyStatus.textContent   = 'Copy failed — select text manually from preview.';
							diagCopyStatus.style.color   = '#d63638';
						}
						diagCopyStatus.style.display = 'inline';
					}
				}
			} ).catch( function () {
				diagCopyBtn.disabled = false;
				diagCopyStatus.textContent   = 'Report generation failed.';
				diagCopyStatus.style.color   = '#d63638';
				diagCopyStatus.style.display = 'inline';
			} );
		} );
	}

	/* ═══════════════════════════════════════════════════════════════════════
	 * CONTENT FRESHNESS ALERTS
	 * ═══════════════════════════════════════════════════════════════════════ */

	var freshBtn     = document.getElementById( 'rr-freshness-scan' );
	var freshDays    = document.getElementById( 'rr-freshness-days' );
	var freshStatus  = document.getElementById( 'rr-freshness-status' );
	var freshSummary = document.getElementById( 'rr-freshness-summary' );
	var freshResults = document.getElementById( 'rr-freshness-results' );
	var freshTbody   = document.getElementById( 'rr-freshness-tbody' );

	if ( freshBtn ) {
		freshBtn.addEventListener( 'click', function () {
			freshBtn.disabled    = true;
			freshBtn.textContent = 'Scanning...';
			freshStatus.style.display  = 'none';
			freshSummary.style.display = 'none';
			freshResults.style.display = 'none';

			var days = freshDays ? freshDays.value : 90;

			wp.apiFetch( { path: '/rankready/v1/freshness?days=' + days } )
				.then( function ( data ) {
					freshBtn.disabled    = false;
					freshBtn.textContent = 'Scan Content Freshness';

					var s = data.summary;
					var freshPct = s.fresh_pct;
					var pctColor = freshPct >= 80 ? '#00a32a' : ( freshPct >= 50 ? '#dba617' : '#d63638' );

					freshSummary.innerHTML =
						'<div style="display:flex;gap:16px;flex-wrap:wrap;">' +
						'<div style="padding:10px 16px;background:#f0f6fc;border:1px solid #c8d8e8;border-radius:6px;">' +
						'<span style="font-size:22px;font-weight:700;color:' + pctColor + ';">' + freshPct + '%</span>' +
						'<span style="font-size:13px;color:#646970;margin-left:6px;">Content Fresh</span>' +
						'</div>' +
						'<div style="padding:10px 16px;background:#fef8f0;border:1px solid #e8d8c0;border-radius:6px;">' +
						'<span style="font-size:22px;font-weight:700;color:#1d2327;">' + s.total_stale + '</span>' +
						'<span style="font-size:13px;color:#646970;margin-left:6px;">Stale Posts (>' + s.threshold_days + ' days)</span>' +
						'</div>' +
						'<div style="padding:10px 16px;background:#f0f6fc;border:1px solid #c8d8e8;border-radius:6px;">' +
						'<span style="font-size:22px;font-weight:700;color:#1d2327;">' + s.total_published + '</span>' +
						'<span style="font-size:13px;color:#646970;margin-left:6px;">Total Published</span>' +
						'</div>' +
						'</div>';
					freshSummary.style.display = 'block';

					if ( data.stale.length === 0 ) {
						freshStatus.textContent   = 'All content is fresh!';
						freshStatus.style.color   = '#00a32a';
						freshStatus.style.display = 'inline';
						return;
					}

					var html = '';
					data.stale.forEach( function ( p ) {
						var urgIcon  = p.urgency === 'critical' ? '\u26d4' : ( p.urgency === 'high' ? '\u26a0\ufe0f' : '\u23f3' );
						var urgColor = p.urgency === 'critical' ? '#d63638' : ( p.urgency === 'high' ? '#dba617' : '#646970' );
						var modDate  = p.modified.substring( 0, 10 );

						html += '<tr>';
						html += '<td style="text-align:center;color:' + urgColor + ';font-size:16px;">' + urgIcon + '</td>';
						html += '<td><strong>' + escHtml( p.title ) + '</strong></td>';
						html += '<td>' + escHtml( p.type ) + '</td>';
						html += '<td>' + modDate + '</td>';
						html += '<td style="color:' + urgColor + ';font-weight:600;">' + p.days_ago + 'd</td>';
						html += '<td>' + ( p.has_summary ? '\u2705' : '\u274c' ) + '</td>';
						html += '<td>' + ( p.has_faq ? '\u2705' : '\u274c' ) + '</td>';
						html += '<td><a href="' + escHtml( p.edit_url ) + '" target="_blank" class="button button-small">Edit</a></td>';
						html += '</tr>';
					} );

					freshTbody.innerHTML       = html;
					freshResults.style.display = 'block';

					freshStatus.textContent   = data.stale.length + ' stale posts found (showing top 50)';
					freshStatus.style.color   = '#dba617';
					freshStatus.style.display = 'inline';
				} ).catch( function () {
					freshBtn.disabled    = false;
					freshBtn.textContent = 'Scan Content Freshness';
					freshStatus.textContent   = 'Request failed.';
					freshStatus.style.color   = '#d63638';
					freshStatus.style.display = 'inline';
				} );
		} );
	}

	// ── Progressive disclosure: toggle reveals child settings ────────────────
	//
	// Pattern: a master checkbox marked with `data-rr-toggle-master`
	// controls visibility of every element marked with the matching
	// `data-rr-toggle-target="<id>"`. Two attributes only — no class
	// hardcoding, works inside any tab.
	//
	// Usage in PHP:
	//   <input type="checkbox" data-rr-toggle-master="headless">
	//   <div data-rr-toggle-target="headless"> ... settings ... </div>
	//
	function bindToggleDisclosure() {
		var masters = document.querySelectorAll( '[data-rr-toggle-master]' );
		if ( ! masters.length ) {
			return;
		}
		masters.forEach( function ( master ) {
			var key      = master.getAttribute( 'data-rr-toggle-master' );
			var targets  = document.querySelectorAll( '[data-rr-toggle-target="' + key + '"]' );
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
	// Each provider card has its own [data-rr-verify-provider] button. Pulls
	// the matching key field's value (or the saved key if masked) and pings
	// the REST verify endpoint with the chosen provider.
	//
	function bindVerifyButtons() {
		var buttons = document.querySelectorAll( '[data-rr-verify-provider]' );
		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var provider = btn.getAttribute( 'data-rr-verify-provider' );
				var keyInput = document.querySelector( '[data-rr-key-for="' + provider + '"]' );
				var status   = document.querySelector( '[data-rr-verify-status="' + provider + '"]' );
				if ( ! keyInput || ! status ) { return; }

				var key = keyInput.value;
				status.style.display = 'inline';
				status.style.color   = '#646970';
				status.textContent   = 'Verifying…';
				btn.disabled = true;

				rrFetch( '/verify-key', 'POST', { key: key, provider: provider } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( data ) {
						btn.disabled       = false;
						status.style.color = data.valid ? '#00a32a' : '#d63638';
						status.textContent = data.valid ? '✓ ' + data.message : '✗ ' + data.message;
					} )
					.catch( function () {
						btn.disabled       = false;
						status.style.color = '#d63638';
						status.textContent = '✗ Request failed.';
					} );
			} );
		} );
	}
	bindVerifyButtons();

} )();
