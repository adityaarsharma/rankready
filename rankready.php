<?php
/**
 * Plugin Name:       RankReady – LLM SEO, EEAT & AI Optimization
 * Plugin URI:        https://posimyth.com/rankready/
 * Description:       AI summaries, Article JSON-LD schema with speakable, LLMs.txt generator, Markdown endpoints for LLM crawlers, bulk author changer. Built for LLM SEO, EEAT, and AI Overviews.
 * Version:           1.5
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

// ── Constants (guarded to prevent conflicts) ─────────────────────────────────
if ( ! defined( 'RR_VERSION' ) ) {
	define( 'RR_VERSION',  '1.5' );
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

	// Register custom cron schedule for schema scanner.
	add_filter( 'cron_schedules', function ( array $schedules ): array {
		if ( ! isset( $schedules['rr_five_minutes'] ) ) {
			$schedules['rr_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 Minutes (RankReady)', 'rankready' ),
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
	RR_Faq::init();

	if ( did_action( 'elementor/loaded' ) ) {
		add_action( 'elementor/widgets/register', function ( $widgets_manager ): void {
			require_once RR_DIR . 'includes/class-rr-elementor.php';
			$widgets_manager->register( new RR_Elementor_Widget() );

			require_once RR_DIR . 'includes/class-rr-elementor-faq.php';
			$widgets_manager->register( new RR_Elementor_Faq_Widget() );
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
