<?php
/**
 * LLMs.txt generator — full spec compliance per llmstxt.org.
 *
 * Generates /llms.txt and optionally /llms-full.txt.
 * Uses WordPress rewrite rules + transient caching.
 *
 * Spec requirements:
 * - H1 with site name (required)
 * - Blockquote summary (recommended)
 * - Markdown body with site info
 * - H2-delimited sections with file lists: - [title](url): description
 * - Optional section for secondary content
 *
 * llms-full.txt format (per real-world implementations like Lovable/Mintlify):
 * - Each page starts with: # Page Title\nSource: URL
 * - Full content inlined as clean markdown below
 * - No XML wrappers, just concatenated markdown pages
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Llms_Txt {

	public static function init(): void {
		add_action( 'init',             array( self::class, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( self::class, 'handle_request' ) );

		// Prevent WordPress from adding trailing slash to .txt URLs.
		add_filter( 'redirect_canonical', array( self::class, 'prevent_txt_trailing_slash' ), 10, 2 );

		// Flush rewrite rules when settings change.
		add_action( 'update_option_' . RR_OPT_LLMS_ENABLE,      array( self::class, 'flush_rules' ) );
		add_action( 'update_option_' . RR_OPT_LLMS_FULL_ENABLE, array( self::class, 'flush_rules' ) );

		// Bust cache when posts are published/updated/deleted.
		add_action( 'transition_post_status', array( self::class, 'bust_cache_on_status_change' ), 10, 3 );
		add_action( 'deleted_post',           array( self::class, 'bust_cache' ) );

		// Add llms.txt reference to robots.txt so AI crawlers discover it.
		add_filter( 'robots_txt', array( self::class, 'add_to_robots_txt' ), 100, 2 );

		// Emit Link: headers and <link> tags for AI discovery on every front-end page.
		add_action( 'send_headers', array( self::class, 'add_discovery_link_headers' ) );
		add_action( 'wp_head',      array( self::class, 'add_discovery_link_tags' ) );

		// Sync to physical robots.txt when settings change.
		add_action( 'update_option_' . RR_OPT_ROBOTS_ENABLE,             array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RR_OPT_ROBOTS_CRAWLERS,           array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RR_OPT_LLMS_ENABLE,               array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RR_OPT_LLMS_FULL_ENABLE,          array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RR_OPT_MD_ENABLE,                 array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RR_OPT_CONTENT_SIGNALS_ENABLE,   array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RR_OPT_CONTENT_SIGNALS_AI_TRAIN, array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RR_OPT_CONTENT_SIGNALS_SEARCH,   array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RR_OPT_CONTENT_SIGNALS_AI_INPUT, array( self::class, 'sync_physical_robots_txt' ) );
	}

	/**
	 * Prevent WordPress from adding a trailing slash to /llms.txt and /llms-full.txt.
	 *
	 * WordPress canonical redirect turns /llms.txt into /llms.txt/ by default,
	 * causing a 301 loop. This filter stops that.
	 */
	public static function prevent_txt_trailing_slash( $redirect_url, $requested_url ) {
		if ( preg_match( '/\/llms(?:-full)?\.txt\/?$/i', $requested_url ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Append llms.txt and llms-full.txt references to WordPress robots.txt.
	 *
	 * This tells AI crawlers where to find structured content about the site.
	 * Similar to how Sitemap is referenced in robots.txt.
	 */
	public static function add_to_robots_txt( string $output, bool $public ): string {
		if ( ! $public ) {
			return $output;
		}

		// Don't duplicate if RankReady block already present.
		if ( false !== stripos( $output, 'RankReady' ) ) {
			return $output;
		}

		$block = self::generate_robots_block();
		if ( empty( trim( $block ) ) ) {
			return $output;
		}

		return $output . $block;
	}

	/**
	 * Emit Link: HTTP response headers for AI agent discovery on all front-end pages.
	 *
	 * These are checked by isitagentready.com and similar agent-readiness scanners
	 * to verify the site exposes its LLM-readable endpoints via standard headers.
	 */
	public static function add_discovery_link_headers(): void {
		if ( is_admin() || defined( 'REST_REQUEST' ) ) {
			return;
		}

		if ( 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' ) ) {
			header( 'Link: <' . esc_url( home_url( '/llms.txt' ) ) . '>; rel="llms-txt"', false );
		}

		if ( 'on' === get_option( RR_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			header( 'Link: <' . esc_url( home_url( '/llms-full.txt' ) ) . '>; rel="llms-full-txt"', false );
		}

		if ( 'on' === get_option( RR_OPT_MD_ENABLE, 'off' ) ) {
			header( 'Link: <' . esc_url( home_url( '/' ) ) . '>; rel="alternate"; type="text/markdown"', false );
		}

		$sitemap_url = get_option( 'permalink_structure' ) ? home_url( '/sitemap_index.xml' ) : home_url( '/?sitemap=1' );
		header( 'Link: <' . esc_url( $sitemap_url ) . '>; rel="sitemap"; type="application/xml"', false );
	}

	/**
	 * Emit <link> tags in <head> for AI agent discovery.
	 *
	 * Mirrors the Link: headers as HTML meta-equivalents so HTML parsers
	 * (and tools that don't inspect response headers) can also discover endpoints.
	 */
	public static function add_discovery_link_tags(): void {
		if ( 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' ) ) {
			echo '<link rel="llms-txt" type="text/plain" href="' . esc_url( home_url( '/llms.txt' ) ) . '" />' . "\n";
		}

		if ( 'on' === get_option( RR_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			echo '<link rel="llms-full-txt" type="text/plain" href="' . esc_url( home_url( '/llms-full.txt' ) ) . '" />' . "\n";
		}
	}

	/**
	 * Generate the RankReady robots.txt block as a standalone string.
	 *
	 * Used both by the `robots_txt` filter (virtual) and physical file sync.
	 */
	public static function generate_robots_block(): string {
		$llms_on    = 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' );
		$full_on    = 'on' === get_option( RR_OPT_LLMS_FULL_ENABLE, 'off' );
		$md_on      = 'on' === get_option( RR_OPT_MD_ENABLE, 'off' );
		$robots_on  = 'on' === get_option( RR_OPT_ROBOTS_ENABLE, 'on' );
		$signals_on = 'on' === get_option( RR_OPT_CONTENT_SIGNALS_ENABLE, 'off' );

		if ( ! $llms_on && ! $md_on && ! $robots_on && ! $signals_on ) {
			return '';
		}

		$allow_paths = array();
		if ( $llms_on ) {
			$allow_paths[] = '/llms.txt';
		}
		if ( $llms_on && $full_on ) {
			$allow_paths[] = '/llms-full.txt';
		}
		if ( $md_on ) {
			$allow_paths[] = '/*.md$';
		}

		$block = "\n# -- LLM & AI Crawler Rules (RankReady) --------------------\n";

		// Stack all User-agent lines in one block — per robots.txt spec,
		// grouped User-agent lines share the same Allow/Disallow rules.
		if ( $robots_on ) {
			$enabled_crawlers = (array) get_option(
				RR_OPT_ROBOTS_CRAWLERS,
				array_keys( RR_Admin::get_llm_crawlers() )
			);

			if ( ! empty( $enabled_crawlers ) ) {
				foreach ( $enabled_crawlers as $crawler ) {
					$block .= 'User-agent: ' . sanitize_text_field( $crawler ) . "\n";
				}
				$block .= "Allow: /\n";
				foreach ( $allow_paths as $path ) {
					$block .= "Allow: {$path}\n";
				}
				$block .= "\n";
			}
		}

		// Content Signals — single Content-Signal directive (contentsignals.org).
		// Format per isitagentready.com check: Content-Signal: ai-train=yes, search=yes, ai-input=yes
		// Options stored as allow/deny internally; mapped to yes/no for output.
		if ( $signals_on ) {
			$ai_train = 'allow' === get_option( RR_OPT_CONTENT_SIGNALS_AI_TRAIN, 'allow' ) ? 'yes' : 'no';
			$search   = 'allow' === get_option( RR_OPT_CONTENT_SIGNALS_SEARCH, 'allow' ) ? 'yes' : 'no';
			$ai_input = 'allow' === get_option( RR_OPT_CONTENT_SIGNALS_AI_INPUT, 'allow' ) ? 'yes' : 'no';

			$block .= "# Content Signals (contentsignals.org)\n";
			$block .= "Content-Signal: ai-train={$ai_train}, search={$search}, ai-input={$ai_input}\n";
			$block .= "\n";
		}

		return $block;
	}

	/**
	 * Sync RankReady rules to a physical robots.txt file.
	 *
	 * When a physical robots.txt exists (e.g. manually created or by a plugin),
	 * WordPress's `robots_txt` filter never fires. This method detects the
	 * physical file and appends/updates the RankReady block directly.
	 *
	 * Safe: only touches the RankReady-marked block, never modifies other rules.
	 */
	public static function sync_physical_robots_txt(): void {
		// Skip physical robots.txt on multisite — subsites share ABSPATH.
		if ( is_multisite() ) {
			return;
		}

		$file = ABSPATH . 'robots.txt';

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) {
			return;
		}

		if ( ! $wp_filesystem->exists( $file ) ) {
			// No physical file — the `robots_txt` filter handles it.
			return;
		}

		if ( ! $wp_filesystem->is_writable( $file ) ) {
			return;
		}

		$contents = $wp_filesystem->get_contents( $file );
		if ( false === $contents ) {
			return;
		}

		// Remove any existing RankReady block (handles both old Unicode and new ASCII format).
		$contents = preg_replace( '/\n?# -+ LLM.*?\(RankReady\).*?\n.*?(?=\n#[^-]|\n?$)/s', '', $contents );
		$contents = preg_replace( '/\n?#[^\n]*LLM[^\n]*RankReady[^\n]*\n.*?(?=\n#[^-]|\n?$)/s', '', $contents );

		// Trim trailing whitespace.
		$contents = rtrim( $contents ) . "\n";

		// Generate and append new block.
		$block = self::generate_robots_block();

		if ( ! empty( trim( $block ) ) ) {
			$contents .= $block;
		}

		$wp_filesystem->put_contents( $file, $contents, FS_CHMOD_FILE );

		// Purge robots.txt from all common page caches so changes are live immediately.
		self::purge_robots_cache();
	}

	/**
	 * Purge robots.txt from every active cache layer.
	 *
	 * Delegates to RR_Cache::purge_url() which covers all major WordPress cache
	 * plugins and CDN layers — no user configuration needed, safe no-op when
	 * a plugin is not active. See class-rr-cache.php for the full layer list.
	 */
	public static function purge_robots_cache(): void {
		RR_Cache::purge_url( home_url( '/robots.txt' ) );
	}

	// ── Rewrite rules ─────────────────────────────────────────────────────────

	public static function add_rewrite_rules(): void {
		if ( 'on' !== get_option( RR_OPT_LLMS_ENABLE, 'off' ) ) {
			return;
		}

		// Skip llms.txt if a major SEO plugin already generates it.
		// RankReady still registers llms-full.txt since no SEO plugin does that.
		if ( ! self::another_plugin_handles_llms_txt() ) {
			add_rewrite_rule( '^llms\.txt$', 'index.php?rr_llms_txt=1', 'top' );
		}

		if ( 'on' === get_option( RR_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			add_rewrite_rule( '^llms-full\.txt$', 'index.php?rr_llms_full_txt=1', 'top' );
		}

		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
	}

	/**
	 * Check if another plugin already handles /llms.txt generation.
	 *
	 * Detects Rank Math, Yoast, AIOSEO, SEOPress, and standalone llms.txt plugins.
	 * Returns true if RankReady should NOT register its own llms.txt route.
	 */
	private static function another_plugin_handles_llms_txt(): bool {
		// Allow users to force RankReady's llms.txt via filter.
		if ( apply_filters( 'rankready_force_llms_txt', false ) ) {
			return false;
		}

		// Rank Math llms.txt (has its own module).
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_modules = (array) get_option( 'rank_math_modules', array() );
			if ( in_array( 'llms-txt', $rm_modules, true ) ) {
				return true;
			}
		}

		// Yoast SEO llms.txt.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$yoast_features = get_option( 'wpseo', array() );
			if ( ! empty( $yoast_features['enable_llms_txt'] ) ) {
				return true;
			}
		}

		// AIOSEO llms.txt.
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return true; // AIOSEO enables llms.txt by default when active.
		}

		// SEOPress llms.txt (v9.5+).
		if ( defined( 'SEOPRESS_VERSION' ) && version_compare( SEOPRESS_VERSION, '9.5', '>=' ) ) {
			return true;
		}

		return false;
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = 'rr_llms_txt';
		$vars[] = 'rr_llms_full_txt';
		return $vars;
	}

	public static function flush_rules(): void {
		flush_rewrite_rules( false );
	}

	// ── Request handler ───────────────────────────────────────────────────────

	public static function handle_request(): void {
		if ( get_query_var( 'rr_llms_txt' ) ) {
			RR_Crawler_Log::log( 'llms_txt' );
			self::serve_llms_txt( false );
		}

		if ( get_query_var( 'rr_llms_full_txt' ) ) {
			RR_Crawler_Log::log( 'llms_full' );
			self::serve_llms_txt( true );
		}
	}

	// ── Serve ─────────────────────────────────────────────────────────────────

	private static function serve_llms_txt( bool $full = false ): void {
		if ( 'on' !== get_option( RR_OPT_LLMS_ENABLE, 'off' ) ) {
			status_header( 404 );
			exit;
		}

		if ( $full && 'on' !== get_option( RR_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			status_header( 404 );
			exit;
		}

		$cache_key = $full ? RR_LLMS_FULL_CACHE_KEY : RR_LLMS_CACHE_KEY;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			self::output_txt( $cached );
			return; // output_txt calls exit, but guard against refactoring.
		}

		$content = $full ? self::generate_full() : self::generate();

		$ttl = (int) get_option( RR_OPT_LLMS_CACHE_TTL, 3600 );
		if ( $ttl < 60 ) {
			$ttl = 3600;
		}
		set_transient( $cache_key, $content, $ttl );

		self::output_txt( $content );
	}

	private static function output_txt( string $content ): void {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// GENERATE: /llms.txt (index with links only)
	// ═══════════════════════════════════════════════════════════════════════════

	public static function generate(): string {
		$lines = array();

		// ── H1: Site name (REQUIRED per spec) ─────────────────────────────
		$site_name = (string) get_option( RR_OPT_LLMS_SITE_NAME, '' );
		if ( empty( $site_name ) ) {
			$site_name = get_bloginfo( 'name' );
		}
		$lines[] = '# ' . self::clean_text( $site_name );
		$lines[] = '';

		// ── Blockquote: Brief summary (RECOMMENDED per spec) ──────────────
		$summary = (string) get_option( RR_OPT_LLMS_SUMMARY, '' );
		if ( empty( $summary ) ) {
			$summary = get_bloginfo( 'description' );
		}
		if ( ! empty( $summary ) ) {
			$lines[] = '> ' . self::clean_text( $summary );
			$lines[] = '';
		}

		// ── About section (detailed info) ─────────────────────────────────
		$about = (string) get_option( RR_OPT_LLMS_ABOUT, '' );
		if ( ! empty( $about ) ) {
			$lines[] = self::clean_text( $about );
			$lines[] = '';
		}

		// ── Site metadata ─────────────────────────────────────────────────
		$lines[] = '- URL: ' . home_url( '/' );

		$feed_url = get_bloginfo( 'rss2_url' );
		if ( ! empty( $feed_url ) ) {
			$lines[] = '- RSS Feed: ' . $feed_url;
		}

		$sitemap_url = home_url( '/sitemap.xml' );
		$lines[]     = '- Sitemap: ' . $sitemap_url;

		// Link to llms-full.txt if enabled.
		if ( 'on' === get_option( RR_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			$lines[] = '- Full version: ' . home_url( '/llms-full.txt' );
		}

		// Tell crawlers that markdown is available per page.
		if ( 'on' === get_option( RR_OPT_MD_ENABLE, 'off' ) ) {
			$lines[] = '- Markdown: Append .md to any page URL for clean markdown (e.g., /page-slug.md)';
			$lines[] = '- Content negotiation: Send `Accept: text/markdown` header on any page URL';
		}

		$lines[] = '';

		// ── Post type sections (H2-delimited file lists) ──────────────────
		$post_types = (array) get_option( RR_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) );
		$max_posts  = (int) get_option( RR_OPT_LLMS_MAX_POSTS, 100 );

		if ( $max_posts < 1 ) {
			$max_posts = 100;
		}

		// Get taxonomy exclusions from settings.
		$exclude_cats = (array) get_option( RR_OPT_LLMS_EXCLUDE_CATS, array() );
		$exclude_tags = (array) get_option( RR_OPT_LLMS_EXCLUDE_TAGS, array() );

		foreach ( $post_types as $pt ) {
			$type_obj = get_post_type_object( $pt );
			if ( ! $type_obj ) {
				continue;
			}

			$query_args = self::build_llms_query( $pt, $max_posts, $exclude_cats, $exclude_tags );
			$posts      = get_posts( $query_args );

			if ( empty( $posts ) ) {
				continue;
			}

			// Filter out posts flagged noindex by SEO plugins.
			$filtered = array();
			foreach ( $posts as $post ) {
				if ( self::should_exclude_from_llms( $post ) ) {
					continue;
				}
				$filtered[] = $post;
			}

			if ( empty( $filtered ) ) {
				continue;
			}

			// H2 section header.
			$section_title = $type_obj->labels->name;
			$lines[]       = '## ' . self::clean_text( $section_title );

			foreach ( $filtered as $post ) {
				$title    = self::clean_text( get_the_title( $post ) );
				$url      = get_permalink( $post );
				$excerpt  = self::get_post_description( $post );
				$lastmod  = get_post_modified_time( 'Y-m-d', false, $post );

				$lines[] = '- [' . $title . '](' . $url . '): ' . $excerpt . ' (updated: ' . $lastmod . ')';
			}

			$lines[] = '';
		}

		// ── Optional section (per spec: secondary/skippable content) ──────
		// Controlled by admin setting — user can toggle it off entirely.
		if ( 'on' === get_option( RR_OPT_LLMS_SHOW_CATEGORIES, 'on' ) ) {
			$cat_args = array(
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 20,
				'hide_empty' => true,
			);

			// Respect excluded categories.
			if ( ! empty( $exclude_cats ) ) {
				$cat_args['exclude'] = $exclude_cats;
			}

			$categories = get_categories( $cat_args );

			if ( ! empty( $categories ) ) {
				$lines[] = '## Optional';

				foreach ( $categories as $cat ) {
					$lines[] = '- [' . self::clean_text( $cat->name ) . '](' . get_category_link( $cat->term_id ) . '): '
						. sprintf( '%d posts', $cat->count );
				}

				$lines[] = '';
			}
		}

		// ── Footer ────────────────────────────────────────────────────────
		$lines[] = '---';
		$lines[] = 'Generated by RankReady v' . RR_VERSION . ' (https://github.com/adityaarsharma/rankready)';

		return implode( "\n", $lines );
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// GENERATE: /llms-full.txt (full content inlined per page)
	//
	// Format follows real-world implementations (Lovable/Mintlify):
	//   # Page Title
	//   Source: https://example.com/page-url
	//
	//   [full page content as clean markdown]
	//
	// ═══════════════════════════════════════════════════════════════════════════

	public static function generate_full(): string {
		$lines = array();

		// ── Header (same as llms.txt) ─────────────────────────────────────
		$site_name = (string) get_option( RR_OPT_LLMS_SITE_NAME, '' );
		if ( empty( $site_name ) ) {
			$site_name = get_bloginfo( 'name' );
		}
		$lines[] = '# ' . self::clean_text( $site_name );
		$lines[] = '';

		$summary = (string) get_option( RR_OPT_LLMS_SUMMARY, '' );
		if ( empty( $summary ) ) {
			$summary = get_bloginfo( 'description' );
		}
		if ( ! empty( $summary ) ) {
			$lines[] = '> ' . self::clean_text( $summary );
			$lines[] = '';
		}

		$about = (string) get_option( RR_OPT_LLMS_ABOUT, '' );
		if ( ! empty( $about ) ) {
			$lines[] = self::clean_text( $about );
			$lines[] = '';
		}

		$lines[] = '---';
		$lines[] = '';

		// ── Inline each page as clean markdown ────────────────────────────
		$post_types = (array) get_option( RR_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) );
		$max_posts  = (int) get_option( RR_OPT_LLMS_MAX_POSTS, 100 );

		if ( $max_posts < 1 ) {
			$max_posts = 100;
		}

		// Get taxonomy exclusions from settings.
		$exclude_cats = (array) get_option( RR_OPT_LLMS_EXCLUDE_CATS, array() );
		$exclude_tags = (array) get_option( RR_OPT_LLMS_EXCLUDE_TAGS, array() );

		foreach ( $post_types as $pt ) {
			$type_obj = get_post_type_object( $pt );
			if ( ! $type_obj ) {
				continue;
			}

			$query_args = self::build_llms_query( $pt, $max_posts, $exclude_cats, $exclude_tags );
			$posts      = get_posts( $query_args );

			if ( empty( $posts ) ) {
				continue;
			}

			foreach ( $posts as $post ) {
				// Skip noindex posts.
				if ( self::should_exclude_from_llms( $post ) ) {
					continue;
				}

				$title   = self::clean_text( get_the_title( $post ) );
				$url     = get_permalink( $post );
				$content = self::post_to_clean_markdown( $post, false );

				// Per-page separator: # Title + Source URL
				$lines[] = '# ' . $title;
				$lines[] = 'Source: ' . $url;
				$lines[] = '';

				if ( ! empty( $content ) ) {
					$lines[] = $content;
				}

				$lines[] = '';
				$lines[] = '---';
				$lines[] = '';
			}
		}

		$lines[] = 'Generated by RankReady v' . RR_VERSION . ' (https://github.com/adityaarsharma/rankready)';

		return implode( "\n", $lines );
	}

	// ── Cache busting ─────────────────────────────────────────────────────────

	public static function bust_cache_on_status_change( $new_status, $old_status, $post ): void {
		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			self::bust_cache();
		}
	}

	public static function bust_cache(): void {
		delete_transient( RR_LLMS_CACHE_KEY );
		delete_transient( RR_LLMS_FULL_CACHE_KEY );
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// HTML-TO-MARKDOWN: Aggressive page-builder-safe converter
	//
	// Strips ALL Elementor, Beaver Builder, Divi, WPBakery, and generic
	// page builder wrapper divs/sections/spans. Preserves only semantic
	// content: headings, paragraphs, lists, links, images, blockquotes, code.
	// ═══════════════════════════════════════════════════════════════════════════

	public static function post_to_clean_markdown( $post, bool $run_shortcodes = true ): string {
		$html = $post->post_content;

		if ( empty( $html ) ) {
			return '';
		}

		// ── Step 1: Strip Gutenberg block comments ────────────────────────
		$html = preg_replace( '/<!--\s*\/?wp:[^\>]+-->/s', '', $html );

		// ── Step 2: Shortcodes — run them for individual requests, strip for bulk ──
		$html = $run_shortcodes ? do_shortcode( $html ) : strip_shortcodes( $html );

		// ── Step 3: Strip ALL page builder wrapper elements ───────────────
		// Elementor: div.elementor-*, section.elementor-*, div.e-*, etc.
		// Divi: div.et_pb_*, div.et_*, etc.
		// WPBakery: div.vc_*, div.wpb_*, etc.
		// Beaver Builder: div.fl-*, etc.
		// Generic: section, article, aside, main, figure wrappers
		//
		// Strategy: Remove open/close tags for non-semantic containers,
		// keeping their inner content.

		// Strip Elementor widget/section/column wrappers (keep inner HTML).
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:elementor-|e-con|e-child)[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<section[^>]*class="[^"]*elementor-[^"]*"[^>]*>/si', '', $html );

		// Strip Divi wrappers.
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:et_pb_|et_builder_)[^"]*"[^>]*>/si', '', $html );

		// Strip WPBakery wrappers.
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:vc_|wpb_)[^"]*"[^>]*>/si', '', $html );

		// Strip Beaver Builder wrappers.
		$html = preg_replace( '/<div[^>]*class="[^"]*fl-[^"]*"[^>]*>/si', '', $html );

		// Strip generic layout wrappers (div with only class/id/style attrs).
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:wp-block-|entry-|post-|content-|container|wrapper|row|col-|grid)[^"]*"[^>]*>/si', '', $html );

		// Remove stray closing divs and sections.
		$html = preg_replace( '/<\/(?:div|section|article|aside|main|header|footer|nav|figure|figcaption)>/si', '', $html );

		// Strip inline styles and data attributes from remaining elements.
		$html = preg_replace( '/\s+style="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+data-[a-z0-9_-]+="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+class="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+id="[^"]*"/si', '', $html );

		// ── Step 4: Convert semantic HTML to markdown ─────────────────────

		// Headings (callback for dynamic #).
		$html = preg_replace_callback( '/<h([1-6])[^>]*>(.*?)<\/h\1>/si', function ( $m ) {
			return "\n" . str_repeat( '#', (int) $m[1] ) . ' ' . strip_tags( $m[2] ) . "\n";
		}, $html );

		// Bold and italic (before stripping tags).
		$html = preg_replace( '/<(strong|b)>(.*?)<\/\1>/si', '**$2**', $html );
		$html = preg_replace( '/<(em|i)>(.*?)<\/\1>/si', '*$2*', $html );

		// Links — strip anchor-only hrefs (#section) since they're meaningless outside the page.
		$html = preg_replace( '/<a\s[^>]*href=["\']#[^"\']*["\'][^>]*>(.*?)<\/a>/si', '$1', $html );
		$html = preg_replace( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', '[$2]($1)', $html );

		// Images — extract src and alt only.
		$html = preg_replace_callback( '/<img[^>]*>/si', function ( $m ) {
			$tag = $m[0];
			$src = '';
			$alt = '';
			if ( preg_match( '/src=["\']([^"\']+)["\']/i', $tag, $sm ) ) {
				$src = $sm[1];
			}
			if ( preg_match( '/alt=["\']([^"\']*)["\']/', $tag, $am ) ) {
				$alt = $am[1];
			}
			if ( empty( $src ) ) {
				return '';
			}
			return '![' . $alt . '](' . $src . ')';
		}, $html );

		// Lists.
		$html = preg_replace( '/<li[^>]*>(.*?)<\/li>/si', '- $1', $html );
		$html = preg_replace( '/<\/?[ou]l[^>]*>/si', '', $html );

		// Paragraphs and br.
		$html = preg_replace( '/<p[^>]*>(.*?)<\/p>/si', "$1\n\n", $html );
		$html = preg_replace( '/<br\s*\/?>/si', "\n", $html );

		// Blockquotes.
		$html = preg_replace_callback( '/<blockquote[^>]*>(.*?)<\/blockquote>/si', function ( $m ) {
			$inner = strip_tags( trim( $m[1] ) );
			$bq_lines = explode( "\n", $inner );
			return implode( "\n", array_map( function ( $l ) { return '> ' . trim( $l ); }, $bq_lines ) );
		}, $html );

		// Code blocks.
		$html = preg_replace( '/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/si', "\n```\n$1\n```\n", $html );
		$html = preg_replace( '/<code[^>]*>(.*?)<\/code>/si', '`$1`', $html );

		// Tables (basic).
		$html = preg_replace_callback( '/<table[^>]*>(.*?)<\/table>/si', function ( $m ) {
			return self::table_to_markdown( $m[1] );
		}, $html );

		// Horizontal rules.
		$html = preg_replace( '/<hr[^>]*\/?>/si', "\n---\n", $html );

		// ── Step 5: Strip ALL remaining HTML tags ─────────────────────────
		$html = wp_strip_all_tags( $html );

		// ── Step 6: Decode entities and clean whitespace ──────────────────
		$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
		$html = preg_replace( '/\n{3,}/', "\n\n", $html );
		$html = preg_replace( '/[ \t]+/', ' ', $html );

		// Clean up lines — remove lines that are just whitespace.
		$final_lines = array();
		foreach ( explode( "\n", $html ) as $line ) {
			$trimmed = trim( $line );
			if ( '' !== $trimmed || ( ! empty( $final_lines ) && '' !== end( $final_lines ) ) ) {
				$final_lines[] = $trimmed;
			}
		}

		return trim( implode( "\n", $final_lines ) );
	}

	/**
	 * Basic HTML table to markdown table.
	 */
	private static function table_to_markdown( string $table_html ): string {
		$rows = array();
		preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/si', $table_html, $row_matches );

		if ( empty( $row_matches[1] ) ) {
			return wp_strip_all_tags( $table_html );
		}

		$is_header = true;
		foreach ( $row_matches[1] as $row_html ) {
			preg_match_all( '/<t[hd][^>]*>(.*?)<\/t[hd]>/si', $row_html, $cell_matches );
			if ( empty( $cell_matches[1] ) ) {
				continue;
			}

			$cells  = array_map( function ( $c ) { return trim( strip_tags( $c ) ); }, $cell_matches[1] );
			$rows[] = '| ' . implode( ' | ', $cells ) . ' |';

			if ( $is_header ) {
				$separator = array_map( function ( $c ) { return str_repeat( '-', max( 3, strlen( $c ) ) ); }, $cells );
				$rows[]    = '| ' . implode( ' | ', $separator ) . ' |';
				$is_header = false;
			}
		}

		return "\n" . implode( "\n", $rows ) . "\n";
	}

	// ── Query builder ─────────────────────────────────────────────────────────

	/**
	 * Build WP_Query args for llms.txt post retrieval.
	 *
	 * Applies:
	 * - Post type and publish status filter
	 * - Rank Math noindex meta_query exclusion (query-level)
	 * - Taxonomy exclusions from admin settings (category, tag)
	 *
	 * @param string $post_type    Post type slug.
	 * @param int    $max_posts    Max posts to retrieve.
	 * @param array  $exclude_cats Category term IDs to exclude.
	 * @param array  $exclude_tags Tag term IDs to exclude.
	 * @return array WP_Query compatible args.
	 */
	private static function build_llms_query( string $post_type, int $max_posts, array $exclude_cats, array $exclude_tags ): array {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $max_posts,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		// Exclude Rank Math noindex posts at query level.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => 'rank_math_robots',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'rank_math_robots',
					'value'   => 'noindex',
					'compare' => 'NOT LIKE',
				),
			);
		}

		// Taxonomy exclusions from admin settings.
		$tax_query = array();

		if ( ! empty( $exclude_cats ) ) {
			$tax_query[] = array(
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $exclude_cats ),
				'operator' => 'NOT IN',
			);
		}

		if ( ! empty( $exclude_tags ) ) {
			$tax_query[] = array(
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $exclude_tags ),
				'operator' => 'NOT IN',
			);
		}

		if ( ! empty( $tax_query ) ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}
			$args['tax_query'] = $tax_query;
		}

		return $args;
	}

	// ── Post exclusion logic ──────────────────────────────────────────────────

	/**
	 * Check if a post should be excluded from llms.txt output.
	 *
	 * Only excludes posts marked noindex by SEO plugins. Everything else
	 * is controlled via taxonomy settings in the admin (Exclude Categories,
	 * Exclude Tags) and post type selection.
	 *
	 * @param WP_Post $post The post to check.
	 * @return bool True if the post should be excluded.
	 */
	private static function should_exclude_from_llms( WP_Post $post ): bool {
		$post_id = $post->ID;

		// ── Yoast noindex ────────────────────────────────────────────────
		if ( defined( 'WPSEO_VERSION' ) ) {
			$yoast_noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
			if ( '1' === $yoast_noindex ) {
				return true;
			}
		}

		// ── AIOSEO noindex ───────────────────────────────────────────────
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$aioseo_noindex = get_post_meta( $post_id, '_aioseo_noindex', true );
			if ( '1' === (string) $aioseo_noindex ) {
				return true;
			}
		}

		// ── SEOPress noindex ─────────────────────────────────────────────
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$sp_noindex = get_post_meta( $post_id, '_seopress_robots_index', true );
			if ( 'yes' === $sp_noindex ) {
				return true;
			}
		}

		// ── Rank Math noindex (secondary check — primary is in meta_query) ──
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_robots = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) ) {
				return true;
			}
		}

		/**
		 * Filter to exclude specific posts from llms.txt.
		 *
		 * @param bool    $exclude Whether to exclude the post (default false).
		 * @param WP_Post $post    The post being checked.
		 */
		return (bool) apply_filters( 'rankready_exclude_from_llms', false, $post );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function clean_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		// Collapse runs of spaces/tabs within each line, but preserve newlines.
		$text = preg_replace( '/[^\S\n]+/', ' ', $text );
		// Collapse 3+ consecutive newlines to 2.
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		return trim( $text );
	}

	private static function get_post_description( $post ): string {
		// Try Yoast.
		$yoast = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		if ( ! empty( $yoast ) ) {
			return self::clean_text( $yoast );
		}

		// Try Rank Math.
		$rankmath = get_post_meta( $post->ID, 'rank_math_description', true );
		if ( ! empty( $rankmath ) ) {
			return self::clean_text( $rankmath );
		}

		// Try AIOSEO.
		$aioseo = get_post_meta( $post->ID, '_aioseo_description', true );
		if ( ! empty( $aioseo ) ) {
			return self::clean_text( $aioseo );
		}

		// Excerpt.
		if ( ! empty( $post->post_excerpt ) ) {
			return self::clean_text( $post->post_excerpt );
		}

		// Auto excerpt.
		$content = wp_strip_all_tags( do_shortcode( $post->post_content ) );
		return self::clean_text( wp_trim_words( $content, 30, '...' ) );
	}
}
