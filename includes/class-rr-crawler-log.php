<?php
/**
 * AI Crawler Access Log — tracks bot hits to llms.txt, .md endpoints,
 * resolves each hit to a WordPress post/CPT, and surfaces organized stats
 * in the AI Crawlers admin tab.
 *
 * DB schema v3 removes the user_agent column (redundant — bot_name captures
 * the identity in human-readable form) and enforces a hard row cap (50 000)
 * plus a 30-day retention window so the table never clutters the database.
 *
 * Migration path:
 *   v1 → v2: dbDelta adds post_id, post_type, post_title.
 *   v2 → v3: dbDelta is a no-op (no new columns); ALTER TABLE drops user_agent.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Crawler_Log {

	const DB_VERSION_KEY = 'rr_crawler_log_db_version';
	const DB_VERSION     = 3;
	const RETENTION_DAYS = 30;   // rows older than 30 days are pruned daily
	const MAX_ROWS       = 50000; // hard cap — oldest rows deleted when exceeded

	// ── Known bots (most-specific first) ─────────────────────────────────

	const BOTS = array(
		'GPTBot'               => 'GPTBot (OpenAI)',
		'ChatGPT-User'         => 'ChatGPT-User (OpenAI)',
		'OAI-SearchBot'        => 'OAI-SearchBot (OpenAI)',
		'ClaudeBot'            => 'ClaudeBot (Anthropic)',
		'Claude-Web'           => 'Claude-Web (Anthropic)',
		'anthropic-ai'         => 'Anthropic AI',
		'Google-Extended'      => 'Google-Extended (Gemini training)',
		'PerplexityBot'        => 'PerplexityBot',
		'cohere-ai'            => 'Cohere AI',
		'AI2Bot'               => 'AI2Bot (Allen Institute)',
		'Bytespider'           => 'Bytespider (ByteDance)',
		'FacebookBot'          => 'FacebookBot (Meta)',
		'Meta-ExternalAgent'   => 'Meta-ExternalAgent',
		'Meta-ExternalFetcher' => 'Meta-ExternalFetcher',
		'YouBot'               => 'YouBot (You.com)',
		'DuckAssistBot'        => 'DuckAssistBot (DuckDuckGo)',
		'Diffbot'              => 'Diffbot',
		'Applebot-Extended'    => 'Applebot-Extended (Apple)',
		'Applebot'             => 'Applebot (Apple)',
		'CCBot'                => 'CCBot (Common Crawl)',
		'omgili'               => 'Omgili',
		'Timpibot'             => 'Timpibot',
		'ImagesiftBot'         => 'ImagesiftBot',
		'magpie-crawler'       => 'Magpie (Brave)',
		'Amazonbot'            => 'Amazonbot (Amazon)',
	);

	const ENDPOINT_LABELS = array(
		'llms_txt'  => 'llms.txt',
		'llms_full' => 'llms-full.txt',
		'markdown'  => '.md URL',
		'home_md'   => 'Homepage .md',
	);

	// ── Bootstrap ─────────────────────────────────────────────────────────

	public static function init(): void {
		$stored = (int) get_option( self::DB_VERSION_KEY, 0 );

		if ( $stored < self::DB_VERSION ) {
			self::create_table();

			// v2 → v3: drop the redundant user_agent column if it still exists.
			// dbDelta cannot remove columns, so we use ALTER TABLE explicitly.
			if ( $stored < 3 ) {
				self::drop_user_agent_column();
			}
		}

		if ( ! wp_next_scheduled( 'rr_crawler_log_prune' ) ) {
			wp_schedule_event( time(), 'daily', 'rr_crawler_log_prune' );
		}
		add_action( 'rr_crawler_log_prune', array( self::class, 'prune' ) );
	}

	// ── Table management ───────────────────────────────────────────────────

	public static function create_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'rr_crawler_log';
		$charset = $wpdb->get_charset_collate();

		// user_agent is intentionally absent (removed in v3 — redundant storage).
		// dbDelta is idempotent: safe to run on fresh installs and existing tables.
		$sql = "CREATE TABLE {$table} (
			id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at  datetime     NOT NULL,
			bot_name   varchar(120) NOT NULL DEFAULT '',
			url_path   varchar(500) NOT NULL DEFAULT '',
			endpoint   varchar(20)  NOT NULL DEFAULT '',
			post_id    bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type  varchar(50)  NOT NULL DEFAULT '',
			post_title varchar(250) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY idx_bot       (bot_name(60)),
			KEY idx_date      (logged_at),
			KEY idx_endpoint  (endpoint),
			KEY idx_post_type (post_type(30))
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * v2 → v3 migration: drop user_agent if it exists.
	 * Runs once via init() when upgrading from a v2 install.
	 */
	private static function drop_user_agent_column(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'user_agent'" );
		if ( ! empty( $exists ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN user_agent" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function drop_table(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'rr_crawler_log' );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::DB_VERSION_KEY );
	}

	// ── Bot detection ──────────────────────────────────────────────────────

	public static function detect_bot(): string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		if ( empty( $ua ) ) {
			return '';
		}
		foreach ( self::BOTS as $pattern => $label ) {
			if ( false !== stripos( $ua, $pattern ) ) {
				return $label;
			}
		}
		return '';
	}

	// ── Logging ────────────────────────────────────────────────────────────

	/**
	 * Record one bot hit. Pass the resolved WP_Post so we store the exact
	 * piece of content the crawler read — title, ID, and post type (CPT).
	 *
	 * @param string        $endpoint  'llms_txt' | 'llms_full' | 'markdown' | 'home_md'
	 * @param WP_Post|null  $post      Resolved post object (null for llms.txt / homepage).
	 */
	public static function log( string $endpoint, ?WP_Post $post = null ): void {
		$bot = self::detect_bot();
		if ( '' === $bot ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		// Resolve post metadata.
		$post_id    = 0;
		$post_type  = '';
		$post_title = '';

		if ( $post instanceof WP_Post ) {
			$post_id    = (int) $post->ID;
			$post_type  = $post->post_type;
			$post_title = $post->post_title;
		} elseif ( 'home_md' === $endpoint ) {
			$post_type  = 'homepage';
			$post_title = get_bloginfo( 'name' );
		}
		// llms_txt / llms_full: virtual files, no post — fields stay empty.

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'rr_crawler_log',
			array(
				'logged_at'  => current_time( 'mysql' ),
				'bot_name'   => $bot,
				'url_path'   => substr( $uri, 0, 500 ),
				'endpoint'   => $endpoint,
				'post_id'    => $post_id,
				'post_type'  => $post_type,
				'post_title' => substr( $post_title, 0, 250 ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	// ── Stat queries ───────────────────────────────────────────────────────

	/**
	 * Per-bot totals with endpoint breakdown and unique page count.
	 */
	public static function get_bot_stats( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					bot_name,
					COUNT(*)                             AS total,
					MAX(logged_at)                       AS last_seen,
					SUM(endpoint = 'llms_txt')           AS llms_txt,
					SUM(endpoint = 'llms_full')          AS llms_full,
					SUM(endpoint = 'markdown')           AS markdown,
					SUM(endpoint = 'home_md')            AS home_md,
					COUNT(DISTINCT NULLIF(post_id, 0))   AS unique_pages
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 GROUP BY bot_name
				 ORDER BY total DESC",
				$days
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Top 5 pages/posts a specific bot accessed most — used for per-bot expand.
	 */
	public static function get_bot_top_pages( string $bot_name, int $days = 30, int $limit = 5 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, post_type, post_title, url_path, COUNT(*) AS total
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				   AND bot_name = %s
				   AND (post_id > 0 OR post_type IN ('homepage'))
				 GROUP BY post_id, post_type, post_title
				 ORDER BY total DESC
				 LIMIT %d",
				$days,
				$bot_name,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Hits grouped by WordPress post type (CPT breakdown).
	 */
	public static function get_cpt_stats( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_type, COUNT(*) AS total, COUNT(DISTINCT post_id) AS unique_posts
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				   AND post_type != ''
				 GROUP BY post_type
				 ORDER BY total DESC",
				$days
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Top individual pages/posts with which bots read them.
	 */
	public static function get_top_pages( int $days = 30, int $limit = 15 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					post_id,
					post_type,
					post_title,
					url_path,
					COUNT(*)                           AS total,
					COUNT(DISTINCT bot_name)           AS unique_bots,
					GROUP_CONCAT(
						DISTINCT bot_name
						ORDER BY bot_name
						SEPARATOR '|'
					)                                  AS bots_csv
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				   AND (post_id > 0 OR post_type = 'homepage')
				 GROUP BY post_id, post_type, post_title
				 ORDER BY total DESC
				 LIMIT %d",
				$days,
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Endpoint-level totals (llms.txt vs markdown etc.) — for summary strip.
	 */
	public static function get_endpoint_totals( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT endpoint, COUNT(*) AS total
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 GROUP BY endpoint",
				$days
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array( 'llms_txt' => 0, 'llms_full' => 0, 'markdown' => 0, 'home_md' => 0 );
		foreach ( $rows as $r ) {
			if ( isset( $out[ $r['endpoint'] ] ) ) {
				$out[ $r['endpoint'] ] = (int) $r['total'];
			}
		}
		return $out;
	}

	/**
	 * Recent individual hits for the live log table.
	 */
	public static function get_recent_hits( int $limit = 40 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT logged_at, bot_name, endpoint, post_type, post_title, url_path
				 FROM {$table}
				 ORDER BY logged_at DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Total hits — used for dashboard stat card. */
	public static function get_total( int $days = 30 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Total unique pages crawled. */
	public static function get_unique_pages( int $days = 30 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY) AND post_id > 0",
				$days
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ── Maintenance ────────────────────────────────────────────────────────

	/**
	 * Daily pruning — two passes:
	 * 1. Delete rows older than RETENTION_DAYS (time-based expiry).
	 * 2. If total rows still exceed MAX_ROWS, delete the oldest excess rows
	 *    (hard cap against runaway crawlers on high-traffic sites).
	 */
	public static function prune(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';

		// Pass 1: time-based expiry.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE logged_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				self::RETENTION_DAYS
			)
		);

		// Pass 2: hard row cap — delete oldest rows beyond MAX_ROWS.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $total > self::MAX_ROWS ) {
			$excess = $total - self::MAX_ROWS;
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} ORDER BY logged_at ASC LIMIT %d",
					$excess
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
