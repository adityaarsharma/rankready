<?php
/**
 * Plugin Name:       RankReady – LLM SEO, EEAT & AI Optimization
 * Plugin URI:        https://github.com/adityaarsharma/rankready
 * Description:       AI summaries, FAQ generator, Author Box with EEAT schema, Article JSON-LD with speakable, LLMs.txt generator, Markdown endpoints, bulk author changer. Built for LLM SEO, EEAT, and AI Overviews.
 * Version:           0.6.4
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            POSIMYTH Innovations
 * Author URI:        https://posimyth.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rankready
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
// the autoloader captured RR_DIR from the first copy's location but the
// second copy's classes were in a different directory. This guard makes the
// second-loaded copy bail out cleanly with a dashboard notice instead.
//
// Regardless of folder name: the FIRST plugin file to define RR_VERSION wins.
// Every subsequent copy becomes a no-op and surfaces a warning to admins.
// ═════════════════════════════════════════════════════════════════════════════
if ( defined( 'RR_VERSION' ) ) {
	add_action( 'admin_notices', function (): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo '<strong>RankReady:</strong> ';
		echo esc_html( sprintf(
			/* translators: 1: active version, 2: second plugin folder name */
			__( 'Another copy of RankReady is already active (version %1$s). The duplicate copy in "%2$s" has been disabled automatically to prevent conflicts. Delete the older folder from Plugins → Installed Plugins or via SFTP.', 'rankready' ),
			RR_VERSION,
			basename( __DIR__ )
		) );
		echo '</p></div>';
	} );
	return; // Abort the rest of this file. No constants, no autoloader, no hooks.
}

// ── Constants (guarded to prevent conflicts) ─────────────────────────────────
if ( ! defined( 'RR_VERSION' ) ) {
	define( 'RR_VERSION',  '0.6.4' );
	define( 'RR_FILE',     __FILE__ );
	define( 'RR_DIR',      plugin_dir_path( __FILE__ ) );
	define( 'RR_URL',      plugin_dir_url( __FILE__ ) );
	define( 'RR_BASENAME', plugin_basename( __FILE__ ) );

	// Option keys — AI Summary.
	define( 'RR_OPT_KEY',              'rr_openai_api_key' );
	define( 'RR_OPT_MODEL',            'rr_openai_model' );
	define( 'RR_OPT_POST_TYPES',       'rr_post_types' );
	define( 'RR_OPT_LABEL',            'rr_default_label' );
	define( 'RR_OPT_SHOW_LABEL',       'rr_default_show_label' );
	define( 'RR_OPT_HEADING_TAG',      'rr_default_heading_tag' );
	define( 'RR_OPT_AUTO_GENERATE',    'rr_auto_generate' );
	define( 'RR_OPT_AUTO_DISPLAY',     'rr_auto_display' );
	define( 'RR_OPT_DISPLAY_POSITION', 'rr_display_position' );
	define( 'RR_OPT_CUSTOM_PROMPT',    'rr_custom_prompt' );
	define( 'RR_OPT_PRODUCT_CONTEXT',  'rr_product_context' );

	// Option keys — LLMs.txt.
	define( 'RR_OPT_LLMS_ENABLE',       'rr_llms_enable' );
	define( 'RR_OPT_LLMS_SITE_NAME',    'rr_llms_site_name' );
	define( 'RR_OPT_LLMS_SUMMARY',      'rr_llms_summary' );
	define( 'RR_OPT_LLMS_ABOUT',        'rr_llms_about' );
	define( 'RR_OPT_LLMS_POST_TYPES',   'rr_llms_post_types' );
	define( 'RR_OPT_LLMS_MAX_POSTS',    'rr_llms_max_posts' );
	define( 'RR_OPT_LLMS_CACHE_TTL',    'rr_llms_cache_ttl' );
	define( 'RR_OPT_LLMS_FULL_ENABLE',  'rr_llms_full_enable' );

	// Option keys — LLMs.txt taxonomy controls.
	define( 'RR_OPT_LLMS_EXCLUDE_CATS',    'rr_llms_exclude_cats' );
	define( 'RR_OPT_LLMS_EXCLUDE_TAGS',    'rr_llms_exclude_tags' );
	define( 'RR_OPT_LLMS_SHOW_CATEGORIES', 'rr_llms_show_categories' );

	// Option keys — LLM Crawler robots.txt controls.
	define( 'RR_OPT_ROBOTS_ENABLE',   'rr_robots_enable' );
	define( 'RR_OPT_ROBOTS_CRAWLERS', 'rr_robots_crawlers' );

	// Option keys — Content Signals (contentsignals.org).
	define( 'RR_OPT_CONTENT_SIGNALS_ENABLE',   'rr_content_signals_enable' );
	define( 'RR_OPT_CONTENT_SIGNALS_AI_TRAIN', 'rr_content_signals_ai_train' );
	define( 'RR_OPT_CONTENT_SIGNALS_SEARCH',   'rr_content_signals_search' );
	define( 'RR_OPT_CONTENT_SIGNALS_AI_INPUT', 'rr_content_signals_ai_input' );

	// Option keys — Agent Discovery (/.well-known/ endpoints).
	define( 'RR_OPT_AGENT_SKILLS_ENABLE', 'rr_agent_skills_enable' );
	define( 'RR_OPT_API_CATALOG_ENABLE',  'rr_api_catalog_enable' );

	// Option keys — Markdown.
	define( 'RR_OPT_MD_ENABLE',         'rr_md_enable' );
	define( 'RR_OPT_MD_POST_TYPES',     'rr_md_post_types' );
	define( 'RR_OPT_MD_INCLUDE_META',   'rr_md_include_meta' );

	// Option keys — Schema Automation.
	define( 'RR_OPT_SCHEMA_ARTICLE',    'rr_schema_article' );
	define( 'RR_OPT_SCHEMA_FAQ',        'rr_schema_faq' );
	define( 'RR_OPT_SCHEMA_HOWTO',      'rr_schema_howto' );
	define( 'RR_OPT_SCHEMA_ITEMLIST',   'rr_schema_itemlist' );
	define( 'RR_OPT_SCHEMA_SPEAKABLE',  'rr_schema_speakable' );
	define( 'RR_OPT_SCHEMA_BATCH_SIZE', 'rr_schema_batch_size' );

	// Meta keys — Schema Automation (stored by WP-Cron scanner).
	define( 'RR_META_SCHEMA_TYPE', '_rr_schema_type' );   // 'howto', 'itemlist', or ''
	define( 'RR_META_SCHEMA_DATA', '_rr_schema_data' );   // Serialized schema array
	define( 'RR_META_SCHEMA_HASH', '_rr_schema_hash' );   // md5(title+content) for change detection

	// Cron — Schema scanner.
	define( 'RR_SCHEMA_CRON_HOOK', 'rr_schema_scan' );

	// Cron — Bulk operations (run even after browser close).
	define( 'RR_CRON_BULK_STARTOVER', 'rr_cron_bulk_startover' );
	define( 'RR_CRON_BULK_FAQ',       'rr_cron_bulk_faq' );
	define( 'RR_CRON_BULK_SUMMARY',   'rr_cron_bulk_summary' );

	// Bulk state — Schema scan.
	define( 'RR_SCHEMA_QUEUE',   'rr_schema_queue' );
	define( 'RR_SCHEMA_DONE',    'rr_schema_done' );
	define( 'RR_SCHEMA_TOTAL',   'rr_schema_total' );
	define( 'RR_SCHEMA_RUNNING', 'rr_schema_running' );

	// Option keys — FAQ.
	define( 'RR_OPT_DFS_LOGIN',        'rr_dfs_login' );
	define( 'RR_OPT_DFS_PASSWORD',     'rr_dfs_password' );
	define( 'RR_OPT_FAQ_POST_TYPES',   'rr_faq_post_types' );
	define( 'RR_OPT_FAQ_COUNT',        'rr_faq_count' );
	define( 'RR_OPT_FAQ_BRAND_TERMS',  'rr_faq_brand_terms' );
	define( 'RR_OPT_FAQ_AUTO_DISPLAY', 'rr_faq_auto_display' );
	define( 'RR_OPT_FAQ_POSITION',     'rr_faq_position' );
	define( 'RR_OPT_FAQ_HEADING_TAG',  'rr_faq_heading_tag' );
	define( 'RR_OPT_FAQ_SHOW_REVIEWED','rr_faq_show_reviewed' );
	define( 'RR_OPT_FAQ_AUTO_GENERATE','rr_faq_auto_generate' );

	// Data retention.
	define( 'RR_OPT_DELETE_ON_UNINSTALL', 'rr_delete_on_uninstall' );

	// Option keys — Author Box (EEAT).
	define( 'RR_OPT_AUTHOR_ENABLE',         'rr_author_enable' );          // Master toggle for the feature.
	define( 'RR_OPT_AUTHOR_AUTO_DISPLAY',   'rr_author_auto_display' );    // 'off' | 'before' | 'after' | 'both'
	define( 'RR_OPT_AUTHOR_LAYOUT',         'rr_author_layout' );          // 'card' | 'compact' | 'inline'
	define( 'RR_OPT_AUTHOR_HEADING',        'rr_author_heading' );         // Default heading text ("About the Author").
	define( 'RR_OPT_AUTHOR_HEADING_TAG',    'rr_author_heading_tag' );     // Default heading tag.
	define( 'RR_OPT_AUTHOR_SCHEMA_ENABLE',  'rr_author_schema_enable' );   // Emit Person schema (auto-skipped vs SEO plugins → merged instead).
	define( 'RR_OPT_AUTHOR_EDITORIAL_URL',  'rr_author_editorial_url' );   // Site-wide publishingPrinciples URL.
	define( 'RR_OPT_AUTHOR_FACTCHECK_URL',  'rr_author_factcheck_url' );   // "How we fact-check" URL (footer link).
	define( 'RR_OPT_AUTHOR_POST_TYPES',     'rr_author_post_types' );      // Which post types auto-display the box on.
	define( 'RR_OPT_AUTHOR_TRUST_ENABLE',   'rr_author_trust_enable' );    // Opt-in for the per-post Fact-Checked/Reviewed/Last-Reviewed panel.

	// Per-post meta keys — Author Trust panel.
	define( 'RR_META_AUTHOR_FACT_CHECKED_BY', '_rr_author_fact_checked_by' );  // user_id of fact-checker
	define( 'RR_META_AUTHOR_REVIEWED_BY',     '_rr_author_reviewed_by' );      // user_id of reviewer
	define( 'RR_META_AUTHOR_LAST_REVIEWED',   '_rr_author_last_reviewed' );    // YYYY-MM-DD string
	define( 'RR_META_AUTHOR_DISABLE',         '_rr_author_disable' );          // per-post opt-out

	// Headless / Public API options.
	define( 'RR_OPT_HEADLESS_ENABLE',          'rr_headless_enable' );            // Master toggle for public read-only API.
	define( 'RR_OPT_HEADLESS_CORS_ORIGINS',    'rr_headless_cors_origins' );      // Comma-separated allowed frontend origins.
	define( 'RR_OPT_HEADLESS_EXPOSE_META',     'rr_headless_expose_meta' );       // Register _rr_faq / _rr_summary in core REST.
	define( 'RR_OPT_HEADLESS_CACHE_TTL',       'rr_headless_cache_ttl' );         // CDN cache max-age in seconds (s-maxage).
	define( 'RR_OPT_HEADLESS_RATE_LIMIT',      'rr_headless_rate_limit' );        // Requests per minute per IP.
	define( 'RR_OPT_HEADLESS_REVALIDATE_URL',  'rr_headless_revalidate_url' );    // Next.js/Nuxt webhook URL.
	define( 'RR_OPT_HEADLESS_REVALIDATE_SEC',  'rr_headless_revalidate_secret' ); // Shared secret for webhook auth.
	define( 'RR_OPT_HEADLESS_GRAPHQL',         'rr_headless_graphql' );           // Register WPGraphQL fields.

	// Meta keys.
	define( 'RR_META_SUMMARY',   '_rr_summary' );
	define( 'RR_META_HASH',      '_rr_content_hash' );
	define( 'RR_META_GENERATED', '_rr_last_generated' );
	define( 'RR_META_DISABLE',   '_rr_disable_summary' );

	// Meta keys — FAQ.
	define( 'RR_META_FAQ',           '_rr_faq' );
	define( 'RR_META_FAQ_HASH',      '_rr_faq_hash' );
	define( 'RR_META_FAQ_GENERATED', '_rr_faq_generated' );
	define( 'RR_META_FAQ_DISABLE',   '_rr_faq_disable' );
	define( 'RR_META_FAQ_KEYWORD',   '_rr_faq_keyword' );

	// Cron.
	define( 'RR_CRON_HOOK', 'rr_async_generate' );

	// Bulk state — summary.
	define( 'RR_BULK_QUEUE',   'rr_bulk_queue' );
	define( 'RR_BULK_DONE',    'rr_bulk_done' );
	define( 'RR_BULK_TOTAL',   'rr_bulk_total' );
	define( 'RR_BULK_RUNNING', 'rr_bulk_running' );

	// Bulk state — FAQ.
	define( 'RR_FAQ_QUEUE',   'rr_faq_queue' );
	define( 'RR_FAQ_DONE',    'rr_faq_done' );
	define( 'RR_FAQ_TOTAL',   'rr_faq_total' );
	define( 'RR_FAQ_RUNNING', 'rr_faq_running' );

	// Bulk state — start over.
	define( 'RR_SO_QUEUE',   'rr_so_queue' );
	define( 'RR_SO_DONE',    'rr_so_done' );
	define( 'RR_SO_TOTAL',   'rr_so_total' );
	define( 'RR_SO_RUNNING', 'rr_so_running' );

	// Bulk state — author.
	define( 'RR_BAC_QUEUE',   'rr_bac_queue' );
	define( 'RR_BAC_TOTAL',   'rr_bac_total' );
	define( 'RR_BAC_DONE',    'rr_bac_done' );
	define( 'RR_BAC_RUNNING', 'rr_bac_running' );
	define( 'RR_BAC_TO',      'rr_bac_to_author' );

	// Transient keys.
	define( 'RR_LLMS_CACHE_KEY',      'rr_llms_txt_cache' );
	define( 'RR_LLMS_FULL_CACHE_KEY', 'rr_llms_full_txt_cache' );
}

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
	if ( 0 !== strpos( $class, 'RR_' ) ) {
		return;
	}
	$file = RR_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
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
	$rr_entries = array_values( array_filter( $active, function ( $plugin_file ) {
		return 'rankready.php' === basename( (string) $plugin_file );
	} ) );
	if ( count( $rr_entries ) <= 1 ) {
		return;
	}
	add_action( 'admin_notices', function () use ( $rr_entries ): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo '<strong>RankReady:</strong> ';
		echo esc_html__( 'Multiple RankReady plugin folders are active at the same time. Only one is running; the rest are disabled by the duplicate-install guard but are still consuming a slot in active_plugins. Deactivate the duplicates to silence this notice:', 'rankready' );
		echo '</p><ul style="margin-left:20px;list-style:disc;">';
		foreach ( $rr_entries as $entry ) {
			echo '<li><code>' . esc_html( dirname( (string) $entry ) ) . '/</code></li>';
		}
		echo '</ul><p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">';
		echo esc_html__( 'Go to Plugins → Installed Plugins', 'rankready' );
		echo '</a></p></div>';
	} );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Plugin Update Checker (PUC) — auto-updates from public GitHub releases.
// ─────────────────────────────────────────────────────────────────────────────
// Pulls update metadata from github.com/adityaarsharma/rankready releases and
// surfaces them in WP-Admin → Plugins like a normal plugin update. The repo
// is public, so no authentication is required — every install just works.
//
// Update flow:
//   1. PUC polls GitHub Releases API once a day (configurable).
//   2. If the latest release tag is newer than RR_VERSION, WordPress shows
//      "Update available" on the Plugins screen.
//   3. WordPress downloads the rankready-X.Y.Z.zip release asset (NOT the
//      auto-generated "Source code" zip — that one has the wrong folder
//      structure) and installs it like any other plugin.
//
// To force a check immediately on a site, hit:
//     /wp-admin/plugins.php?puc_check_for_updates=1&puc_slug=rankready
//
// When license gating lands (planned for v1.0), this block stays mostly the
// same — we'll add a license-key parameter to the GitHub API request and an
// EDD-SL fallback for paid Pro features.
// ═════════════════════════════════════════════════════════════════════════════
if ( file_exists( RR_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once RR_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		$rr_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/adityaarsharma/rankready/',
			RR_FILE,
			'rankready',
			24 // $checkPeriod: hours between update checks. Gentler on GitHub's
			   // unauthenticated rate limit (60/hr) than the 12h default.
		);

		// Pull update zip from the GitHub Release asset (rankready-X.Y.Z.zip),
		// not the auto-generated "Source code" zip — the asset has the correct
		// folder structure (`rankready/rankready.php` at zip root).
		$rr_update_checker->getVcsApi()->enableReleaseAssets();
	}
}

// ═════════════════════════════════════════════════════════════════════════════
// Folder name enforcement — canonical install folder is always 'rankready'.
// ─────────────────────────────────────────────────────────────────────────────
// Problem: users sometimes install RankReady from the wrong source:
//   - GitHub "Code → Download ZIP"              -> rankready-main/
//   - GitHub "Source code (zip)" on release     -> rankready-0.5.3/
//   - Old posimyth release source archives      -> RankReady-LLM-SEO-EEAT-AI-Optimization-1.7.x/
// Only the rankready-X.Y.Z.zip release asset ships the canonical 'rankready/'
// folder structure. Without enforcement, PUC's built-in rename keeps the
// wrong folder name alive across every future upgrade.
//
// Two guards:
//   1. upgrader_source_selection filter — intercepts every plugin install/
//      update, detects a RankReady zip by reading its plugin header, and
//      force-renames the extracted temp folder to 'rankready' before WP
//      moves it into /wp-content/plugins/.
//   2. admin_init auto-migration — if this plugin is currently installed in
//      a non-canonical folder, rename the folder in place on the next admin
//      page load, update active_plugins (+ active_sitewide_plugins on
//      multisite) to match, and redirect. Settings survive (they live in
//      wp_options/wp_postmeta, not the plugin folder).
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
	if ( 'rankready' === $current ) {
		return $source;
	}

	$new_source = trailingslashit( $remote_source ) . 'rankready/';

	if ( file_exists( $new_source ) ) {
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $new_source, true );
		}
	}

	if ( ! @rename( untrailingslashit( $source ), untrailingslashit( $new_source ) ) ) {
		return $source;
	}

	return $new_source;
}, 1, 4 );

add_action( 'admin_init', function (): void {
	if ( wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$current = basename( __DIR__ );
	if ( 'rankready' === $current ) {
		return;
	}

	static $attempted = false;
	if ( $attempted ) {
		return;
	}
	$attempted = true;

	$target = dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'rankready';

	// Duplicate install — leave the duplicate-install guard at top of file to handle.
	if ( file_exists( $target ) ) {
		return;
	}

	if ( ! @rename( __DIR__, $target ) ) {
		set_transient( 'rr_folder_migration_failed', $current, HOUR_IN_SECONDS );
		return;
	}

	$old_basename = $current . '/rankready.php';
	$new_basename = 'rankready/rankready.php';

	$active = (array) get_option( 'active_plugins', array() );
	foreach ( $active as $i => $p ) {
		if ( $p === $old_basename ) {
			$active[ $i ] = $new_basename;
		}
	}
	update_option( 'active_plugins', $active );

	if ( is_multisite() ) {
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		if ( isset( $network[ $old_basename ] ) ) {
			$network[ $new_basename ] = $network[ $old_basename ];
			unset( $network[ $old_basename ] );
			update_site_option( 'active_sitewide_plugins', $network );
		}
	}

	set_transient( 'rr_folder_migrated_from', $current, HOUR_IN_SECONDS );

	wp_safe_redirect( admin_url( 'plugins.php' ) );
	exit;
} );

add_action( 'admin_notices', function (): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$migrated = get_transient( 'rr_folder_migrated_from' );
	if ( $migrated ) {
		delete_transient( 'rr_folder_migrated_from' );
		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			/* translators: %s: old folder name */
			esc_html__( 'RankReady: plugin folder auto-migrated from "%s" to the canonical "rankready" folder. All settings preserved.', 'rankready' ),
			esc_html( $migrated )
		);
		echo '</p></div>';
	}

	$failed = get_transient( 'rr_folder_migration_failed' );
	if ( $failed ) {
		delete_transient( 'rr_folder_migration_failed' );
		echo '<div class="notice notice-warning is-dismissible"><p>';
		printf(
			/* translators: 1: current folder name, 2: current folder name */
			esc_html__( 'RankReady: plugin is installed in "%1$s" instead of the canonical "rankready" folder. Auto-migration failed (file permissions). Rename the folder via SFTP: "%2$s" → "rankready", then reactivate if needed. Settings are preserved either way.', 'rankready' ),
			esc_html( $failed ),
			esc_html( $failed )
		);
		echo '</p></div>';
	}
} );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
	load_plugin_textdomain( 'rankready', false, dirname( RR_BASENAME ) . '/languages' );

	if ( version_compare( get_bloginfo( 'version' ), '6.2', '<' ) ) {
		add_action( 'admin_notices', function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'RankReady requires WordPress 6.2 or higher.', 'rankready' )
				. '</p></div>';
		} );
		return;
	}

	// Auto-flush rewrite rules after plugin update (activation hook doesn't fire on updates).
	$stored_version = get_option( 'rr_installed_version', '' );
	if ( $stored_version !== RR_VERSION ) {
		update_option( 'rr_installed_version', RR_VERSION );
		RR_Llms_Txt::add_rewrite_rules();
		RR_Markdown::add_rewrite_rules();
		// Defer flush to 'init' so all plugins/themes have registered their rules.
		add_action( 'init', 'flush_rewrite_rules', 99 );
		RR_Llms_Txt::sync_physical_robots_txt();

		// Migrate data from old AI Post Summary plugin (_aps_ meta) if present.
		// Only run once — skip if already migrated.
		if ( ! get_option( 'rr_aps_migrated' ) ) {
			global $wpdb;
			$has_aps = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 1",
				'_aps_summary'
			) );
			if ( $has_aps > 0 ) {
				// Update existing empty _rr_summary entries with old _aps_summary data.
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->postmeta} rr
					 INNER JOIN {$wpdb->postmeta} aps ON aps.post_id = rr.post_id AND aps.meta_key = %s AND aps.meta_value != ''
					 SET rr.meta_value = aps.meta_value
					 WHERE rr.meta_key = %s AND (rr.meta_value = '' OR rr.meta_value IS NULL)",
					'_aps_summary',
					'_rr_summary'
				) );
				// Insert for posts that have _aps_summary but no _rr_summary row at all.
				$wpdb->query( $wpdb->prepare(
					"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
					 SELECT pm.post_id, %s, pm.meta_value
					 FROM {$wpdb->postmeta} pm
					 WHERE pm.meta_key = %s
					   AND pm.meta_value != ''
					   AND pm.post_id NOT IN (
					       SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s
					   )",
					'_rr_summary',
					'_aps_summary',
					'_rr_summary'
				) );
			}
			update_option( 'rr_aps_migrated', true );
		}
	}

	// ── Self-healing rewrite rules (1.7.0) ─────────────────────────────────────
	// Problem: flush_rewrite_rules() only fires via update_option_ hooks, which
	// only trigger when a value *changes*. If llms/md were already 'on' before
	// save, the hook never fires and rules stay missing.
	//
	// Fix part 1: bust the "rules OK" transient on every settings save so the
	// self-heal re-runs, even when the saved value is unchanged.
	add_filter( 'pre_update_option_' . RR_OPT_LLMS_ENABLE,      function ( $v ) { delete_transient( 'rr_rewrite_ok' ); return $v; } );
	add_filter( 'pre_update_option_' . RR_OPT_LLMS_FULL_ENABLE, function ( $v ) { delete_transient( 'rr_rewrite_ok' ); return $v; } );
	add_filter( 'pre_update_option_' . RR_OPT_MD_ENABLE,        function ( $v ) { delete_transient( 'rr_rewrite_ok' ); return $v; } );

	// Fix part 2: on admin page loads, detect missing rules and auto-flush.
	// Transient throttles this to at most once per hour.
	add_action( 'admin_init', function (): void {
		if ( get_transient( 'rr_rewrite_ok' ) ) {
			return;
		}

		$rules = (array) get_option( 'rewrite_rules', array() );
		$needs = false;

		// Check llms.txt — skip if another plugin is known to handle it.
		if ( 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' ) && ! isset( $rules['^llms\.txt$'] ) ) {
			$rm_handles    = defined( 'RANK_MATH_VERSION' )
			                 && in_array( 'llms-txt', (array) get_option( 'rank_math_modules', array() ), true );
			$yoast_handles = defined( 'WPSEO_VERSION' )
			                 && ! empty( get_option( 'wpseo', array() )['enable_llms_txt'] );
			if ( ! $rm_handles && ! $yoast_handles ) {
				$needs = true;
			}
		}

		// Check llms-full.txt — never handled by other plugins.
		if ( ! $needs && 'on' === get_option( RR_OPT_LLMS_FULL_ENABLE, 'off' ) && ! isset( $rules['^llms-full\.txt$'] ) ) {
			$needs = true;
		}

		// Check .md rewrite rule.
		if ( ! $needs && 'on' === get_option( RR_OPT_MD_ENABLE, 'off' ) ) {
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
			RR_Llms_Txt::add_rewrite_rules();
			RR_Markdown::add_rewrite_rules();
			flush_rewrite_rules( false );
		}

		set_transient( 'rr_rewrite_ok', 1, HOUR_IN_SECONDS );
	}, 20 );

	// Register custom cron schedules.
	add_filter( 'cron_schedules', function ( array $schedules ): array {
		if ( ! isset( $schedules['rr_five_minutes'] ) ) {
			$schedules['rr_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 Minutes (RankReady)', 'rankready' ),
			);
		}
		if ( ! isset( $schedules['rr_one_minute'] ) ) {
			$schedules['rr_one_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => __( 'Every Minute (RankReady Bulk)', 'rankready' ),
			);
		}
		return $schedules;
	} );

	RR_Admin::init();
	RR_Generator::init();
	RR_Block::init();
	RR_Rest::init();
	RR_Llms_Txt::init();
	RR_Markdown::init();
	RR_Agent_Discovery::init();
	RR_Faq::init();
	RR_Headless::init();
	RR_Author_Box::init();

	if ( did_action( 'elementor/loaded' ) ) {
		add_action( 'elementor/widgets/register', function ( $widgets_manager ): void {
			require_once RR_DIR . 'includes/class-rr-elementor.php';
			$widgets_manager->register( new RR_Elementor_Widget() );

			require_once RR_DIR . 'includes/class-rr-elementor-faq.php';
			$widgets_manager->register( new RR_Elementor_Faq_Widget() );

			require_once RR_DIR . 'includes/class-rr-elementor-author-box.php';
			$widgets_manager->register( new RR_Elementor_Author_Box_Widget() );
		} );

		add_action( 'elementor/frontend/after_enqueue_styles', function (): void {
			wp_enqueue_style( 'rankready-style', RR_URL . 'assets/style.css', array(), RR_VERSION );
		} );
	}
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( RR_FILE, function (): void {
	if ( false === get_option( RR_OPT_POST_TYPES ) ) {
		update_option( RR_OPT_POST_TYPES, array( 'post' ) );
	}
	if ( false === get_option( RR_OPT_LABEL ) ) {
		update_option( RR_OPT_LABEL, 'Key Takeaways' );
	}
	if ( false === get_option( RR_OPT_SHOW_LABEL ) ) {
		update_option( RR_OPT_SHOW_LABEL, true );
	}
	if ( false === get_option( RR_OPT_HEADING_TAG ) ) {
		update_option( RR_OPT_HEADING_TAG, 'h4' );
	}
	if ( false === get_option( RR_OPT_LLMS_ENABLE ) ) {
		update_option( RR_OPT_LLMS_ENABLE, 'off' );
	}
	if ( false === get_option( RR_OPT_MD_ENABLE ) ) {
		update_option( RR_OPT_MD_ENABLE, 'off' );
	}
	if ( false === get_option( RR_OPT_ROBOTS_ENABLE ) ) {
		update_option( RR_OPT_ROBOTS_ENABLE, 'on' );
	}
	if ( false === get_option( RR_OPT_ROBOTS_CRAWLERS ) ) {
		update_option( RR_OPT_ROBOTS_CRAWLERS, array_keys( RR_Admin::get_llm_crawlers() ) );
	}
	if ( false === get_option( RR_OPT_FAQ_COUNT ) ) {
		update_option( RR_OPT_FAQ_COUNT, 5 );
	}
	if ( false === get_option( RR_OPT_FAQ_HEADING_TAG ) ) {
		update_option( RR_OPT_FAQ_HEADING_TAG, 'h3' );
	}
	if ( false === get_option( RR_OPT_FAQ_AUTO_DISPLAY ) ) {
		update_option( RR_OPT_FAQ_AUTO_DISPLAY, 'off' );
	}
	if ( false === get_option( RR_OPT_FAQ_SHOW_REVIEWED ) ) {
		update_option( RR_OPT_FAQ_SHOW_REVIEWED, 'on' );
	}
	if ( false === get_option( RR_OPT_FAQ_AUTO_GENERATE ) ) {
		update_option( RR_OPT_FAQ_AUTO_GENERATE, 'off' );
	}
	// Author Box defaults.
	if ( false === get_option( RR_OPT_AUTHOR_ENABLE ) ) {
		update_option( RR_OPT_AUTHOR_ENABLE, 'on' );
	}
	if ( false === get_option( RR_OPT_AUTHOR_AUTO_DISPLAY ) ) {
		update_option( RR_OPT_AUTHOR_AUTO_DISPLAY, 'off' );
	}
	if ( false === get_option( RR_OPT_AUTHOR_LAYOUT ) ) {
		update_option( RR_OPT_AUTHOR_LAYOUT, 'card' );
	}
	if ( false === get_option( RR_OPT_AUTHOR_HEADING ) ) {
		update_option( RR_OPT_AUTHOR_HEADING, 'About the Author' );
	}
	if ( false === get_option( RR_OPT_AUTHOR_HEADING_TAG ) ) {
		update_option( RR_OPT_AUTHOR_HEADING_TAG, 'h3' );
	}
	if ( false === get_option( RR_OPT_AUTHOR_SCHEMA_ENABLE ) ) {
		update_option( RR_OPT_AUTHOR_SCHEMA_ENABLE, 'on' );
	}
	if ( false === get_option( RR_OPT_AUTHOR_POST_TYPES ) ) {
		update_option( RR_OPT_AUTHOR_POST_TYPES, array( 'post' ) );
	}

	// Register rewrite rules before flushing so they get written.
	RR_Llms_Txt::add_rewrite_rules();
	RR_Markdown::add_rewrite_rules();
	flush_rewrite_rules();

	// Sync to physical robots.txt if one exists.
	RR_Llms_Txt::sync_physical_robots_txt();

	// Schedule schema scanner cron if not already scheduled.
	if ( ! wp_next_scheduled( RR_SCHEMA_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'rr_five_minutes', RR_SCHEMA_CRON_HOOK );
	}
} );

register_deactivation_hook( RR_FILE, function (): void {
	$timestamp = wp_next_scheduled( RR_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, RR_CRON_HOOK );
	}
	wp_clear_scheduled_hook( 'rr_async_faq_generate' );
	wp_clear_scheduled_hook( RR_SCHEMA_CRON_HOOK );
	update_option( RR_BULK_RUNNING, false );
	update_option( RR_BAC_RUNNING, false );
	update_option( RR_FAQ_RUNNING, false );
	update_option( RR_SCHEMA_RUNNING, false );
	delete_transient( RR_LLMS_CACHE_KEY );
	delete_transient( RR_LLMS_FULL_CACHE_KEY );

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
