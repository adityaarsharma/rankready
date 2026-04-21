<?php
/**
 * Cache Layer Compatibility — bypass headers and purge operations.
 *
 * Covers every major WordPress cache plugin and CDN/proxy layer so
 * RankReady's content-negotiated endpoints (markdown, llms.txt, homepage)
 * are never served stale by an intermediate cache.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 *
 * A single Cache-Control header is not enough. Each cache layer reads a
 * different header and has its own bypass logic:
 *
 *   CDN / edge layer (intercepts before origin server):
 *     Cloudflare APO       → cf-edge-cache: no-cache
 *                            APO ignores CDN-Cache-Control entirely. This is
 *                            the ONLY header that reliably bypasses APO from PHP.
 *     Cloudflare non-APO   → CDN-Cache-Control: no-store
 *     BunnyCDN             → CDN-Cache-Control: no-store
 *     Varnish / Fastly     → Surrogate-Control: no-store
 *     Akamai               → Edge-Control: no-store
 *
 *   Server-side proxy / FastCGI cache (nginx / Apache module):
 *     nginx FastCGI cache  → X-Accel-Expires: 0  (0 = bypass entirely)
 *     Nginx Helper plugin  → rt_nginx_helper_purge_url action
 *
 *   PHP page-cache plugins (write cached HTML files or serve from Redis):
 *     WP Rocket            → DONOTCACHEPAGE constant
 *     W3 Total Cache       → DONOTCACHEPAGE + DONOTCACHEOBJECT + DONOTCACHEDB
 *     LiteSpeed Cache      → LSCWP_NO_CACHE constant (separate from DONOTCACHEPAGE)
 *     WP Super Cache       → DONOTCACHEPAGE constant
 *     WP Fastest Cache     → WpFastestCache::deleteCache()
 *     Breeze (Cloudways)   → breeze_clear_all_cache action
 *     SG Optimizer         → sg_cachepress_purge_cache function
 *     Hummingbird          → wphb_clear_cache_url action
 *     Comet Cache          → comet_cache::clear()
 *     Cache Enabler        → cache_enabler_clear_page_cache_by_post action
 *     Swift Performance    → swift_performance_after_clear_all_cache action
 *
 *   Hosting-level edge cache (Pantheon, WP Engine, Kinsta):
 *     Pantheon             → pantheon_clear_edge_paths function
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Cache {

	// ── Bypass headers ────────────────────────────────────────────────────────

	/**
	 * Emit response headers that instruct every known cache layer not to store
	 * the current response.
	 *
	 * Call this early (before any output) on every RankReady virtual endpoint
	 * that uses content negotiation so the correct variant (markdown vs HTML)
	 * always reaches the client.
	 *
	 * Safe to call multiple times — headers_sent() and defined() guards
	 * prevent duplicate headers and constant re-declaration errors.
	 */
	public static function no_cache_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		// ── CDN / reverse-proxy ──────────────────────────────────────────────

		// Cloudflare APO: the canonical APO bypass directive.
		// CDN-Cache-Control alone does NOT stop APO — APO ignores it.
		// cf-edge-cache: no-cache is the only response header APO reads.
		header( 'cf-edge-cache: no-cache' );

		// Cloudflare non-APO, BunnyCDN, and generic CDN proxy layer.
		header( 'CDN-Cache-Control: no-store' );

		// Varnish, Fastly, and any surrogate cache.
		header( 'Surrogate-Control: no-store' );

		// Akamai edge cache directive.
		header( 'Edge-Control: no-store' );

		// ── Server / FastCGI ─────────────────────────────────────────────────

		// nginx FastCGI cache — 0 means do not cache this response at all.
		header( 'X-Accel-Expires: 0' );

		// ── HTTP standard ────────────────────────────────────────────────────

		// Any HTTP/1.1 intermediate proxy not matched above + client browser.
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );

		// HTTP/1.0 proxy compatibility (still needed for some CDN edge nodes).
		header( 'Pragma: no-cache' );

		// ── PHP page-cache plugin constants ──────────────────────────────────

		// All major WP page-cache plugins respect DONOTCACHEPAGE as an early
		// bail-out: WP Rocket, W3TC, WP Super Cache, WP Fastest Cache,
		// Cache Enabler, Comet Cache, Breeze, SG Optimizer.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// W3 Total Cache: separate constants for object cache and DB cache.
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}

		// LiteSpeed Cache: uses its own constant, does not check DONOTCACHEPAGE.
		if ( ! defined( 'LSCWP_NO_CACHE' ) ) {
			define( 'LSCWP_NO_CACHE', true );
		}
	}

	// ── Cache purge ───────────────────────────────────────────────────────────

	/**
	 * Purge a single URL from every active cache layer.
	 *
	 * Uses function_exists / class_exists guards before every call — safe on
	 * any WordPress install regardless of which plugins are active.
	 * Unknown plugins / hosting environments are silently skipped.
	 *
	 * @param string $url Absolute URL to purge (e.g. home_url('/robots.txt')).
	 */
	public static function purge_url( string $url ): void {
		// WP Rocket — precise single-file purge.
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( [ $url ] );
		}

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_url', $url );

		// W3 Total Cache.
		do_action( 'w3tc_flush_url', $url );

		// WP Super Cache — no per-URL API; clears the full site cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// WP Fastest Cache.
		if ( class_exists( 'WpFastestCache' ) && method_exists( 'WpFastestCache', 'deleteCache' ) ) {
			( new \WpFastestCache() )->deleteCache( true );
		}

		// Cloudflare official WP plugin (non-APO; APO has no PHP purge API).
		do_action( 'cloudflare_purge_by_url', [ $url ] );

		// Nginx Helper / FastCGI cache.
		do_action( 'rt_nginx_helper_purge_url', $url );

		// SG Optimizer (SiteGround).
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		// Breeze (Cloudways).
		do_action( 'breeze_clear_all_cache' );

		// Hummingbird (WPMU Dev).
		do_action( 'wphb_clear_cache_url', $url );

		// Cache Enabler — needs a post ID.
		$post_id = url_to_postid( $url );
		if ( $post_id > 0 ) {
			do_action( 'cache_enabler_clear_page_cache_by_post', $post_id );
		}

		// Comet Cache.
		if ( class_exists( 'comet_cache' ) && method_exists( 'comet_cache', 'clear' ) ) {
			\comet_cache::clear();
		}

		// Swift Performance.
		do_action( 'swift_performance_after_clear_all_cache' );

		// Autoptimize — handles CSS/JS asset cache, relevant when URL changes
		// invalidate inlined or combined assets.
		do_action( 'autoptimize_action_cachepurged' );

		// Pantheon / Pressable hosting edge cache.
		if ( function_exists( 'pantheon_clear_edge_paths' ) ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( '' !== $path ) {
				pantheon_clear_edge_paths( [ $path ] );
			}
		}
	}

	/**
	 * Nuclear purge — clears ALL caches site-wide.
	 *
	 * Use on plugin activation / full-settings-save when multiple virtual
	 * endpoints may have changed simultaneously. Prefer purge_url() for
	 * targeted invalidation (e.g. a single robots.txt update).
	 */
	public static function purge_all(): void {
		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// LiteSpeed Cache.
		do_action( 'litespeed_purge_all' );

		// W3 Total Cache.
		do_action( 'w3tc_flush_all' );

		// Breeze.
		do_action( 'breeze_clear_all_cache' );

		// SG Optimizer.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		// Hummingbird.
		do_action( 'wphb_clear_cache' );

		// Comet Cache.
		if ( class_exists( 'comet_cache' ) && method_exists( 'comet_cache', 'clear' ) ) {
			\comet_cache::clear();
		}

		// Swift Performance.
		do_action( 'swift_performance_after_clear_all_cache' );
	}

	// ── Detection ─────────────────────────────────────────────────────────────

	/**
	 * Detect which page-cache plugins are currently active.
	 *
	 * Returns an array of slug => human-readable label.
	 * Used by the health-check dashboard to show the current cache environment
	 * so admins can verify RankReady's bypass is correctly configured.
	 *
	 * @return array<string, string>
	 */
	public static function detect_active(): array {
		$active = array();

		if ( defined( 'WP_ROCKET_VERSION' ) )                                              $active['wp-rocket']      = 'WP Rocket';
		if ( defined( 'LSCWP_V' ) )                                                        $active['litespeed']      = 'LiteSpeed Cache';
		if ( defined( 'W3TC_VERSION' ) )                                                   $active['w3tc']           = 'W3 Total Cache';
		if ( defined( 'WPCACHEHOME' ) )                                                    $active['wp-super-cache'] = 'WP Super Cache';
		if ( class_exists( 'WpFastestCache' ) )                                            $active['wp-fastest']     = 'WP Fastest Cache';
		if ( defined( 'SG_OPTIMIZER_DIR' ) )                                               $active['sg-optimizer']   = 'SG Optimizer';
		if ( defined( 'BREEZE_VERSION' ) )                                                 $active['breeze']         = 'Breeze';
		if ( class_exists( 'Hummingbird\\WP_Hummingbird' ) )                               $active['hummingbird']    = 'Hummingbird';
		if ( class_exists( 'autoptimizeCache' ) )                                          $active['autoptimize']    = 'Autoptimize';
		if ( defined( 'CE_FILE' ) )                                                        $active['cache-enabler']  = 'Cache Enabler';
		if ( class_exists( 'comet_cache' ) )                                               $active['comet-cache']    = 'Comet Cache';
		if ( class_exists( 'Swift_Performance_Lite' ) || class_exists( 'Swift_Performance' ) ) $active['swift']     = 'Swift Performance';
		if ( function_exists( 'pantheon_clear_edge_paths' ) )                              $active['pantheon']       = 'Pantheon Edge';

		return $active;
	}
}
