<?php
/**
 * RankReady — Content Freshness UX.
 *
 * Three-tab WP dashboard widget that buckets every published post into:
 *   - Stale       (>= 60 days since last modified)
 *   - Going stale (30 - 59 days)
 *   - Fresh       (< 30 days)
 *
 * Each tab shows the matching posts and a bulk "Refresh dateModified"
 * action. Refresh updates `post_modified` to the current time without
 * touching content — a legitimate freshness signal that AI engines
 * (per Zyppy / Semrush data) weight at +28% citation lift when within
 * the 60-day window.
 *
 * No score, no email digest, no nudge popup. Just the widget.
 *
 * REST endpoints (admin-only):
 *   GET  /rankready/v1/freshness/list?bucket=stale|going_stale|fresh
 *   POST /rankready/v1/freshness/refresh   { post_ids: [int, ...] }
 *
 * @package RankReady
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

class RR_Freshness {

	private const NS              = 'rankready/v1';
	private const STALE_DAYS      = 60;
	private const GOING_STALE_DAYS = 30;
	private const LIST_LIMIT      = 50;

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		// Note: dashboard widget registration handled by RR_Agent_Dashboard
		// — single consolidated "Agent Visibility" widget replaces the two
		// separate widgets shipped in beta.1.
	}

	// ── Dashboard widget panel (rendered inside the unified widget) ──────

	public static function render_widget(): void {
		$counts = self::bucket_counts();
		$nonce  = wp_create_nonce( 'wp_rest' );
		$api    = esc_url_raw( rest_url( self::NS ) );
		?>
		<div class="rr-freshness-widget" data-rr-api="<?php echo esc_attr( $api ); ?>" data-rr-nonce="<?php echo esc_attr( $nonce ); ?>">
			<div class="rr-fw-tabs" style="display:flex;gap:0;border-bottom:1px solid #dcdcde;margin:-12px -12px 12px;">
				<button type="button" class="rr-fw-tab is-active" data-bucket="stale"
				        style="flex:1;padding:10px;border:0;border-bottom:2px solid #d63638;background:#fff;cursor:pointer;font-weight:600;color:#d63638;">
					<?php esc_html_e( 'Stale', 'rankready' ); ?>
					<span style="display:block;font-size:18px;margin-top:2px;"><?php echo esc_html( (string) $counts['stale'] ); ?></span>
					<span style="display:block;font-size:11px;color:#646970;font-weight:400;"><?php esc_html_e( '60+ days', 'rankready' ); ?></span>
				</button>
				<button type="button" class="rr-fw-tab" data-bucket="going_stale"
				        style="flex:1;padding:10px;border:0;border-bottom:2px solid transparent;background:#fff;cursor:pointer;font-weight:600;color:#646970;">
					<?php esc_html_e( 'Going stale', 'rankready' ); ?>
					<span style="display:block;font-size:18px;margin-top:2px;color:#dba617;"><?php echo esc_html( (string) $counts['going_stale'] ); ?></span>
					<span style="display:block;font-size:11px;color:#646970;font-weight:400;"><?php esc_html_e( '30–59 days', 'rankready' ); ?></span>
				</button>
				<button type="button" class="rr-fw-tab" data-bucket="fresh"
				        style="flex:1;padding:10px;border:0;border-bottom:2px solid transparent;background:#fff;cursor:pointer;font-weight:600;color:#646970;">
					<?php esc_html_e( 'Fresh', 'rankready' ); ?>
					<span style="display:block;font-size:18px;margin-top:2px;color:#00a32a;"><?php echo esc_html( (string) $counts['fresh'] ); ?></span>
					<span style="display:block;font-size:11px;color:#646970;font-weight:400;"><?php esc_html_e( '< 30 days', 'rankready' ); ?></span>
				</button>
			</div>

			<div class="rr-fw-toolbar" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
				<label style="font-size:12px;">
					<input type="checkbox" class="rr-fw-select-all" />
					<?php esc_html_e( 'Select all', 'rankready' ); ?>
				</label>
				<button type="button" class="button button-small rr-fw-refresh" disabled>
					<?php esc_html_e( 'Refresh dateModified', 'rankready' ); ?>
				</button>
			</div>

			<div class="rr-fw-list" style="max-height:280px;overflow-y:auto;">
				<p style="color:var(--rr-color-text-muted,#646970);font-style:italic;font-size:var(--rr-text-sm,12px);padding:8px 0;">
					<?php esc_html_e( 'Loading…', 'rankready' ); ?>
				</p>
			</div>

			<p class="rr-fw-status" style="margin-top:8px;font-size:11px;color:#646970;"></p>
		</div>
		<style>
			.rr-fw-tab:hover { background:#f6f7f7 !important; }
			.rr-fw-list ul { margin:0; }
			.rr-fw-list li { display:flex; align-items:center; padding:6px 0; border-bottom:1px solid #f0f0f1; font-size:12px; }
			.rr-fw-list li:last-child { border-bottom:0; }
			.rr-fw-list li label { flex:1; display:flex; align-items:center; gap:6px; cursor:pointer; min-width:0; }
			.rr-fw-list li .rr-fw-title { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
			.rr-fw-list li .rr-fw-age { color:#646970; font-size:11px; flex-shrink:0; }
		</style>
		<script>
		(function() {
			var root = document.currentScript.previousElementSibling;
			while ( root && ! root.classList.contains( 'rr-freshness-widget' ) ) {
				root = root.previousElementSibling;
			}
			if ( ! root ) return;

			var api      = root.getAttribute( 'data-rr-api' );
			var nonce    = root.getAttribute( 'data-rr-nonce' );
			var listEl   = root.querySelector( '.rr-fw-list' );
			var statusEl = root.querySelector( '.rr-fw-status' );
			var btnRefr  = root.querySelector( '.rr-fw-refresh' );
			var selAll   = root.querySelector( '.rr-fw-select-all' );
			var tabs     = root.querySelectorAll( '.rr-fw-tab' );
			var current  = 'stale';

			function updateButton() {
				var anyChecked = !! listEl.querySelector( 'input[type=checkbox]:checked' );
				btnRefr.disabled = ! anyChecked;
			}

			function setTab( bucket ) {
				current = bucket;
				tabs.forEach( function( t ) {
					var active = t.getAttribute( 'data-bucket' ) === bucket;
					t.classList.toggle( 'is-active', active );
					t.style.borderBottomColor = active ? '#d63638' : 'transparent';
					t.style.color = active ? '#d63638' : '#646970';
				} );
				load();
			}

			function load() {
				listEl.innerHTML = '<p style="color:#646970;font-style:italic;font-size:12px;padding:8px 0;">Loading…</p>';
				selAll.checked = false;
				btnRefr.disabled = true;
				fetch( api + '/freshness/list?bucket=' + encodeURIComponent( current ), {
					headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' },
					credentials: 'same-origin'
				} ).then( function( r ) { return r.json(); } ).then( function( data ) {
					if ( ! data || ! data.posts || ! data.posts.length ) {
						var msg = current === 'stale'
							? '🎯 No stale posts. Every published post has been touched within the last 60 days.'
							: ( current === 'going_stale'
								? '⏳ Nothing in the 30–60 day window. Plenty of time before any post goes stale.'
								: '✨ Newly published or refreshed content shows up here.' );
						listEl.innerHTML = '<p style="color:var(--rr-color-text-muted,#646970);font-style:italic;font-size:var(--rr-text-sm,12px);padding:8px 0;">' + msg + '</p>';
						return;
					}
					var html = '<ul>';
					data.posts.forEach( function( p ) {
						html += '<li><label>'
						     +   '<input type="checkbox" value="' + p.id + '" />'
						     +   '<span class="rr-fw-title"><a href="' + p.edit + '" target="_blank">' + p.title + '</a></span>'
						     + '</label>'
						     + '<span class="rr-fw-age">' + p.age + 'd</span>'
						     + '</li>';
					} );
					html += '</ul>';
					listEl.innerHTML = html;
				} ).catch( function() {
					listEl.innerHTML = '<p style="color:#d63638;font-size:12px;">Failed to load.</p>';
				} );
			}

			tabs.forEach( function( t ) {
				t.addEventListener( 'click', function() { setTab( t.getAttribute( 'data-bucket' ) ); } );
			} );

			listEl.addEventListener( 'change', updateButton );

			selAll.addEventListener( 'change', function() {
				listEl.querySelectorAll( 'input[type=checkbox]' ).forEach( function( cb ) { cb.checked = selAll.checked; } );
				updateButton();
			} );

			btnRefr.addEventListener( 'click', function() {
				var ids = Array.prototype.slice.call(
					listEl.querySelectorAll( 'input[type=checkbox]:checked' )
				).map( function( cb ) { return parseInt( cb.value, 10 ); } );
				if ( ! ids.length ) return;
				btnRefr.disabled = true;
				statusEl.textContent = 'Refreshing ' + ids.length + ' post(s)…';
				fetch( api + '/freshness/refresh', {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json', 'Accept': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify( { post_ids: ids } )
				} ).then( function( r ) { return r.json(); } ).then( function( data ) {
					statusEl.textContent = 'Refreshed ' + ( data.refreshed || 0 ) + ' post(s). Reloading…';
					setTimeout( load, 600 );
				} ).catch( function() {
					statusEl.textContent = 'Refresh failed.';
					btnRefr.disabled = false;
				} );
			} );

			load();
		})();
		</script>
		<?php
	}

	// ── REST routes ───────────────────────────────────────────────────────

	public static function register_routes(): void {
		register_rest_route( self::NS, '/freshness/list', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'rest_list' ),
			'permission_callback' => array( self::class, 'permission' ),
			'args'                => array(
				'bucket' => array(
					'type'              => 'string',
					'default'           => 'stale',
					'enum'              => array( 'stale', 'going_stale', 'fresh' ),
					'sanitize_callback' => 'sanitize_key',
				),
			),
		) );

		register_rest_route( self::NS, '/freshness/refresh', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'rest_refresh' ),
			'permission_callback' => array( self::class, 'permission' ),
		) );
	}

	public static function permission() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error( 'rr_forbidden', __( 'Insufficient permissions.', 'rankready' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_list( WP_REST_Request $request ) {
		$bucket = sanitize_key( (string) $request->get_param( 'bucket' ) );
		$posts  = self::query_bucket( $bucket );

		$out = array();
		foreach ( $posts as $p ) {
			$age = self::days_since_modified( $p );
			$out[] = array(
				'id'    => $p->ID,
				'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'edit'  => get_edit_post_link( $p->ID, 'raw' ),
				'age'   => $age,
			);
		}

		return new WP_REST_Response( array( 'bucket' => $bucket, 'posts' => $out ), 200 );
	}

	public static function rest_refresh( WP_REST_Request $request ) {
		$body     = $request->get_json_params();
		$post_ids = isset( $body['post_ids'] ) && is_array( $body['post_ids'] ) ? array_map( 'absint', $body['post_ids'] ) : array();
		$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );

		if ( empty( $post_ids ) ) {
			return new WP_Error( 'rr_no_ids', __( 'No post IDs supplied.', 'rankready' ), array( 'status' => 400 ) );
		}

		$now_mysql     = current_time( 'mysql' );
		$now_mysql_gmt = current_time( 'mysql', true );

		$refreshed = 0;
		// Suppress summary re-generation cascade — content didn't change, only modified date.
		if ( class_exists( 'RR_Generator' ) ) {
			RR_Generator::$generating = true;
		}

		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$result = wp_update_post( array(
				'ID'                => $post_id,
				'post_modified'     => $now_mysql,
				'post_modified_gmt' => $now_mysql_gmt,
			), true );
			if ( ! is_wp_error( $result ) ) {
				$refreshed++;
			}
		}

		if ( class_exists( 'RR_Generator' ) ) {
			RR_Generator::$generating = false;
		}

		return new WP_REST_Response( array( 'refreshed' => $refreshed, 'requested' => count( $post_ids ) ), 200 );
	}

	// ── Internal queries ──────────────────────────────────────────────────

	private static function bucket_counts(): array {
		return array(
			'stale'       => self::count_bucket( 'stale' ),
			'going_stale' => self::count_bucket( 'going_stale' ),
			'fresh'       => self::count_bucket( 'fresh' ),
		);
	}

	private static function count_bucket( string $bucket ): int {
		global $wpdb;
		$post_types     = self::tracked_post_types();
		$placeholders   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$stale_cutoff   = gmdate( 'Y-m-d H:i:s', time() - self::STALE_DAYS * DAY_IN_SECONDS );
		$going_cutoff   = gmdate( 'Y-m-d H:i:s', time() - self::GOING_STALE_DAYS * DAY_IN_SECONDS );

		$where = "post_status = 'publish' AND post_type IN ($placeholders)";

		if ( 'stale' === $bucket ) {
			$where .= ' AND post_modified_gmt < %s';
			$args   = array_merge( $post_types, array( $stale_cutoff ) );
		} elseif ( 'going_stale' === $bucket ) {
			$where .= ' AND post_modified_gmt < %s AND post_modified_gmt >= %s';
			$args   = array_merge( $post_types, array( $going_cutoff, $stale_cutoff ) );
		} else {
			$where .= ' AND post_modified_gmt >= %s';
			$args   = array_merge( $post_types, array( $going_cutoff ) );
		}

		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function query_bucket( string $bucket ): array {
		$post_types   = self::tracked_post_types();
		$stale_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_DAYS * DAY_IN_SECONDS );
		$going_cutoff = gmdate( 'Y-m-d H:i:s', time() - self::GOING_STALE_DAYS * DAY_IN_SECONDS );

		$date_query = array();
		if ( 'stale' === $bucket ) {
			$date_query = array( array( 'column' => 'post_modified_gmt', 'before' => $stale_cutoff, 'inclusive' => false ) );
		} elseif ( 'going_stale' === $bucket ) {
			$date_query = array(
				'relation' => 'AND',
				array( 'column' => 'post_modified_gmt', 'before' => $stale_cutoff, 'inclusive' => false ),
				array( 'column' => 'post_modified_gmt', 'after'  => $going_cutoff, 'inclusive' => true ),
			);
		} else {
			$date_query = array( array( 'column' => 'post_modified_gmt', 'after' => $going_cutoff, 'inclusive' => true ) );
		}

		return (array) get_posts( array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'orderby'                => 'modified',
			'order'                  => 'ASC',
			'posts_per_page'         => self::LIST_LIMIT,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'date_query'             => $date_query,
		) );
	}

	private static function tracked_post_types(): array {
		$types = (array) get_option( RR_OPT_POST_TYPES, array( 'post' ) );
		// Always include page since RankReady runs for pages by default.
		if ( ! in_array( 'page', $types, true ) ) {
			$types[] = 'page';
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) );
	}

	private static function days_since_modified( WP_Post $p ): int {
		$ts = strtotime( $p->post_modified_gmt );
		if ( $ts <= 0 ) {
			return 0;
		}
		return (int) max( 0, floor( ( time() - $ts ) / DAY_IN_SECONDS ) );
	}
}
