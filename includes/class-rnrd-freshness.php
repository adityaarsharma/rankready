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

class RNRD_Freshness {

	private const NS              = 'rankready/v1';
	private const STALE_DAYS      = 60;
	private const GOING_STALE_DAYS = 30;
	private const LIST_LIMIT      = 50;

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		// Note: dashboard widget registration handled by RNRD_Agent_Dashboard
		// — single consolidated "Agent Visibility" widget replaces the two
		// separate widgets shipped in beta.1.
	}

	// ── Dashboard widget panel (rendered inside the unified widget) ──────

	public static function render_widget(): void {
		$counts = self::bucket_counts();
		$nonce  = wp_create_nonce( 'wp_rest' );
		$api    = esc_url_raw( rest_url( self::NS ) );
		?>
		<div class="rnrd-freshness-widget" data-rnrd-api="<?php echo esc_attr( $api ); ?>" data-rnrd-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php // FREE-103 — unified .rnrd-kpi chrome so freshness buckets match every other Insights tab. ?>
			<div class="rnrd-kpi-row rnrd-fw-tabs" role="radiogroup" aria-label="<?php esc_attr_e( 'Freshness bucket filter', 'rankready-ai-llm-seo' ); ?>">
				<button type="button" class="rnrd-kpi rnrd-fw-tab is-active" data-bucket="stale" role="radio" aria-checked="true">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Stale', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( '60+ days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( (string) $counts['stale'] ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'Refresh these first — they are losing AI citations.', 'rankready-ai-llm-seo' ); ?></div>
				</button>
				<button type="button" class="rnrd-kpi rnrd-fw-tab" data-bucket="going_stale" role="radio" aria-checked="false">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Going stale', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( '30–59 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( (string) $counts['going_stale'] ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'Plan a refresh before they drop out of AI answers.', 'rankready-ai-llm-seo' ); ?></div>
				</button>
				<button type="button" class="rnrd-kpi rnrd-fw-tab" data-bucket="fresh" role="radio" aria-checked="false">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Fresh', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'under 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( (string) $counts['fresh'] ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'Prioritised by ChatGPT, Perplexity, and Gemini today.', 'rankready-ai-llm-seo' ); ?></div>
				</button>
			</div>

			<div class="rnrd-fw-toolbar">
				<label class="rnrd-fw-toolbar__select">
					<input type="checkbox" class="rnrd-fw-select-all" />
					<?php esc_html_e( 'Select all', 'rankready-ai-llm-seo' ); ?>
				</label>
				<button type="button" class="button button-primary rnrd-fw-refresh" disabled>
					<?php esc_html_e( 'Refresh dateModified', 'rankready-ai-llm-seo' ); ?>
				</button>
			</div>

			<div class="rnrd-fw-list">
				<p class="rnrd-fw-empty"><?php esc_html_e( 'Loading…', 'rankready-ai-llm-seo' ); ?></p>
			</div>

			<p class="rnrd-fw-status"></p>
		</div>
		<?php
		// FREE-100 — JS handler relocated to assets/admin.js (initFreshnessWidget).
		// Inline <script> echoes are banned by WP.org Rule #3 and the old
		// inline-style red color (#d63638) violated the mint brand palette.
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
			return new WP_Error( 'rnrd_forbidden', __( 'Insufficient permissions.', 'rankready-ai-llm-seo' ), array( 'status' => 403 ) );
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
			return new WP_Error( 'rnrd_no_ids', __( 'No post IDs supplied.', 'rankready-ai-llm-seo' ), array( 'status' => 400 ) );
		}

		$now_mysql     = current_time( 'mysql' );
		$now_mysql_gmt = current_time( 'mysql', true );

		$refreshed = 0;
		$skipped   = 0;  // capability denied
		$failed    = 0;  // wp_update_post returned WP_Error

		// v1.2.0-beta.4 — save/restore the previous value instead of blanket
		// reset to false. Two concurrent admin requests sharing a PHP-FPM
		// worker would otherwise tear down each other's re-entrancy guard.
		// (Audit beta.3 #10.)
		$prev_generating = class_exists( 'RNRD_Generator' ) ? RNRD_Generator::$generating : null;
		if ( null !== $prev_generating ) {
			RNRD_Generator::$generating = true;
		}

		try {
			foreach ( $post_ids as $post_id ) {
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					$skipped++;
					continue;
				}
				$result = wp_update_post( array(
					'ID'                => $post_id,
					'post_modified'     => $now_mysql,
					'post_modified_gmt' => $now_mysql_gmt,
				), true );
				if ( is_wp_error( $result ) ) {
					$failed++;
				} else {
					$refreshed++;
				}
			}
		} finally {
			if ( null !== $prev_generating ) {
				RNRD_Generator::$generating = $prev_generating;
			}
		}

		// Honest counts so the JS UI doesn't claim N refreshed when N were skipped.
		return new WP_REST_Response( array(
			'refreshed' => $refreshed,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'requested' => count( $post_ids ),
		), 200 );
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

		// FREE-102 — $where is built from constants + placeholders only, no user input.
		// $wpdb->posts is a WP core table name. Real-time count over fresh-window;
		// caching would defeat the purpose (UI shows live "stale today" totals).
		$sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
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
		$types = (array) get_option( RNRD_OPT_POST_TYPES, array( 'post' ) );
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
