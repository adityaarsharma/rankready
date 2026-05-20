<?php
/**
 * Admin settings page — tabbed UI using core WordPress styles.
 *
 * Tabs: Settings | LLM Optimization | Tools | Info
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Admin {

	private const SETTINGS_GROUP   = 'rr_settings_group';  // Settings tab
	private const CONTENT_GROUP    = 'rr_content_group';   // Content AI tab
	private const AUTHORITY_GROUP  = 'rr_authority_group'; // Authority tab (author + schema)
	private const LLMS_GROUP       = 'rr_llms_group';      // AI Crawlers tab
	private const HEADLESS_GROUP   = 'rr_headless_group';  // Advanced tab
	// Legacy aliases kept for any saved nonces in flight during upgrade.
	private const FAQ_GROUP        = 'rr_content_group';
	private const SCHEMA_GROUP     = 'rr_authority_group';
	private const AUTHOR_GROUP     = 'rr_authority_group';
	private const MENU_SLUG        = 'rankready';
	private const NONCE_ACTION     = 'rr_test_connection';
	private const NONCE_FIELD      = 'rr_test_nonce';

	public static function init(): void {
		add_action( 'admin_menu',            array( self::class, 'register_menu' ) );
		add_action( 'admin_init',            array( self::class, 'register_settings' ) );
		add_action( 'admin_init',            array( self::class, 'handle_dismiss_actions' ) );
		add_action( 'admin_init',            array( self::class, 'track_installed_version' ) );
		add_action( 'admin_notices',         array( self::class, 'connection_notice' ) );
		add_action( 'admin_notices',         array( self::class, 'permalink_notice' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . RR_BASENAME, array( self::class, 'action_links' ) );
		add_action( 'add_meta_boxes',        array( self::class, 'register_meta_box' ) );
		add_action( 'save_post',             array( self::class, 'save_meta_box' ) );

		// Defer column registration to 'wp_loaded' so all CPTs are registered.
		add_action( 'wp_loaded', array( self::class, 'register_status_columns' ) );
	}

	// ── "What's new" banner + tutorial dismiss handlers ─────────────────────────

	/**
	 * Records the most recently installed plugin version. When the running
	 * `RR_VERSION` is newer than the stored value, every user gets the
	 * "what's new" banner exactly once until each one dismisses it.
	 *
	 * Also runs idempotent one-shot migrations for stale option values that
	 * would silently break against the live API — currently the deprecated
	 * `deepseek-chat` / `deepseek-reasoner` aliases. Safe to re-enter.
	 */
	public static function track_installed_version(): void {
		$stored = (string) get_option( RR_OPT_INSTALLED_VERSION, '' );
		if ( $stored !== RR_VERSION ) {
			update_option( RR_OPT_INSTALLED_VERSION, RR_VERSION, false );
		}

		// DeepSeek deprecated `deepseek-chat` / `deepseek-reasoner` in favour
		// of pinned V4 IDs. Migrate silently so existing users don't hit a
		// dead alias when DeepSeek finishes the retirement.
		$deepseek_model = (string) get_option( 'rr_deepseek_model', '' );
		if ( 'deepseek-chat' === $deepseek_model ) {
			update_option( 'rr_deepseek_model', 'deepseek-v4-flash', false );
		} elseif ( 'deepseek-reasoner' === $deepseek_model ) {
			update_option( 'rr_deepseek_model', 'deepseek-v4-pro', false );
		}
	}

	/**
	 * Returns true when this user should see the "what's new" banner for
	 * the running version. Skips users who've already dismissed for this
	 * version. Only relevant on RankReady admin pages — caller should
	 * check screen first.
	 */
	public static function should_show_whatsnew( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$dismissed_for = (string) get_user_meta( $user_id, 'rr_whatsnew_dismissed_version', true );
		return $dismissed_for !== RR_VERSION;
	}

	/**
	 * Returns true when this user has unread release notes — drives the
	 * red-dot indicator on the "RankReady" menu item. Same gating as the
	 * banner so the dot disappears when the banner is dismissed.
	 */
	public static function has_unread_release_notes( int $user_id ): bool {
		return self::should_show_whatsnew( $user_id );
	}

	/**
	 * Handles the "Dismiss" action on the what's new banner and the
	 * tutorial card. Both are GET-based with nonces — single-click,
	 * no JS required, no AJAX surface.
	 */
	public static function handle_dismiss_actions(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( isset( $_GET['rr_dismiss_whatsnew'] ) && check_admin_referer( 'rr_dismiss_whatsnew' ) ) {
			update_user_meta( $user_id, 'rr_whatsnew_dismissed_version', RR_VERSION );
			wp_safe_redirect( remove_query_arg( array( 'rr_dismiss_whatsnew', '_wpnonce' ) ) );
			exit;
		}

		if ( isset( $_GET['rr_dismiss_tutorial'] ) && check_admin_referer( 'rr_dismiss_tutorial' ) ) {
			update_user_meta( $user_id, 'rr_tutorial_dismissed', 1 );
			wp_safe_redirect( remove_query_arg( array( 'rr_dismiss_tutorial', '_wpnonce' ) ) );
			exit;
		}
	}

	// ── Status columns (deferred to wp_loaded so CPTs exist) ─────────────────

	public static function register_status_columns(): void {
		$public_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $public_types as $pt ) {
			if ( 'attachment' === $pt ) {
				continue;
			}
			add_filter( "manage_{$pt}_posts_columns",       array( self::class, 'add_status_column' ) );
			add_action( "manage_{$pt}_posts_custom_column",  array( self::class, 'render_status_column' ), 10, 2 );
		}
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public static function register_menu(): void {
		// Append a red-dot bubble to the menu label when this user hasn't
		// seen the latest release notes. Same WP-core CSS class the plugin
		// updates counter uses, so it inherits theme styling.
		$menu_label = __( 'RankReady', 'rankready' );
		if ( is_user_logged_in() && self::has_unread_release_notes( get_current_user_id() ) ) {
			$menu_label .= ' <span class="awaiting-mod update-plugins" style="background:#d63638;color:#fff;border-radius:10px;padding:0 6px;margin-left:5px;font-size:9px;line-height:17px;display:inline-block;vertical-align:top;">1</span>';
		}

		add_menu_page(
			__( 'RankReady', 'rankready' ),
			$menu_label,
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-chart-area',
			81
		);
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public static function enqueue_admin_assets( $hook ): void {
		// Design tokens load on every admin screen so dashboard widgets,
		// meta box, and Content Gaps page all share the same visual language.
		// Tiny file (~3 KB), zero JS — safe to always-load.
		wp_enqueue_style( 'rr-design-tokens', RR_URL . 'assets/design-tokens.css', array(), RR_VERSION );

		// Full admin styles + JS only on the RankReady settings page.
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'rr-admin', RR_URL . 'assets/admin.css', array( 'rr-design-tokens' ), RR_VERSION );
		wp_enqueue_script( 'rr-admin', RR_URL . 'assets/admin.js', array(), RR_VERSION, true );
		wp_localize_script( 'rr-admin', 'rrAdmin', array(
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'apiBase' => rest_url( 'rankready/v1' ),
		) );
	}

	// ── Settings API ──────────────────────────────────────────────────────────

	public static function register_settings(): void {

		// ═══ Settings Tab ═════════════════════════════════════════════════════

		// Active LLM provider — drives which key/model is used by RR_LLM.
		register_setting( self::SETTINGS_GROUP, RR_OPT_LLM_PROVIDER, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_llm_provider' ),
			'default'           => 'openai',
		) );

		// OpenAI key + model.
		register_setting( self::SETTINGS_GROUP, RR_OPT_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_api_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RR_OPT_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_model' ),
			'default'           => 'gpt-4o-mini',
		) );

		// Anthropic (Claude) key + model.
		register_setting( self::SETTINGS_GROUP, RR_OPT_ANTHROPIC_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_anthropic_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RR_OPT_ANTHROPIC_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_provider_model' ),
			'default'           => 'claude-haiku-4-5',
		) );

		// Gemini key + model.
		register_setting( self::SETTINGS_GROUP, RR_OPT_GEMINI_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_gemini_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RR_OPT_GEMINI_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_provider_model' ),
			'default'           => 'gemini-2.5-flash',
		) );

		// DeepSeek key + model.
		register_setting( self::SETTINGS_GROUP, RR_OPT_DEEPSEEK_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_deepseek_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RR_OPT_DEEPSEEK_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_provider_model' ),
			'default'           => 'deepseek-v4-flash',
		) );

		register_setting( self::SETTINGS_GROUP, RR_OPT_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types' ),
			'default'           => array( 'post' ),
		) );

		register_setting( self::SETTINGS_GROUP, RR_OPT_CUSTOM_PROMPT, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::SETTINGS_GROUP, RR_OPT_PRODUCT_CONTEXT, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::SETTINGS_GROUP, RR_OPT_AUTO_GENERATE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::SETTINGS_GROUP, RR_OPT_DELETE_ON_UNINSTALL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::SETTINGS_GROUP, RR_OPT_AUTO_DISPLAY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_auto_display' ),
			'default'           => 'off',
		) );

		register_setting( self::SETTINGS_GROUP, RR_OPT_DISPLAY_POSITION, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_display_position' ),
			'default'           => 'before',
		) );

		register_setting( self::SETTINGS_GROUP, RR_OPT_LABEL, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'Key Takeaways',
		) );

		register_setting( self::SETTINGS_GROUP, RR_OPT_SHOW_LABEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_checkbox_field' ),
			'default'           => '1',
		) );

		register_setting( self::SETTINGS_GROUP, RR_OPT_HEADING_TAG, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_heading_tag' ),
			'default'           => 'h4',
		) );

		// ═══ LLM Optimization Tab ═════════════════════════════════════════════

		register_setting( self::LLMS_GROUP, RR_OPT_LLMS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_LLMS_SITE_NAME, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_LLMS_SUMMARY, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_LLMS_ABOUT, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_LLMS_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types' ),
			'default'           => array( 'post', 'page' ),
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_LLMS_MAX_POSTS, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 100,
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_LLMS_CACHE_TTL, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 3600,
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_LLMS_FULL_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_LLMS_EXCLUDE_CATS, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_term_ids' ),
			'default'           => array(),
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_LLMS_EXCLUDE_TAGS, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_term_ids' ),
			'default'           => array(),
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_LLMS_SHOW_CATEGORIES, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_ROBOTS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_ROBOTS_CRAWLERS, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_crawler_list' ),
			'default'           => array_keys( self::get_llm_crawlers() ),
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_MD_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_MD_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types' ),
			'default'           => array( 'post', 'page' ),
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_MD_INCLUDE_META, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_checkbox_field' ),
			'default'           => '1',
		) );

		// Content Signals.
		register_setting( self::LLMS_GROUP, RR_OPT_CONTENT_SIGNALS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_CONTENT_SIGNALS_AI_TRAIN, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_content_signal' ),
			'default'           => 'allow',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_CONTENT_SIGNALS_SEARCH, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_content_signal' ),
			'default'           => 'allow',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_CONTENT_SIGNALS_AI_INPUT, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_content_signal' ),
			'default'           => 'allow',
		) );

		register_setting( self::LLMS_GROUP, RR_OPT_BRAND_TERMS, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		// v1.2.0 — AI Snippet preview default (per-post override lives in the meta box).
		register_setting( self::LLMS_GROUP, RR_OPT_MAX_SNIPPET_DEFAULT, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		// ── DataForSEO credentials (Settings tab, same save as OpenAI) ──────
		register_setting( self::SETTINGS_GROUP, RR_OPT_DFS_LOGIN, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_dfs_login' ),
			'default'           => '',
		) );

		register_setting( self::SETTINGS_GROUP, RR_OPT_DFS_PASSWORD, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_dfs_password' ),
			'default'           => '',
		) );

		// ═══ FAQ Tab (Content AI) ═════════════════════════════════════════════

		register_setting( self::FAQ_GROUP, RR_OPT_FAQ_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types' ),
			'default'           => array( 'post' ),
		) );

		register_setting( self::FAQ_GROUP, RR_OPT_FAQ_COUNT, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 5,
		) );

		register_setting( self::FAQ_GROUP, RR_OPT_FAQ_BRAND_TERMS, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::FAQ_GROUP, RR_OPT_FAQ_AUTO_DISPLAY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::FAQ_GROUP, RR_OPT_FAQ_POSITION, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_display_position' ),
			'default'           => 'after',
		) );

		register_setting( self::FAQ_GROUP, RR_OPT_FAQ_HEADING_TAG, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_heading_tag' ),
			'default'           => 'h3',
		) );

		register_setting( self::FAQ_GROUP, RR_OPT_FAQ_SHOW_REVIEWED, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::FAQ_GROUP, RR_OPT_FAQ_AUTO_GENERATE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// ═══ Schema Automation Tab ═══════════════════════════════════════════

		register_setting( self::SCHEMA_GROUP, RR_OPT_SCHEMA_ARTICLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RR_OPT_SCHEMA_FAQ, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RR_OPT_SCHEMA_HOWTO, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RR_OPT_SCHEMA_ITEMLIST, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RR_OPT_SCHEMA_SPEAKABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RR_OPT_SCHEMA_BATCH_SIZE, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 10,
		) );

		// ═══ Headless / Public API Tab ═══════════════════════════════════════════

		register_setting( self::HEADLESS_GROUP, RR_OPT_HEADLESS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::HEADLESS_GROUP, RR_OPT_HEADLESS_CORS_ORIGINS, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_cors_origins' ),
			'default'           => '',
		) );

		register_setting( self::HEADLESS_GROUP, RR_OPT_HEADLESS_EXPOSE_META, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::HEADLESS_GROUP, RR_OPT_HEADLESS_CACHE_TTL, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 300,
		) );

		register_setting( self::HEADLESS_GROUP, RR_OPT_HEADLESS_RATE_LIMIT, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 120,
		) );

		register_setting( self::HEADLESS_GROUP, RR_OPT_HEADLESS_REVALIDATE_URL, array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );

		register_setting( self::HEADLESS_GROUP, RR_OPT_HEADLESS_REVALIDATE_SEC, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_revalidate_secret' ),
			'default'           => '',
		) );

		register_setting( self::HEADLESS_GROUP, RR_OPT_HEADLESS_GRAPHQL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// ── Author Box settings ──────────────────────────────────────────
		register_setting( self::AUTHOR_GROUP, RR_OPT_AUTHOR_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );
		register_setting( self::AUTHOR_GROUP, RR_OPT_AUTHOR_AUTO_DISPLAY, array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, array( 'off', 'before', 'after', 'both' ), true ) ? $v : 'off';
			},
			'default'           => 'off',
		) );
		register_setting( self::AUTHOR_GROUP, RR_OPT_AUTHOR_LAYOUT, array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, array( 'card', 'compact', 'inline' ), true ) ? $v : 'card';
			},
			'default'           => 'card',
		) );
		register_setting( self::AUTHOR_GROUP, RR_OPT_AUTHOR_HEADING, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'About the Author',
		) );
		register_setting( self::AUTHOR_GROUP, RR_OPT_AUTHOR_HEADING_TAG, array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true ) ? $v : 'h3';
			},
			'default'           => 'h3',
		) );
		register_setting( self::AUTHOR_GROUP, RR_OPT_AUTHOR_SCHEMA_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );
		register_setting( self::AUTHOR_GROUP, RR_OPT_AUTHOR_EDITORIAL_URL, array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );
		register_setting( self::AUTHOR_GROUP, RR_OPT_AUTHOR_FACTCHECK_URL, array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );
		register_setting( self::AUTHOR_GROUP, RR_OPT_AUTHOR_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => function ( $v ) {
				if ( ! is_array( $v ) ) return array( 'post' );
				return array_values( array_filter( array_map( 'sanitize_key', $v ) ) );
			},
			'default'           => array( 'post' ),
		) );
		register_setting( self::AUTHOR_GROUP, RR_OPT_AUTHOR_TRUST_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );
	}

	/**
	 * Sanitize CORS origins — comma-separated list of valid URLs.
	 */
	public static function sanitize_cors_origins( $value ): string {
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return '';
		}
		$parts = array_map( 'trim', explode( ',', $value ) );
		$valid = array();
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			if ( filter_var( $p, FILTER_VALIDATE_URL ) ) {
				$valid[] = rtrim( esc_url_raw( $p ), '/' );
			}
		}
		return implode( ',', array_unique( $valid ) );
	}

	/**
	 * Sanitize revalidate secret. Preserves sentinel (don't change) and mask.
	 */
	public static function sanitize_revalidate_secret( $value ): string {
		$value = (string) $value;
		if ( '__UNCHANGED__' === $value ) {
			return (string) get_option( RR_OPT_HEADLESS_REVALIDATE_SEC, '' );
		}
		if ( false !== strpos( $value, "\xE2\x80\xA2" ) ) {
			return (string) get_option( RR_OPT_HEADLESS_REVALIDATE_SEC, '' );
		}
		return sanitize_text_field( $value );
	}

	// ── Sanitize callbacks ────────────────────────────────────────────────────

	public static function sanitize_api_key( $value ): string {
		$value = sanitize_text_field( (string) $value );
		// Sentinel or masked value means "don't change".
		if ( '__UNCHANGED__' === $value || false !== strpos( $value, '••••' ) ) {
			return (string) get_option( RR_OPT_KEY, '' );
		}
		if ( ! empty( $value ) && ! preg_match( '/^sk-[A-Za-z0-9\-_]{20,}$/', $value ) ) {
			add_settings_error( RR_OPT_KEY, 'rr_invalid_key',
				__( 'The OpenAI API key format looks incorrect. It should start with sk-', 'rankready' ), 'error' );
			return (string) get_option( RR_OPT_KEY, '' );
		}
		return $value;
	}

	/**
	 * Anthropic keys begin with `sk-ant-`.
	 */
	public static function sanitize_anthropic_key( $value ): string {
		$value = sanitize_text_field( (string) $value );
		if ( '__UNCHANGED__' === $value || false !== strpos( $value, '••••' ) ) {
			return (string) get_option( RR_OPT_ANTHROPIC_KEY, '' );
		}
		if ( ! empty( $value ) && ! preg_match( '/^sk-ant-[A-Za-z0-9\-_]{20,}$/', $value ) ) {
			add_settings_error( RR_OPT_ANTHROPIC_KEY, 'rr_invalid_anthropic_key',
				__( 'The Anthropic API key format looks incorrect. It should start with sk-ant-', 'rankready' ), 'error' );
			return (string) get_option( RR_OPT_ANTHROPIC_KEY, '' );
		}
		return $value;
	}

	/**
	 * Google AI Studio keys begin with `AIza`.
	 */
	public static function sanitize_gemini_key( $value ): string {
		$value = sanitize_text_field( (string) $value );
		if ( '__UNCHANGED__' === $value || false !== strpos( $value, '••••' ) ) {
			return (string) get_option( RR_OPT_GEMINI_KEY, '' );
		}
		if ( ! empty( $value ) && ! preg_match( '/^AIza[A-Za-z0-9\-_]{20,}$/', $value ) ) {
			add_settings_error( RR_OPT_GEMINI_KEY, 'rr_invalid_gemini_key',
				__( 'The Gemini API key format looks incorrect. Get one from Google AI Studio (aistudio.google.com).', 'rankready' ), 'error' );
			return (string) get_option( RR_OPT_GEMINI_KEY, '' );
		}
		return $value;
	}

	/**
	 * DeepSeek keys begin with `sk-`. Same prefix as OpenAI but a different
	 * issuer — we only check format and length, not API validation here.
	 */
	public static function sanitize_deepseek_key( $value ): string {
		$value = sanitize_text_field( (string) $value );
		if ( '__UNCHANGED__' === $value || false !== strpos( $value, '••••' ) ) {
			return (string) get_option( RR_OPT_DEEPSEEK_KEY, '' );
		}
		if ( ! empty( $value ) && ! preg_match( '/^sk-[A-Za-z0-9]{20,}$/', $value ) ) {
			add_settings_error( RR_OPT_DEEPSEEK_KEY, 'rr_invalid_deepseek_key',
				__( 'The DeepSeek API key format looks incorrect. It should start with sk-', 'rankready' ), 'error' );
			return (string) get_option( RR_OPT_DEEPSEEK_KEY, '' );
		}
		return $value;
	}

	/**
	 * Active LLM provider: must be one of the four known IDs.
	 */
	public static function sanitize_llm_provider( $value ): string {
		$value = sanitize_key( (string) $value );
		$valid = array( 'openai', 'anthropic', 'gemini', 'deepseek' );
		return in_array( $value, $valid, true ) ? $value : 'openai';
	}

	/**
	 * Generic provider model sanitizer. Accepts any model ID listed in the
	 * provider's RR_LLM::get_models_for() list, falls back to provider
	 * default. Resolves the provider from the option name being sanitized.
	 */
	public static function sanitize_provider_model( $value ): string {
		$value = sanitize_text_field( (string) $value );
		// Light validation — no strict allowlist so future model IDs work
		// without a plugin update. We just strip anything that isn't a safe
		// model-ID character (alphanumerics, dot, dash, underscore, slash).
		$value = preg_replace( '/[^a-zA-Z0-9._\-\/]/', '', $value );
		return (string) $value;
	}

	public static function sanitize_dfs_login( $value ): string {
		$value = sanitize_text_field( (string) $value );
		if ( '__UNCHANGED__' === $value ) {
			return (string) get_option( RR_OPT_DFS_LOGIN, '' );
		}
		return $value;
	}

	public static function sanitize_dfs_password( $value ): string {
		$value = (string) $value;
		// Sentinel from FAQ tab hidden field.
		if ( '__UNCHANGED__' === $value ) {
			return (string) get_option( RR_OPT_DFS_PASSWORD, '' );
		}
		// Masked display value — don't overwrite stored password.
		if ( false !== strpos( $value, "\xE2\x80\xA2" ) ) {
			return (string) get_option( RR_OPT_DFS_PASSWORD, '' );
		}
		// Empty means user cleared it.
		if ( '' === trim( $value ) ) {
			return '';
		}
		// Real password — store as-is (no sanitize_text_field, it can mangle hex strings).
		return trim( $value );
	}

	public static function sanitize_model( $value ): string {
		$allowed = array_keys( self::get_allowed_models() );
		$value   = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : 'gpt-4o-mini';
	}

	public static function sanitize_post_types( $value ): array {
		if ( ! is_array( $value ) ) {
			return array( 'post' );
		}
		$allowed = array_keys( self::get_allowed_post_types() );
		return array_values( array_intersect( array_map( 'sanitize_key', $value ), $allowed ) );
	}

	public static function sanitize_term_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_map( 'absint', array_filter( $value ) ) );
	}

	public static function sanitize_checkbox_field( $value ): string {
		return ! empty( $value ) ? '1' : '0';
	}

	public static function sanitize_heading_tag( $value ): string {
		$allowed = array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' );
		$value   = sanitize_text_field( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : 'h4';
	}

	public static function sanitize_auto_display( $value ): string {
		return in_array( $value, array( 'on', 'off' ), true ) ? $value : 'off';
	}

	public static function sanitize_display_position( $value ): string {
		return in_array( $value, array( 'before', 'after' ), true ) ? $value : 'before';
	}

	public static function sanitize_on_off( $value ): string {
		return in_array( $value, array( 'on', 'off' ), true ) ? $value : 'off';
	}

	public static function sanitize_content_signal( $value ): string {
		return in_array( $value, array( 'allow', 'deny' ), true ) ? $value : 'allow';
	}

	public static function sanitize_crawler_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$allowed = array_keys( self::get_llm_crawlers() );
		return array_values( array_intersect( array_map( 'sanitize_text_field', $value ), $allowed ) );
	}

	/**
	 * Get the full list of known LLM/AI crawlers with metadata.
	 *
	 * @return array Associative array: user-agent => array( company, purpose ).
	 */
	public static function get_llm_crawlers(): array {
		return array(
			// ── OpenAI ────────────────────────────────────────────────────────
			'GPTBot'              => array( 'OpenAI', 'GPT model training data' ),
			'ChatGPT-User'        => array( 'OpenAI', 'ChatGPT browse mode (user-initiated)' ),
			'OAI-SearchBot'       => array( 'OpenAI', 'ChatGPT search results' ),
			// ── Anthropic ─────────────────────────────────────────────────────
			'ClaudeBot'           => array( 'Anthropic', 'Claude AI retrieval + training' ),
			'anthropic-ai'        => array( 'Anthropic', 'Anthropic training data collection' ),
			'Claude-Web'          => array( 'Anthropic', 'Claude AI (legacy identifier)' ),
			// ── Google ────────────────────────────────────────────────────────
			'Google-Extended'     => array( 'Google', 'Gemini AI training (does NOT affect search ranking)' ),
			'GoogleOther'         => array( 'Google', 'Google R&D crawling (non-search)' ),
			// ── Apple ─────────────────────────────────────────────────────────
			'Applebot-Extended'   => array( 'Apple', 'Apple Intelligence / Siri AI features' ),
			// ── Microsoft ─────────────────────────────────────────────────────
			'Bingbot'             => array( 'Microsoft', 'Bing Search + Copilot (shared UA)' ),
			// ── Perplexity ────────────────────────────────────────────────────
			'PerplexityBot'       => array( 'Perplexity', 'Perplexity AI answer engine' ),
			// ── Meta ──────────────────────────────────────────────────────────
			'Meta-ExternalAgent'  => array( 'Meta', 'Meta AI / Llama training' ),
			'Meta-ExternalFetcher' => array( 'Meta', 'Meta AI real-time retrieval' ),
			'FacebookBot'         => array( 'Meta', 'Facebook/Meta content crawling' ),
			// ── Mistral ───────────────────────────────────────────────────────
			'MistralAI-User'      => array( 'Mistral', 'Le Chat real-time browsing' ),
			// ── ByteDance ─────────────────────────────────────────────────────
			'Bytespider'          => array( 'ByteDance', 'TikTok / ByteDance AI' ),
			// ── Amazon ────────────────────────────────────────────────────────
			'Amazonbot'           => array( 'Amazon', 'Alexa AI / Amazon' ),
			// ── Cohere ────────────────────────────────────────────────────────
			'cohere-ai'           => array( 'Cohere', 'Cohere AI RAG & enterprise' ),
			// ── AI Search Engines ─────────────────────────────────────────────
			'DuckAssistBot'       => array( 'DuckDuckGo', 'DuckDuckGo AI Assist' ),
			'YouBot'              => array( 'You.com', 'You.com AI search' ),
			'PhindBot'            => array( 'Phind', 'Phind AI search for developers' ),
			// ── Training / Dataset Crawlers ────────────────────────────────────
			'CCBot'               => array( 'Common Crawl', 'Open dataset used by many LLMs' ),
			'AI2Bot'              => array( 'Allen Institute', 'AI2 research crawler' ),
			'Diffbot'             => array( 'Diffbot', 'Diffbot AI extraction' ),
			'Omgilibot'           => array( 'Webz.io', 'AI content aggregation' ),
			'PetalBot'            => array( 'Huawei', 'Huawei search & AI data' ),
			'Brightbot'           => array( 'BrightEdge', 'AI SEO data crawling' ),
			'magpie-crawler'      => array( 'Magpie', 'AI data collection' ),
			'DataForSeoBot'       => array( 'DataForSEO', 'SEO data with AI uses' ),
		);
	}

	// ── Main render ───────────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rankready' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';

		// Redirect old tab slugs to new merged tabs (backward compat for bookmarks / links).
		$legacy_map = array(
			'settings' => 'settings',
			'api'      => 'settings',
			'summary'  => 'content',
			'faq'      => 'content',
			'author'   => 'authority',
			'schema'   => 'authority',
			'llm'      => 'crawlers',
			'headless' => 'advanced',
			'tools'    => 'advanced',
			'info'     => 'advanced',
		);
		if ( isset( $legacy_map[ $active_tab ] ) ) {
			$active_tab = $legacy_map[ $active_tab ];
		}

		$tabs = array(
			'dashboard' => __( 'Dashboard', 'rankready' ),
			'content'   => __( 'Content AI', 'rankready' ),
			'authority' => __( 'Authority', 'rankready' ),
			'crawlers'  => __( 'AI Crawlers', 'rankready' ),
			'settings'  => __( 'Settings', 'rankready' ),
			'advanced'  => __( 'Advanced', 'rankready' ),
		);

		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = 'dashboard';
		}
		?>
		<div class="wrap rr-wrap">
			<div class="rr-header">
				<h1 class="rr-title">
					<?php esc_html_e( 'RankReady', 'rankready' ); ?>
					<span class="rr-version">v<?php echo esc_html( RR_VERSION ); ?></span>
				</h1>
				<p class="rr-subtitle"><?php esc_html_e( 'LLM SEO, EEAT &amp; AI Optimization for WordPress', 'rankready' ); ?></p>
			</div>

			<nav class="nav-tab-wrapper rr-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="rr-tab-content">
				<?php
				switch ( $active_tab ) {
					case 'dashboard':
						self::render_tab_dashboard();
						break;
					case 'content':
						self::render_tab_content_ai();
						break;
					case 'authority':
						self::render_tab_authority();
						break;
					case 'crawlers':
						self::render_tab_llm();
						break;
					case 'settings':
						self::render_tab_api();
						break;
					case 'advanced':
						self::render_tab_advanced();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	// ── Shared UI helpers ─────────────────────────────────────────────────────

	/**
	 * Renders a "Locked — available in Pro" gate block inside any card.
	 * Use in place of Pro-only UI to clearly signal what users are missing.
	 *
	 * @param string $feature     Short feature name.
	 * @param string $description One sentence describing the benefit.
	 */
	private static function render_pro_gate( string $feature, string $description = '' ): void {
		?>
		<div class="rr-pro-gate">
			<span class="dashicons dashicons-lock rr-pro-gate__icon" aria-hidden="true"></span>
			<div class="rr-pro-gate__text">
				<strong><?php echo esc_html( $feature ); ?> <span class="rr-pro-badge">PRO</span></strong>
				<?php if ( $description ) : ?>
					<p><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
				<span class="rr-pro-gate__soon"><?php esc_html_e( 'Launching with RankReady Pro.', 'rankready' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Inline PRO badge span.
	 */
	private static function pro_badge(): string {
		return '<span class="rr-pro-badge">PRO</span>';
	}

	/**
	 * Inline FREE badge span.
	 */
	private static function free_badge(): string {
		return '<span class="rr-free-badge">FREE</span>';
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Dashboard — at-a-glance overview
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_dashboard(): void {
		global $wpdb;

		$is_pro = function_exists( 'rr_is_pro' ) && rr_is_pro();

		$summary_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				RR_META_SUMMARY
			)
		);

		$faq_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				RR_META_FAQ
			)
		);

		$llms_on   = 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' );
		$robots_on = (bool) get_option( RR_OPT_ROBOTS_ENABLE, false );
		$md_on     = 'on' === get_option( RR_OPT_MD_ENABLE, 'off' );
		// Across all four providers — true if any has a key configured.
		$api_set   = RR_LLM::active_provider_ready();

		// Tutorial video — dismissible per user.
		$user_id            = get_current_user_id();
		$tutorial_dismissed = (bool) get_user_meta( $user_id, 'rr_tutorial_dismissed', true );

		// "What's new" banner — show once per major version, dismissible per user.
		$show_whatsnew = self::should_show_whatsnew( $user_id );

		$stats  = RR_Limits::get_stats();
		$s_used = $stats['summary_used'];
		$s_lim  = $stats['summary_limit'];
		$f_used = $stats['faq_used'];
		$f_lim  = $stats['faq_limit'];
		$s_pct  = $s_lim > 0 ? min( 100, round( ( $s_used / $s_lim ) * 100 ) ) : 0;
		$f_pct  = $f_lim > 0 ? min( 100, round( ( $f_used / $f_lim ) * 100 ) ) : 0;

		?>

		<?php if ( $show_whatsnew ) : ?>
		<div class="rr-whatsnew-banner" style="background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);color:#fff;padding:18px 22px;border-radius:8px;margin-bottom:20px;display:flex;align-items:flex-start;gap:16px;">
			<div style="font-size:28px;line-height:1;">🎉</div>
			<div style="flex:1;">
				<h3 style="margin:0 0 6px;color:#fff;font-size:16px;"><?php
					/* translators: %s: version number */
					echo esc_html( sprintf( __( 'RankReady %s is here', 'rankready' ), RR_VERSION ) );
				?></h3>
				<ul style="margin:0;padding:0;list-style:none;font-size:13px;line-height:1.6;color:#e8e8f0;">
					<li><strong><?php esc_html_e( 'Brand Terms.', 'rankready' ); ?></strong> <?php esc_html_e( 'Add your canonical brand names in AI Crawlers → Brand Terms. Powers entity consistency checks.', 'rankready' ); ?></li>
					<li><strong><?php esc_html_e( 'DeepSeek updated.', 'rankready' ); ?></strong> <?php esc_html_e( 'Default model switched to deepseek-v4-flash — faster and cheaper than the previous default.', 'rankready' ); ?></li>
					<li><strong><?php esc_html_e( 'Bug fixes &amp; improvements.', 'rankready' ); ?></strong> <?php esc_html_e( 'Several stability and accuracy improvements across the plugin.', 'rankready' ); ?></li>
				</ul>
				<div style="margin-top:12px;">
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rankready&rr_dismiss_whatsnew=' . RR_VERSION ), 'rr_dismiss_whatsnew' ) ); ?>" style="color:#9da3c0;text-decoration:none;font-size:13px;"><?php esc_html_e( 'Dismiss', 'rankready' ); ?></a>
				</div>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( ! $tutorial_dismissed ) : ?>
		<div class="rr-card rr-tutorial-card" style="margin-bottom:24px;">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
				<h2 class="rr-card-title" style="margin:0;"><?php esc_html_e( '👋 New to RankReady? Watch the video', 'rankready' ); ?></h2>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rankready&rr_dismiss_tutorial=1' ), 'rr_dismiss_tutorial' ) ); ?>" class="rr-tutorial-dismiss" style="color:#646970;text-decoration:none;font-size:13px;"><?php esc_html_e( 'Hide this', 'rankready' ); ?></a>
			</div>
			<p class="rr-card-desc" style="margin-top:0;"><?php esc_html_e( 'Aditya walks through every RankReady setting — AI Summary, FAQ Generator, Author Box, llms.txt, AI Crawler controls — so you can ship a 100/100 AI-ready site.', 'rankready' ); ?></p>
			<div style="position:relative;width:100%;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:6px;background:#000;">
				<iframe
					style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;"
					src="https://www.youtube-nocookie.com/embed/JA-rEwMbqNo?rel=0&modestbranding=1"
					title="<?php esc_attr_e( 'RankReady walkthrough', 'rankready' ); ?>"
					loading="lazy"
					allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
					allowfullscreen></iframe>
			</div>
			<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=settings' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Configure API key →', 'rankready' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=authority' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Set up Author Box →', 'rankready' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=crawlers' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Enable LLMs.txt →', 'rankready' ); ?></a>
			</div>
		</div>
		<?php endif; ?>

		<?php if ( ! $api_set ) : ?>
		<div class="rr-notice rr-notice--warn" style="margin-bottom:20px;">
			<?php esc_html_e( 'No AI provider key set — AI Summaries and FAQ Generator won\'t work until you add one.', 'rankready' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=settings' ) ); ?>" style="margin-left:8px;font-weight:600;"><?php esc_html_e( 'Pick a Provider →', 'rankready' ); ?></a>
		</div>
		<?php endif; ?>

		<!-- ── Value-first stats ─────────────────────────────────────────── -->
		<div class="rr-stats-row" style="margin-bottom:24px;">
			<div class="rr-stat">
				<span class="rr-stat-number"><?php echo esc_html( number_format_i18n( $summary_count ) ); ?></span>
				<span class="rr-stat-label"><?php esc_html_e( 'Posts with AI Summary', 'rankready' ); ?></span>
			</div>
			<div class="rr-stat">
				<span class="rr-stat-number"><?php echo esc_html( number_format_i18n( $faq_count ) ); ?></span>
				<span class="rr-stat-label"><?php esc_html_e( 'Posts with FAQ', 'rankready' ); ?></span>
			</div>
			<div class="rr-stat">
				<span class="rr-stat-number" style="font-size:18px;color:<?php echo $llms_on ? '#00a32a' : '#a7aaad'; ?>;">
					<?php echo $llms_on ? '&#10003;' : '&mdash;'; ?>
				</span>
				<span class="rr-stat-label"><?php esc_html_e( 'LLMs.txt Active', 'rankready' ); ?></span>
			</div>
			<div class="rr-stat">
				<span class="rr-stat-number" style="font-size:18px;color:<?php echo $robots_on ? '#00a32a' : '#a7aaad'; ?>;">
					<?php echo $robots_on ? '&#10003;' : '&mdash;'; ?>
				</span>
				<span class="rr-stat-label"><?php esc_html_e( 'Crawler Controls', 'rankready' ); ?></span>
			</div>
		</div>

		<!-- ── Quick navigation ──────────────────────────────────────────── -->
		<div class="rr-info-grid" style="margin-bottom:28px;">
			<div class="rr-info-item rr-dash-feature">
				<h3><?php esc_html_e( 'Content AI', 'rankready' ); ?></h3>
				<p><?php printf( esc_html__( '%1$d summaries · %2$d FAQ sets on your site.', 'rankready' ), $summary_count, $faq_count ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=content' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Open →', 'rankready' ); ?></a>
			</div>
			<div class="rr-info-item rr-dash-feature">
				<h3><?php esc_html_e( 'Authority', 'rankready' ); ?></h3>
				<p><?php esc_html_e( 'Author box, EEAT schema, Article JSON-LD.', 'rankready' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=authority' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Open →', 'rankready' ); ?></a>
			</div>
			<div class="rr-info-item rr-dash-feature">
				<h3><?php esc_html_e( 'AI Crawlers', 'rankready' ); ?></h3>
				<p><?php
					$parts = array();
					if ( $llms_on )   $parts[] = esc_html__( 'LLMs.txt on', 'rankready' );
					if ( $md_on )     $parts[] = esc_html__( 'Markdown on', 'rankready' );
					if ( $robots_on ) $parts[] = esc_html__( 'Robots on', 'rankready' );
					echo $parts ? esc_html( implode( ' · ', $parts ) ) : esc_html__( 'LLMs.txt, Markdown, bot controls.', 'rankready' );
				?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=crawlers' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Open →', 'rankready' ); ?></a>
			</div>
			<div class="rr-info-item rr-dash-feature">
				<h3><?php esc_html_e( 'Settings', 'rankready' ); ?></h3>
				<p><?php
					if ( $api_set ) {
						echo esc_html( sprintf(
							/* translators: %s: provider label like "OpenAI" or "Claude (Anthropic)" */
							__( '%s key configured.', 'rankready' ),
							RR_LLM::get_provider_label( RR_LLM::get_active_provider() )
						) );
					} else {
						echo '<strong style="color:#d63638;">' . esc_html__( 'AI provider key required', 'rankready' ) . '</strong>';
					}
				?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=settings' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Open →', 'rankready' ); ?></a>
			</div>
			<div class="rr-info-item rr-dash-feature">
				<h3><?php esc_html_e( 'Tools', 'rankready' ); ?></h3>
				<p><?php esc_html_e( 'Health check, bulk actions, content signals.', 'rankready' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=advanced' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Open →', 'rankready' ); ?></a>
			</div>
			<div class="rr-info-item rr-dash-feature">
				<h3><?php esc_html_e( 'Plugin Info', 'rankready' ); ?></h3>
				<p>v<?php echo esc_html( RR_VERSION ); ?> &middot; <a href="https://store.posimyth.com/plugins/rankready/" target="_blank" rel="noopener" style="color:#646970;text-decoration:none;"><?php esc_html_e( 'by POSIMYTH Innovations', 'rankready' ); ?></a></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&puc_check_for_updates=1&puc_slug=rankready' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Check for updates', 'rankready' ); ?></a>
			</div>
		</div>

		<!-- ── Beta: all features active + full feature list ───────────── -->
		<?php if ( $is_pro ) : ?>
		<div class="rr-card" style="margin-bottom:24px;">
			<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
				<h2 class="rr-card-title" style="margin:0;"><?php esc_html_e( 'RankReady Beta', 'rankready' ); ?></h2>
				<span style="background:#2271b1;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:3px;letter-spacing:.5px;"><?php esc_html_e( 'BETA BUILD', 'rankready' ); ?></span>
			</div>
			<p class="rr-card-desc"><?php esc_html_e( 'Thank you for beta testing RankReady! All features below are fully unlocked — no limits, no paywalls.', 'rankready' ); ?></p>
			<ul class="rr-feature-list" style="columns:2;column-gap:32px;margin-top:12px;">
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'LLMs.txt + LLMs-full.txt', 'rankready' ); ?><small><?php esc_html_e( 'Unlimited', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Robots.txt — 31 AI bot controls', 'rankready' ); ?><small><?php esc_html_e( 'Unlimited', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Markdown endpoints per post', 'rankready' ); ?><small><?php esc_html_e( 'Posts &amp; Pages', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Article JSON-LD + Speakable schema', 'rankready' ); ?><small><?php esc_html_e( 'Auto-injected', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'FAQPage JSON-LD schema', 'rankready' ); ?><small><?php esc_html_e( 'Auto with every FAQ', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Full EEAT Author Box + Schema', 'rankready' ); ?><small><?php esc_html_e( 'Person JSON-LD, Wikidata, ORCID', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Unlimited AI Summary Generator', 'rankready' ); ?><small><?php esc_html_e( 'Auto on publish + bulk all posts', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Unlimited FAQ Generation', 'rankready' ); ?><small><?php esc_html_e( 'Auto on publish + bulk all posts', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'HowTo + ItemList Schema', 'rankready' ); ?><small><?php esc_html_e( 'Auto-detected for tutorials &amp; listicles', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'AI Crawler Analytics', 'rankready' ); ?><small><?php esc_html_e( 'ChatGPT, Perplexity, Gemini — who/what/when', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Headless REST Endpoints', 'rankready' ); ?><small><?php esc_html_e( 'Next.js, Nuxt, Astro, SvelteKit', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Custom Post Type support', 'rankready' ); ?><small><?php esc_html_e( 'All AI features on any CPT', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Content Freshness Alerts', 'rankready' ); ?><small><?php esc_html_e( 'Stale post notifications at scale', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Bulk Author Change', 'rankready' ); ?><small><?php esc_html_e( 'Unlimited', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Content Signals', 'rankready' ); ?><small><?php esc_html_e( 'Freshness indicators', 'rankready' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rr-feature-icon rr-feature-icon--check"></span><span><?php esc_html_e( 'Health Check Score', 'rankready' ); ?><small><?php esc_html_e( 'Overall score', 'rankready' ); ?></small></span></li>
			</ul>
		</div>

		<?php endif; ?>

		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Content AI — AI Summary + FAQ Generator merged
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_content_ai(): void {
		// ── Free tier usage banner ─────────────────────────────────────────────
		if ( ! ( function_exists( 'rr_is_pro' ) && rr_is_pro() ) ) {
			$stats            = RR_Limits::get_stats();
			$summary_used     = $stats['summary_used'];
			$summary_limit    = $stats['summary_limit'];
			$summary_left     = $stats['summary_remaining'];
			$faq_used         = $stats['faq_used'];
			$faq_limit        = $stats['faq_limit'];
			$faq_left         = $stats['faq_remaining'];
			$reset_date       = $stats['reset_date'];
			$summary_pct      = $summary_limit > 0 ? min( 100, round( ( $summary_used / $summary_limit ) * 100 ) ) : 0;
			$faq_pct          = $faq_limit > 0 ? min( 100, round( ( $faq_used / $faq_limit ) * 100 ) ) : 0;
			$at_limit_summary = $summary_left <= 0;
			$at_limit_faq     = $faq_left <= 0;
			$warn_class       = ( $at_limit_summary || $at_limit_faq ) ? 'rr-usage-banner--warn' : 'rr-usage-banner--ok';
			?>
			<div class="rr-usage-banner <?php echo esc_attr( $warn_class ); ?>">
				<div class="rr-usage-banner__inner">
					<div class="rr-usage-item">
						<span class="rr-usage-label"><?php esc_html_e( 'AI Summaries', 'rankready' ); ?></span>
						<div class="rr-usage-bar-wrap">
							<div class="rr-usage-bar" style="width: <?php echo esc_attr( $summary_pct ); ?>%;"></div>
						</div>
						<span class="rr-usage-count">
							<?php
							printf(
								/* translators: 1: used, 2: limit */
								esc_html__( '%1$d / %2$d used', 'rankready' ),
								$summary_used,
								$summary_limit
							);
							?>
						</span>
					</div>
					<div class="rr-usage-item">
						<span class="rr-usage-label"><?php esc_html_e( 'FAQ Generations', 'rankready' ); ?></span>
						<div class="rr-usage-bar-wrap">
							<div class="rr-usage-bar" style="width: <?php echo esc_attr( $faq_pct ); ?>%;"></div>
						</div>
						<span class="rr-usage-count">
							<?php
							printf(
								/* translators: 1: used, 2: limit */
								esc_html__( '%1$d / %2$d used', 'rankready' ),
								$faq_used,
								$faq_limit
							);
							?>
						</span>
					</div>
					<div class="rr-usage-reset">
						<?php
						printf(
							/* translators: %s: date like "May 1" */
							esc_html__( 'Resets %s', 'rankready' ),
							esc_html( $reset_date )
						);
						?>
					</div>
					<span class="rr-coming-soon"><?php esc_html_e( 'Pro Coming Soon — unlimited generation', 'rankready' ); ?></span>
				</div>
			</div>
			<?php
		}
		?>
		<?php settings_errors(); ?>

		<?php /* Active AI provider summary — quick visibility on the Content AI
		         tab into what model is currently powering Summary + FAQ, with
		         a one-click jump to Settings to change provider/key. */ ?>
		<div class="rr-card" style="margin-bottom:20px;background:linear-gradient(135deg,#f6f7f7 0%,#eef0f2 100%);">
			<h2 class="rr-card-title"><?php esc_html_e( 'Active AI Provider', 'rankready' ); ?></h2>
			<p class="rr-card-desc" style="margin-bottom:8px;">
				<?php
				$provider_label = RR_LLM::get_provider_label( RR_LLM::get_active_provider() );
				$active_model   = RR_LLM::get_model( RR_LLM::get_active_provider() );
				$key_set        = RR_LLM::active_provider_ready();
				echo esc_html( sprintf(
					/* translators: 1: provider name, 2: model id */
					__( '%1$s — %2$s', 'rankready' ),
					$provider_label,
					$active_model
				) );
				if ( $key_set ) {
					echo ' <span style="color:#00a32a;font-weight:600;">' . esc_html__( '(key configured ✓)', 'rankready' ) . '</span>';
				} else {
					echo ' <span style="color:#d63638;font-weight:600;">' . esc_html__( '(no key — Summary + FAQ won\'t run)', 'rankready' ) . '</span>';
				}
				?>
			</p>
			<p style="margin:0;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=settings' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Change provider / Add API key →', 'rankready' ); ?>
				</a>
			</p>
		</div>

		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::CONTENT_GROUP ); ?>

			<div class="rr-section-header">
				<h2 class="rr-section-title"><?php esc_html_e( 'AI Summary', 'rankready' ); ?></h2>
				<p class="rr-section-desc"><?php esc_html_e( 'Generate key takeaways from post content via your active AI provider. Display via block, widget, or auto-inject.', 'rankready' ); ?></p>
			</div>
			<?php self::render_tab_summary(); ?>

			<div class="rr-section-divider"></div>

			<div class="rr-section-header">
				<h2 class="rr-section-title"><?php esc_html_e( 'FAQ Generator', 'rankready' ); ?></h2>
				<p class="rr-section-desc"><?php esc_html_e( 'Generate FAQPage schema and an expandable FAQ section using DataForSEO question discovery + your active AI provider for answers.', 'rankready' ); ?></p>
			</div>
			<?php self::render_tab_faq(); ?>

			<?php submit_button( __( 'Save Content AI Settings', 'rankready' ) ); ?>
		</form>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Authority — Author Box + Schema Automation merged
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_authority(): void {
		?>
		<?php settings_errors(); ?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::AUTHORITY_GROUP ); ?>

			<div class="rr-section-header">
				<h2 class="rr-section-title"><?php esc_html_e( 'Author Box', 'rankready' ); ?></h2>
				<p class="rr-section-desc"><?php esc_html_e( 'EEAT-optimized author bio with Person JSON-LD schema. Smart-merges with Rank Math, Yoast, and other SEO plugins.', 'rankready' ); ?></p>
			</div>
			<?php self::render_tab_author(); ?>

			<div class="rr-section-divider"></div>

			<div class="rr-section-header">
				<h2 class="rr-section-title"><?php esc_html_e( 'Schema Automation', 'rankready' ); ?></h2>
				<p class="rr-section-desc"><?php esc_html_e( 'FAQPage, HowTo, ItemList, and Article JSON-LD — auto-detected from your content structure.', 'rankready' ); ?></p>
			</div>
			<?php self::render_tab_schema(); ?>

			<?php submit_button( __( 'Save Authority Settings', 'rankready' ) ); ?>
		</form>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Advanced — Headless + Tools + Info merged
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_advanced(): void {
		// Order (per UX feedback): Bulk generation operations come first
		// because they're the most-used tools; Headless / Public API is
		// dev-focused and lives lower on the page.
		?>
		<div class="rr-section-header">
			<h2 class="rr-section-title"><?php esc_html_e( 'Tools', 'rankready' ); ?></h2>
			<p class="rr-section-desc"><?php esc_html_e( 'Bulk operations, health check, freshness alerts, API usage, and data retention.', 'rankready' ); ?></p>
		</div>
		<?php self::render_tab_tools(); ?>

		<div class="rr-section-divider"></div>

		<div class="rr-section-header">
			<h2 class="rr-section-title"><?php esc_html_e( 'Headless / Public API', 'rankready' ); ?></h2>
			<p class="rr-section-desc"><?php esc_html_e( 'REST endpoints, CORS, rate limiting, and on-demand revalidation for Next.js, Nuxt, and Astro sites. Off by default — flip the toggle inside the card to reveal settings.', 'rankready' ); ?></p>
		</div>
		<?php self::render_tab_headless(); ?>

		<div class="rr-section-divider"></div>

		<?php self::render_tab_info(); ?>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: API Keys
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_api(): void {
		$active_provider = RR_LLM::get_active_provider();

		// Helper closure to mask saved keys for display.
		$mask = static function( $val ) {
			return ! empty( $val ) ? substr( (string) $val, 0, 7 ) . str_repeat( '••••', 6 ) : '';
		};

		$openai_disp    = $mask( get_option( RR_OPT_KEY, '' ) );
		$anthropic_disp = $mask( get_option( RR_OPT_ANTHROPIC_KEY, '' ) );
		$gemini_disp    = $mask( get_option( RR_OPT_GEMINI_KEY, '' ) );
		$deepseek_disp  = $mask( get_option( RR_OPT_DEEPSEEK_KEY, '' ) );
		?>
		<?php settings_errors(); ?>

		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::SETTINGS_GROUP ); ?>

			<!-- LLM Provider Picker -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'AI Provider', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Pick which AI powers Summary + FAQ generation. Switch any time. Only the selected provider needs an API key — the others stay dormant.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Active provider', 'rankready' ); ?></th>
						<td>
							<fieldset id="rr-llm-provider-picker">
								<?php
								$providers = array(
									'openai'    => array( 'OpenAI',    __( 'GPT-4o, GPT-4o mini', 'rankready' ) ),
									'anthropic' => array( 'Claude',    __( 'Haiku 4.5, Sonnet 4.6, Opus 4.7', 'rankready' ) ),
									'gemini'    => array( 'Gemini',    __( 'Gemini 2.5 Flash, 2.5 Pro', 'rankready' ) ),
									'deepseek'  => array( 'DeepSeek',  __( 'V4 Flash, V4 Pro', 'rankready' ) ),
								);
								foreach ( $providers as $id => $info ) :
									?>
									<label style="display:block;margin-bottom:6px;">
										<input type="radio" name="<?php echo esc_attr( RR_OPT_LLM_PROVIDER ); ?>" value="<?php echo esc_attr( $id ); ?>" <?php checked( $active_provider, $id ); ?> data-rr-provider-radio />
										<strong><?php echo esc_html( $info[0] ); ?></strong>
										<span style="color:#646970;"> — <?php echo esc_html( $info[1] ); ?></span>
									</label>
									<?php
								endforeach;
								?>
							</fieldset>
						</td>
					</tr>
				</table>
			</div>

			<?php
			// Helper for the per-provider verify button row.
			$render_verify_row = function ( $provider_id ) {
				?>
				<p style="margin-top:8px;">
					<button type="button" class="button button-secondary" data-rr-verify-provider="<?php echo esc_attr( $provider_id ); ?>">
						<?php esc_html_e( 'Verify Key', 'rankready' ); ?>
					</button>
					<span data-rr-verify-status="<?php echo esc_attr( $provider_id ); ?>" style="margin-left:10px;font-size:13px;display:none;"></span>
				</p>
				<?php
			};
			?>

			<!-- OpenAI -->
			<div class="rr-card rr-provider-card" data-rr-provider="openai" <?php echo 'openai' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h2 class="rr-card-title"><?php esc_html_e( 'OpenAI', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Powers AI Summary generation and FAQ answer writing.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><label for="rr_api_key"><?php esc_html_e( 'API Key', 'rankready' ); ?></label></th>
						<td>
							<input type="password" id="rr_api_key" name="<?php echo esc_attr( RR_OPT_KEY ); ?>"
								   value="<?php echo esc_attr( $openai_disp ); ?>" class="regular-text"
								   autocomplete="new-password" spellcheck="false"
								   data-rr-key-for="openai" />
							<?php $render_verify_row( 'openai' ); ?>
							<p class="description"><?php esc_html_e( 'Your OpenAI secret key (sk-...). Stored server-side only. Get one at platform.openai.com/api-keys.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rr_model"><?php esc_html_e( 'Model', 'rankready' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RR_OPT_MODEL ); ?>" id="rr_model">
								<?php $current_model = (string) get_option( RR_OPT_MODEL, 'gpt-4o-mini' ); ?>
								<?php foreach ( RR_LLM::get_models_for( 'openai' ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_model, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'gpt-4o-mini is the cheapest and recommended for most sites.', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Anthropic (Claude) -->
			<div class="rr-card rr-provider-card" data-rr-provider="anthropic" <?php echo 'anthropic' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h2 class="rr-card-title"><?php esc_html_e( 'Claude (Anthropic)', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Claude is exceptional at following content rules and producing factual, citation-quality output for AI summaries and FAQs.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><label for="rr_anthropic_key"><?php esc_html_e( 'API Key', 'rankready' ); ?></label></th>
						<td>
							<input type="password" id="rr_anthropic_key" name="<?php echo esc_attr( RR_OPT_ANTHROPIC_KEY ); ?>"
								   value="<?php echo esc_attr( $anthropic_disp ); ?>" class="regular-text"
								   autocomplete="new-password" spellcheck="false"
								   data-rr-key-for="anthropic" />
							<?php $render_verify_row( 'anthropic' ); ?>
							<p class="description"><?php esc_html_e( 'Your Anthropic API key (sk-ant-...). Get one at console.anthropic.com.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rr_anthropic_model"><?php esc_html_e( 'Model', 'rankready' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RR_OPT_ANTHROPIC_MODEL ); ?>" id="rr_anthropic_model">
								<?php $cur = (string) get_option( RR_OPT_ANTHROPIC_MODEL, 'claude-haiku-4-5' ); ?>
								<?php foreach ( RR_LLM::get_models_for( 'anthropic' ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $cur, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Haiku 4.5 is the cheapest and fastest. Sonnet 4.6 is the balanced pick. Opus 4.7 is highest quality (most expensive).', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Gemini -->
			<div class="rr-card rr-provider-card" data-rr-provider="gemini" <?php echo 'gemini' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h2 class="rr-card-title"><?php esc_html_e( 'Gemini (Google)', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Gemini is the cheapest of the major providers and ships native JSON output. Great default for high-volume sites.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><label for="rr_gemini_key"><?php esc_html_e( 'API Key', 'rankready' ); ?></label></th>
						<td>
							<input type="password" id="rr_gemini_key" name="<?php echo esc_attr( RR_OPT_GEMINI_KEY ); ?>"
								   value="<?php echo esc_attr( $gemini_disp ); ?>" class="regular-text"
								   autocomplete="new-password" spellcheck="false"
								   data-rr-key-for="gemini" />
							<?php $render_verify_row( 'gemini' ); ?>
							<p class="description"><?php esc_html_e( 'Your Google AI Studio API key (AIza...). Get one at aistudio.google.com/apikey.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rr_gemini_model"><?php esc_html_e( 'Model', 'rankready' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RR_OPT_GEMINI_MODEL ); ?>" id="rr_gemini_model">
								<?php $cur = (string) get_option( RR_OPT_GEMINI_MODEL, 'gemini-2.5-flash' ); ?>
								<?php foreach ( RR_LLM::get_models_for( 'gemini' ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $cur, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Gemini 2.5 Flash is recommended for both summaries and FAQ.', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- DeepSeek -->
			<div class="rr-card rr-provider-card" data-rr-provider="deepseek" <?php echo 'deepseek' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h2 class="rr-card-title"><?php esc_html_e( 'DeepSeek', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Cost-efficient open-source models. V4 Flash for everyday generation, V4 Pro when you need higher quality. (The legacy `deepseek-chat` and `deepseek-reasoner` aliases are being retired by DeepSeek — switch to V4 IDs.)', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><label for="rr_deepseek_key"><?php esc_html_e( 'API Key', 'rankready' ); ?></label></th>
						<td>
							<input type="password" id="rr_deepseek_key" name="<?php echo esc_attr( RR_OPT_DEEPSEEK_KEY ); ?>"
								   value="<?php echo esc_attr( $deepseek_disp ); ?>" class="regular-text"
								   autocomplete="new-password" spellcheck="false"
								   data-rr-key-for="deepseek" />
							<?php $render_verify_row( 'deepseek' ); ?>
							<p class="description"><?php esc_html_e( 'Your DeepSeek API key (sk-...). Get one at platform.deepseek.com/api_keys.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rr_deepseek_model"><?php esc_html_e( 'Model', 'rankready' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RR_OPT_DEEPSEEK_MODEL ); ?>" id="rr_deepseek_model">
								<?php $cur = (string) get_option( RR_OPT_DEEPSEEK_MODEL, 'deepseek-v4-flash' ); ?>
								<?php foreach ( RR_LLM::get_models_for( 'deepseek' ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $cur, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
			</div>

			<script>
			(function(){
				// Hide non-selected provider cards on radio change.
				const radios = document.querySelectorAll('[data-rr-provider-radio]');
				const cards  = document.querySelectorAll('[data-rr-provider]');
				const sync = () => {
					const sel = document.querySelector('[data-rr-provider-radio]:checked');
					if (!sel) return;
					cards.forEach(c => { c.style.display = (c.dataset.rrProvider === sel.value) ? '' : 'none'; });
				};
				radios.forEach(r => r.addEventListener('change', sync));
			})();
			</script>

			<!-- Product Context -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Product Context', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Describe your product/brand so the AI knows what it is writing about. This is injected into both Key Takeaways and FAQ prompts to prevent hallucination.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><label for="rr_product_context"><?php esc_html_e( 'Product / Brand Info', 'rankready' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( RR_OPT_PRODUCT_CONTEXT ); ?>" id="rr_product_context"
									  rows="6" class="large-text"
									  placeholder="<?php esc_attr_e( 'Example: Acme SEO Plugin is a WordPress plugin that helps site owners improve their search rankings. It does NOT work with page builders other than Gutenberg. Our brand name is Acme. Website: acmeplugin.com', 'rankready' ); ?>"
							><?php echo esc_textarea( (string) get_option( RR_OPT_PRODUCT_CONTEXT, '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Tell the AI what your product is, what it does, what it does NOT do, brand names, and any facts it must get right. Used in both Summary and FAQ generation.', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- DataForSEO -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'DataForSEO', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Powers FAQ question discovery via keyword suggestions and related keywords. Sign up at dataforseo.com.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><label for="rr_dfs_login"><?php esc_html_e( 'API Login', 'rankready' ); ?></label></th>
						<td>
							<input type="text" id="rr_dfs_login" name="<?php echo esc_attr( RR_OPT_DFS_LOGIN ); ?>"
							       value="<?php echo esc_attr( (string) get_option( RR_OPT_DFS_LOGIN, '' ) ); ?>"
							       class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Your DataForSEO API login email.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rr_dfs_password"><?php esc_html_e( 'API Password', 'rankready' ); ?></label></th>
						<td>
							<?php $dfs_pw = (string) get_option( RR_OPT_DFS_PASSWORD, '' ); ?>
							<?php $dfs_pw_display = ! empty( $dfs_pw ) ? str_repeat( '••••', 4 ) : ''; ?>
							<input type="password" id="rr_dfs_password" name="<?php echo esc_attr( RR_OPT_DFS_PASSWORD ); ?>"
							       value="<?php echo esc_attr( $dfs_pw_display ); ?>"
							       class="regular-text" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Your DataForSEO API password. Enter a new value to change.', 'rankready' ); ?></p>
							<p style="margin-top:8px;">
								<button type="button" id="rr-verify-dfs" class="button button-secondary">
									<?php esc_html_e( 'Verify DataForSEO', 'rankready' ); ?>
								</button>
								<span id="rr-verify-dfs-status" style="margin-left:10px;font-size:13px;display:none;"></span>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Data Retention -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Data Retention', 'rankready' ); ?></h2>
				<p class="rr-card-desc">
					<?php esc_html_e( 'Control what happens to your RankReady data when the plugin is deleted.', 'rankready' ); ?>
				</p>
				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'On Deactivate', 'rankready' ); ?></th>
						<td>
							<p style="margin:0;">
								<span class="dashicons dashicons-shield" style="color:#46b450;"></span>
								<strong><?php esc_html_e( 'Nothing is deleted on deactivation.', 'rankready' ); ?></strong>
							</p>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'Deactivating RankReady only pauses its hooks and clears scheduled cron jobs. All settings, API keys, AI summaries, FAQ data, Author Box profiles, freshness history, and post meta stay exactly where they are. You can reactivate any time and pick up where you left off.', 'rankready' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On Uninstall (Delete)', 'rankready' ); ?></th>
						<td>
							<?php $delete_on_uninstall = (string) get_option( RR_OPT_DELETE_ON_UNINSTALL, 'off' ); ?>
							<label>
								<input type="hidden" name="<?php echo esc_attr( RR_OPT_DELETE_ON_UNINSTALL ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_DELETE_ON_UNINSTALL ); ?>" value="on" <?php checked( $delete_on_uninstall, 'on' ); ?> />
								<?php esc_html_e( 'Delete all RankReady data when the plugin is uninstalled', 'rankready' ); ?>
							</label>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'OFF by default. Uninstalling preserves all your data — API keys, settings, every AI Summary, every FAQ, every Author Box profile, all post meta. Reinstalling RankReady brings everything back automatically.', 'rankready' ); ?>
							</p>
							<p class="description" style="margin-top:6px;color:#d63638;">
								<strong><?php esc_html_e( 'Warning:', 'rankready' ); ?></strong>
								<?php esc_html_e( 'When ON, uninstall permanently removes every RankReady option, post meta, and user meta. Cannot be undone. Leave OFF unless you need a completely clean slate.', 'rankready' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<?php submit_button( __( 'Save Settings', 'rankready' ) ); ?>
		</form>

		<!-- Connection Status -->
		<div class="rr-card rr-card--subtle">
			<h3 class="rr-card-title" style="font-size:14px;"><?php esc_html_e( 'Status', 'rankready' ); ?></h3>
			<div class="rr-stats-row" style="margin-top:12px;">
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo RR_LLM::active_provider_ready() ? '&#10003;' : '&#10007;'; ?></span>
					<span class="rr-stat-label"><?php
						echo esc_html( sprintf(
							/* translators: %s: provider name */
							__( '%s Key', 'rankready' ),
							RR_LLM::get_provider_label( RR_LLM::get_active_provider() )
						) );
					?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo ! empty( get_option( RR_OPT_DFS_LOGIN, '' ) ) && ! empty( get_option( RR_OPT_DFS_PASSWORD, '' ) ) ? '&#10003;' : '&#10007;'; ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'DataForSEO', 'rankready' ); ?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number" style="font-size:14px;"><?php echo esc_html( RR_LLM::get_model( RR_LLM::get_active_provider() ) ); ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'Active Model', 'rankready' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: AI Summary
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_summary(): void {
		?>
			<!-- Post Types & Prompt -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Summary Generation', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Configure which posts get AI summaries and how they are generated.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Post Types', 'rankready' ); ?></th>
						<td>
							<?php $selected_types = (array) get_option( RR_OPT_POST_TYPES, array( 'post' ) ); ?>
							<div class="rr-checkboxes-inline">
								<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( RR_OPT_POST_TYPES ); ?>[]"
											   value="<?php echo esc_attr( $slug ); ?>"
											   <?php checked( in_array( $slug, $selected_types, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Summaries will only auto-generate for selected post types.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rr_custom_prompt"><?php esc_html_e( 'Custom Prompt', 'rankready' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( RR_OPT_CUSTOM_PROMPT ); ?>" id="rr_custom_prompt"
									  rows="4" class="large-text"
									  placeholder="<?php esc_attr_e( 'Leave empty to use the default optimized prompt.', 'rankready' ); ?>"
							><?php echo esc_textarea( (string) get_option( RR_OPT_CUSTOM_PROMPT, '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. Extra instructions appended to the AI prompt.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<?php if ( function_exists( 'rr_is_pro' ) && rr_is_pro() ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-Generate on Publish', 'rankready' ); ?></th>
						<td>
							<?php $auto_gen = (string) get_option( RR_OPT_AUTO_GENERATE, 'off' ); ?>
							<label>
								<input type="hidden" name="<?php echo esc_attr( RR_OPT_AUTO_GENERATE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_AUTO_GENERATE ); ?>" value="on" <?php checked( $auto_gen, 'on' ); ?> />
								<?php esc_html_e( 'Automatically generate Key Takeaways when a post is published or updated', 'rankready' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. When off, summaries are only generated via the Regenerate button, Gutenberg block, or Bulk Generate.', 'rankready' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
				</table>
			</div>

			<?php if ( ! ( function_exists( 'rr_is_pro' ) && rr_is_pro() ) ) : ?>
			<?php self::render_pro_gate(
				__( 'Auto-Generate Summary on Publish', 'rankready' ),
				__( 'Save time on every publish — RankReady generates the AI Summary the moment you hit Publish. Pro feature.', 'rankready' )
			); ?>
			<?php endif; ?>

			<!-- Summary Display — single flat card, matches FAQ Display pattern -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Summary Display', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Control how AI Summaries appear on the frontend. Can also use the Gutenberg block or Elementor widget instead.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto Display', 'rankready' ); ?></th>
						<td>
							<?php $auto_display = (string) get_option( RR_OPT_AUTO_DISPLAY, 'off' ); ?>
							<label>
								<input type="radio" name="<?php echo esc_attr( RR_OPT_AUTO_DISPLAY ); ?>" value="on" <?php checked( $auto_display, 'on' ); ?> />
								<?php esc_html_e( 'On — Automatically inject summary into post content', 'rankready' ); ?>
							</label><br/>
							<label>
								<input type="radio" name="<?php echo esc_attr( RR_OPT_AUTO_DISPLAY ); ?>" value="off" <?php checked( $auto_display, 'off' ); ?> />
								<?php esc_html_e( 'Off — Only show via block, widget, or shortcode', 'rankready' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Position', 'rankready' ); ?></label></th>
						<td>
							<?php $display_pos = (string) get_option( RR_OPT_DISPLAY_POSITION, 'before' ); ?>
							<select name="<?php echo esc_attr( RR_OPT_DISPLAY_POSITION ); ?>">
								<option value="before" <?php selected( $display_pos, 'before' ); ?>><?php esc_html_e( 'Before content', 'rankready' ); ?></option>
								<option value="after"  <?php selected( $display_pos, 'after' ); ?>><?php esc_html_e( 'After content', 'rankready' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Heading Tag', 'rankready' ); ?></label></th>
						<td>
							<?php $current_tag = (string) get_option( RR_OPT_HEADING_TAG, 'h4' ); ?>
							<select name="<?php echo esc_attr( RR_OPT_HEADING_TAG ); ?>">
								<?php foreach ( array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6', 'p' => 'P' ) as $tag => $label ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $current_tag, $tag ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show Label', 'rankready' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_SHOW_LABEL ); ?>" value="1"
									   <?php checked( get_option( RR_OPT_SHOW_LABEL, '1' ), '1' ); ?> />
								<?php esc_html_e( 'Show the label heading above the summary bullets', 'rankready' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Label Text', 'rankready' ); ?></label></th>
						<td>
							<input type="text" name="<?php echo esc_attr( RR_OPT_LABEL ); ?>"
								   value="<?php echo esc_attr( (string) get_option( RR_OPT_LABEL, 'Key Takeaways' ) ); ?>"
								   class="regular-text" />
							<p class="description"><?php esc_html_e( 'e.g. "Key Takeaways", "Article Summary", "TL;DR"', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Schema Automation
	// ═══════════════════════════════════════════════════════════════════════════

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Author Box
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_author(): void {
		$enable        = (string) get_option( RR_OPT_AUTHOR_ENABLE, 'on' );
		$auto_display  = (string) get_option( RR_OPT_AUTHOR_AUTO_DISPLAY, 'off' );
		$layout        = (string) get_option( RR_OPT_AUTHOR_LAYOUT, 'card' );
		$heading       = (string) get_option( RR_OPT_AUTHOR_HEADING, 'About the Author' );
		$heading_tag   = (string) get_option( RR_OPT_AUTHOR_HEADING_TAG, 'h3' );
		$schema_enable = (string) get_option( RR_OPT_AUTHOR_SCHEMA_ENABLE, 'on' );
		$editorial     = (string) get_option( RR_OPT_AUTHOR_EDITORIAL_URL, '' );
		$factcheck     = (string) get_option( RR_OPT_AUTHOR_FACTCHECK_URL, '' );
		$post_types    = (array) get_option( RR_OPT_AUTHOR_POST_TYPES, array( 'post' ) );
		$trust_enable  = (string) get_option( RR_OPT_AUTHOR_TRUST_ENABLE, 'off' );

		$has_rankmath = defined( 'RANK_MATH_VERSION' );
		$has_yoast    = defined( 'WPSEO_VERSION' );
		$has_aioseo   = defined( 'AIOSEO_VERSION' );
		$seo_plugin   = $has_rankmath ? 'Rank Math' : ( $has_yoast ? 'Yoast SEO' : ( $has_aioseo ? 'AIOSEO' : '' ) );

		$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Author Box — EEAT Signals for AI Citation', 'rankready' ); ?></h2>
				<p class="rr-card-desc">
					<?php esc_html_e( 'RankReady adds a full EEAT author profile section to every WordPress user. Every field maps to Schema.org Person data (sameAs, knowsAbout, hasCredential, memberOf, award, worksFor) so AI systems can verify authorship and cite your content. Fill author data in Users → Profile → "RankReady Author Box".', 'rankready' ); ?>
				</p>
				<?php if ( $seo_plugin ) : ?>
					<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin-top:12px;">
						<strong><?php echo esc_html( $seo_plugin ); ?></strong> <?php esc_html_e( 'is active. RankReady will not emit a duplicate Person node. Instead, it enhances the existing Person schema in', 'rankready' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'with RankReady data via the plugin\'s filter hooks. Zero conflict.', 'rankready' ); ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'General', 'rankready' ); ?></h2>
				<table class="form-table rr-form-table">
					<tr>
						<th><?php esc_html_e( 'Enable Author Box', 'rankready' ); ?></th>
						<td>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_AUTHOR_ENABLE ); ?>" value="on" <?php checked( $enable, 'on' ); ?> />
								<span class="rr-toggle-label"><?php esc_html_e( 'Master toggle for the Author Box feature (block, Elementor widget, schema, auto-display).', 'rankready' ); ?></span>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="rr_author_auto_display"><?php esc_html_e( 'Auto-display', 'rankready' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RR_OPT_AUTHOR_AUTO_DISPLAY ); ?>" id="rr_author_auto_display">
								<option value="off"    <?php selected( $auto_display, 'off' ); ?>><?php esc_html_e( 'Off — use block/widget only', 'rankready' ); ?></option>
								<option value="before" <?php selected( $auto_display, 'before' ); ?>><?php esc_html_e( 'Before content', 'rankready' ); ?></option>
								<option value="after"  <?php selected( $auto_display, 'after' ); ?>><?php esc_html_e( 'After content', 'rankready' ); ?></option>
								<option value="both"   <?php selected( $auto_display, 'both' ); ?>><?php esc_html_e( 'Both (above and below)', 'rankready' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Append the author box automatically on singular pages. Skipped when the Author Box block/Elementor widget is already in the content.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Post Types', 'rankready' ); ?></th>
						<td>
							<?php foreach ( $all_post_types as $pt ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="<?php echo esc_attr( RR_OPT_AUTHOR_POST_TYPES ); ?>[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $post_types, true ) ); ?> />
									<?php echo esc_html( $pt->labels->singular_name ); ?> <code><?php echo esc_html( $pt->name ); ?></code>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Post types where auto-display is allowed and the per-post "Author Trust" panel appears.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rr_author_layout"><?php esc_html_e( 'Default Layout', 'rankready' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RR_OPT_AUTHOR_LAYOUT ); ?>" id="rr_author_layout">
								<option value="card"    <?php selected( $layout, 'card' ); ?>><?php esc_html_e( 'Card (full end-of-article box)', 'rankready' ); ?></option>
								<option value="compact" <?php selected( $layout, 'compact' ); ?>><?php esc_html_e( 'Compact (small, sidebar-friendly)', 'rankready' ); ?></option>
								<option value="inline"  <?php selected( $layout, 'inline' ); ?>><?php esc_html_e( 'Inline byline (headline-style)', 'rankready' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Default layout for auto-display and new blocks/widgets. Individual blocks/widgets can override this.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rr_author_heading"><?php esc_html_e( 'Default Heading', 'rankready' ); ?></label></th>
						<td>
							<input type="text" name="<?php echo esc_attr( RR_OPT_AUTHOR_HEADING ); ?>" id="rr_author_heading" value="<?php echo esc_attr( $heading ); ?>" class="regular-text" />
							<select name="<?php echo esc_attr( RR_OPT_AUTHOR_HEADING_TAG ); ?>">
								<?php foreach ( array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ) as $tag ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $heading_tag, $tag ); ?>><?php echo esc_html( strtoupper( $tag ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Heading text shown above the box in Card and Compact layouts. Individual blocks/widgets can override.', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<?php
			$_author_is_pro = function_exists( 'rr_is_pro' ) && rr_is_pro();
			if ( $_author_is_pro ) :
			?>
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Schema', 'rankready' ); ?></h2>
				<table class="form-table rr-form-table">
					<tr>
						<th><?php esc_html_e( 'Emit Person Schema', 'rankready' ); ?></th>
						<td>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_AUTHOR_SCHEMA_ENABLE ); ?>" value="on" <?php checked( $schema_enable, 'on' ); ?> />
								<span class="rr-toggle-label"><?php esc_html_e( 'Include RankReady Person data (sameAs, knowsAbout, credentials, memberOf, awards) in schema output.', 'rankready' ); ?></span>
							</label>
							<p class="description">
								<?php esc_html_e( 'When no SEO plugin is active, RankReady emits a standalone Person node on author archives (via wp_head — no visible page changes) and inline in Article.author on posts. When an SEO plugin is active, RankReady merges its Person fields into the plugin\'s existing schema graph.', 'rankready' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="rr_author_editorial_url"><?php esc_html_e( 'Editorial Policy URL', 'rankready' ); ?></label></th>
						<td>
							<input type="url" name="<?php echo esc_attr( RR_OPT_AUTHOR_EDITORIAL_URL ); ?>" id="rr_author_editorial_url" value="<?php echo esc_attr( $editorial ); ?>" class="regular-text" placeholder="https://" />
							<p class="description"><?php esc_html_e( 'Site-wide editorial standards page. Emits as Person.publishingPrinciples on every author. Also shown as a footer link in the Card layout.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rr_author_factcheck_url"><?php esc_html_e( 'Fact-Check Policy URL', 'rankready' ); ?></label></th>
						<td>
							<input type="url" name="<?php echo esc_attr( RR_OPT_AUTHOR_FACTCHECK_URL ); ?>" id="rr_author_factcheck_url" value="<?php echo esc_attr( $factcheck ); ?>" class="regular-text" placeholder="https://" />
							<p class="description"><?php esc_html_e( 'Optional "How we fact-check" page URL. Shown as a footer link in the Card layout. Purely UI — not in schema.', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Author Trust Panel (optional)', 'rankready' ); ?></h2>
				<table class="form-table rr-form-table">
					<tr>
						<th><?php esc_html_e( 'Enable Trust Panel', 'rankready' ); ?></th>
						<td>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_AUTHOR_TRUST_ENABLE ); ?>" value="on" <?php checked( $trust_enable, 'on' ); ?> />
								<span class="rr-toggle-label"><?php esc_html_e( 'Add per-post "Fact-checked by", "Reviewed by", and "Last reviewed" fields to the post editor.', 'rankready' ); ?></span>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default. Only enable if you have a formal editorial process where a second person fact-checks or medically/legally reviews posts. When on, these fields emit as Article.reviewedBy[] and Article.lastReviewed — the Healthline / WebMD EEAT pattern. When off, the fields are not registered, do not appear in the block editor, and RankReady emits zero reviewer schema. Leave it off for regular blogs and documentation sites that do not need a separate reviewer.', 'rankready' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>
			<?php else : ?>
			<?php
				self::render_pro_gate(
					__( 'Person Schema (EEAT)', 'rankready' ),
					__( 'Emit Person JSON-LD with sameAs, knowsAbout, credentials, memberOf, and awards — the schema fields AI systems use to verify authorship and increase citation probability.', 'rankready' )
				);
				self::render_pro_gate(
					__( 'Editorial & Fact-Check Policy URLs', 'rankready' ),
					__( 'Link your editorial standards and fact-check policy pages into the schema graph. The Healthline / WebMD EEAT pattern — signals editorial integrity to Google and LLMs.', 'rankready' )
				);
				self::render_pro_gate(
					__( 'Author Trust Panel (Reviewed By)', 'rankready' ),
					__( 'Add "Fact-checked by" and "Reviewed by" fields to every post editor. Emits as Article.reviewedBy[] and Article.lastReviewed — the full medical/legal EEAT pattern.', 'rankready' )
				);
			?>
			<?php endif; ?>

			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'How to Use', 'rankready' ); ?></h2>
				<ol style="margin-left:18px;">
					<li><?php esc_html_e( 'Go to Users → your profile and fill in the "RankReady Author Box" section — Bio, headshot, job title, and year started are free.', 'rankready' ); ?></li>
					<li><?php esc_html_e( 'Add the "RankReady Author Box" Gutenberg block to posts, or use the Elementor widget, or enable auto-display above.', 'rankready' ); ?></li>
					<?php if ( $_author_is_pro ) : ?>
					<li><?php esc_html_e( 'Fill in Credentials, Verified Identity (Wikidata, ORCID), and Social links to emit a full Person schema graph.', 'rankready' ); ?></li>
					<li><?php esc_html_e( 'Enable the Author Trust Panel above if you have a formal fact-checker / reviewer workflow.', 'rankready' ); ?></li>
					<li><?php esc_html_e( 'Verify schema output with Google\'s Rich Results Test — Person node appears in the graph.', 'rankready' ); ?></li>
					<?php endif; ?>
				</ol>
			</div>

		<?php
	}

	private static function render_tab_schema(): void {
		$is_pro    = function_exists( 'rr_is_pro' ) && rr_is_pro();
		$article   = (string) get_option( RR_OPT_SCHEMA_ARTICLE, 'on' );
		$faq       = (string) get_option( RR_OPT_SCHEMA_FAQ, 'on' );
		$howto     = (string) get_option( RR_OPT_SCHEMA_HOWTO, 'on' );
		$itemlist  = (string) get_option( RR_OPT_SCHEMA_ITEMLIST, 'on' );
		$speakable = (string) get_option( RR_OPT_SCHEMA_SPEAKABLE, 'on' );

		// Detect active SEO plugins.
		$has_rankmath = defined( 'RANK_MATH_VERSION' );
		$has_yoast    = defined( 'WPSEO_VERSION' );
		$has_aioseo   = defined( 'AIOSEO_VERSION' );
		$seo_plugin   = '';
		if ( $has_rankmath ) $seo_plugin = 'Rank Math';
		elseif ( $has_yoast ) $seo_plugin = 'Yoast SEO';
		elseif ( $has_aioseo ) $seo_plugin = 'AIOSEO';
		?>
			<!-- SEO Plugin Detection -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'SEO Plugin Compatibility', 'rankready' ); ?></h2>
				<?php if ( ! empty( $seo_plugin ) ) : ?>
					<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin-bottom:16px;">
						<strong><?php echo esc_html( $seo_plugin ); ?></strong> <?php esc_html_e( 'is active.', 'rankready' ); ?>
						<?php esc_html_e( 'RankReady automatically adjusts schema output to avoid duplicates:', 'rankready' ); ?>
						<ul style="margin:8px 0 0 20px;list-style:disc;">
							<li><?php esc_html_e( 'Article schema — Handled by', 'rankready' ); ?> <?php echo esc_html( $seo_plugin ); ?>. <?php esc_html_e( 'RankReady skips it automatically.', 'rankready' ); ?></li>
							<li><?php esc_html_e( 'FAQPage schema — RankReady injects only when no', 'rankready' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'FAQ block exists in the post.', 'rankready' ); ?></li>
							<li><?php esc_html_e( 'HowTo schema — RankReady injects only when no', 'rankready' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'HowTo block exists in the post.', 'rankready' ); ?></li>
							<li><?php esc_html_e( 'ItemList schema — Always handled by RankReady (no SEO plugin does this).', 'rankready' ); ?></li>
						</ul>
					</div>
				<?php else : ?>
					<div style="background:#fcf9e8;border-left:4px solid #dba617;padding:12px 16px;margin-bottom:16px;">
						<?php esc_html_e( 'No SEO plugin detected. RankReady will handle all schema types (Article, FAQ, HowTo, ItemList).', 'rankready' ); ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Schema Toggles -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Schema Types', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Enable or disable individual schema types. All schema is auto-detected from your existing content — no manual setup required.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">

					<!-- Article + Speakable -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Article Schema', 'rankready' ); ?></th>
						<td>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_SCHEMA_ARTICLE ); ?>"
									   value="on" <?php checked( $article, 'on' ); ?>
									   <?php echo ! empty( $seo_plugin ) ? 'disabled' : ''; ?> />
								<span class="rr-toggle-label"><?php esc_html_e( 'Article/BlogPosting JSON-LD with author, publisher, dateModified', 'rankready' ); ?></span>
							</label>
							<?php if ( ! empty( $seo_plugin ) ) : ?>
								<p class="description" style="margin-top:4px;color:#666;">
									<?php echo esc_html( sprintf( __( 'Disabled — %s handles Article schema.', 'rankready' ), $seo_plugin ) ); ?>
								</p>
								<input type="hidden" name="<?php echo esc_attr( RR_OPT_SCHEMA_ARTICLE ); ?>" value="on" />
							<?php else : ?>
								<p class="description" style="margin-top:4px;">
									<?php esc_html_e( 'Injects Article JSON-LD on all published posts/pages. Includes headline, author, publisher, image, description, about (categories), mentions (tags).', 'rankready' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<!-- Speakable -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Speakable', 'rankready' ); ?></th>
						<td>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_SCHEMA_SPEAKABLE ); ?>"
									   value="on" <?php checked( $speakable, 'on' ); ?> />
								<span class="rr-toggle-label"><?php esc_html_e( 'Add speakable markup for voice search and AI assistants', 'rankready' ); ?></span>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'Marks the title and excerpt as speakable content. Helps Google Assistant, Alexa, and AI voice queries read your content aloud. Works with both RankReady and SEO plugin Article schema.', 'rankready' ); ?>
							</p>
						</td>
					</tr>

					<!-- FAQPage -->
					<tr>
						<th scope="row"><?php esc_html_e( 'FAQPage Schema', 'rankready' ); ?></th>
						<td>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_SCHEMA_FAQ ); ?>"
									   value="on" <?php checked( $faq, 'on' ); ?> />
								<span class="rr-toggle-label"><?php esc_html_e( 'Inject FAQPage JSON-LD when FAQ data exists', 'rankready' ); ?></span>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'When RankReady FAQ data exists for a post, FAQPage schema is injected automatically. Pages with FAQPage schema are 3.2x more likely to appear in AI Overviews.', 'rankready' ); ?>
							</p>
							<?php if ( ! empty( $seo_plugin ) ) : ?>
								<p class="description" style="color:#666;">
									<?php echo esc_html( sprintf( __( 'Auto-skips when a %s FAQ block is present in the post content to prevent duplicates.', 'rankready' ), $seo_plugin ) ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<!-- HowTo — Pro only -->
					<?php if ( $is_pro ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'HowTo Schema', 'rankready' ); ?></th>
						<td>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_SCHEMA_HOWTO ); ?>"
									   value="on" <?php checked( $howto, 'on' ); ?> />
								<span class="rr-toggle-label"><?php esc_html_e( 'Auto-detect step-by-step content and inject HowTo JSON-LD', 'rankready' ); ?></span>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'Scans posts with "How to", "Tutorial", "Step by Step", or "Guide to" in the title. Extracts steps from your existing headings (Step 1, Step 2...) or ordered lists. No content changes needed.', 'rankready' ); ?>
							</p>
							<details style="margin-top:8px;">
								<summary style="cursor:pointer;color:#2271b1;font-weight:500;"><?php esc_html_e( 'How detection works', 'rankready' ); ?></summary>
								<div style="margin-top:8px;padding:12px;background:#f9f9f9;border-radius:4px;">
									<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Title must contain one of:', 'rankready' ); ?></strong> "how to", "how-to", "step by step", "step-by-step", "tutorial", "guide to"</p>
									<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Steps detected from (in priority order):', 'rankready' ); ?></strong></p>
									<ol style="margin:0 0 8px 20px;">
										<li><?php esc_html_e( 'Headings with "Step N" — e.g., <h2>Step 1: Install the Plugin</h2>', 'rankready' ); ?></li>
										<li><?php esc_html_e( 'Numbered headings — e.g., <h2>1. Install the Plugin</h2>', 'rankready' ); ?></li>
										<li><?php esc_html_e( 'Ordered lists — <ol><li>Install the Plugin</li></ol>', 'rankready' ); ?></li>
									</ol>
									<p style="margin:0;"><strong><?php esc_html_e( 'Minimum:', 'rankready' ); ?></strong> <?php esc_html_e( '2 steps required. Extracts step name, description, and images automatically.', 'rankready' ); ?></p>
								</div>
							</details>
							<?php if ( ! empty( $seo_plugin ) ) : ?>
								<p class="description" style="margin-top:4px;color:#666;">
									<?php echo esc_html( sprintf( __( 'Auto-skips when a %s HowTo block is present in the post content.', 'rankready' ), $seo_plugin ) ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; // is_pro — HowTo ?>

					<!-- ItemList — Pro only -->
					<?php if ( $is_pro ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'ItemList Schema', 'rankready' ); ?></th>
						<td>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_SCHEMA_ITEMLIST ); ?>"
									   value="on" <?php checked( $itemlist, 'on' ); ?> />
								<span class="rr-toggle-label"><?php esc_html_e( 'Auto-detect listicle posts and inject ItemList JSON-LD', 'rankready' ); ?></span>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'Scans posts with "Best N", "Top N", "N Best Plugins", etc. in the title. Extracts list items from numbered headings. Perfect for "best of" and comparison posts that AI models use for recommendations.', 'rankready' ); ?>
							</p>
							<details style="margin-top:8px;">
								<summary style="cursor:pointer;color:#2271b1;font-weight:500;"><?php esc_html_e( 'How detection works', 'rankready' ); ?></summary>
								<div style="margin-top:8px;padding:12px;background:#f9f9f9;border-radius:4px;">
									<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Title must match one of these patterns:', 'rankready' ); ?></strong></p>
									<ul style="margin:0 0 8px 20px;list-style:disc;">
										<li><?php esc_html_e( 'Number + qualifier: "10 Best WordPress Plugins", "Top 5 Elementor Addons"', 'rankready' ); ?></li>
										<li><?php esc_html_e( 'Qualifier + number: "Best 10 Tools for SEO"', 'rankready' ); ?></li>
										<li><?php esc_html_e( 'Number + noun: "7 Plugins Every Developer Needs", "15 Tips for Speed"', 'rankready' ); ?></li>
										<li><?php esc_html_e( 'Qualifier without number: "Best Elementor Addons", "Top WordPress Themes"', 'rankready' ); ?></li>
									</ul>
									<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Items extracted from:', 'rankready' ); ?></strong></p>
									<ol style="margin:0 0 8px 20px;">
										<li><?php esc_html_e( 'Numbered headings — e.g., <h2>1. Essential Addons</h2>', 'rankready' ); ?></li>
										<li><?php esc_html_e( 'Consecutive headings — 3+ h2/h3 headings in sequence', 'rankready' ); ?></li>
									</ol>
									<p style="margin:0;"><strong><?php esc_html_e( 'Per item:', 'rankready' ); ?></strong> <?php esc_html_e( 'Extracts name, URL (from links), description (first paragraph), and image. Minimum 3 items required.', 'rankready' ); ?></p>
								</div>
							</details>
							<p class="description" style="margin-top:4px;color:#666;">
								<?php esc_html_e( 'Mutually exclusive with HowTo — if the title matches both, HowTo takes priority. No SEO plugin provides automatic ItemList detection.', 'rankready' ); ?>
							</p>
						</td>
					</tr>
					<?php endif; // is_pro — ItemList ?>
				</table>
			</div>

			<?php if ( ! $is_pro ) : ?>
			<!-- HowTo + ItemList Pro gate (shown below the free schema options) -->
			<div style="margin-top:0;">
				<?php self::render_pro_gate(
					__( 'HowTo Schema', 'rankready' ),
					__( 'Auto-detect step-by-step posts and inject HowTo JSON-LD. Triggered by "How to", "Tutorial", "Step by Step", or "Guide to" in the title — steps extracted from your existing headings.', 'rankready' )
				); ?>
				<?php self::render_pro_gate(
					__( 'ItemList Schema', 'rankready' ),
					__( 'Auto-detect "Best N / Top N" listicle posts and inject ItemList JSON-LD. No SEO plugin does this automatically — it\'s what makes your recommendation posts AI-readable.', 'rankready' )
				); ?>
			</div>
			<?php endif; ?>

			<!-- How Schema Decision Works -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'How Schema Detection Works', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'RankReady reads each post and automatically decides which schema to inject. No manual setup needed.', 'rankready' ); ?></p>
				<div style="padding:16px;background:#f9f9f9;border-radius:4px;font-family:monospace;font-size:13px;line-height:1.8;">
					<?php esc_html_e( 'Post loads on frontend', 'rankready' ); ?><br>
					&nbsp;&nbsp;|<br>
					&nbsp;&nbsp;|-- <?php esc_html_e( 'Article schema?', 'rankready' ); ?><br>
					<?php if ( ! empty( $seo_plugin ) ) : ?>
					&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?php echo esc_html( sprintf( __( 'Skipped (%s active)', 'rankready' ), $seo_plugin ) ); ?><br>
					<?php else : ?>
					&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?php esc_html_e( 'YES — injected on all posts/pages', 'rankready' ); ?><br>
					<?php endif; ?>
					&nbsp;&nbsp;|<br>
					&nbsp;&nbsp;|-- <?php esc_html_e( 'FAQPage schema?', 'rankready' ); ?><br>
					&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?php esc_html_e( 'Only if RankReady FAQ data exists AND no SEO plugin FAQ block in content', 'rankready' ); ?><br>
					&nbsp;&nbsp;|<br>
					&nbsp;&nbsp;|-- <?php esc_html_e( 'HowTo schema?', 'rankready' ); ?><br>
					&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?php esc_html_e( 'Only if title has "how to/tutorial/step by step" AND 2+ steps detected', 'rankready' ); ?><br>
					&nbsp;&nbsp;|<br>
					&nbsp;&nbsp;|-- <?php esc_html_e( 'ItemList schema?', 'rankready' ); ?><br>
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?php esc_html_e( 'Only if title has "Best N/Top N/N Plugins" AND 3+ items AND NOT a HowTo post', 'rankready' ); ?>
				</div>
			</div>

		<?php
	}

	// TAB: LLM Optimization
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_llm(): void {
		?>
		<?php settings_errors(); ?>

		<!-- ── AI Crawler Access Log ─────────────────────────────────────── -->
		<?php
		$ep_labels     = RR_Crawler_Log::ENDPOINT_LABELS;
		$total_30d     = RR_Crawler_Log::get_total( 30 );
		$total_7d      = RR_Crawler_Log::get_total( 7 );
		$unique_pages  = RR_Crawler_Log::get_unique_pages( 30 );
		$bot_stats     = RR_Crawler_Log::get_bot_stats( 30 );
		$cpt_stats     = RR_Crawler_Log::get_cpt_stats( 30 );
		$top_pages     = RR_Crawler_Log::get_top_pages( 30, 15 );
		$ep_totals     = RR_Crawler_Log::get_endpoint_totals( 30 );
		$recent_hits   = RR_Crawler_Log::get_recent_hits( 40 );
		$cpt_max       = empty( $cpt_stats ) ? 1 : max( array_column( $cpt_stats, 'total' ) );
		?>

		<div class="rr-card" style="margin-bottom:24px;">
			<h2 class="rr-card-title"><?php esc_html_e( 'AI Crawler Access Log', 'rankready' ); ?></h2>
			<p class="rr-card-desc"><?php esc_html_e( 'Real-time tracking of known AI bots reading your llms.txt, Markdown, and homepage endpoints. Logs CPT, post title, and URL per hit. 90-day retention, auto-pruned daily.', 'rankready' ); ?></p>

			<!-- ① Summary strip ──────────────────────────────────────────── -->
			<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;">
				<?php
				$cards = array(
					array( 'val' => number_format( $total_30d ),    'label' => __( 'Hits — 30 days', 'rankready' ) ),
					array( 'val' => number_format( $total_7d ),     'label' => __( 'Hits — 7 days', 'rankready' ) ),
					array( 'val' => count( $bot_stats ),            'label' => __( 'Unique bots', 'rankready' ) ),
					array( 'val' => number_format( $unique_pages ),  'label' => __( 'Unique pages read', 'rankready' ) ),
					array( 'val' => number_format( $ep_totals['llms_txt'] ),  'label' => __( 'llms.txt hits', 'rankready' ) ),
					array( 'val' => number_format( $ep_totals['markdown'] + $ep_totals['home_md'] ), 'label' => __( 'Markdown hits', 'rankready' ) ),
				);
				foreach ( $cards as $c ) :
				?>
				<div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px 18px;min-width:110px;text-align:center;flex:1;">
					<div style="font-size:26px;font-weight:700;color:#1d2327;line-height:1.1;"><?php echo esc_html( $c['val'] ); ?></div>
					<div style="font-size:11px;color:#646970;margin-top:3px;"><?php echo esc_html( $c['label'] ); ?></div>
				</div>
				<?php endforeach; ?>
			</div>

			<?php if ( empty( $bot_stats ) ) : ?>
				<p style="color:#646970;font-style:italic;padding:12px 0;"><?php esc_html_e( 'No AI crawler visits recorded yet. Once a known bot hits your llms.txt, .md, or homepage endpoints the data appears here automatically.', 'rankready' ); ?></p>
			<?php else : ?>

			<!-- ② By Bot — expandable rows ──────────────────────────────── -->
			<h3 style="font-size:13px;font-weight:600;color:#1d2327;margin:0 0 10px;text-transform:uppercase;letter-spacing:.04em;"><?php esc_html_e( 'By Bot', 'rankready' ); ?></h3>
			<div style="border:1px solid #dcdcde;border-radius:6px;overflow:hidden;margin-bottom:24px;">
				<!-- header row -->
				<div style="display:grid;grid-template-columns:1fr 70px 80px 80px 75px 75px 80px 130px;gap:0;background:#f6f7f7;border-bottom:1px solid #dcdcde;padding:7px 12px;font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.03em;">
					<span><?php esc_html_e( 'Bot', 'rankready' ); ?></span>
					<span style="text-align:center;"><?php esc_html_e( 'Total', 'rankready' ); ?></span>
					<span style="text-align:center;"><?php esc_html_e( 'llms.txt', 'rankready' ); ?></span>
					<span style="text-align:center;"><?php esc_html_e( 'llms-full', 'rankready' ); ?></span>
					<span style="text-align:center;"><?php esc_html_e( '.md URL', 'rankready' ); ?></span>
					<span style="text-align:center;"><?php esc_html_e( 'Home .md', 'rankready' ); ?></span>
					<span style="text-align:center;"><?php esc_html_e( 'Pages', 'rankready' ); ?></span>
					<span><?php esc_html_e( 'Last Seen', 'rankready' ); ?></span>
				</div>
				<?php foreach ( $bot_stats as $i => $row ) :
					$bot_pages = RR_Crawler_Log::get_bot_top_pages( $row['bot_name'], 30, 5 );
					$has_pages = ! empty( $bot_pages );
					$bg        = 0 === $i % 2 ? '#fff' : '#f9f9f9';
				?>
				<details style="border-bottom:1px solid #dcdcde;">
					<summary style="display:grid;grid-template-columns:1fr 70px 80px 80px 75px 75px 80px 130px;gap:0;padding:9px 12px;background:<?php echo esc_attr( $bg ); ?>;cursor:<?php echo $has_pages ? 'pointer' : 'default'; ?>;list-style:none;align-items:center;">
						<span style="font-size:13px;font-weight:600;color:#1d2327;display:flex;align-items:center;gap:6px;">
							<?php if ( $has_pages ) : ?><span style="color:#2271b1;font-size:10px;">&#9660;</span><?php endif; ?>
							<?php echo esc_html( $row['bot_name'] ); ?>
						</span>
						<span style="text-align:center;font-weight:700;color:#1d2327;"><?php echo esc_html( number_format( (int) $row['total'] ) ); ?></span>
						<span style="text-align:center;color:#646970;"><?php echo ( (int) $row['llms_txt'] > 0 ) ? esc_html( number_format( (int) $row['llms_txt'] ) ) : '—'; ?></span>
						<span style="text-align:center;color:#646970;"><?php echo ( (int) $row['llms_full'] > 0 ) ? esc_html( number_format( (int) $row['llms_full'] ) ) : '—'; ?></span>
						<span style="text-align:center;color:#646970;"><?php echo ( (int) $row['markdown'] > 0 ) ? esc_html( number_format( (int) $row['markdown'] ) ) : '—'; ?></span>
						<span style="text-align:center;color:#646970;"><?php echo ( (int) $row['home_md'] > 0 ) ? esc_html( number_format( (int) $row['home_md'] ) ) : '—'; ?></span>
						<span style="text-align:center;color:#2271b1;font-weight:600;"><?php echo esc_html( number_format( (int) $row['unique_pages'] ) ); ?></span>
						<span style="font-size:11px;color:#646970;"><?php echo esc_html( wp_date( 'M j, H:i', strtotime( $row['last_seen'] ) ) ); ?></span>
					</summary>
					<?php if ( $has_pages ) : ?>
					<div style="padding:8px 12px 10px 28px;background:#f0f6fc;border-top:1px solid #dcdcde;">
						<div style="font-size:11px;font-weight:600;color:#646970;margin-bottom:6px;text-transform:uppercase;letter-spacing:.03em;"><?php esc_html_e( 'Top pages read by this bot', 'rankready' ); ?></div>
						<table style="width:100%;border-collapse:collapse;font-size:12px;">
							<thead>
								<tr style="color:#646970;">
									<th style="text-align:left;padding:3px 8px;font-weight:600;"><?php esc_html_e( 'Title', 'rankready' ); ?></th>
									<th style="text-align:left;padding:3px 8px;font-weight:600;width:80px;"><?php esc_html_e( 'Type', 'rankready' ); ?></th>
									<th style="text-align:left;padding:3px 8px;font-weight:600;"><?php esc_html_e( 'URL', 'rankready' ); ?></th>
									<th style="text-align:center;padding:3px 8px;font-weight:600;width:55px;"><?php esc_html_e( 'Hits', 'rankready' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $bot_pages as $pg ) : ?>
								<tr>
									<td style="padding:4px 8px;color:#1d2327;font-weight:500;">
										<?php if ( ! empty( $pg['post_title'] ) ) : ?>
											<?php if ( (int) $pg['post_id'] > 0 ) : ?>
												<a href="<?php echo esc_url( get_edit_post_link( (int) $pg['post_id'] ) ); ?>" style="color:#2271b1;text-decoration:none;" target="_blank"><?php echo esc_html( $pg['post_title'] ); ?></a>
											<?php else : ?>
												<?php echo esc_html( $pg['post_title'] ); ?>
											<?php endif; ?>
										<?php else : ?>
											<em style="color:#646970;"><?php esc_html_e( '(no title)', 'rankready' ); ?></em>
										<?php endif; ?>
									</td>
									<td style="padding:4px 8px;"><span style="background:#e0e7ff;color:#3730a3;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:600;"><?php echo esc_html( $pg['post_type'] ); ?></span></td>
									<td style="padding:4px 8px;"><code style="font-size:10px;color:#646970;"><?php echo esc_html( $pg['url_path'] ); ?></code></td>
									<td style="padding:4px 8px;text-align:center;font-weight:700;color:#1d2327;"><?php echo esc_html( $pg['total'] ); ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php endif; ?>
				</details>
				<?php endforeach; ?>
			</div>

			<!-- ③ By Content Type (CPT bar chart) ───────────────────────── -->
			<?php if ( ! empty( $cpt_stats ) ) : ?>
			<h3 style="font-size:13px;font-weight:600;color:#1d2327;margin:0 0 10px;text-transform:uppercase;letter-spacing:.04em;"><?php esc_html_e( 'By Content Type', 'rankready' ); ?></h3>
			<div style="border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;margin-bottom:24px;">
				<div style="display:grid;grid-template-columns:100px 1fr 70px 90px;gap:8px 12px;align-items:center;font-size:12px;">
					<span style="font-weight:600;color:#646970;font-size:11px;text-transform:uppercase;"><?php esc_html_e( 'Type', 'rankready' ); ?></span>
					<span></span>
					<span style="text-align:center;font-weight:600;color:#646970;font-size:11px;text-transform:uppercase;"><?php esc_html_e( 'Hits', 'rankready' ); ?></span>
					<span style="text-align:center;font-weight:600;color:#646970;font-size:11px;text-transform:uppercase;"><?php esc_html_e( 'Unique posts', 'rankready' ); ?></span>
				</div>
				<?php foreach ( $cpt_stats as $cpt ) :
					$pct = round( ( (int) $cpt['total'] / max( 1, (int) $cpt_max ) ) * 100 );
					// Colour coding by type
					$bar_colour = '#2271b1';
					if ( 'post' === $cpt['post_type'] )     $bar_colour = '#2271b1';
					if ( 'page' === $cpt['post_type'] )     $bar_colour = '#8b5cf6';
					if ( 'homepage' === $cpt['post_type'] ) $bar_colour = '#059669';
				?>
				<div style="display:grid;grid-template-columns:100px 1fr 70px 90px;gap:4px 12px;align-items:center;padding:5px 0;border-top:1px solid #f0f0f1;">
					<span style="font-size:12px;font-weight:600;color:#1d2327;">
						<span style="background:<?php echo esc_attr( $bar_colour ); ?>22;color:<?php echo esc_attr( $bar_colour ); ?>;padding:1px 7px;border-radius:3px;font-size:11px;">
							<?php echo esc_html( $cpt['post_type'] ); ?>
						</span>
					</span>
					<div style="background:#f0f0f1;border-radius:3px;height:14px;overflow:hidden;">
						<div style="width:<?php echo esc_attr( $pct ); ?>%;background:<?php echo esc_attr( $bar_colour ); ?>;height:14px;border-radius:3px;transition:width .3s;"></div>
					</div>
					<span style="text-align:center;font-weight:700;color:#1d2327;"><?php echo esc_html( number_format( (int) $cpt['total'] ) ); ?></span>
					<span style="text-align:center;color:#646970;"><?php echo esc_html( number_format( (int) $cpt['unique_posts'] ) ); ?></span>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<!-- ④ Top Pages ──────────────────────────────────────────────── -->
			<?php if ( ! empty( $top_pages ) ) : ?>
			<h3 style="font-size:13px;font-weight:600;color:#1d2327;margin:0 0 10px;text-transform:uppercase;letter-spacing:.04em;"><?php esc_html_e( 'Top Pages Read by AI Bots', 'rankready' ); ?></h3>
			<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page / Post Title', 'rankready' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Type', 'rankready' ); ?></th>
						<th style="width:65px;text-align:center;"><?php esc_html_e( 'Hits', 'rankready' ); ?></th>
						<th style="width:65px;text-align:center;"><?php esc_html_e( 'Bots', 'rankready' ); ?></th>
						<th><?php esc_html_e( 'Read by', 'rankready' ); ?></th>
						<th><?php esc_html_e( 'URL', 'rankready' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $top_pages as $pg ) :
						$bots_list = ! empty( $pg['bots_csv'] ) ? explode( '|', $pg['bots_csv'] ) : array();
					?>
					<tr>
						<td style="font-weight:600;">
							<?php if ( ! empty( $pg['post_title'] ) ) : ?>
								<?php if ( (int) $pg['post_id'] > 0 ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( (int) $pg['post_id'] ) ); ?>" style="color:#1d2327;text-decoration:none;" target="_blank">
										<?php echo esc_html( $pg['post_title'] ); ?>
									</a>
									<span style="display:block;font-size:10px;font-weight:400;color:#646970;">ID <?php echo esc_html( $pg['post_id'] ); ?></span>
								<?php else : ?>
									<?php echo esc_html( $pg['post_title'] ); ?>
								<?php endif; ?>
							<?php else : ?>
								<em style="color:#646970;font-weight:400;"><?php esc_html_e( '—', 'rankready' ); ?></em>
							<?php endif; ?>
						</td>
						<td><span style="background:#f0f0f1;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;"><?php echo esc_html( $pg['post_type'] ); ?></span></td>
						<td style="text-align:center;font-weight:700;"><?php echo esc_html( number_format( (int) $pg['total'] ) ); ?></td>
						<td style="text-align:center;color:#646970;"><?php echo esc_html( $pg['unique_bots'] ); ?></td>
						<td style="font-size:11px;color:#646970;">
							<?php foreach ( array_slice( $bots_list, 0, 3 ) as $b ) : ?>
								<span style="display:inline-block;background:#e0f0fb;color:#0a3862;padding:1px 5px;border-radius:3px;margin:1px 2px 1px 0;"><?php echo esc_html( $b ); ?></span>
							<?php endforeach; ?>
							<?php if ( count( $bots_list ) > 3 ) : ?>
								<span style="color:#646970;">+<?php echo esc_html( count( $bots_list ) - 3 ); ?></span>
							<?php endif; ?>
						</td>
						<td><code style="font-size:10px;color:#646970;"><?php echo esc_html( $pg['url_path'] ); ?></code></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<!-- ⑤ Live Hit Log ───────────────────────────────────────────── -->
			<?php if ( ! empty( $recent_hits ) ) : ?>
			<details>
				<summary style="cursor:pointer;font-size:12px;font-weight:600;color:#2271b1;text-decoration:underline;list-style:none;">
					&#9660; <?php esc_html_e( 'Live Hit Log — last 40 entries', 'rankready' ); ?>
				</summary>
				<div style="margin-top:10px;overflow-x:auto;">
				<table class="wp-list-table widefat fixed striped" style="font-size:11px;">
					<thead>
						<tr>
							<th style="width:130px;"><?php esc_html_e( 'Time', 'rankready' ); ?></th>
							<th><?php esc_html_e( 'Bot', 'rankready' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Endpoint', 'rankready' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Type', 'rankready' ); ?></th>
							<th><?php esc_html_e( 'Page / Post', 'rankready' ); ?></th>
							<th><?php esc_html_e( 'URL', 'rankready' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_hits as $hit ) : ?>
						<tr>
							<td style="color:#646970;"><?php echo esc_html( wp_date( 'M j H:i:s', strtotime( $hit['logged_at'] ) ) ); ?></td>
							<td style="font-weight:600;"><?php echo esc_html( $hit['bot_name'] ); ?></td>
							<td><?php echo esc_html( $ep_labels[ $hit['endpoint'] ] ?? $hit['endpoint'] ); ?></td>
							<td>
								<?php if ( ! empty( $hit['post_type'] ) ) : ?>
								<span style="background:#f0f0f1;padding:1px 5px;border-radius:3px;font-size:10px;"><?php echo esc_html( $hit['post_type'] ); ?></span>
								<?php else : ?>—<?php endif; ?>
							</td>
							<td style="color:#1d2327;">
								<?php echo ! empty( $hit['post_title'] ) ? esc_html( $hit['post_title'] ) : '<em style="color:#646970;">—</em>'; ?>
							</td>
							<td><code style="font-size:10px;"><?php echo esc_html( $hit['url_path'] ); ?></code></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</details>
			<?php endif; ?>

			<?php endif; // end if bot_stats not empty ?>
		</div>
		<!-- ── /AI Crawler Access Log ─────────────────────────────────────── -->

		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::LLMS_GROUP ); ?>

			<!-- LLMs.txt -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'LLMs.txt Generator', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Generate a /llms.txt file following the llmstxt.org specification. Helps AI models understand your site.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable LLMs.txt', 'rankready' ); ?></th>
						<td>
							<?php $llms_enable = (string) get_option( RR_OPT_LLMS_ENABLE, 'off' ); ?>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_LLMS_ENABLE ); ?>"
									   value="on" <?php checked( $llms_enable, 'on' ); ?>
									   data-toggle-target="rr-llms-fields" />
								<span class="rr-toggle-label"><?php esc_html_e( 'Serve /llms.txt on your site', 'rankready' ); ?></span>
							</label>
							<?php if ( 'on' === $llms_enable ) : ?>
								<p class="description" style="margin-top:8px;">
									<?php esc_html_e( 'Live at:', 'rankready' ); ?>
									<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank"><code><?php echo esc_html( home_url( '/llms.txt' ) ); ?></code></a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<div id="rr-llms-fields" class="rr-conditional-fields" <?php echo 'on' !== $llms_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rr-form-table">
						<tr>
							<th scope="row"><label for="rr_llms_site_name"><?php esc_html_e( 'Site Name (H1)', 'rankready' ); ?></label></th>
							<td>
								<input type="text" id="rr_llms_site_name" name="<?php echo esc_attr( RR_OPT_LLMS_SITE_NAME ); ?>"
									   value="<?php echo esc_attr( (string) get_option( RR_OPT_LLMS_SITE_NAME, '' ) ); ?>"
									   class="regular-text"
									   placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
								<p class="description"><?php esc_html_e( 'The H1 heading in your llms.txt. Defaults to your site name.', 'rankready' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rr_llms_summary"><?php esc_html_e( 'Site Summary', 'rankready' ); ?></label></th>
							<td>
								<textarea id="rr_llms_summary" name="<?php echo esc_attr( RR_OPT_LLMS_SUMMARY ); ?>"
										  rows="3" class="large-text"
										  placeholder="<?php esc_attr_e( 'Brief one-line summary of your site (appears as blockquote)', 'rankready' ); ?>"
								><?php echo esc_textarea( (string) get_option( RR_OPT_LLMS_SUMMARY, '' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Rendered as a blockquote below the H1. Keep it concise.', 'rankready' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rr_llms_about"><?php esc_html_e( 'About Section', 'rankready' ); ?></label></th>
							<td>
								<textarea id="rr_llms_about" name="<?php echo esc_attr( RR_OPT_LLMS_ABOUT ); ?>"
										  rows="5" class="large-text"
										  placeholder="<?php esc_attr_e( 'Detailed description of your site, products, services. Markdown supported.', 'rankready' ); ?>"
								><?php echo esc_textarea( (string) get_option( RR_OPT_LLMS_ABOUT, '' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Detailed info about your site. Supports markdown. Appears after the summary.', 'rankready' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Include Post Types', 'rankready' ); ?></th>
							<td>
								<?php $llms_types = (array) get_option( RR_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) ); ?>
								<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="<?php echo esc_attr( RR_OPT_LLMS_POST_TYPES ); ?>[]"
											   value="<?php echo esc_attr( $slug ); ?>"
											   <?php checked( in_array( $slug, $llms_types, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Which post types to list in llms.txt as file lists.', 'rankready' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rr_llms_max"><?php esc_html_e( 'Max Posts per Type', 'rankready' ); ?></label></th>
							<td>
								<input type="number" id="rr_llms_max" name="<?php echo esc_attr( RR_OPT_LLMS_MAX_POSTS ); ?>"
									   value="<?php echo esc_attr( (string) get_option( RR_OPT_LLMS_MAX_POSTS, 100 ) ); ?>"
									   min="10" max="500" step="10" class="small-text" />
								<p class="description"><?php esc_html_e( 'Maximum number of posts per post type to include.', 'rankready' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Exclude Categories', 'rankready' ); ?></th>
							<td>
								<?php
								$exclude_cats = (array) get_option( RR_OPT_LLMS_EXCLUDE_CATS, array() );
								$all_cats     = get_categories( array( 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
								?>
								<?php if ( ! empty( $all_cats ) && ! is_wp_error( $all_cats ) ) : ?>
									<fieldset style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:8px 12px;border-radius:4px;">
										<?php foreach ( $all_cats as $cat ) : ?>
											<label style="display:block;margin-bottom:4px;">
												<input type="checkbox" name="<?php echo esc_attr( RR_OPT_LLMS_EXCLUDE_CATS ); ?>[]"
													   value="<?php echo esc_attr( $cat->term_id ); ?>"
													   <?php checked( in_array( (int) $cat->term_id, $exclude_cats, true ) ); ?> />
												<?php echo esc_html( $cat->name ); ?> <span style="color:#999;">(<?php echo esc_html( $cat->count ); ?>)</span>
											</label>
										<?php endforeach; ?>
									</fieldset>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'No categories found.', 'rankready' ); ?></p>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Posts in checked categories will be excluded from llms.txt. Useful for filtering out demo, test, or irrelevant content.', 'rankready' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Exclude Tags', 'rankready' ); ?></th>
							<td>
								<?php
								$exclude_tags = (array) get_option( RR_OPT_LLMS_EXCLUDE_TAGS, array() );
								$all_tags     = get_tags( array( 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
								?>
								<?php if ( ! empty( $all_tags ) && ! is_wp_error( $all_tags ) ) : ?>
									<fieldset style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:8px 12px;border-radius:4px;">
										<?php foreach ( $all_tags as $tag ) : ?>
											<label style="display:block;margin-bottom:4px;">
												<input type="checkbox" name="<?php echo esc_attr( RR_OPT_LLMS_EXCLUDE_TAGS ); ?>[]"
													   value="<?php echo esc_attr( $tag->term_id ); ?>"
													   <?php checked( in_array( (int) $tag->term_id, $exclude_tags, true ) ); ?> />
												<?php echo esc_html( $tag->name ); ?> <span style="color:#999;">(<?php echo esc_html( $tag->count ); ?>)</span>
											</label>
										<?php endforeach; ?>
									</fieldset>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'No tags found.', 'rankready' ); ?></p>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Posts with checked tags will be excluded from llms.txt.', 'rankready' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Show Categories Section', 'rankready' ); ?></th>
							<td>
								<?php $show_cats = (string) get_option( RR_OPT_LLMS_SHOW_CATEGORIES, 'on' ); ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RR_OPT_LLMS_SHOW_CATEGORIES ); ?>"
										   value="on" <?php checked( $show_cats, 'on' ); ?> />
									<?php esc_html_e( 'Show "Optional" categories section at the bottom of llms.txt', 'rankready' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Cache Duration', 'rankready' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( RR_OPT_LLMS_CACHE_TTL ); ?>">
									<?php $current_ttl = (int) get_option( RR_OPT_LLMS_CACHE_TTL, 3600 ); ?>
									<?php foreach ( array(
										900   => __( '15 minutes', 'rankready' ),
										3600  => __( '1 hour', 'rankready' ),
										21600 => __( '6 hours', 'rankready' ),
										86400 => __( '24 hours', 'rankready' ),
									) as $seconds => $label ) : ?>
										<option value="<?php echo esc_attr( $seconds ); ?>" <?php selected( $current_ttl, $seconds ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'How long to cache the generated llms.txt output.', 'rankready' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Full Version', 'rankready' ); ?></th>
							<td>
								<?php $llms_full = (string) get_option( RR_OPT_LLMS_FULL_ENABLE, 'off' ); ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RR_OPT_LLMS_FULL_ENABLE ); ?>"
										   value="on" <?php checked( $llms_full, 'on' ); ?> />
									<?php esc_html_e( 'Also serve /llms-full.txt with full post content inlined', 'rankready' ); ?>
								</label>
								<?php if ( 'on' === $llms_full && 'on' === $llms_enable ) : ?>
									<p class="description" style="margin-top:4px;">
										<a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>" target="_blank"><code><?php echo esc_html( home_url( '/llms-full.txt' ) ); ?></code></a>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<!-- Markdown Endpoints -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Markdown Endpoints', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Serve every post as clean Markdown at its URL + .md suffix. LLM crawlers get structured content.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable .md Endpoints', 'rankready' ); ?></th>
						<td>
							<?php $md_enable = (string) get_option( RR_OPT_MD_ENABLE, 'off' ); ?>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_MD_ENABLE ); ?>"
									   value="on" <?php checked( $md_enable, 'on' ); ?>
									   data-toggle-target="rr-md-fields" />
								<span class="rr-toggle-label"><?php esc_html_e( 'Add .md endpoint to each post URL', 'rankready' ); ?></span>
							</label>
							<?php if ( 'on' === $md_enable ) : ?>
								<p class="description" style="margin-top:8px;">
									<?php esc_html_e( 'Example:', 'rankready' ); ?>
									<code>yoursite.com/sample-post.md</code>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<div id="rr-md-fields" class="rr-conditional-fields" <?php echo 'on' !== $md_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rr-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Post Types', 'rankready' ); ?></th>
							<td>
								<?php $md_types = (array) get_option( RR_OPT_MD_POST_TYPES, array( 'post', 'page' ) ); ?>
								<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="<?php echo esc_attr( RR_OPT_MD_POST_TYPES ); ?>[]"
											   value="<?php echo esc_attr( $slug ); ?>"
											   <?php checked( in_array( $slug, $md_types, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Include Metadata', 'rankready' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RR_OPT_MD_INCLUDE_META ); ?>"
										   value="1" <?php checked( get_option( RR_OPT_MD_INCLUDE_META, '1' ), '1' ); ?> />
									<?php esc_html_e( 'Add YAML frontmatter (title, date, author, excerpt, tags)', 'rankready' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<!-- LLM Crawler Access (robots.txt) -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'LLM Crawler Access (robots.txt)', 'rankready' ); ?></h2>
				<p class="rr-card-desc">
					<?php esc_html_e( 'Control which AI crawlers can access your content via robots.txt. Enabled crawlers get explicit Allow rules appended — never modifies existing rules from Rank Math, Yoast, or any other plugin.', 'rankready' ); ?>
				</p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Crawler Rules', 'rankready' ); ?></th>
						<td>
							<?php $robots_enable = (string) get_option( RR_OPT_ROBOTS_ENABLE, 'on' ); ?>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_ROBOTS_ENABLE ); ?>"
									   value="on" <?php checked( $robots_enable, 'on' ); ?>
									   data-toggle-target="rr-robots-fields" />
								<span class="rr-toggle-label"><?php esc_html_e( 'Append LLM crawler rules to robots.txt', 'rankready' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Adds per-crawler User-agent blocks with Allow directives. Safe — appends only, never touches existing rules.', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>

				<div id="rr-robots-fields" class="rr-conditional-fields" <?php echo 'on' !== $robots_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rr-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Allow Crawlers', 'rankready' ); ?></th>
							<td>
								<?php
								$enabled_crawlers = (array) get_option( RR_OPT_ROBOTS_CRAWLERS, array_keys( self::get_llm_crawlers() ) );
								$all_crawlers     = self::get_llm_crawlers();
								$current_company  = '';
								?>
								<fieldset style="max-height:400px;overflow-y:auto;border:1px solid #ddd;padding:12px 16px;border-radius:4px;">
									<p style="margin:0 0 8px;"><strong>
										<label><input type="checkbox" id="rr-crawlers-select-all" /> <?php esc_html_e( 'Select / Deselect All', 'rankready' ); ?></label>
									</strong></p>
									<hr style="margin:8px 0;" />
									<?php foreach ( $all_crawlers as $ua => $info ) : ?>
										<?php if ( $info[0] !== $current_company ) :
											$current_company = $info[0];
											if ( 'OpenAI' !== $current_company ) : ?>
												<hr style="margin:8px 0;border:none;border-top:1px solid #eee;" />
											<?php endif; ?>
											<p style="margin:4px 0 2px;font-weight:600;color:#1d2327;font-size:13px;"><?php echo esc_html( $current_company ); ?></p>
										<?php endif; ?>
										<label style="display:block;margin-bottom:3px;padding-left:16px;">
											<input type="checkbox" name="<?php echo esc_attr( RR_OPT_ROBOTS_CRAWLERS ); ?>[]"
												   value="<?php echo esc_attr( $ua ); ?>"
												   class="rr-crawler-checkbox"
												   <?php checked( in_array( $ua, $enabled_crawlers, true ) ); ?> />
											<code style="font-size:12px;"><?php echo esc_html( $ua ); ?></code>
											<span style="color:#666;font-size:12px;"> — <?php echo esc_html( $info[1] ); ?></span>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description" style="margin-top:8px;">
									<?php esc_html_e( 'Checked crawlers get "User-agent: X / Allow: /" appended to robots.txt. Helps AI search engines, AI Overviews, and answer engines discover and cite your content.', 'rankready' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<!-- Content Signals -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Content Signals', 'rankready' ); ?></h2>
				<p class="rr-card-desc">
					<?php esc_html_e( 'Declare AI usage preferences in robots.txt via the Content Signals standard (contentsignals.org). Tells AI systems whether your content may be used for training, search, or AI-generated responses.', 'rankready' ); ?>
				</p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Content Signals', 'rankready' ); ?></th>
						<td>
							<?php $signals_enable = (string) get_option( RR_OPT_CONTENT_SIGNALS_ENABLE, 'off' ); ?>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_CONTENT_SIGNALS_ENABLE ); ?>"
									   value="on" <?php checked( $signals_enable, 'on' ); ?>
									   data-toggle-target="rr-content-signals-fields" />
								<span class="rr-toggle-label"><?php esc_html_e( 'Add Content Signals directives to robots.txt', 'rankready' ); ?></span>
							</label>
						</td>
					</tr>
				</table>

				<div id="rr-content-signals-fields" class="rr-conditional-fields" <?php echo 'on' !== $signals_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rr-form-table">
						<?php
						$signal_options = array(
							RR_OPT_CONTENT_SIGNALS_AI_TRAIN => array(
								'label' => __( 'ai-train', 'rankready' ),
								'desc'  => __( 'May AI systems use this content to train models?', 'rankready' ),
							),
							RR_OPT_CONTENT_SIGNALS_SEARCH   => array(
								'label' => __( 'search', 'rankready' ),
								'desc'  => __( 'May AI systems use this content in search results?', 'rankready' ),
							),
							RR_OPT_CONTENT_SIGNALS_AI_INPUT => array(
								'label' => __( 'ai-input', 'rankready' ),
								'desc'  => __( 'May AI systems use this content as RAG/context input?', 'rankready' ),
							),
						);
						foreach ( $signal_options as $opt_key => $info ) :
							$val = (string) get_option( $opt_key, 'allow' );
							?>
							<tr>
								<th scope="row"><code><?php echo esc_html( $info['label'] ); ?></code></th>
								<td>
									<select name="<?php echo esc_attr( $opt_key ); ?>">
										<option value="allow" <?php selected( $val, 'allow' ); ?>><?php esc_html_e( 'allow', 'rankready' ); ?></option>
										<option value="deny"  <?php selected( $val, 'deny' ); ?>><?php esc_html_e( 'deny', 'rankready' ); ?></option>
									</select>
									<p class="description"><?php echo esc_html( $info['desc'] ); ?></p>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>
			</div>


			<!-- ── Brand Terms ───────────────────────────────────────────────── -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Brand Terms', 'rankready' ); ?></h2>
				<p class="rr-card-desc">
					<?php esc_html_e( 'Canonical brand and product names for this site. RankReady uses these to guard against brand name variants confusing AI models.', 'rankready' ); ?>
				</p>
				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><label for="rr_brand_terms"><?php esc_html_e( 'Canonical Names', 'rankready' ); ?></label></th>
						<td>
							<textarea id="rr_brand_terms"
							          name="<?php echo esc_attr( RR_OPT_BRAND_TERMS ); ?>"
							          rows="4"
							          class="large-text"
							          placeholder="<?php esc_attr_e( "e.g.\nThe Plus Addons for Elementor\nNexterWP\nYour Brand Name", 'rankready' ); ?>"
							><?php echo esc_textarea( (string) get_option( RR_OPT_BRAND_TERMS, '' ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One name per line. Use the exact capitalisation and spacing you want AI models to use. RankReady will warn you if a post uses inconsistent variants (e.g. "theplusaddons" vs "The Plus Addons for Elementor").', 'rankready' ); ?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<?php submit_button( __( 'Save LLM Settings', 'rankready' ) ); ?>
		</form>

		<!-- Cache Controls (outside form) -->
		<div class="rr-card rr-card--subtle">
			<h3 class="rr-card-title" style="font-size:14px;"><?php esc_html_e( 'Cache Management', 'rankready' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Clear cached LLMs.txt output to regenerate with latest content.', 'rankready' ); ?></p>
			<p style="margin-top:10px;">
				<button id="rr-flush-llms-cache" class="button button-secondary">
					<?php esc_html_e( 'Flush LLMs.txt Cache', 'rankready' ); ?>
				</button>
				<span id="rr-flush-status" style="margin-left:10px;font-size:13px;color:#00a32a;display:none;">
					<?php esc_html_e( 'Cache cleared.', 'rankready' ); ?>
				</span>
			</p>
		</div>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: FAQ Generator
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_faq(): void {
		?>
			<!-- FAQ Generation -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'FAQ Generation', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Configure how FAQs are generated. Uses DataForSEO for question discovery and OpenAI for answers with brand entity injection.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Post Types', 'rankready' ); ?></th>
						<td>
							<?php $faq_types = (array) get_option( RR_OPT_FAQ_POST_TYPES, array( 'post' ) ); ?>
							<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="<?php echo esc_attr( RR_OPT_FAQ_POST_TYPES ); ?>[]"
									       value="<?php echo esc_attr( $slug ); ?>"
									       <?php checked( in_array( $slug, $faq_types, true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'FAQ will be generated for these post types.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rr_faq_count"><?php esc_html_e( 'FAQ Count', 'rankready' ); ?></label></th>
						<td>
							<input type="number" id="rr_faq_count" name="<?php echo esc_attr( RR_OPT_FAQ_COUNT ); ?>"
							       value="<?php echo esc_attr( (string) get_option( RR_OPT_FAQ_COUNT, 5 ) ); ?>"
							       min="3" max="10" step="1" class="small-text" />
							<p class="description"><?php esc_html_e( 'Number of FAQ items to generate per post (3-10). More FAQs = more API calls.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rr_faq_brand_terms"><?php esc_html_e( 'Brand Terms', 'rankready' ); ?></label></th>
						<td>
							<textarea id="rr_faq_brand_terms" name="<?php echo esc_attr( RR_OPT_FAQ_BRAND_TERMS ); ?>"
							          rows="3" class="large-text"
							          placeholder="<?php esc_attr_e( 'e.g. Acme Plugin, Your Brand Name, Your Product Name (one per line or comma-separated)', 'rankready' ); ?>"
							><?php echo esc_textarea( (string) get_option( RR_OPT_FAQ_BRAND_TERMS, '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Brand/product names to inject as semantic triples in FAQ answers. This builds brand-entity association for LLMs (+642% AI citation lift).', 'rankready' ); ?></p>
						</td>
					</tr>
					<?php if ( function_exists( 'rr_is_pro' ) && rr_is_pro() ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-Generate on Publish', 'rankready' ); ?></th>
						<td>
							<?php $faq_auto_gen = (string) get_option( RR_OPT_FAQ_AUTO_GENERATE, 'off' ); ?>
							<label>
								<input type="hidden" name="<?php echo esc_attr( RR_OPT_FAQ_AUTO_GENERATE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_FAQ_AUTO_GENERATE ); ?>" value="on" <?php checked( $faq_auto_gen, 'on' ); ?> />
								<?php esc_html_e( 'Automatically generate FAQs when a post is published or updated', 'rankready' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. When off, FAQs are only generated via the Gutenberg block, Elementor widget, or Bulk Generate.', 'rankready' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
				</table>
			</div>

			<?php if ( ! ( function_exists( 'rr_is_pro' ) && rr_is_pro() ) ) : ?>
			<?php self::render_pro_gate(
				__( 'Auto-Generate FAQ on Publish', 'rankready' ),
				__( 'Save time on every publish — RankReady generates FAQ schema the moment you hit Publish. Pro feature.', 'rankready' )
			); ?>
			<?php endif; ?>

			<!-- FAQ Display -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'FAQ Display', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Control how FAQs appear on the frontend. Can also use the Gutenberg block or Elementor widget instead.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto Display', 'rankready' ); ?></th>
						<td>
							<?php $faq_auto = (string) get_option( RR_OPT_FAQ_AUTO_DISPLAY, 'off' ); ?>
							<label>
								<input type="radio" name="<?php echo esc_attr( RR_OPT_FAQ_AUTO_DISPLAY ); ?>" value="on" <?php checked( $faq_auto, 'on' ); ?> />
								<?php esc_html_e( 'On — Automatically inject FAQ into post content', 'rankready' ); ?>
							</label><br/>
							<label>
								<input type="radio" name="<?php echo esc_attr( RR_OPT_FAQ_AUTO_DISPLAY ); ?>" value="off" <?php checked( $faq_auto, 'off' ); ?> />
								<?php esc_html_e( 'Off — Only show via block, widget, or shortcode', 'rankready' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Position', 'rankready' ); ?></label></th>
						<td>
							<?php $faq_pos = (string) get_option( RR_OPT_FAQ_POSITION, 'after' ); ?>
							<select name="<?php echo esc_attr( RR_OPT_FAQ_POSITION ); ?>">
								<option value="before" <?php selected( $faq_pos, 'before' ); ?>><?php esc_html_e( 'Before content', 'rankready' ); ?></option>
								<option value="after"  <?php selected( $faq_pos, 'after' ); ?>><?php esc_html_e( 'After content', 'rankready' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Heading Tag', 'rankready' ); ?></label></th>
						<td>
							<?php $faq_tag = (string) get_option( RR_OPT_FAQ_HEADING_TAG, 'h3' ); ?>
							<select name="<?php echo esc_attr( RR_OPT_FAQ_HEADING_TAG ); ?>">
								<?php foreach ( array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6' ) as $tag => $label ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $faq_tag, $tag ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show "Reviewed" Date', 'rankready' ); ?></th>
						<td>
							<?php $show_reviewed = (string) get_option( RR_OPT_FAQ_SHOW_REVIEWED, 'on' ); ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_FAQ_SHOW_REVIEWED ); ?>"
								       value="on" <?php checked( $show_reviewed, 'on' ); ?> />
								<?php esc_html_e( 'Show "Last reviewed: [date]" below the FAQ section', 'rankready' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Signals content freshness to LLMs and users.', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Headless / Public API
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_headless(): void {
		// ── Pro gate — free users see a locked preview ────────────────────────
		if ( ! ( function_exists( 'rr_is_pro' ) && rr_is_pro() ) ) {
			self::render_pro_gate(
				__( 'Headless WordPress Public API', 'rankready' ),
				__( 'Expose FAQ, summaries, and JSON-LD schema via a read-only REST API for Next.js, Nuxt, Astro, SvelteKit, Gatsby, and other headless frontends. Includes CORS control, CDN cache headers, on-demand revalidation webhooks, rate limiting, and WPGraphQL integration.', 'rankready' )
			);
			self::render_pro_gate(
				__( 'On-Demand Revalidation (Next.js / Nuxt)', 'rankready' ),
				__( 'When FAQ or summary data changes, RankReady pings your frontend to revalidate the affected page — fire-and-forget, never blocks the editor.', 'rankready' )
			);
			self::render_pro_gate(
				__( 'WPGraphQL Integration', 'rankready' ),
				__( 'Adds rankready_faq, rankready_summary, and rankready_schema fields to the WPGraphQL schema — zero config required when WPGraphQL is active.', 'rankready' )
			);
			return;
		}

		$enabled          = 'on' === get_option( RR_OPT_HEADLESS_ENABLE, 'off' );
		$cors_origins     = (string) get_option( RR_OPT_HEADLESS_CORS_ORIGINS, '' );
		$expose_meta      = 'on' === get_option( RR_OPT_HEADLESS_EXPOSE_META, 'on' );
		$cache_ttl        = (int) get_option( RR_OPT_HEADLESS_CACHE_TTL, 300 );
		$rate_limit       = (int) get_option( RR_OPT_HEADLESS_RATE_LIMIT, 120 );
		$revalidate_url   = (string) get_option( RR_OPT_HEADLESS_REVALIDATE_URL, '' );
		$revalidate_sec   = (string) get_option( RR_OPT_HEADLESS_REVALIDATE_SEC, '' );
		$graphql          = 'on' === get_option( RR_OPT_HEADLESS_GRAPHQL, 'off' );
		$graphql_active   = class_exists( 'WPGraphQL' ) || function_exists( 'register_graphql_field' );
		$secret_masked    = ! empty( $revalidate_sec ) ? str_repeat( "\xE2\x80\xA2", 16 ) : '';
		$site_url         = rest_url( 'rankready/v1/public/' );
		?>
		<form method="post" action="options.php" class="rr-form">
			<?php settings_fields( self::HEADLESS_GROUP ); ?>

			<?php /* Single Headless card. All sub-sections (Public API, On-Demand
			         Revalidation, WPGraphQL, Endpoint Reference) live inside ONE
			         outer rr-card to match the rest of the plugin's UI density.
			         The master "Enable Public API" toggle controls visibility of
			         every setting below it via #rr-headless-inner. */ ?>
			<div class="rr-card">
				<div class="rr-card-header">
					<h2><?php esc_html_e( 'Headless WordPress Public API', 'rankready' ); ?></h2>
					<p class="rr-card-subtitle">
						<?php esc_html_e( 'Expose FAQ, summaries, and JSON-LD schema via a read-only REST API for Next.js, Nuxt, Astro, SvelteKit, Gatsby, and other headless frontends.', 'rankready' ); ?>
					</p>
				</div>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Public API', 'rankready' ); ?></th>
						<td>
							<label class="rr-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_HEADLESS_ENABLE ); ?>" value="on" <?php checked( $enabled ); ?>
									data-toggle-target="rr-headless-inner" />
								<span class="rr-toggle-slider"></span>
							</label>
							<p class="description">
								<?php esc_html_e( 'Turn on the public REST endpoints. Off by default for security. Settings appear once enabled.', 'rankready' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<div id="rr-headless-inner" class="rr-conditional-fields" <?php echo $enabled ? '' : 'style="display:none;"'; ?>>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Expose in Core REST', 'rankready' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RR_OPT_HEADLESS_EXPOSE_META ); ?>" value="on" <?php checked( $expose_meta ); ?> />
									<?php esc_html_e( 'Add rankready_faq, rankready_summary, rankready_schema to /wp/v2/posts/{id}', 'rankready' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Recommended for Faust.js, headless themes, and anything that already consumes core WP REST.', 'rankready' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'CORS Allowed Origins', 'rankready' ); ?></th>
							<td>
								<textarea name="<?php echo esc_attr( RR_OPT_HEADLESS_CORS_ORIGINS ); ?>" rows="3" class="large-text code" placeholder="https://www.example.com, https://staging.example.com"><?php echo esc_textarea( $cors_origins ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Comma-separated list of allowed frontend origins. Leave empty to allow all origins (wildcard). Use specific origins in production.', 'rankready' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'CDN Cache TTL (seconds)', 'rankready' ); ?></th>
							<td>
								<input type="number" min="0" max="31536000" step="1" name="<?php echo esc_attr( RR_OPT_HEADLESS_CACHE_TTL ); ?>" value="<?php echo esc_attr( (string) $cache_ttl ); ?>" class="small-text" />
								<p class="description">
									<?php esc_html_e( 'Cache-Control: public, s-maxage=N, stale-while-revalidate=86400. Default 300 (5 min). Set higher for stable content.', 'rankready' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Rate Limit (req/min per IP)', 'rankready' ); ?></th>
							<td>
								<input type="number" min="0" max="10000" step="1" name="<?php echo esc_attr( RR_OPT_HEADLESS_RATE_LIMIT ); ?>" value="<?php echo esc_attr( (string) $rate_limit ); ?>" class="small-text" />
								<p class="description">
									<?php esc_html_e( '0 disables rate limiting. Authenticated editors are always exempt. IP is detected from Cloudflare / X-Forwarded-For / X-Real-IP.', 'rankready' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<h3 class="rr-subsection-title" style="margin:24px 0 4px;font-size:15px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'On-Demand Revalidation (Next.js / Nuxt)', 'rankready' ); ?></h3>
					<p class="rr-subsection-desc" style="margin:0 0 8px;color:#646970;font-size:13px;">
						<?php esc_html_e( 'When FAQ or summary data changes, RankReady pings your frontend to revalidate the affected page. Fire-and-forget, never blocks the editor.', 'rankready' ); ?>
					</p>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Webhook URL', 'rankready' ); ?></th>
							<td>
								<input type="url" name="<?php echo esc_attr( RR_OPT_HEADLESS_REVALIDATE_URL ); ?>" value="<?php echo esc_attr( $revalidate_url ); ?>" class="large-text" placeholder="https://www.example.com/api/revalidate" />
								<p class="description">
									<?php esc_html_e( 'Your Next.js / Nuxt revalidation endpoint. POST receives JSON { post_id, slug, reason, ts, site } and header X-RR-Secret.', 'rankready' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Shared Secret', 'rankready' ); ?></th>
							<td>
								<input type="text" name="<?php echo esc_attr( RR_OPT_HEADLESS_REVALIDATE_SEC ); ?>" value="<?php echo esc_attr( $secret_masked ); ?>" class="regular-text" autocomplete="off" />
								<p class="description">
									<?php esc_html_e( 'Shared secret sent as X-RR-Secret header. Use hash_equals() to verify on the frontend. Leave blank to clear.', 'rankready' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<h3 class="rr-subsection-title" style="margin:24px 0 4px;font-size:15px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'WPGraphQL Integration', 'rankready' ); ?></h3>
					<p class="rr-subsection-desc" style="margin:0 0 8px;color:#646970;font-size:13px;">
						<?php esc_html_e( 'Register rankReadyFaq, rankReadySummary, rankReadySchema as GraphQL fields on every public post type.', 'rankready' ); ?>
					</p>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Register GraphQL Fields', 'rankready' ); ?></th>
							<td>
								<label class="rr-toggle">
									<input type="checkbox" name="<?php echo esc_attr( RR_OPT_HEADLESS_GRAPHQL ); ?>" value="on" <?php checked( $graphql ); ?> <?php disabled( ! $graphql_active ); ?> />
									<span class="rr-toggle-slider"></span>
								</label>
								<?php if ( ! $graphql_active ) : ?>
									<p class="description" style="color:#d63638;">
										<?php esc_html_e( 'WPGraphQL plugin is not active. Install and activate it to enable this option.', 'rankready' ); ?>
									</p>
								<?php else : ?>
									<p class="description">
										<?php esc_html_e( 'WPGraphQL detected. Fields will be available on all GraphQL post types.', 'rankready' ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					</table>

					<?php if ( $enabled ) : ?>
					<h3 class="rr-subsection-title" style="margin:24px 0 4px;font-size:15px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'Endpoint Reference', 'rankready' ); ?></h3>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Base URL', 'rankready' ); ?></th>
							<td><code><?php echo esc_html( $site_url ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Available Routes', 'rankready' ); ?></th>
							<td>
								<ul class="rr-endpoint-list">
									<li><code>GET  faq/{id}</code> &mdash; <?php esc_html_e( 'FAQ items for a post', 'rankready' ); ?></li>
									<li><code>GET  summary/{id}</code> &mdash; <?php esc_html_e( 'AI summary for a post', 'rankready' ); ?></li>
									<li><code>GET  schema/{id}</code> &mdash; <?php esc_html_e( 'Ready-to-inject JSON-LD', 'rankready' ); ?></li>
									<li><code>GET  post/{id}</code> &mdash; <?php esc_html_e( 'Combined (FAQ + summary + schema)', 'rankready' ); ?></li>
									<li><code>GET  post-by-slug/{slug}?post_type=post&amp;lang=en</code></li>
									<li><code>GET  list?post_type=post&amp;per_page=20&amp;page=1&amp;since=ISO8601</code></li>
									<li><code>POST revalidate</code> &mdash; <?php esc_html_e( 'Manual revalidation trigger (requires secret)', 'rankready' ); ?></li>
								</ul>
								<p class="description">
									<?php esc_html_e( 'All responses include ETag, Last-Modified, Cache-Control s-maxage + stale-while-revalidate, and X-RR-Request-Id headers. 304 Not Modified is returned on matching If-None-Match / If-Modified-Since.', 'rankready' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<?php endif; ?>

				</div><?php /* /#rr-headless-inner */ ?>
			</div><?php /* /.rr-card — single Headless container */ ?>

			<?php submit_button( __( 'Save Headless Settings', 'rankready' ) ); ?>
		</form>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Tools
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_tools(): void {
		$post_types = self::get_allowed_post_types();
		$users      = self::get_authors();
		$tools_is_pro = function_exists( 'rr_is_pro' ) && rr_is_pro();
		?>

		<?php if ( $tools_is_pro ) : ?>

		<!-- Bulk Regenerate AI Summaries — PRO -->
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'Bulk Regenerate — AI Summaries', 'rankready' ); ?></h2>
			<p class="rr-card-desc">
				<?php esc_html_e( 'Generate AI summaries across all existing published posts. Skips posts with unchanged content. Processes 5 posts at a time.', 'rankready' ); ?>
			</p>

			<table class="form-table rr-form-table" style="width:auto;">
				<tr>
					<th style="padding:10px 20px 10px 0;"><?php esc_html_e( 'Post Types', 'rankready' ); ?></th>
					<td>
						<?php foreach ( $post_types as $slug => $label ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" class="rr-bulk-type" value="<?php echo esc_attr( $slug ); ?>" checked />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th></th>
					<td>
						<button id="rr-bulk-start" class="button button-primary"><?php esc_html_e( 'Start Bulk Generate', 'rankready' ); ?></button>
						<button id="rr-bulk-resume" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e( 'Resume', 'rankready' ); ?></button>
						<button id="rr-bulk-stop" class="button button-secondary" style="display:none;margin-left:8px;"><?php esc_html_e( 'Stop', 'rankready' ); ?></button>
						<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Resume picks up from where you stopped.', 'rankready' ); ?></p>
					</td>
				</tr>
			</table>

			<div id="rr-bulk-progress" style="display:none;margin-top:16px;">
				<div class="rr-progress-track">
					<div id="rr-bulk-bar" class="rr-progress-fill"></div>
				</div>
				<p id="rr-bulk-status" class="rr-progress-label"><?php esc_html_e( 'Preparing...', 'rankready' ); ?></p>
			</div>
		</div>

		<?php /* Start Over card removed in v1.1.1-beta.1 — Bulk Regenerate already
		         covers re-running the prompt over existing posts; the
		         destructive "delete then re-call" flow was duplicate UX. */ ?>

		<?php else : ?>

		<!-- FREE: locked Pro gate cards for bulk operations (kept separate per feature) -->
		<?php
			self::render_pro_gate(
				__( 'Bulk Regenerate — AI Summaries', 'rankready' ),
				__( 'Generate AI summaries across all existing published posts in one run. Free plan is limited to 5 manual summaries per month — bulk processing is a Pro feature.', 'rankready' )
			);
			self::render_pro_gate(
				__( 'Bulk Regenerate — FAQ', 'rankready' ),
				__( 'Generate FAQ schema across all existing published posts in one run. Free plan is limited to 5 manual FAQ generations per month — bulk processing is a Pro feature.', 'rankready' )
			);
			// Start Over Pro gates removed in v1.1.1-beta.1 alongside the
			// Pro card itself — Bulk Regenerate already handles re-running
			// the prompt over existing posts.
		?>

		<?php endif; ?>

		<!-- Bulk Generate FAQs (placed adjacent to Bulk AI Summaries — both
		     are content-generation operations, grouped per UX feedback). -->
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'Bulk Generate FAQs', 'rankready' ); ?></h2>
			<p class="rr-card-desc">
				<?php esc_html_e( 'Generate FAQ Q&A pairs for all existing published posts using DataForSEO + your active AI provider. Requires both API keys to be configured.', 'rankready' ); ?>
			</p>

			<table class="form-table rr-form-table" style="width:auto;">
				<tr>
					<th style="padding:10px 20px 10px 0;"><?php esc_html_e( 'Post Types', 'rankready' ); ?></th>
					<td>
						<?php foreach ( $post_types as $slug => $label ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" class="rr-faq-bulk-type" value="<?php echo esc_attr( $slug ); ?>" checked />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th></th>
					<td>
						<button id="rr-faq-bulk-start" class="button button-primary"><?php esc_html_e( 'Start Bulk FAQ Generate', 'rankready' ); ?></button>
						<button id="rr-faq-bulk-resume" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e( 'Resume', 'rankready' ); ?></button>
						<button id="rr-faq-bulk-stop" class="button button-secondary" style="display:none;margin-left:8px;"><?php esc_html_e( 'Stop', 'rankready' ); ?></button>
						<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Skips posts with unchanged content. Resume picks up from where you stopped.', 'rankready' ); ?></p>
					</td>
				</tr>
			</table>

			<div id="rr-faq-bulk-progress" style="display:none;margin-top:16px;">
				<div class="rr-progress-track">
					<div id="rr-faq-bulk-bar" class="rr-progress-fill"></div>
				</div>
				<p id="rr-faq-bulk-status" class="rr-progress-label"><?php esc_html_e( 'Preparing...', 'rankready' ); ?></p>
			</div>
		</div>

		<!-- Bulk Author Changer -->
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'Bulk Author Changer', 'rankready' ); ?></h2>
			<p class="rr-card-desc">
				<?php esc_html_e( 'Reassign authors across any post type. Preview the affected count before executing.', 'rankready' ); ?>
			</p>

			<table class="form-table rr-form-table">
				<!-- Post Types -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Types', 'rankready' ); ?></th>
					<td>
						<div class="rr-checkboxes-inline">
							<?php foreach ( self::get_author_post_types() as $slug => $label ) : ?>
								<label>
									<input type="checkbox" class="rr-bac-pt" value="<?php echo esc_attr( $slug ); ?>" />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Select one or more post types to affect.', 'rankready' ); ?></p>
					</td>
				</tr>
				<!-- From Author -->
				<tr>
					<th scope="row"><label for="rr-bac-from"><?php esc_html_e( 'Current Author (From)', 'rankready' ); ?></label></th>
					<td>
						<select id="rr-bac-from" class="regular-text">
							<option value=""><?php esc_html_e( '-- All authors --', 'rankready' ); ?></option>
							<?php foreach ( $users as $user ) : ?>
								<option value="<?php echo esc_attr( $user->ID ); ?>">
									<?php echo esc_html( $user->display_name . ' (@' . $user->user_login . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Leave blank to reassign posts regardless of current author.', 'rankready' ); ?></p>
					</td>
				</tr>
				<!-- To Author -->
				<tr>
					<th scope="row">
						<label for="rr-bac-to"><?php esc_html_e( 'New Author (To)', 'rankready' ); ?> <span style="color:#d63638;">*</span></label>
					</th>
					<td>
						<select id="rr-bac-to" class="regular-text">
							<option value=""><?php esc_html_e( '-- Select new author --', 'rankready' ); ?></option>
							<?php foreach ( $users as $user ) : ?>
								<option value="<?php echo esc_attr( $user->ID ); ?>">
									<?php echo esc_html( $user->display_name . ' (@' . $user->user_login . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<!-- Date Range -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Date Range', 'rankready' ); ?></th>
					<td>
						<div class="rr-date-range">
							<div>
								<label for="rr-bac-date-from"><?php esc_html_e( 'After', 'rankready' ); ?></label>
								<input type="date" id="rr-bac-date-from" />
							</div>
							<div>
								<label for="rr-bac-date-to"><?php esc_html_e( 'Before', 'rankready' ); ?></label>
								<input type="date" id="rr-bac-date-to" />
							</div>
						</div>
						<p class="description"><?php esc_html_e( 'Optional — leave blank to include all dates.', 'rankready' ); ?></p>
					</td>
				</tr>
				<!-- Actions -->
				<tr>
					<th></th>
					<td>
						<div class="rr-tool-actions">
							<button id="rr-bac-preview" class="button button-secondary"><?php esc_html_e( 'Preview Count', 'rankready' ); ?></button>
							<button id="rr-bac-execute" class="button button-primary" disabled><?php esc_html_e( 'Execute', 'rankready' ); ?></button>
							<button id="rr-bac-stop" class="button" style="display:none;"><?php esc_html_e( 'Stop', 'rankready' ); ?></button>
						</div>
					</td>
				</tr>
			</table>

			<!-- Preview result -->
			<div id="rr-bac-preview-result" style="display:none;" class="rr-notice rr-notice--info"></div>

			<!-- Progress -->
			<div id="rr-bac-progress" style="display:none;margin-top:16px;">
				<div class="rr-progress-track">
					<div id="rr-bac-bar" class="rr-progress-fill"></div>
				</div>
				<p id="rr-bac-status" class="rr-progress-label"></p>
			</div>

			<!-- Done -->
			<div id="rr-bac-done" style="display:none;" class="rr-notice rr-notice--success"></div>
		</div>

		<!-- Token Usage -->
		<?php
		$token_usage = (array) get_option( 'rr_token_usage', array(
			'summary_tokens' => 0,
			'faq_tokens'     => 0,
			'total_calls'    => 0,
		) );
		$summary_tokens = isset( $token_usage['summary_tokens'] ) ? (int) $token_usage['summary_tokens'] : 0;
		$faq_tokens     = isset( $token_usage['faq_tokens'] ) ? (int) $token_usage['faq_tokens'] : 0;
		$total_calls    = isset( $token_usage['total_calls'] ) ? (int) $token_usage['total_calls'] : 0;
		$total_tokens   = $summary_tokens + $faq_tokens;

		// Cost-per-token varies by provider (and by model within a provider).
		// We display a blended estimate using a conservative average across
		// the four provider defaults — actual cost depends on the provider
		// you have active. See RR_LLM for the per-model price reference.
		$est_cost = ( $total_tokens / 1000000 ) * 0.30;

		// DataForSEO usage.
		$dfs_usage = (array) get_option( 'rr_dfs_usage', array(
			'total_calls' => 0,
			'total_cost'  => 0,
		) );
		$dfs_calls = isset( $dfs_usage['total_calls'] ) ? (int) $dfs_usage['total_calls'] : 0;
		$dfs_cost  = isset( $dfs_usage['total_cost'] ) ? (float) $dfs_usage['total_cost'] : 0;
		?>
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'API Usage', 'rankready' ); ?></h2>
			<p class="rr-card-desc"><?php esc_html_e( 'Cumulative API usage tracked since this feature was enabled.', 'rankready' ); ?></p>

			<div style="margin-top:12px;margin-bottom:8px;display:flex;gap:12px;flex-wrap:wrap;">
				<div style="padding:10px 16px;background:#f0f6fc;border:1px solid #c8d8e8;border-radius:6px;display:inline-block;">
					<span style="font-size:22px;font-weight:700;color:#1d2327;">$<?php echo esc_html( number_format( $est_cost, 4 ) ); ?></span>
					<span style="font-size:13px;color:#646970;margin-left:6px;"><?php esc_html_e( 'Estimated Cost (blended)', 'rankready' ); ?></span>
				</div>
				<?php if ( $dfs_calls > 0 || $dfs_cost > 0 ) : ?>
				<div style="padding:10px 16px;background:#fef8f0;border:1px solid #e8d8c0;border-radius:6px;display:inline-block;">
					<span style="font-size:22px;font-weight:700;color:#1d2327;">$<?php echo esc_html( number_format( $dfs_cost, 4 ) ); ?></span>
					<span style="font-size:13px;color:#646970;margin-left:6px;"><?php esc_html_e( 'DataForSEO Cost', 'rankready' ); ?></span>
				</div>
				<?php endif; ?>
			</div>

			<h3 style="font-size:14px;margin:16px 0 8px;color:#1d2327;"><?php
				echo esc_html( sprintf(
					/* translators: %s: active LLM provider label */
					__( 'AI Provider — %s', 'rankready' ),
					RR_LLM::get_provider_label( RR_LLM::get_active_provider() )
				) );
			?></h3>
			<div class="rr-stats-row">
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo esc_html( number_format_i18n( $summary_tokens ) ); ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'Summary Tokens', 'rankready' ); ?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo esc_html( number_format_i18n( $faq_tokens ) ); ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'FAQ Tokens', 'rankready' ); ?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo esc_html( number_format_i18n( $total_tokens ) ); ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'Total Tokens', 'rankready' ); ?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo esc_html( number_format_i18n( $total_calls ) ); ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'API Calls', 'rankready' ); ?></span>
				</div>
			</div>

			<h3 style="font-size:14px;margin:16px 0 8px;color:#1d2327;"><?php esc_html_e( 'DataForSEO', 'rankready' ); ?></h3>
			<div class="rr-stats-row">
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo esc_html( number_format_i18n( $dfs_calls ) ); ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'API Calls', 'rankready' ); ?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number">$<?php echo esc_html( number_format( $dfs_cost, 4 ) ); ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'Total Cost', 'rankready' ); ?></span>
				</div>
			</div>

			<p style="margin-top:16px;">
				<button type="button" id="rr-tokens-load" class="button button-secondary"><?php esc_html_e( 'Load Per-Post Details', 'rankready' ); ?></button>
				<span id="rr-tokens-count" style="margin-left:10px;font-size:13px;display:none;"></span>
			</p>
			<div id="rr-tokens-list" style="display:none;margin-top:12px;max-height:400px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post', 'rankready' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Type', 'rankready' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Tokens Used', 'rankready' ); ?></th>
							<th style="width:15%;"><?php esc_html_e( 'Actions', 'rankready' ); ?></th>
						</tr>
					</thead>
					<tbody id="rr-tokens-tbody"></tbody>
				</table>
			</div>
		</div>

		<!-- Content Freshness Alerts -->
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'Content Freshness Alerts', 'rankready' ); ?></h2>
			<p class="rr-card-desc">
				<?php esc_html_e( 'AI search engines strongly prefer fresh content. 65% of AI citations target content updated within the past year. Find stale posts that may be losing AI visibility.', 'rankready' ); ?>
			</p>
			<table class="form-table rr-form-table" style="width:auto;">
				<tr>
					<th style="padding:10px 20px 10px 0;">
						<label for="rr-freshness-days"><?php esc_html_e( 'Stale Threshold', 'rankready' ); ?></label>
					</th>
					<td>
						<select id="rr-freshness-days">
							<option value="60"><?php esc_html_e( '60 days', 'rankready' ); ?></option>
							<option value="90" selected><?php esc_html_e( '90 days (recommended)', 'rankready' ); ?></option>
							<option value="180"><?php esc_html_e( '180 days', 'rankready' ); ?></option>
							<option value="365"><?php esc_html_e( '1 year', 'rankready' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th></th>
					<td>
						<button type="button" id="rr-freshness-scan" class="button button-primary"><?php esc_html_e( 'Scan Content Freshness', 'rankready' ); ?></button>
						<span id="rr-freshness-status" style="margin-left:10px;font-size:13px;display:none;"></span>
					</td>
				</tr>
			</table>
			<div id="rr-freshness-summary" style="display:none;margin-top:12px;"></div>
			<div id="rr-freshness-results" style="display:none;margin-top:16px;max-height:500px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th style="width:5%;"><?php esc_html_e( 'Urgency', 'rankready' ); ?></th>
							<th><?php esc_html_e( 'Post', 'rankready' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Type', 'rankready' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Last Updated', 'rankready' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Days Stale', 'rankready' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Summary', 'rankready' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'FAQ', 'rankready' ); ?></th>
							<th style="width:8%;"><?php esc_html_e( 'Actions', 'rankready' ); ?></th>
						</tr>
					</thead>
					<tbody id="rr-freshness-tbody"></tbody>
				</table>
			</div>
		</div>

		<!-- Health Check -->
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'Health Check', 'rankready' ); ?></h2>
			<p class="rr-card-desc"><?php esc_html_e( 'Run a diagnostic scan to verify all RankReady features are configured and working correctly.', 'rankready' ); ?></p>
			<p>
				<button type="button" id="rr-health-check" class="button button-primary"><?php esc_html_e( 'Run Health Check', 'rankready' ); ?></button>
				<span id="rr-health-status" style="margin-left:10px;font-size:13px;display:none;"></span>
			</p>
			<div id="rr-health-results" style="display:none;margin-top:16px;">
				<table class="widefat" style="margin:0;">
					<thead>
						<tr>
							<th style="width:5%;"></th>
							<th style="width:30%;"><?php esc_html_e( 'Check', 'rankready' ); ?></th>
							<th><?php esc_html_e( 'Result', 'rankready' ); ?></th>
						</tr>
					</thead>
					<tbody id="rr-health-tbody"></tbody>
				</table>
			</div>
		</div>

		<!-- Error Log -->
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'Error Log', 'rankready' ); ?></h2>
			<p class="rr-card-desc"><?php esc_html_e( 'Recent API errors from OpenAI and DataForSEO. Shows the last 50 entries.', 'rankready' ); ?></p>
			<p>
				<button type="button" id="rr-errors-load" class="button button-secondary"><?php esc_html_e( 'Load Error Log', 'rankready' ); ?></button>
				<button type="button" id="rr-errors-clear" class="button" style="margin-left:8px;"><?php esc_html_e( 'Clear Log', 'rankready' ); ?></button>
				<span id="rr-errors-status" style="margin-left:10px;font-size:13px;display:none;"></span>
			</p>
			<div id="rr-errors-list" style="display:none;margin-top:16px;max-height:400px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th style="width:15%;"><?php esc_html_e( 'When', 'rankready' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Source', 'rankready' ); ?></th>
							<th><?php esc_html_e( 'Error', 'rankready' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Post', 'rankready' ); ?></th>
						</tr>
					</thead>
					<tbody id="rr-errors-tbody"></tbody>
				</table>
			</div>
		</div>

		<!-- Data Retention info (settings are on the Settings tab) -->
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'Data Retention', 'rankready' ); ?></h2>
			<p class="rr-card-desc">
				<?php esc_html_e( 'Data retention settings (including the "Delete all data on uninstall" toggle) are on the Settings tab.', 'rankready' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=settings' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Go to Settings', 'rankready' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Info
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_info(): void {
		$summary_count = 0;
		$enabled_types = (array) get_option( RR_OPT_POST_TYPES, array( 'post' ) );

		if ( ! empty( $enabled_types ) ) {
			global $wpdb;
			$summary_count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				RR_META_SUMMARY
			) );
		}

		$faq_count = 0;
		global $wpdb;
		$faq_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
			RR_META_FAQ
		) );
		?>
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'How It Works', 'rankready' ); ?></h2>

			<div class="rr-info-grid">
				<div class="rr-info-item">
					<span class="dashicons dashicons-update rr-info-icon"></span>
					<h3><?php esc_html_e( 'AI Summary', 'rankready' ); ?></h3>
					<p><?php esc_html_e( 'On publish/update, the plugin generates an AI summary in the background. The API is only called when content changes (verified via content hash).', 'rankready' ); ?></p>
				</div>
				<div class="rr-info-item">
					<span class="dashicons dashicons-editor-code rr-info-icon"></span>
					<h3><?php esc_html_e( 'Schema Markup', 'rankready' ); ?></h3>
					<p><?php esc_html_e( 'Article JSON-LD schema with speakable markup is injected automatically. Skips if Yoast, Rank Math, or AIOSEO is active.', 'rankready' ); ?></p>
				</div>
				<div class="rr-info-item">
					<span class="dashicons dashicons-media-text rr-info-icon"></span>
					<h3><?php esc_html_e( 'LLMs.txt', 'rankready' ); ?></h3>
					<p><?php esc_html_e( 'Generates a /llms.txt file following the llmstxt.org spec. Lists your content for AI models to understand your site structure.', 'rankready' ); ?></p>
				</div>
				<div class="rr-info-item">
					<span class="dashicons dashicons-editor-paste-text rr-info-icon"></span>
					<h3><?php esc_html_e( 'Markdown Endpoints', 'rankready' ); ?></h3>
					<p><?php esc_html_e( 'Appending .md to any post URL serves clean Markdown with YAML frontmatter. Ideal for LLM crawlers and AI agents.', 'rankready' ); ?></p>
				</div>
			</div>
		</div>

		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'Quick Stats', 'rankready' ); ?></h2>
			<div class="rr-stats-row">
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo esc_html( number_format_i18n( $summary_count ) ); ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'AI Summaries Generated', 'rankready' ); ?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' ) ? '&#10003;' : '&#10007;'; ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'LLMs.txt', 'rankready' ); ?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo 'on' === get_option( RR_OPT_MD_ENABLE, 'off' ) ? '&#10003;' : '&#10007;'; ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'Markdown Endpoints', 'rankready' ); ?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo ! empty( get_option( RR_OPT_KEY, '' ) ) ? '&#10003;' : '&#10007;'; ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'API Key', 'rankready' ); ?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo esc_html( number_format_i18n( $faq_count ) ); ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'FAQs Generated', 'rankready' ); ?></span>
				</div>
			</div>
		</div>

		<div class="rr-card rr-card--subtle">
			<p style="margin:0;font-size:13px;color:#646970;">
				<?php
				printf(
					/* translators: 1: plugin version, 2: company link */
					esc_html__( 'RankReady v%1$s by %2$s', 'rankready' ),
					esc_html( RR_VERSION ),
					'<a href="https://store.posimyth.com/plugins/rankready/" target="_blank" rel="noopener">POSIMYTH Innovations</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	// ── Per-post meta box ─────────────────────────────────────────────────────

	public static function register_meta_box(): void {
		// Union of every post type RankReady touches — keeps the consolidated
		// meta box visible wherever any RankReady feature applies. Reduces
		// "where do I tick exclude from llms.txt?" support tickets.
		$pts = array();
		foreach ( array(
			(array) get_option( RR_OPT_POST_TYPES, array( 'post' ) ),
			(array) get_option( RR_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) ),
			(array) get_option( RR_OPT_MD_POST_TYPES, array( 'post', 'page' ) ),
		) as $list ) {
			foreach ( $list as $pt ) {
				$pts[ $pt ] = true;
			}
		}

		foreach ( array_keys( $pts ) as $pt ) {
			add_meta_box(
				'rr_summary_meta',
				__( 'RankReady — Agent Visibility', 'rankready' ),
				array( self::class, 'render_meta_box' ),
				$pt,
				'side',
				'default'
			);
		}
	}

	public static function render_meta_box( $post ): void {
		$disabled        = (bool) get_post_meta( $post->ID, RR_META_DISABLE, true );
		$llms_excluded   = '1' === (string) get_post_meta( $post->ID, RR_META_LLMS_EXCLUDE, true );
		$snippet_pref    = (string) get_post_meta( $post->ID, RR_META_MAX_SNIPPET, true );
		$snippet_default = 'on' === get_option( RR_OPT_MAX_SNIPPET_DEFAULT, 'on' );
		$summary         = (string) get_post_meta( $post->ID, RR_META_SUMMARY, true );
		$generated       = (int) get_post_meta( $post->ID, RR_META_GENERATED, true );
		$faq             = (string) get_post_meta( $post->ID, RR_META_FAQ, true );

		// ── Compute status banner ────────────────────────────────────────
		// Decide tone (ok / warn / muted) + plain-English headline + sub.
		$status = self::compute_meta_box_status( $post, $disabled, $llms_excluded, ! empty( $summary ), ! empty( $faq ) );

		wp_nonce_field( 'rr_meta_box', 'rr_meta_nonce' );
		?>
		<style>
			.rr-mb { font-size: var(--rr-text-md, 13px); }
			.rr-mb__status {
				display: flex; align-items: flex-start; gap: 8px;
				padding: 10px 12px;
				margin: -6px -12px 10px;
				border-radius: 0;
				font-size: var(--rr-text-md, 13px);
				line-height: 1.4;
			}
			.rr-mb__status--ok    { background: var(--rr-color-success-bg, #d1ecdf); color: var(--rr-color-success-text, #0a6c39); }
			.rr-mb__status--warn  { background: var(--rr-color-warning-bg, #fcf9e8); color: var(--rr-color-warning-text, #674c00); }
			.rr-mb__status--muted { background: var(--rr-color-surface-2, #f6f7f7); color: var(--rr-color-text-muted, #646970); }
			.rr-mb__icon { font-size: 16px; line-height: 1.2; flex-shrink: 0; }
			.rr-mb__title { font-weight: var(--rr-weight-semibold, 600); margin-bottom: 2px; }
			.rr-mb__sub { font-size: var(--rr-text-sm, 12px); opacity: 0.85; }
			.rr-mb__preview {
				margin: 0 0 10px;
				padding: 8px 10px;
				background: var(--rr-color-surface-2, #f6f7f7);
				border-radius: var(--rr-radius-md, 6px);
				font-size: var(--rr-text-sm, 12px);
				color: var(--rr-color-ink-soft, #3c434a);
			}
			.rr-mb__preview ul { margin: 0; padding-left: 14px; list-style: disc; }
			.rr-mb__preview li { margin-bottom: 3px; }
			.rr-mb details { margin-top: 8px; }
			.rr-mb summary {
				cursor: pointer;
				font-size: var(--rr-text-sm, 12px);
				color: var(--rr-color-text-muted, #646970);
				padding: 4px 0;
				user-select: none;
			}
			.rr-mb summary:hover { color: var(--rr-color-brand, #2271b1); }
			.rr-mb__advanced { padding-top: 8px; }
			.rr-mb__field { margin: 0 0 10px; }
			.rr-mb__field-label {
				display: block;
				font-size: var(--rr-text-xs, 11px);
				font-weight: var(--rr-weight-semibold, 600);
				text-transform: uppercase;
				letter-spacing: 0.04em;
				color: var(--rr-color-text-muted, #646970);
				margin-bottom: 4px;
			}
			.rr-mb__field select { width: 100%; }
			.rr-mb__hint { margin: 2px 0 0; font-size: var(--rr-text-xs, 11px); color: var(--rr-color-text-muted, #646970); }
		</style>

		<div class="rr-mb">
			<div class="rr-mb__status rr-mb__status--<?php echo esc_attr( $status['tone'] ); ?>">
				<span class="rr-mb__icon" aria-hidden="true"><?php echo esc_html( $status['icon'] ); ?></span>
				<span>
					<span class="rr-mb__title"><?php echo esc_html( $status['title'] ); ?></span>
					<span class="rr-mb__sub"><?php echo esc_html( $status['sub'] ); ?></span>
				</span>
			</div>

			<?php
			// Compact summary preview when a summary exists.
			if ( ! empty( $summary ) ) :
				$decoded = RR_Generator::decode_summary( $summary );
				if ( 'bullets' === $decoded['type'] && ! empty( $decoded['data'] ) ) : ?>
					<div class="rr-mb__preview">
						<ul>
							<?php foreach ( array_slice( (array) $decoded['data'], 0, 3 ) as $bullet ) : ?>
								<li><?php echo esc_html( $bullet ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif;
			endif; ?>

			<details<?php echo ( $disabled || $llms_excluded || '' !== $snippet_pref ) ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Advanced options', 'rankready' ); ?></summary>
				<div class="rr-mb__advanced">

					<div class="rr-mb__field">
						<label class="rr-mb__field-label" for="rr_max_snippet"><?php esc_html_e( 'AI snippet', 'rankready' ); ?></label>
						<select name="rr_max_snippet" id="rr_max_snippet">
							<option value="" <?php selected( $snippet_pref, '' ); ?>>
								<?php
								printf(
									esc_html__( 'Use default (%s)', 'rankready' ),
									$snippet_default ? esc_html__( 'Allow full snippet', 'rankready' ) : esc_html__( 'Standard snippet', 'rankready' )
								);
								?>
							</option>
							<option value="on" <?php selected( $snippet_pref, 'on' ); ?>>
								<?php esc_html_e( 'Allow full snippet (max-snippet:-1)', 'rankready' ); ?>
							</option>
							<option value="off" <?php selected( $snippet_pref, 'off' ); ?>>
								<?php esc_html_e( 'Standard snippet only', 'rankready' ); ?>
							</option>
						</select>
						<p class="rr-mb__hint"><?php esc_html_e( 'How much of this page AI engines may quote.', 'rankready' ); ?></p>
					</div>

					<div class="rr-mb__field">
						<label>
							<input type="checkbox" name="rr_llms_exclude" value="1" <?php checked( $llms_excluded ); ?> />
							<?php esc_html_e( 'Exclude this post from llms.txt', 'rankready' ); ?>
						</label>
					</div>

					<div class="rr-mb__field">
						<label>
							<input type="checkbox" name="rr_disable_summary" value="1" <?php checked( $disabled ); ?> />
							<?php esc_html_e( 'Disable AI summary on publish', 'rankready' ); ?>
						</label>
					</div>

					<?php if ( $generated ) : ?>
						<p class="rr-mb__hint">
							<?php
							printf(
								esc_html__( 'Summary generated %s ago', 'rankready' ),
								esc_html( human_time_diff( $generated ) )
							);
							?>
						</p>
					<?php endif; ?>

				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Compute the status banner shown at the top of the meta box.
	 *
	 * Three tones, one short headline + one subline. Aim: editor scans for
	 * 1 second and knows whether the post is AI-ready.
	 *
	 * Tone priority (worst signal wins):
	 *   warn  → opted out (disable / exclude) — user knows but flag it anyway
	 *   muted → not yet generated (no summary AND no FAQ on a fresh post)
	 *   ok    → fully optimised
	 *
	 * @return array{tone:string,icon:string,title:string,sub:string}
	 */
	private static function compute_meta_box_status( $post, bool $disabled, bool $llms_excluded, bool $has_summary, bool $has_faq ): array {
		if ( $disabled || $llms_excluded ) {
			$flags = array();
			if ( $disabled )      { $flags[] = __( 'AI summary disabled', 'rankready' ); }
			if ( $llms_excluded ) { $flags[] = __( 'excluded from llms.txt', 'rankready' ); }
			return array(
				'tone'  => 'warn',
				'icon'  => '⚠',
				'title' => __( 'AI visibility reduced', 'rankready' ),
				'sub'   => implode( ' • ', $flags ),
			);
		}

		if ( $has_summary && $has_faq ) {
			return array(
				'tone'  => 'ok',
				'icon'  => '✓',
				'title' => __( 'Optimised for AI', 'rankready' ),
				'sub'   => __( 'Summary + FAQ ready. ChatGPT, Perplexity & Claude can cite this page.', 'rankready' ),
			);
		}

		if ( $has_summary ) {
			return array(
				'tone'  => 'ok',
				'icon'  => '✓',
				'title' => __( 'AI summary ready', 'rankready' ),
				'sub'   => __( 'Add an FAQ to boost citation rate.', 'rankready' ),
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return array(
				'tone'  => 'muted',
				'icon'  => '○',
				'title' => __( 'Generation runs on publish', 'rankready' ),
				'sub'   => __( 'RankReady generates summary + FAQ after this post goes live.', 'rankready' ),
			);
		}

		return array(
			'tone'  => 'muted',
			'icon'  => '○',
			'title' => __( 'Not yet optimised', 'rankready' ),
			'sub'   => __( 'Open the AI tab to generate summary + FAQ.', 'rankready' ),
		);
	}

	public static function save_meta_box( $post_id ): void {
		if ( ! isset( $_POST['rr_meta_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rr_meta_nonce'] ) ), 'rr_meta_box' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// AI summary disable.
		$disabled = isset( $_POST['rr_disable_summary'] ) ? '1' : '';
		update_post_meta( $post_id, RR_META_DISABLE, $disabled );

		// llms.txt per-post exclusion (v1.2.0).
		$llms_excluded = isset( $_POST['rr_llms_exclude'] ) ? '1' : '';
		update_post_meta( $post_id, RR_META_LLMS_EXCLUDE, $llms_excluded );

		// max-snippet preference (v1.2.0) — '' inherits sitewide default.
		$snippet = isset( $_POST['rr_max_snippet'] ) ? sanitize_key( wp_unslash( $_POST['rr_max_snippet'] ) ) : '';
		if ( ! in_array( $snippet, array( '', 'on', 'off' ), true ) ) {
			$snippet = '';
		}
		if ( '' === $snippet ) {
			delete_post_meta( $post_id, RR_META_MAX_SNIPPET );
		} else {
			update_post_meta( $post_id, RR_META_MAX_SNIPPET, $snippet );
		}
	}

	// ── Test connection ───────────────────────────────────────────────────────

	private static function test_connection_url(): string {
		return add_query_arg( array(
			'page'           => self::MENU_SLUG,
			'tab'            => 'api',
			self::NONCE_FIELD => wp_create_nonce( self::NONCE_ACTION ),
			'rr_action'      => 'test',
		), admin_url( 'admin.php' ) );
	}

	/**
	 * Warn when plain permalinks are active.
	 *
	 * RankReady's virtual endpoints (llms.txt, llms-full.txt, *.md) rely on
	 * WordPress rewrite rules which only work with pretty permalinks. When the
	 * site uses the default ?p=123 structure, those endpoints return 404 and
	 * LLM crawlers cannot access the content.
	 */
	public static function permalink_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}
		if ( get_option( 'permalink_structure' ) ) {
			return; // Pretty permalinks are active — all good.
		}
		$permalink_url = admin_url( 'options-permalink.php' );
		echo '<div class="notice notice-warning is-dismissible"><p>'
			. '<strong>' . esc_html__( 'RankReady: Pretty Permalinks required', 'rankready' ) . '</strong> — '
			. esc_html__( 'Your site is using plain permalinks (?p=123). RankReady\'s LLM endpoints (llms.txt, llms-full.txt, and per-post .md files) will return 404 until you enable pretty permalinks.', 'rankready' )
			. ' <a href="' . esc_url( $permalink_url ) . '">'
			. esc_html__( 'Fix it in Settings → Permalinks →', 'rankready' )
			. '</a>'
			. '</p></div>';
	}

	public static function connection_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}
		if ( empty( $_GET['rr_action'] ) || 'test' !== $_GET['rr_action'] ) {
			return;
		}
		if ( ! isset( $_GET[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Security check failed.', 'rankready' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'rankready' ) );
		}
		$result = RR_Generator::test_api_connection();
		if ( true === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Connection successful! Your OpenAI API key is valid.', 'rankready' )
				. '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'Connection failed: ', 'rankready' )
				. esc_html( $result )
				. '</p></div>';
		}
	}

	// ── Plugin action links ───────────────────────────────────────────────────

	public static function action_links( array $links ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">'
			. esc_html__( 'Settings', 'rankready' )
			. '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	// ── Post list status column ───────────────────────────────────────────

	public static function add_status_column( array $columns ): array {
		$columns['rr_status'] = __( 'RankReady', 'rankready' );
		return $columns;
	}

	public static function render_status_column( string $column, int $post_id ): void {
		if ( 'rr_status' !== $column ) {
			return;
		}

		$summary   = get_post_meta( $post_id, RR_META_SUMMARY, true );
		$faq       = get_post_meta( $post_id, RR_META_FAQ, true );
		$disabled  = get_post_meta( $post_id, RR_META_DISABLE, true );
		$faq_off   = get_post_meta( $post_id, RR_META_FAQ_DISABLE, true );

		$parts = array();

		if ( $disabled ) {
			$parts[] = '<span style="color:#d63638;" title="' . esc_attr__( 'Summary disabled', 'rankready' ) . '">S: off</span>';
		} elseif ( ! empty( $summary ) ) {
			$parts[] = '<span style="color:#00a32a;" title="' . esc_attr__( 'Summary generated', 'rankready' ) . '">S: &#10003;</span>';
		} else {
			$parts[] = '<span style="color:#999;" title="' . esc_attr__( 'No summary', 'rankready' ) . '">S: —</span>';
		}

		if ( $faq_off ) {
			$parts[] = '<span style="color:#d63638;" title="' . esc_attr__( 'FAQ disabled', 'rankready' ) . '">F: off</span>';
		} elseif ( ! empty( $faq ) ) {
			$parts[] = '<span style="color:#00a32a;" title="' . esc_attr__( 'FAQ generated', 'rankready' ) . '">F: &#10003;</span>';
		} else {
			$parts[] = '<span style="color:#999;" title="' . esc_attr__( 'No FAQ', 'rankready' ) . '">F: —</span>';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- all values escaped above
		echo implode( ' &nbsp; ', $parts );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function get_allowed_models(): array {
		return array(
			'gpt-4o-mini'   => 'GPT-4o Mini -- fast & cheap (recommended)',
			'gpt-4o'        => 'GPT-4o -- more powerful, higher cost',
			'gpt-3.5-turbo' => 'GPT-3.5 Turbo -- legacy',
		);
	}

	/**
	 * Hard-exclude list shared by all post-type pickers in the plugin.
	 *
	 * These are built-in / system CPTs that should never appear in user-facing
	 * tabs (FAQ, Summary, Bulk Author Changer, etc.) regardless of their
	 * public / show_ui flags.
	 */
	private static function get_excluded_post_types(): array {
		return array(
			'attachment',           // Media — never used as content
			'nav_menu_item',        // Menu items
			'wp_block',             // Reusable blocks
			'wp_template',          // FSE templates
			'wp_template_part',     // FSE template parts
			'wp_navigation',        // FSE navigation
			'wp_global_styles',     // FSE global styles
			'revision',             // Post revisions
			'custom_css',           // Customizer CSS
			'customize_changeset',  // Customizer changesets
			'oembed_cache',         // oEmbed cache
			'user_request',         // Privacy requests
		);
	}

	/**
	 * Return all post types that should appear in user-facing pickers.
	 *
	 * Catches both `public => true` CPTs (front-end visible) AND private CPTs
	 * with `show_ui => true` (admin-visible only — common pattern for plugin
	 * CPTs like LearnDash quizzes, WooCommerce orders, custom internal types).
	 *
	 * Result format: `[ 'slug' => 'Label (slug)' ]`, sorted alphabetically by
	 * label so plugin CPTs don't get buried after `post`/`page`.
	 */
	public static function get_allowed_post_types(): array {
		$excluded = self::get_excluded_post_types();
		$result   = array();

		// Pull every registered post type and keep the ones with admin UI.
		// `_builtin => false` is NOT used here — we want `post` and `page` too.
		$types = get_post_types( array(), 'objects' );
		foreach ( $types as $slug => $obj ) {
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}
			// Must be visible somewhere — either public or admin-visible.
			if ( empty( $obj->public ) && empty( $obj->show_ui ) ) {
				continue;
			}
			$label             = $obj->labels->singular_name . ' (' . $slug . ')';
			$result[ $slug ]   = $label;
		}

		// Stable alphabetical sort by label so plugin CPTs surface
		// alongside post/page instead of getting buried at the bottom.
		asort( $result, SORT_NATURAL | SORT_FLAG_CASE );

		/**
		 * Filter the post-type list shown in RankReady tabs.
		 *
		 * @param array<string,string> $result slug => "Label (slug)"
		 */
		return apply_filters( 'rankready_allowed_post_types', $result );
	}

	/**
	 * Return all post types eligible for the Bulk Author Changer.
	 *
	 * Same broad detection as get_allowed_post_types() but additionally
	 * requires the type to support the `author` feature — without that,
	 * wp_update_post() can't reassign authors on it.
	 */
	public static function get_author_post_types(): array {
		$excluded = self::get_excluded_post_types();
		$result   = array();

		$types = get_post_types( array(), 'objects' );
		foreach ( $types as $slug => $obj ) {
			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}
			if ( empty( $obj->public ) && empty( $obj->show_ui ) ) {
				continue;
			}
			if ( ! post_type_supports( $slug, 'author' ) ) {
				continue;
			}
			$result[ $slug ] = $obj->labels->singular_name . ' (' . $slug . ')';
		}

		asort( $result, SORT_NATURAL | SORT_FLAG_CASE );

		/**
		 * Filter the post-type list shown in the Bulk Author Changer.
		 *
		 * @param array<string,string> $result slug => "Label (slug)"
		 */
		return apply_filters( 'rankready_author_post_types', $result );
	}

	public static function get_authors(): array {
		return get_users( array(
			'role__in' => array( 'administrator', 'editor', 'author', 'contributor' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'fields'   => array( 'ID', 'display_name', 'user_login' ),
		) );
	}
}
