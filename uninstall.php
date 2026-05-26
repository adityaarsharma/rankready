<?php
/**
 * Uninstall — optionally clean up all plugin data.
 *
 * Preserves all user data by default. If the admin opted in via
 * Settings → Tools → "Delete all data on uninstall", every RankReady
 * option, post meta, user meta, and transient is removed.
 *
 * This file only runs on a full plugin "Delete" from the Plugins page,
 * never on deactivation. Deactivation preserves everything.
 *
 * @package RankReady
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// FREE-102 — file-level phpcs ignore for the direct-DB sniffs.
// Uninstall.php deletes plugin data in bulk: $wpdb->delete() on $wpdb->postmeta
// and $wpdb->usermeta filtered by our own RankReady meta_key list, plus a
// DROP TABLE on $wpdb->prefix . 'rnrd_crawler_log'. No user input flows into
// the queries. Caching is meaningless — we are deleting the data the cache
// would describe. Runs once per uninstall, never on a normal request.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

// ── Honor the opt-in ─────────────────────────────────────────────────────────
// Bail out early and preserve ALL user data unless the admin explicitly
// enabled "Delete all data on uninstall" in the plugin settings. The option
// itself is always cleaned up so the next install starts clean.
$rnrd_should_delete_all = 'on' === get_option( 'rnrd_delete_on_uninstall', 'off' );
delete_option( 'rnrd_delete_on_uninstall' );

if ( ! $rnrd_should_delete_all ) {
	return;
}

// ── Delete options ────────────────────────────────────────────────────────────
$rnrd_options = array(
	// Multi-LLM provider keys + models (v1.1.0+). MUST be in this list — opting
	// into "delete all" for key rotation / GDPR compliance is meaningless if
	// API keys survive uninstall. (Audit beta.3 finding #2.)
	'rnrd_llm_provider',
	'rnrd_anthropic_api_key',
	'rnrd_anthropic_model',
	'rnrd_gemini_api_key',
	'rnrd_gemini_model',
	'rnrd_deepseek_api_key',
	'rnrd_deepseek_model',

	// v1.2.0 — Agent Ready options.
	'rnrd_brand_terms',
	'rnrd_max_snippet_default',
	'rnrd_ai_referral_stats',
	'rnrd_ai_referral_enable',
	'rnrd_mcp_enable',
	'rnrd_mcp_expose_posts',
	'rnrd_mcp_expose_pages',
	'rnrd_mcp_expose_authors',
	'rnrd_mcp_expose_taxonomies',
	'rnrd_mcp_expose_sitemap',
	'rnrd_mcp_expose_menus',
	'rnrd_mcp_expose_llms_txt',
	'rnrd_mcp_expose_rr_ai',
	'rnrd_mcp_expose_freshness',
	'rnrd_mcp_expose_cpts',
	'rnrd_mcp_expose_comments',
	'rnrd_mcp_expose_media',
	'rnrd_mcp_expose_users',
	'rnrd_mcp_expose_plugins',
	'rnrd_mcp_expose_themes',
	'rnrd_mcp_expose_settings',
	'rnrd_md_hint_div',
	'rnrd_md_bot_auto_serve',
	'rnrd_welcome_completed',

	// v1.1.x — Content Signals.
	'rnrd_content_signals_enable',
	'rnrd_content_signals_ai_train',
	'rnrd_content_signals_search',
	'rnrd_content_signals_ai_input',

	// v1.1.x — Headless / Public API.
	'rnrd_headless_enable',
	'rnrd_headless_cors_origins',
	'rnrd_headless_expose_meta',
	'rnrd_headless_cache_ttl',
	'rnrd_headless_rate_limit',
	'rnrd_headless_revalidate_url',
	'rnrd_headless_revalidate_secret',
	'rnrd_headless_graphql',

	// v1.1.1+ — "What's new" banner version tracker.
	'rnrd_installed_version',

	// AI Summary.
	'rnrd_openai_api_key',
	'rnrd_openai_model',
	'rnrd_post_types',
	'rnrd_default_label',
	'rnrd_default_show_label',
	'rnrd_default_heading_tag',
	'rnrd_auto_display',
	'rnrd_display_position',
	'rnrd_custom_prompt',
	'rnrd_product_context',
	'rnrd_auto_generate',
	// Bulk summary state.
	'rnrd_bulk_queue',
	'rnrd_bulk_done',
	'rnrd_bulk_total',
	'rnrd_bulk_running',
	// LLMs.txt.
	'rnrd_llms_enable',
	'rnrd_llms_site_name',
	'rnrd_llms_summary',
	'rnrd_llms_about',
	'rnrd_llms_post_types',
	'rnrd_llms_max_posts',
	'rnrd_llms_cache_ttl',
	'rnrd_llms_full_enable',
	// Markdown.
	'rnrd_md_enable',
	'rnrd_md_post_types',
	'rnrd_md_include_meta',
	// LLMs.txt taxonomy controls.
	'rnrd_llms_exclude_cats',
	'rnrd_llms_exclude_tags',
	'rnrd_llms_show_categories',
	// Robots.txt crawler settings.
	'rnrd_robots_enable',
	'rnrd_robots_crawlers',
	// Bulk author state.
	'rnrd_bac_queue',
	'rnrd_bac_total',
	'rnrd_bac_done',
	'rnrd_bac_running',
	'rnrd_bac_to_author',
	// FAQ settings.
	'rnrd_dfs_login',
	'rnrd_dfs_password',
	'rnrd_faq_post_types',
	'rnrd_faq_count',
	'rnrd_faq_brand_terms',
	'rnrd_faq_auto_display',
	'rnrd_faq_position',
	'rnrd_faq_heading_tag',
	'rnrd_faq_show_reviewed',
	'rnrd_faq_auto_generate',
	// Bulk FAQ state.
	'rnrd_faq_queue',
	'rnrd_faq_done',
	'rnrd_faq_total',
	'rnrd_faq_running',
	// Bulk start-over state.
	'rnrd_so_queue',
	'rnrd_so_done',
	'rnrd_so_total',
	'rnrd_so_running',
	// Bulk operation tracking.
	'rnrd_bulk_skipped',
	'rnrd_bulk_failed',
	'rnrd_faq_skipped',
	'rnrd_faq_failed',
	// Error log.
	'rnrd_error_log',
	// Token usage.
	'rnrd_token_usage',
	// DataForSEO usage.
	'rnrd_dfs_usage',
	// Version tracking.
	'rnrd_installed_version',
	// Migration flag.
	'rnrd_aps_migrated',
	// Schema automation.
	'rnrd_schema_article',
	'rnrd_schema_faq',
	'rnrd_schema_howto',
	'rnrd_schema_itemlist',
	'rnrd_schema_speakable',
	'rnrd_schema_batch_size',
	// Schema scan bulk state.
	'rnrd_schema_queue',
	'rnrd_schema_done',
	'rnrd_schema_total',
	'rnrd_schema_running',
	// Author Box.
	'rnrd_author_enable',
	'rnrd_author_auto_display',
	'rnrd_author_layout',
	'rnrd_author_heading',
	'rnrd_author_heading_tag',
	'rnrd_author_schema_enable',
	'rnrd_author_editorial_url',
	'rnrd_author_factcheck_url',
	'rnrd_author_post_types',
	'rnrd_author_trust_enable',
);

foreach ( $rnrd_options as $rnrd_option ) {
	delete_option( $rnrd_option );
}

// ── Delete transients ─────────────────────────────────────────────────────────
delete_transient( 'rnrd_llms_txt_cache' );
delete_transient( 'rnrd_llms_full_txt_cache' );

// ── Delete post meta ──────────────────────────────────────────────────────────
global $wpdb;

$rnrd_meta_keys = array(
	'_rnrd_summary',
	'_rnrd_content_hash',
	'_rnrd_last_generated',
	'_rnrd_disable_summary',
	'_rnrd_faq',
	'_rnrd_faq_hash',
	'_rnrd_faq_generated',
	'_rnrd_faq_disable',
	'_rnrd_faq_keyword',
	'_rnrd_faq_last_failure',  // v1.1.3 circuit breaker timestamp
	'_rnrd_max_snippet',       // v1.2.0 per-post max-snippet override
	'_rnrd_llms_exclude',      // v1.2.0 per-post llms.txt exclusion
	'_rnrd_tokens_used',
	'_rnrd_schema_type',
	'_rnrd_schema_data',
	'_rnrd_schema_hash',
	// Author Box per-post meta.
	'_rnrd_author_fact_checked_by',
	'_rnrd_author_reviewed_by',
	'_rnrd_author_last_reviewed',
	'_rnrd_author_disable',
);

foreach ( $rnrd_meta_keys as $rnrd_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $rnrd_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
}

// ── Delete user meta (Author Box profile fields) ─────────────────────────────
$rnrd_user_meta_keys = array(
	'rnrd_author_job_title',
	'rnrd_author_employer',
	'rnrd_author_employer_url',
	'rnrd_author_bio',
	'rnrd_author_headshot',
	'rnrd_author_headshot_alt',
	'rnrd_author_started_year',
	'rnrd_author_expertise',
	'rnrd_author_credentials_suffix',
	'rnrd_author_education',
	'rnrd_author_certifications',
	'rnrd_author_memberships',
	'rnrd_author_awards',
	'rnrd_author_wikidata',
	'rnrd_author_wikipedia',
	'rnrd_author_orcid',
	'rnrd_author_scholar',
	'rnrd_author_linkedin',
	'rnrd_author_github',
	'rnrd_author_youtube',
	'rnrd_author_twitter',
	'rnrd_author_website',
	'rnrd_author_contact_url',
);

foreach ( $rnrd_user_meta_keys as $rnrd_key ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $rnrd_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
}

// ── Clear scheduled cron ──────────────────────────────────────────────────────
wp_clear_scheduled_hook( 'rnrd_async_generate' );
wp_clear_scheduled_hook( 'rnrd_async_faq_generate' );
wp_clear_scheduled_hook( 'rnrd_schema_scan' );
wp_clear_scheduled_hook( 'rnrd_cron_bulk_startover' );
wp_clear_scheduled_hook( 'rnrd_cron_bulk_faq' );
wp_clear_scheduled_hook( 'rnrd_cron_bulk_summary' );
wp_clear_scheduled_hook( 'rnrd_crawler_log_prune' );  // v0.6.6 daily prune cron

// ── Drop the crawler-log table (v0.6.6) ──────────────────────────────────────
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'rnrd_crawler_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
delete_option( 'rnrd_crawler_log_db_version' );

// ── Flush rewrite rules to clean up llms.txt and .md endpoints ───────────────
flush_rewrite_rules( false );
