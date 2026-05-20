<?php
/**
 * RankReady — Content Gap Scanner.
 *
 * Admin page that surfaces every published post missing one or more of the
 * AI-readiness signals RankReady generates:
 *   - AI Summary  (RR_META_SUMMARY)
 *   - FAQ         (RR_META_FAQ)
 *   - Freshness   (post_modified within 60 days)
 *
 * Each row has a per-signal "Generate" button that fires the existing
 * single-post endpoints (no new generators). Bulk "Fix all" hands off to
 * the existing bulk endpoints (bulk_start / faq_bulk_start / freshness/refresh).
 *
 * Lives under: WP Admin → RankReady → Content Gaps
 *
 * REST endpoints:
 *   GET  /rankready/v1/gaps/scan?post_type=post&filter=any|missing_summary|missing_faq|stale&page=1
 *
 * Schema gap detection deferred — RankReady ships schema automatically when
 * the relevant feature is enabled; "missing schema" is a function of feature
 * toggles, not per-post state.
 *
 * @package RankReady
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

class RR_Gap_Scanner {

	private const NS         = 'rankready/v1';
	private const PER_PAGE   = 25;
	private const STALE_DAYS = 60;

	public static function init(): void {
		add_action( 'admin_menu',    array( self::class, 'register_menu' ), 20 );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'rankready',
			__( 'Content Gaps', 'rankready' ),
			__( 'Content Gaps', 'rankready' ),
			'edit_others_posts',
			'rankready-gaps',
			array( self::class, 'render_page' )
		);
	}

	public static function render_page(): void {
		$post_types = self::available_post_types();
		$nonce      = wp_create_nonce( 'wp_rest' );
		$api        = esc_url_raw( rest_url( self::NS ) );
		?>
		<div class="wrap rr-gap-scanner" data-rr-api="<?php echo esc_attr( $api ); ?>" data-rr-nonce="<?php echo esc_attr( $nonce ); ?>">
			<h1><?php esc_html_e( 'Content Gaps', 'rankready' ); ?></h1>
			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'Posts missing AI summaries, FAQs, or freshness signals. Click Generate on any row to fill the gap. AI engines need these signals to cite your content — fix the most-trafficked posts first.', 'rankready' ); ?>
			</p>

			<div class="rr-gap-toolbar" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:14px 0;background:#fff;border:1px solid #c3c4c7;padding:10px 12px;border-radius:4px;">
				<label style="font-size:13px;">
					<?php esc_html_e( 'Post type:', 'rankready' ); ?>
					<select class="rr-gap-pt" style="margin-left:4px;">
						<?php foreach ( $post_types as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label style="font-size:13px;">
					<?php esc_html_e( 'Filter:', 'rankready' ); ?>
					<select class="rr-gap-filter" style="margin-left:4px;">
						<option value="any"><?php esc_html_e( 'Any gap', 'rankready' ); ?></option>
						<option value="missing_summary"><?php esc_html_e( 'Missing AI summary', 'rankready' ); ?></option>
						<option value="missing_faq"><?php esc_html_e( 'Missing FAQ', 'rankready' ); ?></option>
						<option value="stale"><?php esc_html_e( 'Stale (60+ days)', 'rankready' ); ?></option>
					</select>
				</label>
				<span class="rr-gap-counts" style="font-size:12px;color:#646970;margin-left:auto;"></span>
			</div>

			<table class="wp-list-table widefat striped rr-gap-table">
				<thead>
					<tr>
						<th style="width:50%;"><?php esc_html_e( 'Post', 'rankready' ); ?></th>
						<th style="width:13%;"><?php esc_html_e( 'Summary', 'rankready' ); ?></th>
						<th style="width:13%;"><?php esc_html_e( 'FAQ', 'rankready' ); ?></th>
						<th style="width:13%;"><?php esc_html_e( 'Freshness', 'rankready' ); ?></th>
						<th style="width:11%;"><?php esc_html_e( 'Actions', 'rankready' ); ?></th>
					</tr>
				</thead>
				<tbody class="rr-gap-rows">
					<tr><td colspan="5" style="padding:20px;color:#646970;font-style:italic;"><?php esc_html_e( 'Loading…', 'rankready' ); ?></td></tr>
				</tbody>
			</table>

			<div class="rr-gap-pagination" style="margin-top:12px;text-align:right;"></div>
		</div>

		<script>
		(function() {
			var root      = document.querySelector( '.rr-gap-scanner' );
			if ( ! root ) return;
			var api       = root.getAttribute( 'data-rr-api' );
			var nonce     = root.getAttribute( 'data-rr-nonce' );
			var ptSel     = root.querySelector( '.rr-gap-pt' );
			var filterSel = root.querySelector( '.rr-gap-filter' );
			var rows      = root.querySelector( '.rr-gap-rows' );
			var counts    = root.querySelector( '.rr-gap-counts' );
			var pager     = root.querySelector( '.rr-gap-pagination' );
			var page      = 1;

			function badge( ok, label ) {
				if ( ok ) {
					return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#d1ecdf;color:#0a6c39;font-size:11px;font-weight:600;">' + label + '</span>';
				}
				return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;background:#fcebe6;color:#a72e1f;font-size:11px;font-weight:600;">Missing</span>';
			}

			function freshnessBadge( days ) {
				if ( days === null ) return badge( false, '' );
				if ( days < 30 ) return '<span style="color:#0a6c39;font-weight:600;font-size:12px;">' + days + 'd</span>';
				if ( days < 60 ) return '<span style="color:#dba617;font-weight:600;font-size:12px;">' + days + 'd</span>';
				return '<span style="color:#a72e1f;font-weight:600;font-size:12px;">' + days + 'd</span>';
			}

			function rowHtml( p ) {
				var actions = [];
				if ( ! p.has_summary ) {
					actions.push( '<button type="button" class="button button-small rr-gap-gen" data-target="summary" data-id="' + p.id + '">Summary</button>' );
				}
				if ( ! p.has_faq ) {
					actions.push( '<button type="button" class="button button-small rr-gap-gen" data-target="faq" data-id="' + p.id + '">FAQ</button>' );
				}
				if ( p.is_stale ) {
					actions.push( '<button type="button" class="button button-small rr-gap-refresh" data-id="' + p.id + '">Refresh</button>' );
				}
				if ( ! actions.length ) {
					actions.push( '<span style="color:#646970;font-size:11px;">All good</span>' );
				}

				return '<tr data-id="' + p.id + '">'
				     + '<td><strong><a href="' + p.edit + '" target="_blank">' + p.title + '</a></strong></td>'
				     + '<td>' + ( p.has_summary ? badge( true, 'Yes' ) : badge( false, 'Missing' ) ) + '</td>'
				     + '<td>' + ( p.has_faq ? badge( true, 'Yes' ) : badge( false, 'Missing' ) ) + '</td>'
				     + '<td>' + freshnessBadge( p.modified_age ) + '</td>'
				     + '<td style="white-space:nowrap;">' + actions.join( ' ' ) + '</td>'
				     + '</tr>';
			}

			function load() {
				rows.innerHTML = '<tr><td colspan="5" style="padding:20px;color:#646970;font-style:italic;">Loading…</td></tr>';
				var url = api + '/gaps/scan?post_type=' + encodeURIComponent( ptSel.value )
				        + '&filter=' + encodeURIComponent( filterSel.value )
				        + '&page=' + page;
				fetch( url, {
					headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' },
					credentials: 'same-origin'
				} ).then( function( r ) { return r.json(); } ).then( function( data ) {
					if ( ! data || ! data.posts ) {
						rows.innerHTML = '<tr><td colspan="5" style="padding:20px;color:#a72e1f;">Failed to load.</td></tr>';
						return;
					}
					if ( ! data.posts.length ) {
						var emptyMsg = filterSel.value === 'any'
							? '🎯 No gaps found. Every published post has a summary, an FAQ, and is fresher than 60 days.'
							: ( filterSel.value === 'missing_summary'
								? '✓ Every post has an AI summary. Switch to "Missing FAQ" or "Stale" to find other gaps.'
								: ( filterSel.value === 'missing_faq'
									? '✓ Every post has an FAQ. Switch to another filter to find other gaps.'
									: '✓ No stale posts. All published content is within 60 days.' ) );
						rows.innerHTML = '<tr><td colspan="5" style="padding:24px;color:var(--rr-color-text-muted,#646970);text-align:center;font-size:13px;">' + emptyMsg + '</td></tr>';
					} else {
						rows.innerHTML = data.posts.map( rowHtml ).join( '' );
					}
					counts.textContent = data.total + ' total · page ' + data.page + ' of ' + data.pages;

					var pHtml = '';
					if ( data.pages > 1 ) {
						pHtml += '<button type="button" class="button button-small rr-gap-prev"' + ( data.page <= 1 ? ' disabled' : '' ) + '>« Prev</button> ';
						pHtml += '<span style="margin:0 8px;">' + data.page + ' / ' + data.pages + '</span>';
						pHtml += '<button type="button" class="button button-small rr-gap-next"' + ( data.page >= data.pages ? ' disabled' : '' ) + '>Next »</button>';
					}
					pager.innerHTML = pHtml;
				} ).catch( function() {
					rows.innerHTML = '<tr><td colspan="5" style="padding:20px;color:#a72e1f;">Network error.</td></tr>';
				} );
			}

			ptSel.addEventListener( 'change', function() { page = 1; load(); } );
			filterSel.addEventListener( 'change', function() { page = 1; load(); } );

			pager.addEventListener( 'click', function( e ) {
				if ( e.target.classList.contains( 'rr-gap-prev' ) ) { page = Math.max( 1, page - 1 ); load(); }
				if ( e.target.classList.contains( 'rr-gap-next' ) ) { page = page + 1; load(); }
			} );

			rows.addEventListener( 'click', function( e ) {
				var btn = e.target;
				if ( btn.classList.contains( 'rr-gap-gen' ) ) {
					var id = btn.getAttribute( 'data-id' );
					var target = btn.getAttribute( 'data-target' );
					btn.disabled = true; btn.textContent = '…';
					var endpoint = target === 'faq' ? '/faq/generate/' + id : '/regenerate/' + id;
					fetch( api + endpoint, {
						method: 'POST',
						headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' },
						credentials: 'same-origin'
					} ).then( function( r ) { return r.json(); } ).then( function( data ) {
						if ( data && ( data.success || data.summary || data.faq ) ) {
							btn.textContent = '✓';
							setTimeout( load, 600 );
						} else {
							btn.textContent = 'Failed';
							btn.disabled = false;
						}
					} ).catch( function() {
						btn.textContent = 'Failed'; btn.disabled = false;
					} );
				} else if ( btn.classList.contains( 'rr-gap-refresh' ) ) {
					var rid = parseInt( btn.getAttribute( 'data-id' ), 10 );
					btn.disabled = true; btn.textContent = '…';
					fetch( api + '/freshness/refresh', {
						method: 'POST',
						headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json', 'Accept': 'application/json' },
						credentials: 'same-origin',
						body: JSON.stringify( { post_ids: [ rid ] } )
					} ).then( function( r ) { return r.json(); } ).then( function() {
						btn.textContent = '✓';
						setTimeout( load, 400 );
					} ).catch( function() {
						btn.textContent = 'Failed'; btn.disabled = false;
					} );
				}
			} );

			load();
		})();
		</script>
		<?php
	}

	// ── REST ──────────────────────────────────────────────────────────────

	public static function register_routes(): void {
		register_rest_route( self::NS, '/gaps/scan', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'rest_scan' ),
			'permission_callback' => array( self::class, 'permission' ),
			'args'                => array(
				'post_type' => array( 'type' => 'string', 'default' => 'post', 'sanitize_callback' => 'sanitize_key' ),
				'filter'    => array( 'type' => 'string', 'default' => 'any',  'sanitize_callback' => 'sanitize_key' ),
				'page'      => array( 'type' => 'integer', 'default' => 1,     'sanitize_callback' => 'absint' ),
			),
		) );
	}

	public static function permission() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error( 'rr_forbidden', __( 'Insufficient permissions.', 'rankready' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_scan( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$filter    = sanitize_key( (string) $request->get_param( 'filter' ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) );

		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}
		if ( ! in_array( $filter, array( 'any', 'missing_summary', 'missing_faq', 'stale' ), true ) ) {
			$filter = 'any';
		}

		$args = array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => self::PER_PAGE,
			'paged'                  => $page,
			'orderby'                => 'modified',
			'order'                  => 'ASC',
			'update_post_term_cache' => false,
			'no_found_rows'          => false,
		);

		if ( 'missing_summary' === $filter ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => RR_META_SUMMARY, 'compare' => 'NOT EXISTS' ),
				array( 'key' => RR_META_SUMMARY, 'value' => '', 'compare' => '=' ),
			);
		} elseif ( 'missing_faq' === $filter ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => RR_META_FAQ, 'compare' => 'NOT EXISTS' ),
				array( 'key' => RR_META_FAQ, 'value' => '', 'compare' => '=' ),
			);
		} elseif ( 'stale' === $filter ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_DAYS * DAY_IN_SECONDS );
			$args['date_query'] = array(
				array( 'column' => 'post_modified_gmt', 'before' => $cutoff, 'inclusive' => false ),
			);
		}

		$query = new WP_Query( $args );
		$posts = array();

		foreach ( $query->posts as $p ) {
			$has_summary = ! empty( get_post_meta( $p->ID, RR_META_SUMMARY, true ) );
			$has_faq     = ! empty( get_post_meta( $p->ID, RR_META_FAQ, true ) );
			$mod_ts      = strtotime( $p->post_modified_gmt );
			$age_days    = $mod_ts > 0 ? (int) max( 0, floor( ( time() - $mod_ts ) / DAY_IN_SECONDS ) ) : null;
			$is_stale    = ( null !== $age_days && $age_days >= self::STALE_DAYS );

			// "any gap" filter — only include posts that have at least one gap.
			if ( 'any' === $filter && $has_summary && $has_faq && ! $is_stale ) {
				continue;
			}

			$posts[] = array(
				'id'            => $p->ID,
				'title'         => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'edit'          => get_edit_post_link( $p->ID, 'raw' ),
				'has_summary'   => $has_summary,
				'has_faq'       => $has_faq,
				'modified_age'  => $age_days,
				'is_stale'      => $is_stale,
			);
		}

		return new WP_REST_Response( array(
			'posts'  => $posts,
			'total'  => (int) $query->found_posts,
			'page'   => $page,
			'pages'  => max( 1, (int) $query->max_num_pages ),
			'filter' => $filter,
		), 200 );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private static function available_post_types(): array {
		$types  = (array) get_option( RR_OPT_POST_TYPES, array( 'post' ) );
		$result = array();
		foreach ( $types as $slug ) {
			$obj = get_post_type_object( $slug );
			if ( $obj ) {
				$result[ $slug ] = $obj->labels->name;
			}
		}
		if ( empty( $result ) ) {
			$result['post'] = __( 'Posts', 'rankready' );
		}
		return $result;
	}
}
