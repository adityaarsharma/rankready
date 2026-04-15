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

// ── Honor the opt-in ─────────────────────────────────────────────────────────
// Bail out early and preserve ALL user data unless the admin explicitly
// enabled "Delete all data on uninstall" in the plugin settings. The option
// itself is always cleaned up so the next install starts clean.
$should_delete_all = 'on' === get_option( 'rr_delete_on_uninstall', 'off' );
delete_option( 'rr_delete_on_uninstall' );

if ( ! $should_delete_all ) {
	return;
}

// ── Delete options ────────────────────────────────────────────────────────────
$options = array(
	// AI Summary.
	'rr_openai_api_key',
	'rr_openai_model',
	'rr_post_types',
	'rr_default_label',
	'rr_default_show_label',
	'rr_default_heading_tag',
	'rr_auto_display',
	'rr_display_position',
	'rr_custom_prompt',
	'rr_product_context',
	'rr_auto_generate',
	// Bulk summary state.
	'rr_bulk_queue',
	'rr_bulk_done',
	'rr_bulk_total',
	'rr_bulk_running',
	// LLMs.txt.
	'rr_llms_enable',
	'rr_llms_site_name',
	'rr_llms_summary',
	'rr_llms_about',
	'rr_llms_post_types',
	'rr_llms_max_posts',
	'rr_llms_cache_ttl',
	'rr_llms_full_enable',
	// Markdown.
	'rr_md_enable',
	'rr_md_post_types',
	'rr_md_include_meta',
	// LLMs.txt taxonomy controls.
	'rr_llms_exclude_cats',
	'rr_llms_exclude_tags',
	'rr_llms_show_categories',
	// Robots.txt crawler settings.
	'rr_robots_enable',
	'rr_robots_crawlers',
	// Bulk author state.
	'rr_bac_queue',
	'rr_bac_total',
	'rr_bac_done',
	'rr_bac_running',
	'rr_bac_to_author',
	// FAQ settings.
	'rr_dfs_login',
	'rr_dfs_password',
	'rr_faq_post_types',
	'rr_faq_count',
	'rr_faq_brand_terms',
	'rr_faq_auto_display',
	'rr_faq_position',
	'rr_faq_heading_tag',
	'rr_faq_show_reviewed',
	'rr_faq_auto_generate',
	// Bulk FAQ state.
	'rr_faq_queue',
	'rr_faq_done',
	'rr_faq_total',
	'rr_faq_running',
	// Bulk start-over state.
	'rr_so_queue',
	'rr_so_done',
	'rr_so_total',
	'rr_so_running',
	// Bulk operation tracking.
	'rr_bulk_skipped',
	'rr_bulk_failed',
	'rr_faq_skipped',
	'rr_faq_failed',
	// Error log.
	'rr_error_log',
	// Token usage.
	'rr_token_usage',
	// DataForSEO usage.
	'rr_dfs_usage',
	// Version tracking.
	'rr_installed_version',
	// Migration flag.
	'rr_aps_migrated',
	// Schema automation.
	'rr_schema_article',
	'rr_schema_faq',
	'rr_schema_howto',
	'rr_schema_itemlist',
	'rr_schema_speakable',
	'rr_schema_batch_size',
	// Schema scan bulk state.
	'rr_schema_queue',
	'rr_schema_done',
	'rr_schema_total',
	'rr_schema_running',
	// Author Box.
	'rr_author_enable',
	'rr_author_auto_display',
	'rr_author_layout',
	'rr_author_heading',
	'rr_author_heading_tag',
	'rr_author_schema_enable',
	'rr_author_editorial_url',
	'rr_author_factcheck_url',
	'rr_author_post_types',
	'rr_author_trust_enable',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Delete transients ─────────────────────────────────────────────────────────
delete_transient( 'rr_llms_txt_cache' );
delete_transient( 'rr_llms_full_txt_cache' );

// ── Delete post meta ──────────────────────────────────────────────────────────
global $wpdb;

$meta_keys = array(
	'_rr_summary',
	'_rr_content_hash',
	'_rr_last_generated',
	'_rr_disable_summary',
	'_rr_faq',
	'_rr_faq_hash',
	'_rr_faq_generated',
	'_rr_faq_disable',
	'_rr_faq_keyword',
	'_rr_tokens_used',
	'_rr_schema_type',
	'_rr_schema_data',
	'_rr_schema_hash',
	// Author Box per-post meta.
	'_rr_author_fact_checked_by',
	'_rr_author_reviewed_by',
	'_rr_author_last_reviewed',
	'_rr_author_disable',
);

foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
}

// ── Delete user meta (Author Box profile fields) ─────────────────────────────
$user_meta_keys = array(
	'rr_author_job_title',
	'rr_author_employer',
	'rr_author_employer_url',
	'rr_author_bio',
	'rr_author_headshot',
	'rr_author_headshot_alt',
	'rr_author_started_year',
	'rr_author_expertise',
	'rr_author_credentials_suffix',
	'rr_author_education',
	'rr_author_certifications',
	'rr_author_memberships',
	'rr_author_awards',
	'rr_author_wikidata',
	'rr_author_wikipedia',
	'rr_author_orcid',
	'rr_author_scholar',
	'rr_author_linkedin',
	'rr_author_github',
	'rr_author_youtube',
	'rr_author_twitter',
	'rr_author_website',
	'rr_author_contact_url',
);

foreach ( $user_meta_keys as $key ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
}

// ── Clear scheduled cron ──────────────────────────────────────────────────────
wp_clear_scheduled_hook( 'rr_async_generate' );
wp_clear_scheduled_hook( 'rr_async_faq_generate' );
wp_clear_scheduled_hook( 'rr_schema_scan' );
wp_clear_scheduled_hook( 'rr_cron_bulk_startover' );
wp_clear_scheduled_hook( 'rr_cron_bulk_faq' );
wp_clear_scheduled_hook( 'rr_cron_bulk_summary' );

// ── Flush rewrite rules to clean up llms.txt and .md endpoints ───────────────
flush_rewrite_rules( false );
