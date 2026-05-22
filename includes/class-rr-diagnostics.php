<?php
/**
 * RankReady — Live Diagnostics
 *
 * Replaces the legacy "Health Check" with **real probes** that actually
 * fetch each endpoint and validate the response. Every failure ships with
 * a specific conflict diagnosis + one-line fix.
 *
 * Probe categories
 * ────────────────
 *   1. Endpoint reachability (llms.txt, llms-full.txt, robots.txt, mcp.json, .md routes)
 *   2. Environment conflict detection (page-cache plugin, page builder, SEO plugin)
 *   3. WordPress runtime (rewrite rules flushed, cron, DB tables, abilities API)
 *   4. LLM provider reachability (opt-in — costs an API call)
 *   5. Site configuration (Brand Identity filled, PHP / WP versions)
 *
 * Output shape per probe:
 *   [
 *     'id'     => string,          // stable identifier ('llms_txt')
 *     'label'  => string,          // human label ('/llms.txt loads')
 *     'status' => 'pass'|'warn'|'fail'|'info',
 *     'detail' => string,          // what we observed
 *     'fix'    => string|null,     // one-line action to take
 *     'meta'   => array|null,      // optional extras (url, http_code, body_len, etc.)
 *   ]
 *
 * Designed for: live page-load diagnostics + 1-click copy-to-clipboard
 * support report.
 *
 * @package RankReady
 * @since   1.2.0-rc.5
 */

defined( 'ABSPATH' ) || exit;

class RR_Diagnostics {

	/**
	 * REST namespace.
	 */
	const NS = 'rankready/v1';

	/**
	 * Default HTTP timeout per probe (seconds).
	 */
	const TIMEOUT = 8;

	/**
	 * Provider endpoints for reachability tests. Each value is a low-cost
	 * GET that just verifies the key — no completion is generated.
	 */
	const PROVIDER_PROBES = array(
		'openai'    => array(
			'url'    => 'https://api.openai.com/v1/models',
			'header' => 'Authorization: Bearer %s',
			'option' => 'rr_openai_api_key',
		),
		'anthropic' => array(
			'url'    => 'https://api.anthropic.com/v1/models',
			'header' => 'x-api-key: %s',
			'option' => 'rr_anthropic_api_key',
		),
		'gemini'    => array(
			'url'    => 'https://generativelanguage.googleapis.com/v1beta/models?key=%s',
			'header' => '',
			'option' => 'rr_gemini_api_key',
		),
		'deepseek'  => array(
			'url'    => 'https://api.deepseek.com/v1/models',
			'header' => 'Authorization: Bearer %s',
			'option' => 'rr_deepseek_api_key',
		),
	);

	/**
	 * Bootstrap REST endpoints.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/diagnostics', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'rest_run' ),
			'permission_callback' => array( self::class, 'permission_check' ),
			'args'                => array(
				'include_api' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'Include LLM provider reachability tests (costs 1 API call per provider).',
				),
			),
		) );

		register_rest_route( self::NS, '/diagnostics/report', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'rest_report' ),
			'permission_callback' => array( self::class, 'permission_check' ),
			'args'                => array(
				'include_api' => array( 'type' => 'boolean', 'default' => false ),
			),
		) );
	}

	public static function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// REST handlers
	// ─────────────────────────────────────────────────────────────────────────

	public static function rest_run( WP_REST_Request $request ) {
		$include_api = (bool) $request->get_param( 'include_api' );
		return rest_ensure_response( self::run( $include_api ) );
	}

	public static function rest_report( WP_REST_Request $request ) {
		$include_api = (bool) $request->get_param( 'include_api' );
		$result      = self::run( $include_api );

		$report = self::format_plaintext_report( $result );

		return rest_ensure_response( array(
			'report' => $report,
			'length' => strlen( $report ),
		) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Main orchestrator
	// ─────────────────────────────────────────────────────────────────────────

	public static function run( bool $include_api = false ): array {
		$checks = array();

		// Group 1 — Endpoint reachability
		$checks[] = self::probe_llms_txt();
		$checks[] = self::probe_llms_full_txt();
		$checks[] = self::probe_homepage_md();
		$checks[] = self::probe_post_md();
		$checks[] = self::probe_robots_txt();
		$checks[] = self::probe_mcp_manifest();

		// Group 2 — WordPress runtime
		$checks[] = self::probe_rewrite_rules();
		$checks[] = self::probe_wp_cron();
		$checks[] = self::probe_db_tables();
		$checks[] = self::probe_abilities_api();

		// Group 3 — Environment conflict detection
		$checks[] = self::probe_cache_plugin();
		$checks[] = self::probe_page_builder();
		$checks[] = self::probe_seo_plugin();
		$checks[] = self::probe_cache_constants();

		// Group 4 — Site configuration
		$checks[] = self::probe_brand_identity();
		$checks[] = self::probe_php_version();
		$checks[] = self::probe_wp_version();

		// Group 5 — LLM provider reachability (opt-in, costs API calls)
		if ( $include_api ) {
			$checks[] = self::probe_provider( 'openai' );
			$checks[] = self::probe_provider( 'anthropic' );
			$checks[] = self::probe_provider( 'gemini' );
			$checks[] = self::probe_provider( 'deepseek' );
			$checks[] = self::probe_dataforseo();
		} else {
			$checks[] = self::placeholder_provider();
		}

		// Roll up totals
		$totals = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 );
		foreach ( $checks as $c ) {
			if ( isset( $totals[ $c['status'] ] ) ) {
				$totals[ $c['status'] ]++;
			}
		}

		return array(
			'checks'       => $checks,
			'totals'       => $totals,
			'environment'  => self::get_environment(),
			'generated_at' => current_time( 'mysql', true ),
			'version'      => defined( 'RR_VERSION' ) ? RR_VERSION : 'unknown',
			'site_url'     => home_url(),
			'include_api'  => $include_api,
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Endpoint probes — actually fetch each URL
	// ═════════════════════════════════════════════════════════════════════════

	private static function probe_llms_txt(): array {
		if ( 'on' !== get_option( 'rr_llms_enable', 'off' ) ) {
			return self::result( 'llms_txt', '/llms.txt loads', 'info',
				'Toggle is OFF in AI Crawlers → LLMs.txt.',
				'Enable LLMs.txt to expose your site index to AI engines.'
			);
		}

		$url      = home_url( '/llms.txt' );
		$response = self::fetch( $url );

		if ( is_wp_error( $response ) ) {
			return self::result( 'llms_txt', '/llms.txt loads', 'fail',
				'HTTP request failed: ' . $response->get_error_message(),
				'Server can\'t reach itself via loopback. Check firewall / hosts file.',
				array( 'url' => $url )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 404 === $code ) {
			return self::result( 'llms_txt', '/llms.txt loads', 'fail',
				'HTTP 404 — endpoint not registered with WordPress.',
				'Visit Settings → Permalinks → Save (no changes). This re-flushes rewrite rules.',
				array( 'url' => $url, 'http_code' => 404 )
			);
		}

		if ( 200 !== $code ) {
			return self::result( 'llms_txt', '/llms.txt loads', 'fail',
				"HTTP $code returned.",
				'Check wp-content/debug.log for fatal errors during the request.',
				array( 'url' => $url, 'http_code' => $code )
			);
		}

		// Detect HTML interception
		if ( preg_match( '#<(html|body|head|!doctype)#i', substr( $body, 0, 500 ) ) ) {
			$builder = self::detect_active_page_builder();
			$cause   = $builder
				? "Page builder ($builder) is intercepting the request despite our priority 1 hook."
				: 'A theme template or plugin is intercepting the request.';

			return self::result( 'llms_txt', '/llms.txt loads', 'fail',
				'HTTP 200 but body is HTML, not plaintext.',
				$cause . ' Disable theme/builder temporarily to identify the conflict.',
				array( 'url' => $url, 'http_code' => 200, 'body_preview' => substr( $body, 0, 100 ) )
			);
		}

		if ( strlen( $body ) < 50 ) {
			return self::result( 'llms_txt', '/llms.txt loads', 'warn',
				'HTTP 200 but body is near-empty (' . strlen( $body ) . ' bytes).',
				'Fill Brand Identity in E-E-A-T tab — Site name + Summary populate llms.txt.',
				array( 'url' => $url, 'body_len' => strlen( $body ) )
			);
		}

		if ( false === strpos( substr( $body, 0, 200 ), '# ' ) ) {
			return self::result( 'llms_txt', '/llms.txt loads', 'warn',
				'HTTP 200 but missing H1 marker (# ). Format may be corrupted.',
				'Toggle AI Crawlers → LLMs.txt OFF then ON to regenerate.',
				array( 'url' => $url )
			);
		}

		return self::result( 'llms_txt', '/llms.txt loads', 'pass',
			sprintf( 'HTTP 200, %s bytes, valid LLMs.txt format.', number_format( strlen( $body ) ) ),
			null,
			array( 'url' => $url, 'body_len' => strlen( $body ) )
		);
	}

	private static function probe_llms_full_txt(): array {
		if ( 'on' !== get_option( 'rr_llms_enable', 'off' ) ) {
			return self::result( 'llms_full_txt', '/llms-full.txt loads', 'info',
				'Requires LLMs.txt to be enabled (it\'s OFF).',
				'Enable AI Crawlers → LLMs.txt first.'
			);
		}

		$url      = home_url( '/llms-full.txt' );
		$response = self::fetch( $url );

		if ( is_wp_error( $response ) ) {
			return self::result( 'llms_full_txt', '/llms-full.txt loads', 'fail',
				'HTTP request failed: ' . $response->get_error_message(),
				'Server can\'t reach itself via loopback.',
				array( 'url' => $url )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return self::result( 'llms_full_txt', '/llms-full.txt loads', 'fail',
				"HTTP $code.",
				404 === $code
					? 'Re-flush rewrite rules (Settings → Permalinks → Save).'
					: 'Check error log for fatals.',
				array( 'url' => $url, 'http_code' => $code )
			);
		}

		if ( preg_match( '#<(html|body|head)#i', substr( $body, 0, 500 ) ) ) {
			return self::result( 'llms_full_txt', '/llms-full.txt loads', 'fail',
				'HTTP 200 but HTML returned (intercepted).',
				'Page builder priority conflict. Disable theme to isolate.',
				array( 'url' => $url )
			);
		}

		if ( strlen( $body ) < 500 ) {
			return self::result( 'llms_full_txt', '/llms-full.txt loads', 'warn',
				'Body is short (' . strlen( $body ) . ' bytes) — likely no published posts yet.',
				'Publish at least one post to populate llms-full.txt.',
				array( 'url' => $url, 'body_len' => strlen( $body ) )
			);
		}

		return self::result( 'llms_full_txt', '/llms-full.txt loads', 'pass',
			sprintf( 'HTTP 200, %s bytes.', number_format( strlen( $body ) ) ),
			null,
			array( 'url' => $url, 'body_len' => strlen( $body ) )
		);
	}

	private static function probe_homepage_md(): array {
		if ( 'on' !== get_option( 'rr_md_enable', 'off' ) ) {
			return self::result( 'homepage_md', 'Homepage .md route', 'info',
				'Toggle is OFF in AI Crawlers → Markdown Endpoints.',
				'Enable Markdown Endpoints to serve .md versions of pages.'
			);
		}

		$url      = home_url( '/' );
		$response = self::fetch( $url, array( 'Accept' => 'text/markdown' ) );

		if ( is_wp_error( $response ) ) {
			return self::result( 'homepage_md', 'Homepage .md route', 'fail',
				'HTTP request failed: ' . $response->get_error_message(),
				'Loopback request blocked.',
				array( 'url' => $url )
			);
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body  = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return self::result( 'homepage_md', 'Homepage .md route', 'fail',
				"HTTP $code.",
				'Check rewrite rules.',
				array( 'url' => $url, 'http_code' => $code )
			);
		}

		// Either Content-Type negotiation worked, or the page builder won
		if ( false === stripos( $ctype, 'markdown' ) && preg_match( '#<(html|body|head)#i', substr( $body, 0, 200 ) ) ) {
			$builder = self::detect_active_page_builder();
			return self::result( 'homepage_md', 'Homepage .md route', 'fail',
				sprintf( 'HTTP 200 but Content-Type is %s (HTML returned).', $ctype ?: 'unknown' ),
				$builder
					? "Page builder ($builder) is rendering before our Markdown handler. RankReady uses priority 1; check for mu-plugins overriding template_redirect."
					: 'Theme template is winning. Add ?format=md to URL as a workaround.',
				array( 'url' => $url, 'content_type' => $ctype )
			);
		}

		return self::result( 'homepage_md', 'Homepage .md route', 'pass',
			sprintf( 'HTTP 200, Content-Type: %s, %s bytes.', $ctype, number_format( strlen( $body ) ) ),
			null,
			array( 'url' => $url, 'content_type' => $ctype )
		);
	}

	private static function probe_post_md(): array {
		if ( 'on' !== get_option( 'rr_md_enable', 'off' ) ) {
			return self::result( 'post_md', 'Post .md route', 'info',
				'Markdown Endpoints disabled.',
				'Enable AI Crawlers → Markdown Endpoints.'
			);
		}

		$posts = get_posts( array(
			'numberposts' => 1,
			'post_status' => 'publish',
			'post_type'   => 'post',
			'fields'      => 'ids',
		) );

		if ( empty( $posts ) ) {
			return self::result( 'post_md', 'Post .md route', 'info',
				'No published posts to test.',
				'Publish at least one post.'
			);
		}

		$post_url = get_permalink( $posts[0] );
		$response = self::fetch( $post_url, array( 'Accept' => 'text/markdown' ) );

		if ( is_wp_error( $response ) ) {
			return self::result( 'post_md', 'Post .md route', 'fail',
				'Loopback failed: ' . $response->get_error_message(),
				'Loopback request blocked.',
				array( 'url' => $post_url )
			);
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );
		$body  = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return self::result( 'post_md', 'Post .md route', 'fail',
				"HTTP $code for $post_url.",
				'Check rewrite rules.',
				array( 'url' => $post_url, 'http_code' => $code )
			);
		}

		if ( false === stripos( $ctype, 'markdown' ) && preg_match( '#<(html|body|head)#i', substr( $body, 0, 200 ) ) ) {
			return self::result( 'post_md', 'Post .md route', 'fail',
				'HTML returned instead of Markdown.',
				'Page builder intercepting. Check template_redirect priority conflicts.',
				array( 'url' => $post_url, 'content_type' => $ctype )
			);
		}

		return self::result( 'post_md', 'Post .md route', 'pass',
			sprintf( 'HTTP 200, %s, %s bytes.', $ctype, number_format( strlen( $body ) ) ),
			null,
			array( 'url' => $post_url, 'content_type' => $ctype )
		);
	}

	private static function probe_robots_txt(): array {
		$url      = home_url( '/robots.txt' );
		$response = self::fetch( $url );

		if ( is_wp_error( $response ) ) {
			return self::result( 'robots_txt', '/robots.txt has RankReady block', 'fail',
				'Loopback failed: ' . $response->get_error_message(),
				'Server can\'t reach itself.',
				array( 'url' => $url )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return self::result( 'robots_txt', '/robots.txt has RankReady block', 'fail',
				"HTTP $code.",
				'A physical robots.txt file may be blocking WordPress.',
				array( 'url' => $url, 'http_code' => $code )
			);
		}

		$robots_enabled = (bool) get_option( 'rr_robots_enable', false );

		if ( ! $robots_enabled ) {
			return self::result( 'robots_txt', '/robots.txt has RankReady block', 'info',
				'AI Crawler robots.txt toggle is OFF.',
				'Enable AI Crawlers → LLM Crawler Access to inject the bot block.'
			);
		}

		$has_marker  = false !== strpos( $body, '# BEGIN RankReady' );
		$has_agent   = false !== strpos( $body, 'User-agent:' );

		if ( ! $has_marker ) {
			return self::result( 'robots_txt', '/robots.txt has RankReady block', 'fail',
				'RankReady block missing from /robots.txt response.',
				'Another plugin is filtering robots_txt at lower priority. RankReady uses priority 99. Clear all SEO plugin caches.',
				array( 'url' => $url )
			);
		}

		if ( ! $has_agent ) {
			return self::result( 'robots_txt', '/robots.txt has RankReady block', 'warn',
				'RankReady block present but no User-agent directives.',
				'Re-save AI Crawlers → LLM Crawler Access to regenerate.',
				array( 'url' => $url )
			);
		}

		return self::result( 'robots_txt', '/robots.txt has RankReady block', 'pass',
			'RankReady block present with crawler directives.',
			null,
			array( 'url' => $url, 'body_len' => strlen( $body ) )
		);
	}

	private static function probe_mcp_manifest(): array {
		if ( 'on' !== get_option( 'rr_mcp_enable', 'off' ) ) {
			return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'info',
				'WebMCP toggle is OFF.',
				'Enable AI Crawlers → WebMCP Manifest to expose 16 abilities to Claude/Cursor/VS Code.'
			);
		}

		$url      = home_url( '/.well-known/mcp.json' );
		$response = self::fetch( $url );

		if ( is_wp_error( $response ) ) {
			return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'fail',
				'Loopback failed: ' . $response->get_error_message(),
				'Loopback blocked.',
				array( 'url' => $url )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 503 === $code ) {
			return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'warn',
				'HTTP 503 (Service Unavailable) — MCP toggle is OFF at request time.',
				'Confirm AI Crawlers → WebMCP toggle is actually saved as ON.',
				array( 'url' => $url, 'http_code' => 503 )
			);
		}

		if ( 200 !== $code ) {
			return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'fail',
				"HTTP $code.",
				'Re-flush rewrite rules.',
				array( 'url' => $url, 'http_code' => $code )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['name'] ) ) {
			return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'fail',
				'HTTP 200 but JSON is invalid or missing "name" key.',
				'Toggle WebMCP off/on to regenerate manifest.',
				array( 'url' => $url )
			);
		}

		$ability_count = isset( $decoded['abilities'] ) && is_array( $decoded['abilities'] )
			? count( $decoded['abilities'] )
			: 0;

		return self::result( 'mcp_manifest', '/.well-known/mcp.json loads', 'pass',
			sprintf( 'HTTP 200, valid JSON, %d abilities exposed.', $ability_count ),
			null,
			array( 'url' => $url, 'abilities' => $ability_count )
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// WordPress runtime probes
	// ═════════════════════════════════════════════════════════════════════════

	private static function probe_rewrite_rules(): array {
		$rules = get_option( 'rewrite_rules', array() );

		if ( empty( $rules ) ) {
			return self::result( 'rewrite_rules', 'Rewrite rules flushed', 'warn',
				'WordPress rewrite_rules option is empty.',
				'Visit Settings → Permalinks → Save Changes.'
			);
		}

		$rules_string = is_array( $rules ) ? implode( "\n", array_keys( $rules ) ) : (string) $rules;
		$has_llms     = false !== strpos( $rules_string, 'llms' );
		$has_md       = false !== strpos( $rules_string, '\\.md' ) || false !== strpos( $rules_string, '.md' );

		if ( ! $has_llms && 'on' === get_option( 'rr_llms_enable', 'off' ) ) {
			return self::result( 'rewrite_rules', 'Rewrite rules flushed', 'fail',
				'LLMs.txt enabled but rewrite rule missing.',
				'Settings → Permalinks → Save (no changes) — re-flushes rules.'
			);
		}

		return self::result( 'rewrite_rules', 'Rewrite rules flushed', 'pass',
			sprintf( '%d rewrite rules registered.', is_array( $rules ) ? count( $rules ) : 0 ),
			null
		);
	}

	private static function probe_wp_cron(): array {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return self::result( 'wp_cron', 'WP Cron available', 'warn',
				'DISABLE_WP_CRON constant is true.',
				'Set up real cron at server level (crontab -e: */15 * * * * wget -q -O - https://YOURSITE/wp-cron.php) or freshness scans won\'t run.'
			);
		}

		$cron = _get_cron_array();
		$count = is_array( $cron ) ? count( $cron ) : 0;

		return self::result( 'wp_cron', 'WP Cron available', 'pass',
			sprintf( '%d cron events scheduled.', $count ),
			null
		);
	}

	private static function probe_db_tables(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'rr_crawler_log';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( ! $exists ) {
			return self::result( 'db_tables', 'Crawler log DB table', 'fail',
				"Table {$table} is missing.",
				'Deactivate then reactivate RankReady — tables are created on activation.'
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		return self::result( 'db_tables', 'Crawler log DB table', 'pass',
			sprintf( 'Table exists, %s rows logged.', number_format( $row_count ) ),
			null,
			array( 'table' => $table, 'rows' => $row_count )
		);
	}

	private static function probe_abilities_api(): array {
		if ( function_exists( 'wp_register_ability' ) ) {
			return self::result( 'abilities_api', 'WP Abilities API (6.9+)', 'pass',
				'wp_register_ability() is available — MCP uses native registration.',
				null
			);
		}

		return self::result( 'abilities_api', 'WP Abilities API (6.9+)', 'info',
			'WP Abilities API not loaded (requires WP 6.9+ or Abilities plugin).',
			'Optional. RankReady ships its own MCP manifest as a fallback — no action needed.'
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Environment conflict detection
	// ═════════════════════════════════════════════════════════════════════════

	private static function probe_cache_plugin(): array {
		$detected = self::detect_active_cache_plugins();

		if ( empty( $detected ) ) {
			return self::result( 'cache_plugin', 'Page cache plugin', 'info',
				'No known page-cache plugin detected.',
				null
			);
		}

		// RR_Cache::exclude_url_patterns() ships exclusions for these. Verify the filter ran.
		$known_with_bypass = array(
			'LiteSpeed Cache' => 'LITESPEED_VERSION',
			'WP Rocket'       => 'WP_ROCKET_VERSION',
			'W3 Total Cache'  => 'W3TC',
			'WP Super Cache'  => 'WPSC_VERSION',
			'Autoptimize'     => 'AUTOPTIMIZE_PLUGIN_DIR',
			'WP Fastest Cache'=> 'WPFC_WP_PLUGIN_DIR',
			'SG Optimizer'    => 'SiteGround_Optimizer\\VERSION',
			'Hummingbird'     => 'WPHB_VERSION',
		);

		$reports = array();
		foreach ( $detected as $name ) {
			$reports[] = $name . ' — RankReady bypass rules applied';
		}

		return self::result( 'cache_plugin', 'Page cache plugin', 'pass',
			implode( '; ', $reports ),
			null,
			array( 'detected' => $detected )
		);
	}

	private static function probe_page_builder(): array {
		$builder = self::detect_active_page_builder();

		if ( ! $builder ) {
			return self::result( 'page_builder', 'Page builder', 'info',
				'No major page builder detected.',
				null
			);
		}

		return self::result( 'page_builder', 'Page builder', 'pass',
			"$builder detected — RankReady uses template_redirect priority 1 to beat default builder hooks.",
			null,
			array( 'builder' => $builder )
		);
	}

	private static function probe_seo_plugin(): array {
		$plugin = '';
		if ( defined( 'RANK_MATH_VERSION' ) )         $plugin = 'Rank Math ' . RANK_MATH_VERSION;
		elseif ( defined( 'WPSEO_VERSION' ) )         $plugin = 'Yoast SEO ' . WPSEO_VERSION;
		elseif ( defined( 'AIOSEO_VERSION' ) )        $plugin = 'All in One SEO ' . AIOSEO_VERSION;
		elseif ( defined( 'SEOPRESS_VERSION' ) )      $plugin = 'SEOPress ' . SEOPRESS_VERSION;
		elseif ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) $plugin = 'The SEO Framework ' . THE_SEO_FRAMEWORK_VERSION;

		if ( '' === $plugin ) {
			return self::result( 'seo_plugin', 'SEO plugin', 'info',
				'No SEO plugin detected — RankReady will emit standalone Article + Speakable schema.',
				null
			);
		}

		return self::result( 'seo_plugin', 'SEO plugin', 'pass',
			"$plugin detected. Schema merging active, double meta-robots tag prevented.",
			null,
			array( 'plugin' => $plugin )
		);
	}

	private static function probe_cache_constants(): array {
		$states = array();
		$states[] = 'WP_CACHE = ' . ( defined( 'WP_CACHE' ) && WP_CACHE ? 'true' : 'false' );
		$states[] = 'DONOTCACHEPAGE = ' . ( defined( 'DONOTCACHEPAGE' ) ? 'defined' : '(unset)' );
		$states[] = 'DISABLE_WP_CRON = ' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'true' : 'false' );

		return self::result( 'cache_constants', 'Cache constants', 'info',
			implode( '; ', $states ),
			null,
			array( 'constants' => $states )
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Site configuration probes
	// ═════════════════════════════════════════════════════════════════════════

	private static function probe_brand_identity(): array {
		$name    = trim( (string) get_option( 'rr_brand_name', '' ) );
		$summary = trim( (string) get_option( 'rr_brand_summary', '' ) );
		$about   = trim( (string) get_option( 'rr_brand_about', '' ) );

		$missing = array();
		if ( '' === $name )    $missing[] = 'Site / brand name';
		if ( '' === $summary ) $missing[] = 'One-line summary';
		if ( '' === $about )   $missing[] = 'About';

		if ( empty( $missing ) ) {
			return self::result( 'brand_identity', 'Brand Identity filled', 'pass',
				'All 3 core fields are populated (name, summary, about).',
				null
			);
		}

		return self::result( 'brand_identity', 'Brand Identity filled', 'warn',
			'Missing: ' . implode( ', ', $missing ) . '.',
			'Fill in E-E-A-T → Brand Identity. These feed llms.txt, FAQ prompts, AI summaries, MCP abilities.',
			array( 'missing' => $missing )
		);
	}

	private static function probe_php_version(): array {
		$v  = PHP_VERSION;
		$ok = version_compare( $v, '7.4', '>=' );

		return self::result( 'php_version', 'PHP version', $ok ? 'pass' : 'fail',
			"PHP $v",
			$ok ? null : 'Upgrade to PHP 8.0+ for performance and security.'
		);
	}

	private static function probe_wp_version(): array {
		global $wp_version;
		$ok = version_compare( $wp_version, '6.0', '>=' );

		return self::result( 'wp_version', 'WordPress version', $ok ? 'pass' : 'fail',
			"WordPress $wp_version",
			$ok ? null : 'Upgrade WordPress to 6.0+.'
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// LLM Provider reachability (opt-in — costs an API call each)
	// ═════════════════════════════════════════════════════════════════════════

	private static function placeholder_provider(): array {
		return self::result( 'provider_keys', 'LLM provider keys', 'info',
			'Not tested — click "Test API keys" to verify reachability (uses 1 API call per provider).',
			null
		);
	}

	private static function probe_provider( string $provider ): array {
		$cfg = self::PROVIDER_PROBES[ $provider ] ?? null;
		if ( ! $cfg ) {
			return self::result( "provider_$provider", "Provider: $provider", 'fail',
				'Unknown provider.', null );
		}

		$key = trim( (string) get_option( $cfg['option'], '' ) );

		if ( '' === $key ) {
			return self::result( "provider_$provider", "Provider: $provider", 'info',
				'No API key configured.',
				"Add key in Settings → AI Provider → $provider."
			);
		}

		$url     = sprintf( $cfg['url'], $key );
		$headers = array();
		if ( ! empty( $cfg['header'] ) ) {
			$parts = explode( ': ', sprintf( $cfg['header'], $key ), 2 );
			if ( 2 === count( $parts ) ) {
				$headers[ $parts[0] ] = $parts[1];
			}
		}

		// Anthropic also needs a version header
		if ( 'anthropic' === $provider ) {
			$headers['anthropic-version'] = '2023-06-01';
		}

		$response = wp_remote_get( $url, array(
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		) );

		if ( is_wp_error( $response ) ) {
			return self::result( "provider_$provider", "Provider: $provider", 'fail',
				'Network error: ' . $response->get_error_message(),
				'Server can\'t reach provider. Check firewall / outbound HTTPS.'
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code || 201 === $code ) {
			return self::result( "provider_$provider", "Provider: $provider", 'pass',
				"HTTP $code — key valid and provider reachable.",
				null
			);
		}

		if ( 401 === $code || 403 === $code ) {
			return self::result( "provider_$provider", "Provider: $provider", 'fail',
				"HTTP $code — invalid API key.",
				'Regenerate key from provider dashboard and re-save in Settings.'
			);
		}

		if ( 429 === $code ) {
			return self::result( "provider_$provider", "Provider: $provider", 'warn',
				"HTTP 429 — rate limited.",
				'Wait a minute and retest. Key is valid.'
			);
		}

		return self::result( "provider_$provider", "Provider: $provider", 'warn',
			"HTTP $code returned (unexpected).",
			'Provider may be having an outage. Retest later.'
		);
	}

	private static function probe_dataforseo(): array {
		$login    = trim( (string) get_option( 'rr_dataforseo_login', '' ) );
		$password = trim( (string) get_option( 'rr_dataforseo_password', '' ) );

		if ( '' === $login || '' === $password ) {
			return self::result( 'provider_dataforseo', 'Provider: DataForSEO', 'info',
				'No credentials configured.',
				'Add login + password in Settings → DataForSEO (optional — only for FAQ question discovery).'
			);
		}

		$response = wp_remote_get( 'https://api.dataforseo.com/v3/appendix/user_data', array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( "$login:$password" ),
			),
		) );

		if ( is_wp_error( $response ) ) {
			return self::result( 'provider_dataforseo', 'Provider: DataForSEO', 'fail',
				'Network error: ' . $response->get_error_message(),
				'Check outbound HTTPS to api.dataforseo.com.'
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return self::result( 'provider_dataforseo', 'Provider: DataForSEO', 'pass',
				'HTTP 200 — credentials valid.',
				null
			);
		}

		if ( 401 === $code ) {
			return self::result( 'provider_dataforseo', 'Provider: DataForSEO', 'fail',
				'HTTP 401 — invalid credentials.',
				'Verify login + password from DataForSEO dashboard.'
			);
		}

		return self::result( 'provider_dataforseo', 'Provider: DataForSEO', 'warn',
			"HTTP $code (unexpected).",
			'Retest later.'
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Helpers
	// ═════════════════════════════════════════════════════════════════════════

	private static function fetch( string $url, array $headers = array() ) {
		return wp_remote_get( $url, array(
			'timeout'     => self::TIMEOUT,
			'sslverify'   => false, // Loopback often hits self-signed certs in dev
			'redirection' => 2,
			'headers'     => array_merge( array(
				'User-Agent' => 'RankReady-Diagnostics/' . ( defined( 'RR_VERSION' ) ? RR_VERSION : '1.0' ),
			), $headers ),
		) );
	}

	private static function result( string $id, string $label, string $status, string $detail, ?string $fix, array $meta = array() ): array {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => in_array( $status, array( 'pass', 'warn', 'fail', 'info' ), true ) ? $status : 'info',
			'detail' => $detail,
			'fix'    => $fix,
			'meta'   => $meta ?: null,
		);
	}

	public static function detect_active_page_builder(): string {
		if ( defined( 'BRICKS_VERSION' ) )                  return 'Bricks Builder ' . BRICKS_VERSION;
		if ( defined( 'ELEMENTOR_VERSION' ) )               return 'Elementor ' . ELEMENTOR_VERSION;
		if ( defined( 'OXY_VERSION' ) || defined( 'CT_VERSION' ) ) return 'Oxygen Builder';
		if ( defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_CORE_VERSION' ) ) return 'Divi / ET Builder';
		if ( defined( 'BREAKDANCE_VERSION' ) )              return 'Breakdance';
		if ( defined( 'BB_PLUGIN_VERSION' ) )               return 'Beaver Builder';
		return '';
	}

	public static function detect_active_cache_plugins(): array {
		$found = array();
		if ( defined( 'LITESPEED_VERSION' ) || defined( 'LSCWP_V' ) ) $found[] = 'LiteSpeed Cache';
		if ( defined( 'WP_ROCKET_VERSION' ) )                          $found[] = 'WP Rocket';
		if ( defined( 'W3TC' ) )                                       $found[] = 'W3 Total Cache';
		if ( defined( 'WPSC_VERSION' ) || function_exists( 'wpsc_init' ) ) $found[] = 'WP Super Cache';
		if ( defined( 'AUTOPTIMIZE_PLUGIN_DIR' ) )                     $found[] = 'Autoptimize';
		if ( defined( 'WPFC_WP_PLUGIN_DIR' ) )                         $found[] = 'WP Fastest Cache';
		if ( class_exists( 'SiteGround_Optimizer\\Loader\\Loader' ) )  $found[] = 'SG Optimizer';
		if ( defined( 'WPHB_VERSION' ) )                               $found[] = 'Hummingbird';
		if ( defined( 'NITROPACK_VERSION' ) )                          $found[] = 'NitroPack';
		if ( defined( 'PERFMATTERS_VERSION' ) )                        $found[] = 'Perfmatters';
		return $found;
	}

	public static function get_environment(): array {
		global $wp_version, $wpdb;

		$active_plugins = array();
		$plugins        = (array) get_option( 'active_plugins', array() );
		foreach ( $plugins as $plugin_file ) {
			$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( file_exists( $plugin_path ) ) {
				$data = get_file_data( $plugin_path, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
				$active_plugins[] = $data['Name'] . ' ' . $data['Version'];
			} else {
				$active_plugins[] = $plugin_file;
			}
		}

		$theme = wp_get_theme();

		return array(
			'rankready_version' => defined( 'RR_VERSION' ) ? RR_VERSION : 'unknown',
			'wordpress_version' => $wp_version,
			'php_version'       => PHP_VERSION,
			'mysql_version'     => $wpdb->db_version(),
			'site_url'          => home_url(),
			'theme'             => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'multisite'         => is_multisite(),
			'is_https'          => is_ssl(),
			'active_plugins'    => $active_plugins,
		);
	}

	// ═════════════════════════════════════════════════════════════════════════
	// Plaintext report formatter — for 1-click copy-to-clipboard
	// ═════════════════════════════════════════════════════════════════════════

	public static function format_plaintext_report( array $result ): string {
		$env    = $result['environment'];
		$checks = $result['checks'];
		$totals = $result['totals'];

		$lines   = array();
		$lines[] = '=== RankReady Diagnostic Report ===';
		$lines[] = 'Generated: ' . $result['generated_at'] . ' UTC';
		$lines[] = 'Site: ' . $result['site_url'];
		$lines[] = 'RankReady: ' . $result['version'];
		$lines[] = 'WordPress: ' . $env['wordpress_version'] . '  •  PHP: ' . $env['php_version'] . '  •  MySQL: ' . $env['mysql_version'];
		$lines[] = 'Theme: ' . $env['theme'];
		$lines[] = sprintf( 'Multisite: %s  •  HTTPS: %s', $env['multisite'] ? 'yes' : 'no', $env['is_https'] ? 'yes' : 'no' );
		$lines[] = '';
		$lines[] = sprintf( 'Summary: %d pass · %d warn · %d fail · %d info',
			$totals['pass'], $totals['warn'], $totals['fail'], $totals['info']
		);
		$lines[] = '';

		foreach ( $checks as $c ) {
			$label = strtoupper( $c['status'] );
			$lines[] = sprintf( '[%s] %s — %s', $label, $c['label'], $c['detail'] );
			if ( ! empty( $c['fix'] ) ) {
				$lines[] = '       → ' . $c['fix'];
			}
		}

		$lines[] = '';
		$lines[] = 'Active plugins (' . count( $env['active_plugins'] ) . '):';
		foreach ( $env['active_plugins'] as $p ) {
			$lines[] = '  • ' . $p;
		}
		$lines[] = '';
		$lines[] = '=== End Report ===';

		return implode( "\n", $lines );
	}
}

// Bootstrap is invoked from rankready.php in plugins_loaded.
