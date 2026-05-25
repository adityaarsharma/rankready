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

class RNRD_Cache {

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
	/**
	 * Tell every page-cache plugin to never cache the listed URL patterns.
	 *
	 * Unlike no_cache_headers() — which forces no-store on a single response —
	 * this method hooks each cache plugin's "reject URI" filter so the URLs
	 * are excluded BEFORE they hit the disk cache. Use for endpoints that
	 * RankReady caches itself (llms.txt has its own transient + Cache-Control:
	 * public, max-age=3600) and where the WP page-cache layer would otherwise
	 * stomp the response.
	 *
	 * Page builders like Bricks also leave these endpoints alone because the
	 * template_redirect handlers (registered at priority 1 in v1.2.0-rc.2)
	 * exit() before the theme runs.
	 *
	 * @param string[] $patterns Path prefixes ('/llms.txt', '/llms-full.txt', '/.well-known/mcp.json') or regex fragments.
	 * @since 1.2.0-rc.2
	 */
	public static function exclude_url_patterns( array $patterns ): void {
		$patterns = array_values( array_filter( array_map( 'strval', $patterns ) ) );
		if ( empty( $patterns ) ) {
			return;
		}

		// LiteSpeed Cache — runtime filter (covers in-process requests).
		// NOTE: runtime filter alone is insufficient — LSWS server-level
		// LSCACHE module reads the persisted `litespeed.conf.cache-exc` option
		// via .htaccess BEFORE PHP runs. persist_litespeed_exclusions() handles
		// the persisted side. See rc.16 audit C2.
		add_filter( 'litespeed_excluded_url', function ( $existing ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $existing, $patterns ) ) );
		} );
		// Defense in depth — LiteSpeed also reads a control filter.
		add_action( 'litespeed_control_set_nocache', function ( $reason = '' ) {
			// no-op — just gives LS a signal RankReady is in charge.
		} );

		// WP Rocket — reject URI regex array.
		add_filter( 'rocket_cache_reject_uri', function ( $existing ) use ( $patterns ) {
			$regex = array_map( function ( $p ) { return '(' . preg_quote( $p, '/' ) . ')'; }, $patterns );
			return array_values( array_unique( array_merge( (array) $existing, $regex ) ) );
		} );

		// W3 Total Cache — page cache reject URI array.
		add_filter( 'w3tc_pagecache_reject_uri', function ( $existing ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $existing, $patterns ) ) );
		} );

		// WP Super Cache — uri reject patterns.
		// rc.16 audit fix H2: the previous global-mutation approach only
		// affected request-local state; WPSC reads `cache_rejected_uri` from
		// its persisted config BEFORE PHP runs on cached pages. We now both
		// (a) mutate the global as a runtime defense for the current request
		// and (b) persist to WPSC's settings via persist_wpsc_exclusions().
		add_filter( 'wp_cache_get_cookies_values', function ( $string ) use ( $patterns ) {
			global $cache_rejected_uri;
			if ( is_array( $cache_rejected_uri ) ) {
				$cache_rejected_uri = array_values( array_unique( array_merge( $cache_rejected_uri, $patterns ) ) );
			}
			return $string;
		} );

		// WP Fastest Cache — rule array.
		add_filter( 'wpfc_exclude_url', function ( $excluded ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $excluded, $patterns ) ) );
		} );

		// SG Optimizer — uses `sg_optimizer_dynamic_cache_excluded_urls`.
		add_filter( 'sg_optimizer_dynamic_cache_excluded_urls', function ( $excluded ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $excluded, $patterns ) ) );
		} );

		// Breeze (Cloudways) — reject URI option filter.
		add_filter( 'breeze_rules_cache_excluded_url', function ( $excluded ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $excluded, $patterns ) ) );
		} );

		// Cache Enabler — also reads URI exclusion.
		add_filter( 'cache_enabler_bypass_cache', function ( $bypass ) use ( $patterns ) {
			if ( $bypass ) {
				return $bypass;
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			foreach ( $patterns as $p ) {
				if ( false !== strpos( $uri, $p ) ) {
					return true;
				}
			}
			return $bypass;
		} );

		// rc.16 audit fix M1 — NitroPack bypass.
		// NitroPack ships its own edge cache that ignores DONOTCACHEPAGE.
		// The `nitropack_should_skip_cache` filter is the supported escape hatch.
		add_filter( 'nitropack_should_skip_cache', function ( $skip ) use ( $patterns ) {
			if ( $skip ) {
				return $skip;
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			foreach ( $patterns as $p ) {
				if ( false !== strpos( $uri, $p ) ) {
					return true;
				}
			}
			return $skip;
		} );

		// rc.16 audit fix M1 — Perfmatters URL exclusion. Perfmatters mostly
		// optimises CSS/JS; defensive only — its CDN rewrite shouldn't touch
		// .txt/.md/.json content types but we exclude to be explicit.
		add_filter( 'perfmatters_excluded_urls', function ( $excluded ) use ( $patterns ) {
			return array_values( array_unique( array_merge( (array) $excluded, $patterns ) ) );
		} );

		// rc.16 audit fix M1 — Hummingbird page-cache exclusion.
		add_filter( 'wphb_should_cache_request', function ( $should_cache ) use ( $patterns ) {
			if ( ! $should_cache ) {
				return $should_cache;
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			foreach ( $patterns as $p ) {
				if ( false !== strpos( $uri, $p ) ) {
					return false;
				}
			}
			return $should_cache;
		} );
	}

	/**
	 * Persist exclusion rules to each cache plugin's saved option.
	 *
	 * Runtime filters in exclude_url_patterns() only affect the in-process
	 * request. Server-level caches (LiteSpeed Web Server, FastCGI cache) read
	 * the persisted option BEFORE PHP runs — so cold cache hits would bypass
	 * our runtime filter entirely.
	 *
	 * Call this on activation, on plugin update, and on settings save.
	 *
	 * @since 1.2.0-rc.16
	 * @param string[] $patterns Path fragments to persist.
	 */
	public static function persist_exclusions( array $patterns ): void {
		$patterns = array_values( array_filter( array_map( 'strval', $patterns ) ) );
		if ( empty( $patterns ) ) {
			return;
		}

		// ── LiteSpeed Cache / LSWS — persisted exclusion list ─────────────
		// Read by LSWS via .htaccess LSCACHE module BEFORE PHP runs.
		// Without this, a cold-cache /llms.txt request gets a stale HTML
		// response from disk and our headers never fire. (Audit C2.)
		if ( defined( 'LSCWP_V' ) || defined( 'LITESPEED_VERSION' ) ) {
			$existing = (array) get_option( 'litespeed.conf.cache-exc', array() );
			$merged   = array_values( array_unique( array_merge( $existing, $patterns ) ) );
			if ( $merged !== $existing ) {
				update_option( 'litespeed.conf.cache-exc', $merged );
				do_action( 'litespeed_purge_all' );
			}
		}

		// ── WP Super Cache — persisted reject URI ─────────────────────────
		// (Audit H2.)
		if ( function_exists( 'wp_cache_setting' ) ) {
			$existing = (array) wp_cache_setting( 'cache_rejected_uri', array() );
			$merged   = array_values( array_unique( array_merge( $existing, $patterns ) ) );
			if ( $merged !== $existing ) {
				wp_cache_setting( 'cache_rejected_uri', $merged );
			}
		}

		// ── WP Rocket — persisted reject URI ─────────────────────────────
		if ( defined( 'WP_ROCKET_VERSION' ) && function_exists( 'get_rocket_option' ) && function_exists( 'update_rocket_option' ) ) {
			$existing = (array) get_rocket_option( 'cache_reject_uri', array() );
			$regex    = array_map( function ( $p ) { return preg_quote( $p, '/' ); }, $patterns );
			$merged   = array_values( array_unique( array_merge( $existing, $regex ) ) );
			if ( $merged !== $existing ) {
				update_rocket_option( 'cache_reject_uri', $merged );
			}
		}

		// ── W3 Total Cache — persisted reject URI ─────────────────────────
		if ( defined( 'W3TC' ) && class_exists( '\\W3TC\\Dispatcher' ) ) {
			try {
				$config   = \W3TC\Dispatcher::config();
				$existing = (array) $config->get_array( 'pgcache.reject.uri' );
				$merged   = array_values( array_unique( array_merge( $existing, $patterns ) ) );
				if ( $merged !== $existing ) {
					$config->set( 'pgcache.reject.uri', $merged );
					$config->save();
				}
			} catch ( \Throwable $e ) {
				// W3TC API surface shifts across versions — skip on error.
			}
		}
	}

	/**
	 * Lightweight variant of no_cache_headers() — sets page-cache bypass
	 * constants WITHOUT overriding Cache-Control. Use when RankReady wants
	 * to control its own caching via response headers (llms.txt at 1h, mcp
	 * manifest at 5min) but doesn't want WP page-cache plugins to layer
	 * their own cache on top.
	 *
	 * @since 1.2.0-rc.2
	 */
	public static function bypass_page_cache_plugins_only(): void {
		// Tell every plugin that respects DONOTCACHEPAGE / LSCWP_NO_CACHE to
		// skip this response. We're NOT setting Cache-Control here — that's
		// the caller's responsibility.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! defined( 'DONOTCACHEDB' ) ) {
			define( 'DONOTCACHEDB', true );
		}
		if ( ! defined( 'LSCWP_NO_CACHE' ) ) {
			define( 'LSCWP_NO_CACHE', true );
		}

		// rc.16 audit fix C2 + M5 — emit LSWS server-level bypass headers.
		// The LSCWP_NO_CACHE constant only signals the LSCWP PHP plugin.
		// LiteSpeed Web Server (LSWS) reads these response headers to decide
		// whether to store the response at the server level. Without these,
		// LSWS will cache /llms.txt + /.well-known/mcp.json on disk.
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'X-LiteSpeed-Tag: rnrd-dynamic' );
		}
	}

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

		// rc.16 audit fix C2 — LiteSpeed Web Server (LSWS) server-level cache
		// bypass. Distinct from the LSCWP plugin constant — LSWS reads the
		// X-LiteSpeed-Cache-Control response header to decide on-disk storage.
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'X-LiteSpeed-Tag: rnrd-dynamic' );

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
		// rc.16 audit fix M2 — Nginx Helper detection.
		if ( class_exists( 'Nginx_Helper' ) || defined( 'RT_WP_NGINX_HELPER_PATH' ) )      $active['nginx-helper']   = 'Nginx Helper';
		// rc.16 audit — NitroPack detection.
		if ( defined( 'NITROPACK_VERSION' ) )                                              $active['nitropack']      = 'NitroPack';
		// rc.16 audit — Perfmatters detection (caching-adjacent).
		if ( defined( 'PERFMATTERS_VERSION' ) )                                            $active['perfmatters']    = 'Perfmatters';

		return $active;
	}

	// ── Server bypass snippets ───────────────────────────────────────────────

	/**
	 * Apache / LiteSpeed `.htaccess` bypass snippet for RankReady endpoints.
	 *
	 * Apply at server level when runtime headers aren't sufficient — i.e. when
	 * LiteSpeed Web Server caches a stale HTML response for /llms.txt before
	 * PHP runs. Surfaced in Diagnostics → "Server bypass snippet".
	 *
	 * @since 1.2.0-rc.16
	 */
	public static function apache_htaccess_snippet(): string {
		return <<<HTACCESS
# BEGIN RankReady — LiteSpeed/Apache cache bypass
<IfModule LiteSpeed>
  RewriteEngine On
  RewriteRule ^llms(-full)?\.txt$       - [E=cache-control:no-cache,L]
  RewriteRule ^\.well-known/mcp\.json$  - [E=cache-control:no-cache,L]
  RewriteRule \.md$                     - [E=cache-control:no-cache,L]
</IfModule>
<IfModule mod_headers.c>
  <FilesMatch "\.(txt|md|json)$">
    Header set X-LiteSpeed-Cache-Control "no-cache"
    Header set Cache-Control             "no-store, no-cache, must-revalidate"
  </FilesMatch>
</IfModule>
# END RankReady
HTACCESS;
	}

	/**
	 * Nginx server-block bypass snippet for RankReady endpoints.
	 *
	 * Use with FastCGI cache stacks (nginx + php-fpm) where header-based
	 * bypass via X-Accel-Expires alone is unreliable on cold cache builds.
	 *
	 * @since 1.2.0-rc.16
	 */
	public static function nginx_snippet(): string {
		return <<<NGINX
# RankReady — FastCGI cache bypass (add to your nginx server block)
location ~ ^/(llms\.txt|llms-full\.txt|\.well-known/mcp\.json)\$ {
    fastcgi_cache_bypass 1;
    fastcgi_no_cache     1;
    add_header X-RR-Bypass "1" always;
    try_files \$uri \$uri/ /index.php?\$args;
}
location ~ \.md\$ {
    fastcgi_cache_bypass 1;
    fastcgi_no_cache     1;
    add_header X-RR-Bypass "1" always;
    try_files \$uri \$uri/ /index.php?\$args;
}
NGINX;
	}
}
