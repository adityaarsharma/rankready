<?php
/**
 * Plugin Name:       RankReady – AI & LLM SEO for ChatGPT, Perplexity & Google AI
 * Plugin URI:        https://posimyth.com
 * Description:       Make your WordPress site cited by ChatGPT, Perplexity, Claude, Gemini, and Google AI Overviews. AI summaries, FAQ schema, llms.txt, agent discovery headers, WebMCP, and crawler controls — in one plugin.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            POSIMYTH Inc. & Aditya Sharma
 * Author URI:        https://posimyth.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rankready-ai-llm-seo
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// ═════════════════════════════════════════════════════════════════════════════
// Duplicate-install guard — prevent fatals when two copies are active.
// ─────────────────────────────────────────────────────────────────────────────
// WordPress does not dedupe plugin installs by slug. If a site ends up with
// two RankReady folders in /wp-content/plugins/ (e.g. one installed from a
// GitHub "Source code" zip named "RankReady-LLM-SEO-EEAT-AI-Optimization-1.5"
// and one from a release asset named "rankready"), WordPress will happily
// try to activate both. The second copy used to fatal the entire site because
// the autoloader captured RNRD_DIR from the first copy's location but the
// second copy's classes were in a different directory. This guard makes the
// second-loaded copy bail out cleanly with a dashboard notice instead.
//
// Regardless of folder name: the FIRST plugin file to define RNRD_VERSION wins.
// Every subsequent copy becomes a no-op and surfaces a warning to admins.
// ═════════════════════════════════════════════════════════════════════════════
if ( defined( 'RNRD_VERSION' ) ) {
	add_action( 'admin_notices', function (): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo '<strong>RankReady:</strong> ';
		echo esc_html( sprintf(
			/* translators: 1: active version, 2: second plugin folder name */
			__( 'Another copy of RankReady is already active (version %1$s). The duplicate copy in "%2$s" has been disabled automatically to prevent conflicts. Delete the older folder from Plugins → Installed Plugins or via SFTP.', 'rankready-ai-llm-seo' ),
			RNRD_VERSION,
			basename( __DIR__ )
		) );
		echo '</p></div>';
	} );
	return; // Abort the rest of this file. No constants, no autoloader, no hooks.
}

// ── Constants (guarded to prevent conflicts) ─────────────────────────────────
if ( ! defined( 'RNRD_VERSION' ) ) {
	define( 'RNRD_VERSION',  '1.0.1' );
	define( 'RNRD_FILE',     __FILE__ );
	define( 'RNRD_DIR',      plugin_dir_path( __FILE__ ) );
	define( 'RNRD_URL',      plugin_dir_url( __FILE__ ) );
	define( 'RNRD_BASENAME', plugin_basename( __FILE__ ) );

	// Free build has NO monthly caps. Manual AI Summary + FAQ generation is
	// unlimited. Auto/Bulk generation are "Coming Soon" placeholders, not
	// capped — they're simply not yet implemented in the Free build.
	//
	// No store URL constant either. The Free WP.org build relies entirely on
	// WordPress.org's native update mechanism — no EDD updater, no Plugin
	// Update Checker, no custom-update-server endpoint, no license activation.
	// Pro (internal-only) lives in a separate branch with its own distribution.

	// Option keys — LLM provider selection (multi-provider, since v1.1.1).
	// `RNRD_OPT_KEY` and `RNRD_OPT_MODEL` below remain the OpenAI key/model for
	// backwards compatibility — every existing install keeps working.
	define( 'RNRD_OPT_LLM_PROVIDER',     'rnrd_llm_provider' ); // 'openai' | 'anthropic' | 'gemini' | 'deepseek'

	// Anthropic (Claude).
	define( 'RNRD_OPT_ANTHROPIC_KEY',    'rnrd_anthropic_api_key' );
	define( 'RNRD_OPT_ANTHROPIC_MODEL',  'rnrd_anthropic_model' );

	// Google Gemini.
	define( 'RNRD_OPT_GEMINI_KEY',       'rnrd_gemini_api_key' );
	define( 'RNRD_OPT_GEMINI_MODEL',     'rnrd_gemini_model' );

	// DeepSeek.
	define( 'RNRD_OPT_DEEPSEEK_KEY',     'rnrd_deepseek_api_key' );
	define( 'RNRD_OPT_DEEPSEEK_MODEL',   'rnrd_deepseek_model' );

	// "What's new" banner — last seen plugin version, per-user dismiss.
	define( 'RNRD_OPT_INSTALLED_VERSION', 'rnrd_installed_version' );

	// Option keys — AI Summary (OpenAI legacy keys, kept for back-compat).
	define( 'RNRD_OPT_KEY',              'rnrd_openai_api_key' );
	define( 'RNRD_OPT_MODEL',            'rnrd_openai_model' );
	define( 'RNRD_OPT_POST_TYPES',       'rnrd_post_types' );
	define( 'RNRD_OPT_LABEL',            'rnrd_default_label' );
	define( 'RNRD_OPT_SHOW_LABEL',       'rnrd_default_show_label' );
	define( 'RNRD_OPT_HEADING_TAG',      'rnrd_default_heading_tag' );
	define( 'RNRD_OPT_AUTO_GENERATE',    'rnrd_auto_generate' );
	define( 'RNRD_OPT_AUTO_DISPLAY',     'rnrd_auto_display' );
	define( 'RNRD_OPT_DISPLAY_POSITION', 'rnrd_display_position' );
	define( 'RNRD_OPT_CUSTOM_PROMPT',    'rnrd_custom_prompt' );
	define( 'RNRD_OPT_PRODUCT_CONTEXT',  'rnrd_product_context' );

	// Option keys — LLMs.txt.
	define( 'RNRD_OPT_LLMS_ENABLE',       'rnrd_llms_enable' );
	define( 'RNRD_OPT_LLMS_SITE_NAME',    'rnrd_llms_site_name' );
	define( 'RNRD_OPT_LLMS_SUMMARY',      'rnrd_llms_summary' );
	define( 'RNRD_OPT_LLMS_ABOUT',        'rnrd_llms_about' );
	define( 'RNRD_OPT_LLMS_POST_TYPES',   'rnrd_llms_post_types' );
	define( 'RNRD_OPT_LLMS_MAX_POSTS',    'rnrd_llms_max_posts' );
	define( 'RNRD_OPT_LLMS_CACHE_TTL',    'rnrd_llms_cache_ttl' );
	define( 'RNRD_OPT_LLMS_FULL_ENABLE',  'rnrd_llms_full_enable' );

	// Option keys — LLMs.txt taxonomy controls.
	define( 'RNRD_OPT_LLMS_EXCLUDE_CATS',    'rnrd_llms_exclude_cats' );
	define( 'RNRD_OPT_LLMS_EXCLUDE_TAGS',    'rnrd_llms_exclude_tags' );
	define( 'RNRD_OPT_LLMS_SHOW_CATEGORIES', 'rnrd_llms_show_categories' );

	// Option keys — LLM Crawler robots.txt controls.
	define( 'RNRD_OPT_ROBOTS_ENABLE',   'rnrd_robots_enable' );
	define( 'RNRD_OPT_ROBOTS_CRAWLERS', 'rnrd_robots_crawlers' );

	// Option keys — Content Signals (contentsignals.org).
	define( 'RNRD_OPT_CONTENT_SIGNALS_ENABLE',   'rnrd_content_signals_enable' );
	define( 'RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN', 'rnrd_content_signals_ai_train' );
	define( 'RNRD_OPT_CONTENT_SIGNALS_SEARCH',   'rnrd_content_signals_search' );
	define( 'RNRD_OPT_CONTENT_SIGNALS_AI_INPUT', 'rnrd_content_signals_ai_input' );

	// Option keys — Markdown.
	define( 'RNRD_OPT_MD_ENABLE',         'rnrd_md_enable' );
	define( 'RNRD_OPT_MD_POST_TYPES',     'rnrd_md_post_types' );
	define( 'RNRD_OPT_MD_INCLUDE_META',   'rnrd_md_include_meta' );

	// Option keys — Schema Automation.
	define( 'RNRD_OPT_SCHEMA_ARTICLE',    'rnrd_schema_article' );
	define( 'RNRD_OPT_SCHEMA_FAQ',        'rnrd_schema_faq' );
	define( 'RNRD_OPT_SCHEMA_HOWTO',      'rnrd_schema_howto' );
	define( 'RNRD_OPT_SCHEMA_ITEMLIST',   'rnrd_schema_itemlist' );
	define( 'RNRD_OPT_SCHEMA_SPEAKABLE',  'rnrd_schema_speakable' );
	define( 'RNRD_OPT_SCHEMA_BATCH_SIZE', 'rnrd_schema_batch_size' );

	// Meta keys — Schema Automation (stored by WP-Cron scanner).
	define( 'RNRD_META_SCHEMA_TYPE', '_rnrd_schema_type' );   // 'howto', 'itemlist', or ''
	define( 'RNRD_META_SCHEMA_DATA', '_rnrd_schema_data' );   // Serialized schema array
	define( 'RNRD_META_SCHEMA_HASH', '_rnrd_schema_hash' );   // md5(title+content) for change detection

	// Cron — Schema scanner.
	define( 'RNRD_SCHEMA_CRON_HOOK', 'rnrd_schema_scan' );

	// Cron — Bulk operations (run even after browser close).
	define( 'RNRD_CRON_BULK_STARTOVER', 'rnrd_cron_bulk_startover' );
	define( 'RNRD_CRON_BULK_FAQ',       'rnrd_cron_bulk_faq' );
	define( 'RNRD_CRON_BULK_SUMMARY',   'rnrd_cron_bulk_summary' );

	// Bulk state — Schema scan.
	define( 'RNRD_SCHEMA_QUEUE',   'rnrd_schema_queue' );
	define( 'RNRD_SCHEMA_DONE',    'rnrd_schema_done' );
	define( 'RNRD_SCHEMA_TOTAL',   'rnrd_schema_total' );
	define( 'RNRD_SCHEMA_RUNNING', 'rnrd_schema_running' );

	// Option keys — FAQ.
	define( 'RNRD_OPT_DFS_LOGIN',        'rnrd_dfs_login' );
	define( 'RNRD_OPT_DFS_PASSWORD',     'rnrd_dfs_password' );
	define( 'RNRD_OPT_FAQ_POST_TYPES',   'rnrd_faq_post_types' );
	define( 'RNRD_OPT_FAQ_COUNT',        'rnrd_faq_count' );
	define( 'RNRD_OPT_FAQ_BRAND_TERMS',  'rnrd_faq_brand_terms' );
	define( 'RNRD_OPT_FAQ_AUTO_DISPLAY', 'rnrd_faq_auto_display' );
	define( 'RNRD_OPT_FAQ_POSITION',     'rnrd_faq_position' );
	define( 'RNRD_OPT_FAQ_HEADING_TAG',  'rnrd_faq_heading_tag' );
	define( 'RNRD_OPT_FAQ_SHOW_REVIEWED','rnrd_faq_show_reviewed' );
	define( 'RNRD_OPT_FAQ_AUTO_GENERATE','rnrd_faq_auto_generate' );

	// Data retention.
	define( 'RNRD_OPT_DELETE_ON_UNINSTALL', 'rnrd_delete_on_uninstall' );

	// rc.11 — Hide "Generated from RankReady" credit line (placeholder, Coming Soon).
	// Today: option ignored, credit always shows.
	define( 'RNRD_OPT_HIDE_BRANDING', 'rnrd_hide_branding' );

	// Option keys — Author Box (EEAT).
	define( 'RNRD_OPT_AUTHOR_ENABLE',         'rnrd_author_enable' );          // Master toggle for the feature.
	define( 'RNRD_OPT_AUTHOR_AUTO_DISPLAY',   'rnrd_author_auto_display' );    // 'off' | 'before' | 'after' | 'both'
	define( 'RNRD_OPT_AUTHOR_LAYOUT',         'rnrd_author_layout' );          // 'card' | 'compact' | 'inline'
	define( 'RNRD_OPT_AUTHOR_HEADING',        'rnrd_author_heading' );         // Default heading text ("About the Author").
	define( 'RNRD_OPT_AUTHOR_HEADING_TAG',    'rnrd_author_heading_tag' );     // Default heading tag.
	define( 'RNRD_OPT_AUTHOR_SCHEMA_ENABLE',  'rnrd_author_schema_enable' );   // Emit Person schema (auto-skipped vs SEO plugins → merged instead).
	define( 'RNRD_OPT_AUTHOR_EDITORIAL_URL',  'rnrd_author_editorial_url' );   // Site-wide publishingPrinciples URL.
	define( 'RNRD_OPT_AUTHOR_FACTCHECK_URL',  'rnrd_author_factcheck_url' );   // "How we fact-check" URL (footer link).
	define( 'RNRD_OPT_AUTHOR_POST_TYPES',     'rnrd_author_post_types' );      // Which post types auto-display the box on.
	define( 'RNRD_OPT_AUTHOR_TRUST_ENABLE',   'rnrd_author_trust_enable' );    // Opt-in for the per-post Fact-Checked/Reviewed/Last-Reviewed panel.

	// Per-post meta keys — Author Trust panel.
	define( 'RNRD_META_AUTHOR_FACT_CHECKED_BY', '_rnrd_author_fact_checked_by' );  // user_id of fact-checker
	define( 'RNRD_META_AUTHOR_REVIEWED_BY',     '_rnrd_author_reviewed_by' );      // user_id of reviewer
	define( 'RNRD_META_AUTHOR_LAST_REVIEWED',   '_rnrd_author_last_reviewed' );    // YYYY-MM-DD string
	define( 'RNRD_META_AUTHOR_DISABLE',         '_rnrd_author_disable' );          // per-post opt-out

	// Headless / Public API options.
	define( 'RNRD_OPT_HEADLESS_ENABLE',          'rnrd_headless_enable' );            // Master toggle for public read-only API.
	define( 'RNRD_OPT_HEADLESS_CORS_ORIGINS',    'rnrd_headless_cors_origins' );      // Comma-separated allowed frontend origins.
	define( 'RNRD_OPT_HEADLESS_EXPOSE_META',     'rnrd_headless_expose_meta' );       // Register _rnrd_faq / _rnrd_summary in core REST.
	define( 'RNRD_OPT_HEADLESS_CACHE_TTL',       'rnrd_headless_cache_ttl' );         // CDN cache max-age in seconds (s-maxage).
	define( 'RNRD_OPT_HEADLESS_RATE_LIMIT',      'rnrd_headless_rate_limit' );        // Requests per minute per IP.
	define( 'RNRD_OPT_HEADLESS_REVALIDATE_URL',  'rnrd_headless_revalidate_url' );    // Next.js/Nuxt webhook URL.
	define( 'RNRD_OPT_HEADLESS_REVALIDATE_SEC',  'rnrd_headless_revalidate_secret' ); // Shared secret for webhook auth.
	define( 'RNRD_OPT_HEADLESS_GRAPHQL',         'rnrd_headless_graphql' );           // Register WPGraphQL fields.

	// ── v1.2.0 — Agent Ready options ──────────────────────────────────────────
	// Brand Terms — single canonical input wired to llms.txt, robots.txt, FAQ prompt, and summary prompt.
	define( 'RNRD_OPT_BRAND_TERMS',          'rnrd_brand_terms' );

	// AI snippet preview controls.
	define( 'RNRD_OPT_MAX_SNIPPET_DEFAULT',  'rnrd_max_snippet_default' );  // 'on'/'off' — default for new posts
	define( 'RNRD_META_MAX_SNIPPET',          '_rnrd_max_snippet' );          // per-post override: 'on'|'off'|'' (inherit)

	// Per-post llms.txt exclusion.
	define( 'RNRD_META_LLMS_EXCLUDE',         '_rnrd_llms_exclude' );         // '1' = exclude this post from llms.txt

	// AI Referral Traffic — daily counts per source, rolling 30 days.
	define( 'RNRD_OPT_AI_REFERRAL_STATS',     'rnrd_ai_referral_stats' );
	define( 'RNRD_OPT_AI_REFERRAL_ENABLE',    'rnrd_ai_referral_enable' ); // 'on' | 'off' — master toggle.

	// WebMCP — master toggle for /.well-known/mcp.json + Abilities API registration.
	define( 'RNRD_OPT_MCP_ENABLE',            'rnrd_mcp_enable' );          // 'on' | 'off'

	// WebMCP — per-resource exposure toggles (v1.2.0-beta.6).
	// Sensible defaults: public content ON, PII/heavy/stack-reveal resources OFF.
	define( 'RNRD_OPT_MCP_EXPOSE_POSTS',      'rnrd_mcp_expose_posts' );      // ON  — core public content
	define( 'RNRD_OPT_MCP_EXPOSE_PAGES',      'rnrd_mcp_expose_pages' );      // ON  — static pages
	define( 'RNRD_OPT_MCP_EXPOSE_AUTHORS',    'rnrd_mcp_expose_authors' );    // ON  — EEAT signal
	define( 'RNRD_OPT_MCP_EXPOSE_TAXONOMIES', 'rnrd_mcp_expose_taxonomies' ); // ON  — discovery graph
	define( 'RNRD_OPT_MCP_EXPOSE_SITEMAP',    'rnrd_mcp_expose_sitemap' );    // ON  — cold-crawl seed
	define( 'RNRD_OPT_MCP_EXPOSE_MENUS',      'rnrd_mcp_expose_menus' );      // ON  — public anyway
	define( 'RNRD_OPT_MCP_EXPOSE_LLMS_TXT',   'rnrd_mcp_expose_llms_txt' );   // ON  — content already public
	define( 'RNRD_OPT_MCP_EXPOSE_RR_AI',      'rnrd_mcp_expose_rr_ai' );      // ON  — summaries / FAQs / brand
	define( 'RNRD_OPT_MCP_EXPOSE_FRESHNESS',  'rnrd_mcp_expose_freshness' );  // ON  — public surface
	define( 'RNRD_OPT_MCP_EXPOSE_CPTS',       'rnrd_mcp_expose_cpts' );       // array — opt-in per CPT
	define( 'RNRD_OPT_MCP_EXPOSE_COMMENTS',   'rnrd_mcp_expose_comments' );   // OFF — PII (author names/emails)
	define( 'RNRD_OPT_MCP_EXPOSE_MEDIA',      'rnrd_mcp_expose_media' );      // OFF — heavy + non-attached uploads
	define( 'RNRD_OPT_MCP_EXPOSE_USERS',      'rnrd_mcp_expose_users' );      // OFF — PII (full user list)
	define( 'RNRD_OPT_MCP_EXPOSE_PLUGINS',    'rnrd_mcp_expose_plugins' );    // OFF — reveals stack / attack surface
	define( 'RNRD_OPT_MCP_EXPOSE_THEMES',     'rnrd_mcp_expose_themes' );     // OFF — reveals stack
	define( 'RNRD_OPT_MCP_EXPOSE_SETTINGS',   'rnrd_mcp_expose_settings' );   // OFF — may leak secrets

	// Markdown layer sub-toggles (controlled inside the Markdown Endpoints card).
	define( 'RNRD_OPT_MD_HINT_DIV',           'rnrd_md_hint_div' );         // 'on' | 'off' — hidden AI-hint div in body
	define( 'RNRD_OPT_MD_BOT_AUTO_SERVE',     'rnrd_md_bot_auto_serve' );   // 'on' | 'off' — UA-based forced markdown for AI bots

	// Meta keys.
	define( 'RNRD_META_SUMMARY',   '_rnrd_summary' );
	define( 'RNRD_META_HASH',      '_rnrd_content_hash' );
	define( 'RNRD_META_GENERATED', '_rnrd_last_generated' );
	define( 'RNRD_META_DISABLE',   '_rnrd_disable_summary' );

	// Meta keys — FAQ.
	define( 'RNRD_META_FAQ',           '_rnrd_faq' );
	define( 'RNRD_META_FAQ_HASH',      '_rnrd_faq_hash' );
	define( 'RNRD_META_FAQ_GENERATED',    '_rnrd_faq_generated' );
	define( 'RNRD_META_FAQ_DISABLE',      '_rnrd_faq_disable' );
	define( 'RNRD_META_FAQ_KEYWORD',      '_rnrd_faq_keyword' );
	define( 'RNRD_META_FAQ_LAST_FAILURE', '_rnrd_faq_last_failure' ); // v1.1.3 — circuit-breaker timestamp.

	// Cron.
	define( 'RNRD_CRON_HOOK', 'rnrd_async_generate' );

	// Bulk state — summary.
	define( 'RNRD_BULK_QUEUE',   'rnrd_bulk_queue' );
	define( 'RNRD_BULK_DONE',    'rnrd_bulk_done' );
	define( 'RNRD_BULK_TOTAL',   'rnrd_bulk_total' );
	define( 'RNRD_BULK_RUNNING', 'rnrd_bulk_running' );

	// Bulk state — FAQ.
	define( 'RNRD_FAQ_QUEUE',   'rnrd_faq_queue' );
	define( 'RNRD_FAQ_DONE',    'rnrd_faq_done' );
	define( 'RNRD_FAQ_TOTAL',   'rnrd_faq_total' );
	define( 'RNRD_FAQ_RUNNING', 'rnrd_faq_running' );

	// Bulk state — start over.
	define( 'RNRD_SO_QUEUE',   'rnrd_so_queue' );
	define( 'RNRD_SO_DONE',    'rnrd_so_done' );
	define( 'RNRD_SO_TOTAL',   'rnrd_so_total' );
	define( 'RNRD_SO_RUNNING', 'rnrd_so_running' );

	// Bulk state — author.
	define( 'RNRD_BAC_QUEUE',   'rnrd_bac_queue' );
	define( 'RNRD_BAC_TOTAL',   'rnrd_bac_total' );
	define( 'RNRD_BAC_DONE',    'rnrd_bac_done' );
	define( 'RNRD_BAC_RUNNING', 'rnrd_bac_running' );
	define( 'RNRD_BAC_TO',      'rnrd_bac_to_author' );

	// Transient keys.
	define( 'RNRD_LLMS_CACHE_KEY',      'rnrd_llms_txt_cache' );
	define( 'RNRD_LLMS_FULL_CACHE_KEY', 'rnrd_llms_full_txt_cache' );
}

// ── rnrd_is_pro() — Pro extension point ─────────────────────────────────────
// The Free build always returns false by default. The companion Pro addon
// (or any third party with permission) opts in by attaching to the
// `rnrd_is_pro` filter:
//
//     add_filter( 'rnrd_is_pro', '__return_true' );
//
// This filter pattern means Pro never needs to win a function_exists race,
// never needs a mu-plugin trick, never needs to load before Free in the
// plugin order — it just hooks in like any other WordPress filter. Free
// stays the single source of truth for the function itself.
if ( ! function_exists( 'rnrd_is_pro' ) ) {
	function rnrd_is_pro(): bool {
		return (bool) apply_filters( 'rnrd_is_pro', false );
	}
}

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
	if ( 0 !== strpos( $class, 'RNRD_' ) ) {
		return;
	}
	$file = RNRD_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// ── Duplicate-install scanner (belt + braces) ────────────────────────────────
// The guard at the top of this file stops the second-loaded copy from running,
// but the first-loaded copy has no way to know a duplicate exists until
// something queries active_plugins. This hook scans active_plugins on every
// admin page load and shows a warning to admins if more than one plugin file
// ending in /rankready.php is active. The check is cheap — one array_filter
// over a single option read — and only runs when is_admin() is true.
add_action( 'admin_init', function (): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$active = (array) get_option( 'active_plugins', array() );
	$rnrd_entries = array_values( array_filter( $active, function ( $plugin_file ) {
		return 'rankready.php' === basename( (string) $plugin_file );
	} ) );
	if ( count( $rnrd_entries ) <= 1 ) {
		return;
	}
	add_action( 'admin_notices', function () use ( $rnrd_entries ): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo '<strong>RankReady:</strong> ';
		echo esc_html__( 'Multiple RankReady plugin folders are active at the same time. Only one is running; the rest are disabled by the duplicate-install guard but are still consuming a slot in active_plugins. Deactivate the duplicates to silence this notice:', 'rankready-ai-llm-seo' );
		echo '</p><ul style="margin-left:20px;list-style:disc;">';
		foreach ( $rnrd_entries as $entry ) {
			echo '<li><code>' . esc_html( dirname( (string) $entry ) ) . '/</code></li>';
		}
		echo '</ul><p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">';
		echo esc_html__( 'Go to Plugins → Installed Plugins', 'rankready-ai-llm-seo' );
		echo '</a></p></div>';
	} );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Updates: handled by WordPress.org SVN (auto-updates via Plugins screen).
// ─────────────────────────────────────────────────────────────────────────────
// v1.0.0+ ships exclusively from WordPress.org. The Plugin Update Checker
// (PUC) library was removed from the WP.org distribution per WP.org policy.
// ═════════════════════════════════════════════════════════════════════════════

// ═════════════════════════════════════════════════════════════════════════════
// Folder name enforcement — WP.org policy compliant version.
// ─────────────────────────────────────────────────────────────────────────────
// Only ONE guard remains: upgrader_source_selection — renames the EXTRACTED
// temp folder during plugin install/update. This is allowed under WP.org
// guidelines because it operates only on the staging directory during the
// upgrade process; it does NOT touch wp-content/plugins/ post-install and
// does NOT modify the active_plugins option.
//
// Removed for WP.org policy compliance (May 2026 review):
//   - admin_init auto-migration that renamed the plugin folder in place
//   - direct write to the active plugins option after folder rename
//   - multisite sitewide active plugins option rewrite
//
// If a user installs from a non-canonical zip (GitHub "Download ZIP"), the
// duplicate-install guard at the top of this file handles deactivation
// cleanly and the user is shown a dashboard notice telling them to rename
// the folder via SFTP. No silent DB writes.
// ═════════════════════════════════════════════════════════════════════════════

add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra ) {
	if ( ! is_object( $upgrader ) || ! is_a( $upgrader, 'Plugin_Upgrader' ) ) {
		return $source;
	}

	$source = trailingslashit( $source );
	$main   = $source . 'rankready.php';

	if ( ! is_readable( $main ) ) {
		return $source;
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data = get_plugin_data( $main, false, false );
	if ( empty( $data['Name'] ) || false === stripos( $data['Name'], 'RankReady' ) ) {
		return $source;
	}

	$current = basename( untrailingslashit( $source ) );
	if ( 'rankready-ai-llm-seo' === $current ) {
		return $source;
	}

	$new_source = trailingslashit( $remote_source ) . 'rankready/';

	if ( file_exists( $new_source ) ) {
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $new_source, true );
		}
	}

	// FREE-102 — use WP_Filesystem::move() instead of native rename() per WP.org policy.
	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
	}
	if ( ! $wp_filesystem || ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $new_source ), true ) ) {
		return $source;
	}

	return $new_source;
}, 1, 4 );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
	// FREE-102 — load_plugin_textdomain() removed. Since WP 4.6 WordPress.org
	// auto-loads translations for plugins hosted on WordPress.org. Calling it
	// manually triggers a PCP warning and is no longer needed for the .org build.

	if ( version_compare( get_bloginfo( 'version' ), '6.2', '<' ) ) {
		add_action( 'admin_notices', function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'RankReady requires WordPress 6.2 or higher.', 'rankready-ai-llm-seo' )
				. '</p></div>';
		} );
		return;
	}

	// Auto-flush rewrite rules after plugin update (activation hook doesn't fire on updates).
	$stored_version = get_option( 'rnrd_installed_version', '' );
	if ( $stored_version !== RNRD_VERSION ) {
		// v1.2.0-rc.1 — silent-upgrade safety. Existing v1.1.x installs
		// should NOT auto-flip new behaviour-changing toggles on. The
		// register_setting() defaults make these ON for fresh installs;
		// for upgrades, we explicitly seed 'off' if the option row is
		// missing AND the previous version is v1.1.x or earlier.
		// (Audit beta.3 #7.)
		if ( '' !== $stored_version && version_compare( $stored_version, '1.2.0-beta.1', '<' ) ) {
			$upgrade_safe_off = array(
				'rnrd_max_snippet_default',  // emits <meta robots> sitewide
				'rnrd_ai_referral_enable',   // tracks Referer on every pageview (privacy)
				'rnrd_mcp_enable',           // publishes /.well-known/mcp.json
				'rnrd_md_hint_div',          // injects hidden div on every post
				'rnrd_md_bot_auto_serve',    // UA-based markdown switching
			);
			foreach ( $upgrade_safe_off as $opt ) {
				if ( false === get_option( $opt, false ) ) {
					update_option( $opt, 'off', false );
				}
			}
		}

		update_option( 'rnrd_installed_version', RNRD_VERSION );
		// Defer rewrite rule registration + flush to 'init' — $wp_rewrite is not
		// ready at plugins_loaded and calling add_rewrite_rule() before init causes
		// a fatal "Call to a member function add_rule() on null".
		add_action( 'init', function () {
			RNRD_Llms_Txt::add_rewrite_rules();
			RNRD_Markdown::add_rewrite_rules();
			RNRD_MCP::add_manifest_rewrite(); // v1.2.0 — /.well-known/mcp.json
			flush_rewrite_rules( false );
		}, 99 );
		RNRD_Llms_Txt::sync_physical_robots_txt();

		// Migrate data from old AI Post Summary plugin (_aps_ meta) if present.
		// Only run once — skip if already migrated.
		if ( ! get_option( 'rnrd_aps_migrated' ) ) {
			global $wpdb;
			// FREE-102 — one-time legacy data migration from AI Post Summary plugin.
			// Runs ONCE per site (guarded by rnrd_aps_migrated option), so caching
			// would never hit. Direct DB query is the only correct choice here —
			// get_post_meta() would require iterating every post on the site.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$has_aps = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 1",
				'_aps_summary'
			) );
			if ( $has_aps > 0 ) {
				// Update existing empty _rnrd_summary entries with old _aps_summary data.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->postmeta} rr
					 INNER JOIN {$wpdb->postmeta} aps ON aps.post_id = rr.post_id AND aps.meta_key = %s AND aps.meta_value != ''
					 SET rr.meta_value = aps.meta_value
					 WHERE rr.meta_key = %s AND (rr.meta_value = '' OR rr.meta_value IS NULL)",
					'_aps_summary',
					'_rnrd_summary'
				) );
				// Insert for posts that have _aps_summary but no _rnrd_summary row at all.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
					 SELECT pm.post_id, %s, pm.meta_value
					 FROM {$wpdb->postmeta} pm
					 WHERE pm.meta_key = %s
					   AND pm.meta_value != ''
					   AND pm.post_id NOT IN (
					       SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s
					   )",
					'_rnrd_summary',
					'_aps_summary',
					'_rnrd_summary'
				) );
			}
			update_option( 'rnrd_aps_migrated', true );
		}
	}

	// ── Self-healing rewrite rules (1.7.0) ─────────────────────────────────────
	// Problem: flush_rewrite_rules() only fires via update_option_ hooks, which
	// only trigger when a value *changes*. If llms/md were already 'on' before
	// save, the hook never fires and rules stay missing.
	//
	// Fix part 1: bust the "rules OK" transient on every settings save so the
	// self-heal re-runs, even when the saved value is unchanged.
	add_filter( 'pre_update_option_' . RNRD_OPT_LLMS_ENABLE,      function ( $v ) { delete_transient( 'rnrd_rewrite_ok' ); return $v; } );
	add_filter( 'pre_update_option_' . RNRD_OPT_LLMS_FULL_ENABLE, function ( $v ) { delete_transient( 'rnrd_rewrite_ok' ); return $v; } );
	add_filter( 'pre_update_option_' . RNRD_OPT_MD_ENABLE,        function ( $v ) { delete_transient( 'rnrd_rewrite_ok' ); return $v; } );

	// Fix part 2: on admin page loads, detect missing rules and auto-flush.
	// Transient throttles this to at most once per hour.
	add_action( 'admin_init', function (): void {
		if ( get_transient( 'rnrd_rewrite_ok' ) ) {
			return;
		}

		$rules = (array) get_option( 'rewrite_rules', array() );
		$needs = false;

		// Check llms.txt — skip if another plugin is known to handle it.
		if ( 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) && ! isset( $rules['^llms\.txt$'] ) ) {
			$rm_handles    = defined( 'RANK_MATH_VERSION' )
			                 && in_array( 'llms-txt', (array) get_option( 'rank_math_modules', array() ), true );
			$yoast_handles = defined( 'WPSEO_VERSION' )
			                 && ! empty( get_option( 'wpseo', array() )['enable_llms_txt'] );
			if ( ! $rm_handles && ! $yoast_handles ) {
				$needs = true;
			}
		}

		// Check llms-full.txt — never handled by other plugins.
		if ( ! $needs && 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ) && ! isset( $rules['^llms-full\.txt$'] ) ) {
			$needs = true;
		}

		// Check .md rewrite rule.
		if ( ! $needs && 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			$md_found = false;
			foreach ( array_keys( $rules ) as $k ) {
				if ( false !== strpos( $k, '\.md$' ) ) {
					$md_found = true;
					break;
				}
			}
			if ( ! $md_found ) {
				$needs = true;
			}
		}

		if ( $needs ) {
			RNRD_Llms_Txt::add_rewrite_rules();
			RNRD_Markdown::add_rewrite_rules();
			RNRD_MCP::add_manifest_rewrite(); // v1.2.0 — /.well-known/mcp.json
			flush_rewrite_rules( false );
		}

		set_transient( 'rnrd_rewrite_ok', 1, HOUR_IN_SECONDS );
	}, 20 );

	// Register custom cron schedules.
	add_filter( 'cron_schedules', function ( array $schedules ): array {
		if ( ! isset( $schedules['rnrd_five_minutes'] ) ) {
			$schedules['rnrd_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 Minutes (RankReady)', 'rankready-ai-llm-seo' ),
			);
		}
		if ( ! isset( $schedules['rnrd_one_minute'] ) ) {
			$schedules['rnrd_one_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every Minute (RankReady Bulk)', 'rankready-ai-llm-seo' ),
			);
		}
		return $schedules;
	} );

	RNRD_Admin::init();
	RNRD_Generator::init();
	RNRD_Block::init();
	RNRD_Rest::init();
	RNRD_Llms_Txt::init();
	RNRD_Markdown::init();
	RNRD_Faq::init();
	RNRD_Headless::init();
	RNRD_Author_Box::init();
	RNRD_Crawler_Log::init();

	// v1.2.0 — Agent Ready feature modules.
	RNRD_Welcome::init();          // 1-question onboarding flow on first activation.
	RNRD_Snippet::init();          // <meta robots max-snippet:-1> per-post + sitewide.
	RNRD_AI_Referral::init();      // Track AI-referrer visits (ChatGPT/Perplexity/etc).
	RNRD_Freshness::init();        // REST + bulk dateModified refresh.
	RNRD_Agent_Dashboard::init();  // Unified dashboard widget (consolidates AI Referral + Freshness).
	RNRD_MCP::init();              // WebMCP — WordPress Abilities API + /.well-known/mcp.json.
	RNRD_Diagnostics::init();      // v1.2.0-rc.5 — Live endpoint probes + conflict detection.
	RNRD_Cache::init();            // FREE-99 — cache compat init (Autoptimize asset excludes, etc).

	/**
	 * Fires after every core RankReady class has booted.
	 *
	 * The Pro addon attaches its own classes here so it has a guaranteed-safe
	 * moment to call the Free classes it composes with (RNRD_Generator,
	 * RNRD_Faq, etc.) without race conditions or order dependencies.
	 *
	 * @since 1.0.1
	 */
	do_action( 'rnrd_loaded' );

	// Free tier limits — REST endpoint for admin JS usage display.
	add_action( 'rest_api_init', array( 'RNRD_Limits', 'register_rest' ) );

	if ( did_action( 'elementor/loaded' ) ) {
		add_action( 'elementor/widgets/register', function ( $widgets_manager ): void {
			require_once RNRD_DIR . 'includes/class-rnrd-elementor.php';
			$widgets_manager->register( new RNRD_Elementor_Widget() );

			require_once RNRD_DIR . 'includes/class-rnrd-elementor-faq.php';
			$widgets_manager->register( new RNRD_Elementor_Faq_Widget() );

			require_once RNRD_DIR . 'includes/class-rnrd-elementor-author-box.php';
			$widgets_manager->register( new RNRD_Elementor_Author_Box_Widget() );
		} );

		add_action( 'elementor/frontend/after_enqueue_styles', function (): void {
			wp_enqueue_style( 'rankready-style', RNRD_URL . 'assets/style.css', array(), RNRD_VERSION );
		} );
	}
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( RNRD_FILE, function (): void {
	// v1.2.0 — flag the one-shot welcome redirect for first-time activations.
	// RNRD_Welcome::flag_activation() is a no-op when the welcome has already
	// been completed, so re-activating an existing install does NOT relaunch
	// the onboarding flow.
	if ( class_exists( 'RNRD_Welcome' ) ) {
		RNRD_Welcome::flag_activation();
	}

	if ( false === get_option( RNRD_OPT_POST_TYPES ) ) {
		update_option( RNRD_OPT_POST_TYPES, array( 'post' ) );
	}
	if ( false === get_option( RNRD_OPT_LABEL ) ) {
		update_option( RNRD_OPT_LABEL, 'Key Takeaways' );
	}
	if ( false === get_option( RNRD_OPT_SHOW_LABEL ) ) {
		update_option( RNRD_OPT_SHOW_LABEL, true );
	}
	if ( false === get_option( RNRD_OPT_HEADING_TAG ) ) {
		update_option( RNRD_OPT_HEADING_TAG, 'h4' );
	}
	// Create crawler access log table.
	RNRD_Crawler_Log::create_table();

	if ( false === get_option( RNRD_OPT_LLMS_ENABLE ) ) {
		update_option( RNRD_OPT_LLMS_ENABLE, 'off' );
	}
	if ( false === get_option( RNRD_OPT_MD_ENABLE ) ) {
		update_option( RNRD_OPT_MD_ENABLE, 'off' );
	}
	if ( false === get_option( RNRD_OPT_ROBOTS_ENABLE ) ) {
		update_option( RNRD_OPT_ROBOTS_ENABLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_ROBOTS_CRAWLERS ) ) {
		update_option( RNRD_OPT_ROBOTS_CRAWLERS, array_keys( RNRD_Admin::get_llm_crawlers() ) );
	}
	if ( false === get_option( RNRD_OPT_FAQ_COUNT ) ) {
		update_option( RNRD_OPT_FAQ_COUNT, 5 );
	}
	if ( false === get_option( RNRD_OPT_FAQ_HEADING_TAG ) ) {
		update_option( RNRD_OPT_FAQ_HEADING_TAG, 'h3' );
	}
	if ( false === get_option( RNRD_OPT_FAQ_AUTO_DISPLAY ) ) {
		update_option( RNRD_OPT_FAQ_AUTO_DISPLAY, 'off' );
	}
	if ( false === get_option( RNRD_OPT_FAQ_SHOW_REVIEWED ) ) {
		update_option( RNRD_OPT_FAQ_SHOW_REVIEWED, 'on' );
	}
	if ( false === get_option( RNRD_OPT_FAQ_AUTO_GENERATE ) ) {
		update_option( RNRD_OPT_FAQ_AUTO_GENERATE, 'off' );
	}
	// Author Box defaults.
	if ( false === get_option( RNRD_OPT_AUTHOR_ENABLE ) ) {
		update_option( RNRD_OPT_AUTHOR_ENABLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_AUTO_DISPLAY ) ) {
		update_option( RNRD_OPT_AUTHOR_AUTO_DISPLAY, 'off' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_LAYOUT ) ) {
		update_option( RNRD_OPT_AUTHOR_LAYOUT, 'card' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_HEADING ) ) {
		update_option( RNRD_OPT_AUTHOR_HEADING, 'About the Author' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_HEADING_TAG ) ) {
		update_option( RNRD_OPT_AUTHOR_HEADING_TAG, 'h3' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_SCHEMA_ENABLE ) ) {
		update_option( RNRD_OPT_AUTHOR_SCHEMA_ENABLE, 'on' );
	}
	if ( false === get_option( RNRD_OPT_AUTHOR_POST_TYPES ) ) {
		update_option( RNRD_OPT_AUTHOR_POST_TYPES, array( 'post' ) );
	}

	// Register rewrite rules before flushing so they get written.
	RNRD_Llms_Txt::add_rewrite_rules();
	RNRD_Markdown::add_rewrite_rules();
	RNRD_MCP::add_manifest_rewrite(); // v1.2.0 — /.well-known/mcp.json
	flush_rewrite_rules();

	// Sync to physical robots.txt if one exists.
	RNRD_Llms_Txt::sync_physical_robots_txt();

	// rc.16 audit fix C2 + M3 — persist exclusions to every cache plugin's
	// saved option so LSWS / FastCGI / WPSC honour our bypass BEFORE PHP runs.
	// Runtime filters alone aren't enough — server-level caches read the
	// persisted option before WordPress boots.
	if ( class_exists( 'RNRD_Cache' ) ) {
		RNRD_Cache::persist_exclusions( array(
			'/llms.txt',
			'/llms-full.txt',
			'/.well-known/mcp.json',
			'.md',
		) );
		// Unconditional purge — covers the case where a stale cache existed
		// before RankReady was activated.
		RNRD_Cache::purge_url( home_url( '/robots.txt' ) );
		RNRD_Cache::purge_url( home_url( '/llms.txt' ) );
		RNRD_Cache::purge_url( home_url( '/llms-full.txt' ) );
		RNRD_Cache::purge_url( home_url( '/.well-known/mcp.json' ) );
	}

	// Schedule schema scanner cron if not already scheduled.
	if ( ! wp_next_scheduled( RNRD_SCHEMA_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'rnrd_five_minutes', RNRD_SCHEMA_CRON_HOOK );
	}
} );

// Re-sync robots.txt and rewrite rules when the plugin is updated (activation hook
// doesn't fire on silent updates — version mismatch triggers it instead).
add_action( 'admin_init', function (): void {
	$stored = get_option( 'rnrd_installed_version', '' );
	if ( version_compare( $stored, RNRD_VERSION, '<' ) ) {
		update_option( 'rnrd_installed_version', RNRD_VERSION );
		RNRD_Llms_Txt::sync_physical_robots_txt();
		// rc.16 — re-persist cache exclusions on silent update; new bypass
		// rules (LSWS .htaccess option entries) won't exist on sites updated
		// from earlier RCs until they save settings or hit this admin_init.
		if ( class_exists( 'RNRD_Cache' ) ) {
			RNRD_Cache::persist_exclusions( array(
				'/llms.txt', '/llms-full.txt', '/.well-known/mcp.json', '.md',
			) );
		}
	}
} );

register_deactivation_hook( RNRD_FILE, function (): void {
	$timestamp = wp_next_scheduled( RNRD_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, RNRD_CRON_HOOK );
	}
	wp_clear_scheduled_hook( 'rnrd_async_faq_generate' );
	wp_clear_scheduled_hook( RNRD_SCHEMA_CRON_HOOK );

	// v1.2.0-beta.4 — clear cron hooks that were uncovered in beta.3 audit #9.
	wp_clear_scheduled_hook( 'rnrd_crawler_log_prune' );  // daily prune was leaving zombie queries against a (possibly dropped) table.
	wp_clear_scheduled_hook( RNRD_CRON_BULK_STARTOVER );
	wp_clear_scheduled_hook( RNRD_CRON_BULK_FAQ );
	wp_clear_scheduled_hook( RNRD_CRON_BULK_SUMMARY );
	update_option( RNRD_BULK_RUNNING, false );
	update_option( RNRD_BAC_RUNNING, false );
	update_option( RNRD_FAQ_RUNNING, false );
	update_option( RNRD_SCHEMA_RUNNING, false );
	delete_transient( RNRD_LLMS_CACHE_KEY );
	delete_transient( RNRD_LLMS_FULL_CACHE_KEY );

	// Clean up RankReady block from physical robots.txt on deactivation.
	$robots_file = ABSPATH . 'robots.txt';
	if ( file_exists( $robots_file ) ) {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( WP_Filesystem() && $wp_filesystem->exists( $robots_file ) && $wp_filesystem->is_writable( $robots_file ) ) {
			$contents = $wp_filesystem->get_contents( $robots_file );
			if ( false !== $contents && false !== strpos( $contents, 'RankReady' ) ) {
				$contents = preg_replace( '/\n?#[^\n]*LLM[^\n]*RankReady[^\n]*\n.*?(?=\n#[^-]|\n?$)/s', '', $contents );
				$contents = rtrim( $contents ) . "\n";
				$wp_filesystem->put_contents( $robots_file, $contents, FS_CHMOD_FILE );
			}
		}
	}

	flush_rewrite_rules();
} );
