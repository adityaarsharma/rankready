<?php
/**
 * Uninstall — clean up all plugin data.
 *
 * @package RankReady
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

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
);

foreach ( $meta_keys as $key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
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
