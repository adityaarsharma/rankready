<?php
/**
 * AI Crawler Access Log — tracks bot hits to llms.txt, .md, and markdown
 * Accept-header endpoints and surfaces stats in the admin dashboard.
 *
 * Only known AI/LLM bots are logged — regular browsers and unrecognised
 * user-agents are silently skipped. Records are kept for 90 days and then
 * pruned automatically via a daily WP-Cron task.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Crawler_Log {

	/** wp_options key that stores the installed DB schema version. */
	const DB_VERSION_KEY = 'rr_crawler_log_db_version';

	/** Current schema version — bump if the table structure changes. */
	const DB_VERSION = 1;

	/** How many days of records to keep. */
	const RETENTION_DAYS = 90;

	// ── Known AI / LLM bots ────────────────────────────────────────────────
	// Pattern (substring match, case-insensitive) => display label.
	// Ordered most-specific first so GPTBot is matched before a generic "bot".

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

	// ── Endpoint labels ────────────────────────────────────────────────────

	const ENDPOINT_LABELS = array(
		'llms_txt'  => 'llms.txt',
		'llms_full' => 'llms-full.txt',
		'markdown'  => '.md URL',
		'home_md'   => 'Homepage .md',
	);

	// ── Bootstrap ─────────────────────────────────────────────────────────

	public static function init(): void {
		// Ensure table exists on every request (cheap version-check guard).
		if ( (int) get_option( self::DB_VERSION_KEY, 0 ) < self::DB_VERSION ) {
			self::create_table();
		}

		// Daily cleanup cron.
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

		// dbDelta is safe to call repeatedly — it only creates or alters.
		$sql = "CREATE TABLE {$table} (
			id         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			logged_at  datetime NOT NULL,
			bot_name   varchar(120) NOT NULL DEFAULT '',
			user_agent varchar(500) NOT NULL DEFAULT '',
			url_path   varchar(500) NOT NULL DEFAULT '',
			endpoint   varchar(20)  NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY idx_bot      (bot_name(60)),
			KEY idx_date     (logged_at),
			KEY idx_endpoint (endpoint)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	public static function drop_table(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'rr_crawler_log' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		delete_option( self::DB_VERSION_KEY );
	}

	// ── Bot detection ──────────────────────────────────────────────────────

	/**
	 * Check the current request's User-Agent against the known-bot list.
	 *
	 * @return string Display label, or empty string if not a known bot.
	 */
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
	 * Record one bot hit if the current User-Agent is a known AI crawler.
	 *
	 * @param string $endpoint  'llms_txt' | 'llms_full' | 'markdown' | 'home_md'
	 */
	public static function log( string $endpoint ): void {
		$bot = self::detect_bot();
		if ( '' === $bot ) {
			return;
		}

		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'rr_crawler_log',
			array(
				'logged_at'  => current_time( 'mysql' ),
				'bot_name'   => $bot,
				'user_agent' => substr( $ua, 0, 500 ),
				'url_path'   => substr( $uri, 0, 500 ),
				'endpoint'   => $endpoint,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	// ── Stats queries ──────────────────────────────────────────────────────

	/**
	 * Per-bot totals for the given window.
	 *
	 * @param  int   $days  Look-back window in days (default 30).
	 * @return array        Rows: bot_name, total, last_seen, + per-endpoint counts.
	 */
	public static function get_bot_stats( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					bot_name,
					COUNT(*)                           AS total,
					MAX(logged_at)                     AS last_seen,
					SUM(endpoint = 'llms_txt')         AS llms_txt,
					SUM(endpoint = 'llms_full')        AS llms_full,
					SUM(endpoint = 'markdown')         AS markdown,
					SUM(endpoint = 'home_md')          AS home_md
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 GROUP BY bot_name
				 ORDER BY total DESC",
				$days
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows ?: array();
	}

	/**
	 * Daily hit counts for a sparkline / chart.
	 *
	 * @param  int   $days
	 * @return array  Rows: day (Y-m-d), total.
	 */
	public static function get_daily_counts( int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(logged_at) AS day, COUNT(*) AS total
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 GROUP BY DATE(logged_at)
				 ORDER BY day ASC",
				$days
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows ?: array();
	}

	/**
	 * Most-accessed URLs (helps identify which content AI bots read most).
	 *
	 * @param  int   $days
	 * @param  int   $limit
	 * @return array  Rows: url_path, total, endpoint.
	 */
	public static function get_top_urls( int $days = 30, int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url_path, endpoint, COUNT(*) AS total
				 FROM {$table}
				 WHERE logged_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
				 GROUP BY url_path, endpoint
				 ORDER BY total DESC
				 LIMIT %d",
				$days,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows ?: array();
	}

	/**
	 * Recent individual hits for the live-log table.
	 *
	 * @param  int   $limit
	 * @return array  Rows: logged_at, bot_name, endpoint, url_path.
	 */
	public static function get_recent_hits( int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT logged_at, bot_name, endpoint, url_path
				 FROM {$table}
				 ORDER BY logged_at DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows ?: array();
	}

	/**
	 * Total hits in the given window — used for the dashboard stat card.
	 *
	 * @param  int $days
	 * @return int
	 */
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

	// ── Maintenance ────────────────────────────────────────────────────────

	/**
	 * Delete records older than RETENTION_DAYS. Called by daily WP-Cron.
	 */
	public static function prune(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rr_crawler_log';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE logged_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				self::RETENTION_DAYS
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
