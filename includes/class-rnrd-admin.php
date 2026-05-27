<?php
/**
 * Admin settings page — tabbed UI using core WordPress styles.
 *
 * Tabs: Settings | LLM Optimization | Tools | Info
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Admin {

	private const SETTINGS_GROUP   = 'rnrd_settings_group';  // Settings tab
	private const CONTENT_GROUP    = 'rnrd_content_group';   // Content AI tab
	// v1.2.0-rc.3 — Brand Identity has its own group so the 4-field "Save Brand
	// Identity" form doesn't trigger options.php to null-out every other
	// LLMS_GROUP option that isn't in this form.
	private const BRAND_GROUP      = 'rnrd_brand_group';     // Brand Identity card only
	// v1.2.0-rc.3 — Data Retention has its own group too, for the same reason:
	// a small isolated form must not null out unrelated options when saved.
	private const DATA_GROUP       = 'rnrd_data_group';      // Data Retention card on Advanced tab
	private const AUTHORITY_GROUP  = 'rnrd_authority_group'; // Authority tab (author + schema)
	private const LLMS_GROUP       = 'rnrd_llms_group';      // AI Crawlers tab
	private const HEADLESS_GROUP   = 'rnrd_headless_group';  // Advanced tab
	// Legacy aliases kept for any saved nonces in flight during upgrade.
	private const FAQ_GROUP        = 'rnrd_content_group';
	private const SCHEMA_GROUP     = 'rnrd_authority_group';
	private const AUTHOR_GROUP     = 'rnrd_authority_group';
	private const MENU_SLUG        = 'rankready-ai-llm-seo';
	private const NONCE_ACTION     = 'rnrd_test_connection';
	private const NONCE_FIELD      = 'rnrd_test_nonce';

	public static function init(): void {
		add_action( 'admin_menu',            array( self::class, 'register_menu' ) );
		add_action( 'admin_init',            array( self::class, 'register_settings' ) );
		add_action( 'admin_init',            array( self::class, 'handle_dismiss_actions' ) );
		add_action( 'admin_init',            array( self::class, 'track_installed_version' ) );
		// v1.2.0-rc.7 — Quick-enable POST handler for locked-state cards.
		// Runs early on admin_init so the wp_safe_redirect() fires before
		// any output. See render_locked_preview() / handle_quick_enable().
		add_action( 'admin_init',            array( self::class, 'handle_quick_enable' ) );
		add_action( 'admin_notices',         array( self::class, 'connection_notice' ) );
		add_action( 'admin_notices',         array( self::class, 'permalink_notice' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . RNRD_BASENAME, array( self::class, 'action_links' ) );
		add_action( 'add_meta_boxes',        array( self::class, 'register_meta_box' ) );
		add_action( 'save_post',             array( self::class, 'save_meta_box' ) );

		// Defer column registration to 'wp_loaded' so all CPTs are registered.
		add_action( 'wp_loaded', array( self::class, 'register_status_columns' ) );
	}

	// ── "What's new" banner + tutorial dismiss handlers ─────────────────────────

	/**
	 * Records the most recently installed plugin version. When the running
	 * `RNRD_VERSION` is newer than the stored value, every user gets the
	 * "what's new" banner exactly once until each one dismisses it.
	 *
	 * Also runs idempotent one-shot migrations for stale option values that
	 * would silently break against the live API — currently the deprecated
	 * `deepseek-chat` / `deepseek-reasoner` aliases. Safe to re-enter.
	 */
	public static function track_installed_version(): void {
		$stored = (string) get_option( RNRD_OPT_INSTALLED_VERSION, '' );
		if ( $stored !== RNRD_VERSION ) {
			update_option( RNRD_OPT_INSTALLED_VERSION, RNRD_VERSION, false );
		}

		// DeepSeek deprecated `deepseek-chat` / `deepseek-reasoner` in favour
		// of pinned V4 IDs. Migrate silently so existing users don't hit a
		// dead alias when DeepSeek finishes the retirement.
		$deepseek_model = (string) get_option( 'rnrd_deepseek_model', '' );
		if ( 'deepseek-chat' === $deepseek_model ) {
			update_option( 'rnrd_deepseek_model', 'deepseek-v4-flash', false );
		} elseif ( 'deepseek-reasoner' === $deepseek_model ) {
			update_option( 'rnrd_deepseek_model', 'deepseek-v4-pro', false );
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
		$dismissed_for = (string) get_user_meta( $user_id, 'rnrd_whatsnew_dismissed_version', true );
		return $dismissed_for !== RNRD_VERSION;
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

		if ( isset( $_GET['rnrd_dismiss_whatsnew'] ) && check_admin_referer( 'rnrd_dismiss_whatsnew' ) ) {
			update_user_meta( $user_id, 'rnrd_whatsnew_dismissed_version', RNRD_VERSION );
			wp_safe_redirect( remove_query_arg( array( 'rnrd_dismiss_whatsnew', '_wpnonce' ) ) );
			exit;
		}

		if ( isset( $_GET['rnrd_dismiss_tutorial'] ) && check_admin_referer( 'rnrd_dismiss_tutorial' ) ) {
			update_user_meta( $user_id, 'rnrd_tutorial_dismissed', 1 );
			wp_safe_redirect( remove_query_arg( array( 'rnrd_dismiss_tutorial', '_wpnonce' ) ) );
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
		$menu_label = __( 'RankReady', 'rankready-ai-llm-seo' );
		if ( is_user_logged_in() && self::has_unread_release_notes( get_current_user_id() ) ) {
			$menu_label .= ' <span class="awaiting-mod update-plugins" style="background:#d63638;color:#fff;border-radius:10px;padding:0 6px;margin-left:5px;font-size:9px;line-height:17px;display:inline-block;vertical-align:top;">1</span>';
		}

		add_menu_page(
			__( 'RankReady', 'rankready-ai-llm-seo' ),
			$menu_label,
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render_page' ),
			self::menu_icon_data_uri(),
			81
		);
	}

	/**
	 * WP sidebar menu icon.
	 *
	 * Returns base64-encoded `data:image/svg+xml` URI from assets/logo-mark.svg.
	 * WP's esc_url() allows `data:image/svg+xml` (it's special-cased in core,
	 * unlike data:image/png which gets stripped). White fills render correctly
	 * against WP's dark sidebar.
	 *
	 * Falls back to dashicons-chart-area if the SVG asset is missing.
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function menu_icon_data_uri(): string {
		$path = RNRD_DIR . 'assets/logo-mark.svg';
		if ( ! is_readable( $path ) ) {
			return 'dashicons-chart-area';
		}
		$svg = (string) file_get_contents( $path );
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/**
	 * Asset version string for `wp_enqueue_*`. Uses filemtime() so every
	 * edit busts the browser cache automatically. Falls back to RNRD_VERSION
	 * if the file is unreadable (shouldn't happen but defends against weird
	 * file-permission setups).
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function asset_ver( string $relative_path ): string {
		$full = RNRD_DIR . ltrim( $relative_path, '/' );
		$mt   = file_exists( $full ) ? filemtime( $full ) : 0;
		return $mt ? RNRD_VERSION . '.' . $mt : RNRD_VERSION;
	}

	public static function enqueue_admin_assets( $hook ): void {
		// Use filemtime() so any CSS/JS edit forces a fresh download. The
		// stable RNRD_VERSION string would otherwise cache stale assets in
		// users' browsers across plugin updates that don't bump the version
		// (e.g. point fixes within the same rc.16 build).
		$tokens_ver = self::asset_ver( 'assets/design-tokens.css' );
		$admin_ver  = self::asset_ver( 'assets/admin.css' );
		$js_ver     = self::asset_ver( 'assets/admin.js' );

		wp_enqueue_style( 'rnrd-design-tokens', RNRD_URL . 'assets/design-tokens.css', array(), $tokens_ver );

		// Meta-box CSS (rnrd-mb classes) loads on post-edit screens too so
		// the RankReady meta box renders correctly without inline <style>
		// (WP.org Rule #3). The full admin.css is the same file — loading
		// it on post-edit is cheap (cached) and avoids a separate stylesheet.
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style( 'rnrd-admin', RNRD_URL . 'assets/admin.css', array( 'rnrd-design-tokens' ), self::asset_ver( 'assets/admin.css' ) );
			return;
		}

		// Full admin styles + JS only on the RankReady settings page.
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		// Inter font — design-system typography. Scoped to RankReady admin
		// pages via CSS in design-tokens.css. Loaded only on RankReady screens
		// to avoid slowing down the rest of wp-admin.
		wp_enqueue_style(
			'rnrd-inter-font',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
			array(),
			null
		);

		wp_enqueue_style( 'rnrd-admin', RNRD_URL . 'assets/admin.css', array( 'rnrd-design-tokens', 'rnrd-inter-font' ), $admin_ver );
		wp_enqueue_script( 'rnrd-admin', RNRD_URL . 'assets/admin.js', array(), $js_ver, true );
		wp_localize_script( 'rnrd-admin', 'rnrdAdmin', array(
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'apiBase' => rest_url( 'rankready/v1' ),
		) );
	}

	// ── Settings API ──────────────────────────────────────────────────────────

	public static function register_settings(): void {

		// ═══ Settings Tab ═════════════════════════════════════════════════════

		// Active LLM provider — drives which key/model is used by RNRD_LLM.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_LLM_PROVIDER, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_llm_provider' ),
			'default'           => 'openai',
		) );

		// OpenAI key + model.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_api_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_model' ),
			'default'           => 'gpt-4o-mini',
		) );

		// Anthropic (Claude) key + model.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_ANTHROPIC_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_anthropic_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_ANTHROPIC_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_provider_model' ),
			'default'           => 'claude-haiku-4-5',
		) );

		// Gemini key + model.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_GEMINI_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_gemini_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_GEMINI_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_provider_model' ),
			'default'           => 'gemini-2.5-flash',
		) );

		// DeepSeek key + model.
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_DEEPSEEK_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_deepseek_key' ),
			'default'           => '',
		) );
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_DEEPSEEK_MODEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_provider_model' ),
			'default'           => 'deepseek-v4-flash',
		) );

		// v1.0.1 — These 8 options were previously registered against
		// SETTINGS_GROUP but the Content AI tab's <form> (render_tab_content_ai)
		// posts to CONTENT_GROUP. options.php silently dropped them on save.
		// Repointed to CONTENT_GROUP to match the form they're actually in.
		register_setting( self::CONTENT_GROUP, RNRD_OPT_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types' ),
			'default'           => array( 'post' ),
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_CUSTOM_PROMPT, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		// v1.2.0-rc.3 — Product Context kept registered for back-compat reads,
		// but the input field is removed from the UI. The "About" field on
		// the Brand Identity card now serves both llms.txt content AND prompt
		// injection (RNRD_Generator + RNRD_Faq read RNRD_Llms_Txt::get_brand_about()
		// with rnrd_product_context as legacy fallback).
		register_setting( self::CONTENT_GROUP, RNRD_OPT_PRODUCT_CONTEXT, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_AUTO_GENERATE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// v1.2.0-rc.3 — Data Retention moved to its own group so the "Save
		// Data Retention" form on the Advanced tab can save just one toggle
		// without nullifying other Settings options. Same isolation pattern
		// Brand Identity uses.
		register_setting( self::DATA_GROUP, RNRD_OPT_DELETE_ON_UNINSTALL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_AUTO_DISPLAY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_auto_display' ),
			'default'           => 'off',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_DISPLAY_POSITION, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_display_position' ),
			'default'           => 'before',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_LABEL, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'Key Takeaways',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_SHOW_LABEL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_checkbox_field' ),
			'default'           => '1',
		) );

		register_setting( self::CONTENT_GROUP, RNRD_OPT_HEADING_TAG, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_heading_tag' ),
			'default'           => 'h4',
		) );

		// ═══ LLM Optimization Tab ═════════════════════════════════════════════

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// v1.2.0-rc.3 — Brand Identity options live in their own group so the
		// "Save Brand Identity" form (which only POSTs these 4 fields) does
		// NOT cause options.php to null-out every other LLMS_GROUP setting.
		register_setting( self::BRAND_GROUP, RNRD_OPT_LLMS_SITE_NAME, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( self::BRAND_GROUP, RNRD_OPT_LLMS_SUMMARY, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::BRAND_GROUP, RNRD_OPT_LLMS_ABOUT, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types' ),
			'default'           => array( 'post', 'page' ),
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_MAX_POSTS, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 100,
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_CACHE_TTL, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 3600,
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_FULL_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_EXCLUDE_CATS, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_term_ids' ),
			'default'           => array(),
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_EXCLUDE_TAGS, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_term_ids' ),
			'default'           => array(),
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_LLMS_SHOW_CATEGORIES, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_ROBOTS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_ROBOTS_CRAWLERS, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_crawler_list' ),
			'default'           => array_keys( self::get_llm_crawlers() ),
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types' ),
			'default'           => array( 'post', 'page' ),
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_INCLUDE_META, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_checkbox_field' ),
			'default'           => '1',
		) );

		// Content Signals.
		register_setting( self::LLMS_GROUP, RNRD_OPT_CONTENT_SIGNALS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_content_signal' ),
			'default'           => 'allow',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_CONTENT_SIGNALS_SEARCH, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_content_signal' ),
			'default'           => 'allow',
		) );

		register_setting( self::LLMS_GROUP, RNRD_OPT_CONTENT_SIGNALS_AI_INPUT, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_content_signal' ),
			'default'           => 'allow',
		) );

		// v1.2.0-rc.3 — Moved to BRAND_GROUP (see note above on Brand Identity).
		register_setting( self::BRAND_GROUP, RNRD_OPT_BRAND_TERMS, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		// v1.2.0 — AI Snippet preview default (per-post override lives in the meta box).
		register_setting( self::LLMS_GROUP, RNRD_OPT_MAX_SNIPPET_DEFAULT, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		// v1.2.0-beta.3 — AI Referral Traffic tracker master toggle.
		register_setting( self::LLMS_GROUP, RNRD_OPT_AI_REFERRAL_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		// v1.2.0-beta.3 — WebMCP master toggle.
		// rc.16: defaults OFF — user must explicitly opt in to expose agent
		// abilities. Avoids surprise data exposure on fresh installs.
		register_setting( self::LLMS_GROUP, RNRD_OPT_MCP_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// v1.2.0-beta.6 — Per-resource MCP exposure toggles.
		// rc.16: ALL resource exposures default OFF. User must explicitly
		// pick which content to expose to AI agents (no auto-enable on first
		// install). Sensitive resources stay off as before.
		$rnrd_mcp_all_toggles = array(
			RNRD_OPT_MCP_EXPOSE_POSTS,
			RNRD_OPT_MCP_EXPOSE_PAGES,
			RNRD_OPT_MCP_EXPOSE_AUTHORS,
			RNRD_OPT_MCP_EXPOSE_TAXONOMIES,
			RNRD_OPT_MCP_EXPOSE_SITEMAP,
			RNRD_OPT_MCP_EXPOSE_MENUS,
			RNRD_OPT_MCP_EXPOSE_LLMS_TXT,
			RNRD_OPT_MCP_EXPOSE_RR_AI,
			RNRD_OPT_MCP_EXPOSE_FRESHNESS,
			RNRD_OPT_MCP_EXPOSE_COMMENTS,
			RNRD_OPT_MCP_EXPOSE_MEDIA,
			RNRD_OPT_MCP_EXPOSE_USERS,
			RNRD_OPT_MCP_EXPOSE_PLUGINS,
			RNRD_OPT_MCP_EXPOSE_THEMES,
			RNRD_OPT_MCP_EXPOSE_SETTINGS,
		);
		foreach ( $rnrd_mcp_all_toggles as $opt ) {
			register_setting( self::LLMS_GROUP, $opt, array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
				'default'           => 'off',
			) );
		}
		// CPT opt-in list — array of slugs the user has explicitly enabled.
		register_setting( self::LLMS_GROUP, RNRD_OPT_MCP_EXPOSE_CPTS, array(
			'type'              => 'array',
			'sanitize_callback' => function ( $value ) {
				return is_array( $value ) ? array_values( array_filter( array_map( 'sanitize_key', $value ) ) ) : array();
			},
			'default'           => array(),
		) );

		// v1.2.0-beta.3 — Markdown sub-toggles.
		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_HINT_DIV, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );
		register_setting( self::LLMS_GROUP, RNRD_OPT_MD_BOT_AUTO_SERVE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		// ── DataForSEO credentials (Settings tab, same save as OpenAI) ──────
		register_setting( self::SETTINGS_GROUP, RNRD_OPT_DFS_LOGIN, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_dfs_login' ),
			'default'           => '',
		) );

		register_setting( self::SETTINGS_GROUP, RNRD_OPT_DFS_PASSWORD, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_dfs_password' ),
			'default'           => '',
		) );

		// ═══ FAQ Tab (Content AI) ═════════════════════════════════════════════

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => array( self::class, 'sanitize_post_types' ),
			'default'           => array( 'post' ),
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_COUNT, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 5,
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_BRAND_TERMS, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_AUTO_DISPLAY, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_POSITION, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_display_position' ),
			'default'           => 'after',
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_HEADING_TAG, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_heading_tag' ),
			'default'           => 'h3',
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_SHOW_REVIEWED, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::FAQ_GROUP, RNRD_OPT_FAQ_AUTO_GENERATE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// ═══ Schema Automation Tab ═══════════════════════════════════════════

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_ARTICLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_FAQ, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_HOWTO, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_ITEMLIST, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_SPEAKABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::SCHEMA_GROUP, RNRD_OPT_SCHEMA_BATCH_SIZE, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 10,
		) );

		// ═══ Headless / Public API Tab ═══════════════════════════════════════════

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_CORS_ORIGINS, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_cors_origins' ),
			'default'           => '',
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_EXPOSE_META, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_CACHE_TTL, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 300,
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_RATE_LIMIT, array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 120,
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_REVALIDATE_URL, array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_REVALIDATE_SEC, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_revalidate_secret' ),
			'default'           => '',
		) );

		register_setting( self::HEADLESS_GROUP, RNRD_OPT_HEADLESS_GRAPHQL, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'off',
		) );

		// ── Author Box settings ──────────────────────────────────────────
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_AUTO_DISPLAY, array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, array( 'off', 'before', 'after', 'both' ), true ) ? $v : 'off';
			},
			'default'           => 'off',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_LAYOUT, array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, array( 'card', 'compact', 'inline' ), true ) ? $v : 'card';
			},
			'default'           => 'card',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_HEADING, array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'About the Author',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_HEADING_TAG, array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return in_array( $v, array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true ) ? $v : 'h3';
			},
			'default'           => 'h3',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_SCHEMA_ENABLE, array(
			'type'              => 'string',
			'sanitize_callback' => array( self::class, 'sanitize_on_off' ),
			'default'           => 'on',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_EDITORIAL_URL, array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_FACTCHECK_URL, array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_POST_TYPES, array(
			'type'              => 'array',
			'sanitize_callback' => function ( $v ) {
				if ( ! is_array( $v ) ) return array( 'post' );
				return array_values( array_filter( array_map( 'sanitize_key', $v ) ) );
			},
			'default'           => array( 'post' ),
		) );
		register_setting( self::AUTHOR_GROUP, RNRD_OPT_AUTHOR_TRUST_ENABLE, array(
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
			return (string) get_option( RNRD_OPT_HEADLESS_REVALIDATE_SEC, '' );
		}
		if ( false !== strpos( $value, "\xE2\x80\xA2" ) ) {
			return (string) get_option( RNRD_OPT_HEADLESS_REVALIDATE_SEC, '' );
		}
		return sanitize_text_field( $value );
	}

	// ── Sanitize callbacks ────────────────────────────────────────────────────

	public static function sanitize_api_key( $value ): string {
		$value = sanitize_text_field( (string) $value );
		// Sentinel or masked value means "don't change".
		if ( '__UNCHANGED__' === $value || false !== strpos( $value, '••••' ) ) {
			return (string) get_option( RNRD_OPT_KEY, '' );
		}
		if ( ! empty( $value ) && ! preg_match( '/^sk-[A-Za-z0-9\-_]{20,}$/', $value ) ) {
			add_settings_error( RNRD_OPT_KEY, 'rnrd_invalid_key',
				__( 'The OpenAI API key format looks incorrect. It should start with sk-', 'rankready-ai-llm-seo' ), 'error' );
			return (string) get_option( RNRD_OPT_KEY, '' );
		}
		return $value;
	}

	/**
	 * Anthropic keys begin with `sk-ant-`.
	 */
	public static function sanitize_anthropic_key( $value ): string {
		$value = sanitize_text_field( (string) $value );
		if ( '__UNCHANGED__' === $value || false !== strpos( $value, '••••' ) ) {
			return (string) get_option( RNRD_OPT_ANTHROPIC_KEY, '' );
		}
		if ( ! empty( $value ) && ! preg_match( '/^sk-ant-[A-Za-z0-9\-_]{20,}$/', $value ) ) {
			add_settings_error( RNRD_OPT_ANTHROPIC_KEY, 'rnrd_invalid_anthropic_key',
				__( 'The Anthropic API key format looks incorrect. It should start with sk-ant-', 'rankready-ai-llm-seo' ), 'error' );
			return (string) get_option( RNRD_OPT_ANTHROPIC_KEY, '' );
		}
		return $value;
	}

	/**
	 * Google AI Studio keys begin with `AIza`.
	 */
	public static function sanitize_gemini_key( $value ): string {
		$value = sanitize_text_field( (string) $value );
		if ( '__UNCHANGED__' === $value || false !== strpos( $value, '••••' ) ) {
			return (string) get_option( RNRD_OPT_GEMINI_KEY, '' );
		}
		if ( ! empty( $value ) && ! preg_match( '/^AIza[A-Za-z0-9\-_]{20,}$/', $value ) ) {
			add_settings_error( RNRD_OPT_GEMINI_KEY, 'rnrd_invalid_gemini_key',
				__( 'The Gemini API key format looks incorrect. Get one from Google AI Studio (aistudio.google.com).', 'rankready-ai-llm-seo' ), 'error' );
			return (string) get_option( RNRD_OPT_GEMINI_KEY, '' );
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
			return (string) get_option( RNRD_OPT_DEEPSEEK_KEY, '' );
		}
		if ( ! empty( $value ) && ! preg_match( '/^sk-[A-Za-z0-9]{20,}$/', $value ) ) {
			add_settings_error( RNRD_OPT_DEEPSEEK_KEY, 'rnrd_invalid_deepseek_key',
				__( 'The DeepSeek API key format looks incorrect. It should start with sk-', 'rankready-ai-llm-seo' ), 'error' );
			return (string) get_option( RNRD_OPT_DEEPSEEK_KEY, '' );
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
	 * provider's RNRD_LLM::get_models_for() list, falls back to provider
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
			return (string) get_option( RNRD_OPT_DFS_LOGIN, '' );
		}
		return $value;
	}

	public static function sanitize_dfs_password( $value ): string {
		$value = (string) $value;
		// Sentinel from FAQ tab hidden field.
		if ( '__UNCHANGED__' === $value ) {
			return (string) get_option( RNRD_OPT_DFS_PASSWORD, '' );
		}
		// Masked display value — don't overwrite stored password.
		if ( false !== strpos( $value, "\xE2\x80\xA2" ) ) {
			return (string) get_option( RNRD_OPT_DFS_PASSWORD, '' );
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rankready-ai-llm-seo' ) );
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
			'dashboard' => __( 'Dashboard', 'rankready-ai-llm-seo' ),
			'content'   => __( 'Content AI', 'rankready-ai-llm-seo' ),
			'authority' => __( 'E-E-A-T', 'rankready-ai-llm-seo' ),
			'crawlers'  => __( 'AI Crawlers', 'rankready-ai-llm-seo' ),
			'insights'  => __( 'Insights', 'rankready-ai-llm-seo' ),  // v1.2.0-beta.7 — Bot Activity / Citation / Referral / Freshness
			'settings'  => __( 'Settings', 'rankready-ai-llm-seo' ),
			'advanced'  => __( 'Advanced', 'rankready-ai-llm-seo' ),
		);

		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = 'dashboard';
		}
		?>
		<div class="wrap rnrd-wrap">
			<div class="rnrd-header">
				<img class="rnrd-header__logo" src="<?php echo esc_url( RNRD_URL . 'assets/logo-source.png' ); ?>" alt="" width="44" height="44" />
				<div class="rnrd-header__text">
					<h1 class="rnrd-title">
						<?php esc_html_e( 'RankReady', 'rankready-ai-llm-seo' ); ?>
						<span class="rnrd-version">v<?php echo esc_html( RNRD_VERSION ); ?></span>
						<a class="rnrd-header__home-link" href="https://store.posimyth.com/plugins/rankready/?ref=rankreadydashboard" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Official Website', 'rankready-ai-llm-seo' ); ?>
							<span aria-hidden="true">↗</span>
						</a>
					</h1>
					<p class="rnrd-subtitle"><?php esc_html_e( 'LLM SEO, EEAT &amp; AI Optimization for WordPress', 'rankready-ai-llm-seo' ); ?></p>
				</div>
			</div>

			<nav class="nav-tab-wrapper rnrd-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="rnrd-dash-layout">
				<main class="rnrd-dash-main">
				<?php
				// v1.2.0-rc.7 — Show one-time success notice after a quick-enable POST.
				self::render_quick_enable_banner();
				?>
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
					case 'insights':
						self::render_tab_insights();
						break;
					case 'settings':
						self::render_tab_api();
						break;
					case 'advanced':
						self::render_tab_advanced();
						break;
				}
				?>
				</main>
				<?php self::render_dashboard_sidebar(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Shared right-rail sidebar — renders on every tab.
	 *
	 * Contains: What's New (changes list), Connect with us (Community +
	 * Support), Star rating widget. Mobile (≤1100px) collapses to single
	 * column via CSS Section 43.
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function render_dashboard_sidebar(): void {
		$user_id       = get_current_user_id();
		$show_whatsnew = self::should_show_whatsnew( $user_id );
		$dismiss_url   = $show_whatsnew ? wp_nonce_url(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '&rnrd_dismiss_whatsnew=' . RNRD_VERSION ),
			'rnrd_dismiss_whatsnew'
		) : '';
		?>
		<aside class="rnrd-dash-aside">

			<?php if ( $show_whatsnew ) : ?>
			<div class="rnrd-aside-card rnrd-aside-card--whatsnew">
				<div class="rnrd-aside-card__head">
					<h3 class="rnrd-aside-card__title">
						<span class="rnrd-whatsnew__tag"><?php esc_html_e( 'NEW', 'rankready-ai-llm-seo' ); ?></span>
						<?php esc_html_e( "What's new", 'rankready-ai-llm-seo' ); ?>
					</h3>
					<a href="<?php echo esc_url( $dismiss_url ); ?>" class="rnrd-aside-card__close" aria-label="<?php esc_attr_e( 'Dismiss', 'rankready-ai-llm-seo' ); ?>" title="<?php esc_attr_e( 'Dismiss', 'rankready-ai-llm-seo' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</a>
				</div>
				<p class="rnrd-aside-card__version">v<?php echo esc_html( RNRD_VERSION ); ?></p>
				<ul class="rnrd-aside-changes">
					<li><strong><?php esc_html_e( 'Unlimited Content AI.', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'No monthly cap on AI Summary or FAQ.', 'rankready-ai-llm-seo' ); ?></li>
					<li><strong><?php esc_html_e( 'Insights tab.', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'Training, Citation, Referrals, Freshness.', 'rankready-ai-llm-seo' ); ?></li>
					<li><strong><?php esc_html_e( 'Multilingual llms.txt.', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'WPML, Polylang, TranslatePress, Weglot.', 'rankready-ai-llm-seo' ); ?></li>
					<li><strong><?php esc_html_e( 'Cache compatibility.', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'LiteSpeed, WP Rocket, W3TC, Cloudflare.', 'rankready-ai-llm-seo' ); ?></li>
					<li><strong><?php esc_html_e( 'Mint design system.', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'Refreshed admin UI.', 'rankready-ai-llm-seo' ); ?></li>
				</ul>
			</div>
			<?php endif; ?>

			<div class="rnrd-aside-card">
				<h3 class="rnrd-aside-card__title"><?php esc_html_e( 'Connect with us', 'rankready-ai-llm-seo' ); ?></h3>
				<a href="https://go.posimyth.com/rankready-community?ref=rankreadydashboard" target="_blank" rel="noopener" class="rnrd-aside-link">
					<span class="dashicons dashicons-groups" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Join community', 'rankready-ai-llm-seo' ); ?></span>
					<span class="rnrd-aside-link__arrow" aria-hidden="true">→</span>
				</a>
				<a href="https://wordpress.org/support/plugin/rankready-ai-llm-seo/" target="_blank" rel="noopener" class="rnrd-aside-link">
					<span class="dashicons dashicons-sos" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Need help? Raise a ticket', 'rankready-ai-llm-seo' ); ?></span>
					<span class="rnrd-aside-link__arrow" aria-hidden="true">→</span>
				</a>
			</div>

			<div class="rnrd-aside-card rnrd-aside-card--rate">
				<h3 class="rnrd-aside-card__title"><?php esc_html_e( 'Loving RankReady so far?', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-aside-card__sub"><?php esc_html_e( 'A quick 5★ review on WordPress.org means the world to our small team — it keeps RankReady free and shipping fast. Thank you!', 'rankready-ai-llm-seo' ); ?></p>
				<div class="rnrd-rate-widget" role="radiogroup" aria-label="<?php esc_attr_e( 'Rate RankReady', 'rankready-ai-llm-seo' ); ?>"
					 data-rate-wp="https://wordpress.org/support/plugin/rankready-ai-llm-seo/reviews/?rate=5#new-post"
					 data-rate-mailto="mailto:support@posimyth.com?subject=<?php echo rawurlencode( 'Feedback for RankReady' ); ?>">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<button type="button" class="rnrd-rate-star" data-value="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d is star count */ __( 'Rate %d out of 5', 'rankready-ai-llm-seo' ), $i ) ); ?>">
							<span class="dashicons dashicons-star-empty" aria-hidden="true"></span>
						</button>
					<?php endfor; ?>
				</div>
			</div>

		</aside>
		<?php
	}

	// ── Shared UI helpers ─────────────────────────────────────────────────────

	/**
	 * Renders a "Coming Soon" gate block inside any card.
	 * Use in place of unavailable UI to clearly signal what is planned.
	 *
	 * @param string $feature     Short feature name.
	 * @param string $description One sentence describing the benefit.
	 */
	private static function render_pro_gate( string $feature, string $description = '' ): void {
		?>
		<div class="rnrd-pro-gate">
			<span class="rnrd-pro-gate__checkbox" aria-hidden="true" title="<?php esc_attr_e( 'Locked — Coming Soon', 'rankready-ai-llm-seo' ); ?>">
				<span class="dashicons dashicons-lock"></span>
			</span>
			<div class="rnrd-pro-gate__text">
				<strong><?php echo esc_html( $feature ); ?> <span class="rnrd-soon-badge"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span></strong>
				<?php if ( $description ) : ?>
					<p><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
				<span class="rnrd-pro-gate__soon"><?php esc_html_e( 'This feature is planned for a future release.', 'rankready-ai-llm-seo' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Reusable "what is this tab?" intro card. Rendered as the very first
	 * card on every top-level tab so users always know the purpose of the
	 * surface they just clicked into. Same .rnrd-card chrome as every
	 * other card in the plugin.
	 *
	 * @since 1.2.0-rc.16
	 * @param string $title Tab name (also card title).
	 * @param string $goal  Italic one-liner — what this tab is for.
	 * @param string $desc  Plain paragraph — slightly longer explanation.
	 */
	private static function render_tab_intro( string $title, string $goal, string $desc ): void {
		?>
		<div class="rnrd-card rnrd-tab-intro">
			<h2 class="rnrd-card-title"><?php echo esc_html( $title ); ?></h2>
			<p class="rnrd-card-goal"><?php echo esc_html( $goal ); ?></p>
			<p class="rnrd-card-desc rnrd-mb-0"><?php echo esc_html( $desc ); ?></p>
		</div>
		<?php
	}

	/**
	 * Inline locked-checkbox indicator for form-table rows where a future
	 * Pro toggle will live. Visual match for render_pro_gate's checkbox-lock
	 * symbol — used inside `<td>` cells in Settings tables so locked rows
	 * read the same as locked Coming-Soon cards.
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function locked_checkbox_indicator(): string {
		return '<span class="rnrd-pro-gate__checkbox" aria-hidden="true" title="' . esc_attr__( 'Locked — Coming Soon', 'rankready-ai-llm-seo' ) . '"><span class="dashicons dashicons-lock"></span></span>';
	}

	/**
	 * Inline "Coming Soon" badge span.
	 */
	private static function pro_badge(): string {
		return '<span class="rnrd-soon-badge">' . esc_html__( 'COMING SOON', 'rankready-ai-llm-seo' ) . '</span>';
	}

	/**
	 * Inline FREE badge span (retained for layout symmetry; no longer paired with PRO).
	 */
	private static function free_badge(): string {
		return '<span class="rnrd-free-badge">' . esc_html__( 'ACTIVE', 'rankready-ai-llm-seo' ) . '</span>';
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Dashboard — at-a-glance overview
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_dashboard(): void {
		global $wpdb;

		$is_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();

		$summary_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				RNRD_META_SUMMARY
			)
		);

		$faq_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				RNRD_META_FAQ
			)
		);

		$llms_on   = 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$robots_on = (bool) get_option( RNRD_OPT_ROBOTS_ENABLE, false );
		$md_on     = 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' );
		// Across all four providers — true if any has a key configured.
		$api_set   = RNRD_LLM::active_provider_ready();

		// Tutorial video — dismissible per user.
		$user_id            = get_current_user_id();
		$tutorial_dismissed = (bool) get_user_meta( $user_id, 'rnrd_tutorial_dismissed', true );

		// "What's new" banner — show once per major version, dismissible per user.
		$show_whatsnew = self::should_show_whatsnew( $user_id );

		// Manual AI Summary + FAQ generation is unlimited in the Free build.
		// Stats kept as 0 values for back-compat with any template that still
		// references $s_used / $f_used — they render harmlessly as "0".
		$s_used = 0;
		$s_lim  = -1; // -1 = unlimited
		$f_used = 0;
		$f_lim  = -1;
		$s_pct  = 0;
		$f_pct  = 0;

		?>
		<?php self::render_tab_intro(
			__( 'Dashboard', 'rankready-ai-llm-seo' ),
			__( 'See what ChatGPT, Claude, Perplexity, and Gemini can read on your site.', 'rankready-ai-llm-seo' ),
			__( 'Start here. Fill in Brand Identity once — every other tab reads from it. The coverage tile below shows which AI signals are live.', 'rankready-ai-llm-seo' )
		); ?>

		<?php
		// rc.16 — Dashboard 2-column layout. Main content on the left,
		// "What's New / Blog / Community / Rate / Support" sidebar on the
		// right, mirroring the pattern Perfmatters/Yoast use.
		$dismiss_url = $show_whatsnew ? wp_nonce_url(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '&rnrd_dismiss_whatsnew=' . RNRD_VERSION ),
			'rnrd_dismiss_whatsnew'
		) : '';
		?>

		<?php // rc.16 — layout wrapper moved to render_page() so sidebar is persistent. ?>

				<?php if ( ! $tutorial_dismissed ) : ?>
		<div class="rnrd-card rnrd-tutorial-card" style="margin-bottom:24px;">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
				<h2 class="rnrd-card-title" style="margin:0;"><?php esc_html_e( 'New to RankReady? Watch the video', 'rankready-ai-llm-seo' ); ?></h2>
			</div>
			<p class="rnrd-card-desc" style="margin-top:0;"><?php esc_html_e( 'Aditya walks through every RankReady setting — AI Summary, FAQ Generator, Author Box, llms.txt, AI Crawler controls — so you can ship a 100/100 AI-ready site.', 'rankready-ai-llm-seo' ); ?></p>
			<div style="position:relative;width:100%;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:6px;background:#000;">
				<iframe
					style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;"
					src="https://www.youtube-nocookie.com/embed/JA-rEwMbqNo?rel=0&modestbranding=1"
					title="<?php esc_attr_e( 'RankReady walkthrough', 'rankready-ai-llm-seo' ); ?>"
					loading="lazy"
					allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
					allowfullscreen></iframe>
			</div>
			<!-- Quick-action button row removed in rc.16 — the 6-tile quick-nav
			     grid below already covers Configure API key (Settings tile),
			     Set up Author Box (Authority tile), Enable LLMs.txt (AI
			     Crawlers tile), and Re-run setup wizard appears as a small
			     standalone link when the tutorial is dismissed. -->
		</div>
		<?php endif; ?>

		<?php if ( $tutorial_dismissed ) : // When the tutorial card is hidden, surface the wizard link as a tiny standalone row so it stays discoverable. ?>
			<p style="margin:0 0 14px;font-size:12px;color:var(--rnrd-color-text-muted,#646970);">
				<?php esc_html_e( 'Need to start over?', 'rankready-ai-llm-seo' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-welcome' ) ); ?>"><?php esc_html_e( 'Re-run the setup wizard →', 'rankready-ai-llm-seo' ); ?></a>
			</p>
		<?php endif; ?>

		<!-- Provider-missing alert, 4 stat tiles, and big 22-signal scorecard
		     removed in rc.16 — the smaller Agent Visibility card above already
		     surfaces the same coverage data, and the Settings quick-access
		     tile below preserves the red "AI provider key required" message
		     for sites without a configured key. -->

		<!-- ── Quick navigation — tiles mirror the tab order in the menu ── -->
		<div class="rnrd-quicknav">
			<a class="rnrd-quicknav__tile" href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=content' ) ); ?>">
				<span class="rnrd-quicknav__title"><?php esc_html_e( 'Content AI', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__desc"><?php esc_html_e( 'AI Summaries and FAQ schema for any post.', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__stat"><?php
					/* translators: 1: summary count, 2: FAQ-set count */
					echo esc_html( sprintf( __( '%1$d summaries · %2$d FAQ sets', 'rankready-ai-llm-seo' ), (int) $summary_count, (int) $faq_count ) );
				?></span>
				<span class="rnrd-quicknav__open"><?php esc_html_e( 'Open', 'rankready-ai-llm-seo' ); ?> <span aria-hidden="true">→</span></span>
			</a>
			<a class="rnrd-quicknav__tile" href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=authority' ) ); ?>">
				<span class="rnrd-quicknav__title"><?php esc_html_e( 'E-E-A-T', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__desc"><?php esc_html_e( 'Author bio, EEAT schema, and Article JSON-LD.', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__stat"><?php esc_html_e( 'Trust signals for ChatGPT, Claude, Perplexity', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__open"><?php esc_html_e( 'Open', 'rankready-ai-llm-seo' ); ?> <span aria-hidden="true">→</span></span>
			</a>
			<a class="rnrd-quicknav__tile" href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=crawlers' ) ); ?>">
				<span class="rnrd-quicknav__title"><?php esc_html_e( 'AI Crawlers', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__desc"><?php esc_html_e( 'Control how 31+ AI crawlers see your site.', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__stat"><?php
					$parts = array();
					if ( $llms_on )   $parts[] = esc_html__( 'LLMs.txt on', 'rankready-ai-llm-seo' );
					if ( $md_on )     $parts[] = esc_html__( 'Markdown on', 'rankready-ai-llm-seo' );
					if ( $robots_on ) $parts[] = esc_html__( 'Robots on', 'rankready-ai-llm-seo' );
					echo $parts ? esc_html( implode( ' · ', $parts ) ) : esc_html__( 'LLMs.txt, Markdown, robots — all off', 'rankready-ai-llm-seo' );
				?></span>
				<span class="rnrd-quicknav__open"><?php esc_html_e( 'Open', 'rankready-ai-llm-seo' ); ?> <span aria-hidden="true">→</span></span>
			</a>
			<a class="rnrd-quicknav__tile" href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=insights' ) ); ?>">
				<span class="rnrd-quicknav__title"><?php esc_html_e( 'Insights', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__desc"><?php esc_html_e( 'Who reads your site, what they cite, what brings them back.', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__stat"><?php esc_html_e( 'Training, Citation, Referrals, Freshness', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__open"><?php esc_html_e( 'Open', 'rankready-ai-llm-seo' ); ?> <span aria-hidden="true">→</span></span>
			</a>
			<a class="rnrd-quicknav__tile" href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=settings' ) ); ?>">
				<span class="rnrd-quicknav__title"><?php esc_html_e( 'Settings', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__desc"><?php esc_html_e( 'API keys and provider configuration.', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__stat <?php echo $api_set ? '' : 'rnrd-quicknav__stat--alert'; ?>"><?php
					if ( $api_set ) {
						echo esc_html( sprintf(
							/* translators: %s: provider label like "OpenAI" or "Claude (Anthropic)" */
							__( '%s key configured', 'rankready-ai-llm-seo' ),
							RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() )
						) );
					} else {
						esc_html_e( 'AI provider key required', 'rankready-ai-llm-seo' );
					}
				?></span>
				<span class="rnrd-quicknav__open"><?php esc_html_e( 'Open', 'rankready-ai-llm-seo' ); ?> <span aria-hidden="true">→</span></span>
			</a>
			<a class="rnrd-quicknav__tile" href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=advanced' ) ); ?>">
				<span class="rnrd-quicknav__title"><?php esc_html_e( 'Advanced', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__desc"><?php esc_html_e( 'Diagnostics, error log, API usage, data retention.', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__stat">v<?php echo esc_html( RNRD_VERSION ); ?> · <?php esc_html_e( 'by POSIMYTH Innovations', 'rankready-ai-llm-seo' ); ?></span>
				<span class="rnrd-quicknav__open"><?php esc_html_e( 'Open', 'rankready-ai-llm-seo' ); ?> <span aria-hidden="true">→</span></span>
			</a>
		</div>

		<!-- ── Beta: all features active + full feature list ───────────── -->
		<?php if ( $is_pro ) : ?>
		<div class="rnrd-card" style="margin-bottom:24px;">
			<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px;">
				<h2 class="rnrd-card-title" style="margin:0;"><?php esc_html_e( 'RankReady Beta', 'rankready-ai-llm-seo' ); ?></h2>
				<span style="background:#0F9C70;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:3px;letter-spacing:.5px;"><?php esc_html_e( 'BETA BUILD', 'rankready-ai-llm-seo' ); ?></span>
			</div>
			<p class="rnrd-card-desc"><?php esc_html_e( 'Thank you for beta testing RankReady! All features below are fully unlocked — no limits, no paywalls.', 'rankready-ai-llm-seo' ); ?></p>
			<ul class="rnrd-feature-list" style="columns:2;column-gap:32px;margin-top:12px;">
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'LLMs.txt + LLMs-full.txt', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Unlimited', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Robots.txt — 31 AI bot controls', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Unlimited', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Markdown endpoints per post', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Posts &amp; Pages', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Article JSON-LD + Speakable schema', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Auto-injected', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'FAQPage JSON-LD schema', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Auto with every FAQ', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Full EEAT Author Box + Schema', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Person JSON-LD, Wikidata, ORCID', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Unlimited AI Summary Generator', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Auto on publish + bulk all posts', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Unlimited FAQ Generation', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Auto on publish + bulk all posts', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'HowTo + ItemList Schema', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Auto-detected for tutorials &amp; listicles', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'AI Crawler Analytics', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'ChatGPT, Perplexity, Gemini — who/what/when', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Headless REST Endpoints', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Next.js, Nuxt, Astro, SvelteKit', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Custom Post Type support', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'All AI features on any CPT', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Content Freshness Alerts', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Stale post notifications at scale', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Bulk Author Change', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Unlimited', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Content Signals', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Freshness indicators', 'rankready-ai-llm-seo' ); ?></small></span></li>
				<li><span class="dashicons dashicons-yes rnrd-feature-icon rnrd-feature-icon--check"></span><span><?php esc_html_e( 'Health Check Score', 'rankready-ai-llm-seo' ); ?><small><?php esc_html_e( 'Overall score', 'rankready-ai-llm-seo' ); ?></small></span></li>
			</ul>
		</div>

		<?php endif; ?>
		<?php // sidebar markup moved to render_page() so it appears on every tab. ?>

		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// Dashboard: Agentic Ready Scorecard (rc.6)
	// ═══════════════════════════════════════════════════════════════════════════
	//
	// 22 binary signals across 6 groups (Discovery, Content AI, Brand Authority,
	// Provider, Engagement, Tracking). Each row deep-links to the tab that
	// configures it. Progress bar shows % active. Purely read-only — no
	// settings persisted here.

	/**
	 * Build the list of scorecard signals.
	 *
	 * Each row: ['group' => str, 'label' => str, 'active' => bool,
	 *           'deeplink' => str (relative to admin.php?page=rankready-ai-llm-seo),
	 *           'cta' => str (button label)]
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_scorecard_signals(): array {
		global $wpdb;

		// Cached existence checks so this method runs O(few queries) max.
		$has_any_summary = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 1",
				RNRD_META_SUMMARY
			)
		) > 0;
		$has_any_faq = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 1",
				RNRD_META_FAQ
			)
		) > 0;

		// Crawler-log existence + totals (graceful if table absent).
		$crawler_table  = $wpdb->prefix . 'rnrd_crawler_log';
		$crawler_exists = (bool) $wpdb->get_var( $wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$crawler_table
		) );
		$citation_hits = 0;
		$training_hits = 0;
		$crawler_rows  = 0;
		if ( $crawler_exists && class_exists( 'RNRD_Crawler_Log' ) ) {
			$citation_hits = RNRD_Crawler_Log::get_citation_hits_total( 365 );
			$training_hits = RNRD_Crawler_Log::get_training_hits_total( 365 );
			$crawler_rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$crawler_table} LIMIT 1" );
		}

		// Freshness ever scanned — option set by RNRD_Freshness once a scan completes.
		$freshness_scanned = (bool) get_option( 'rnrd_freshness_last_run', 0 );

		// Tab deep-links.
		$tab_crawlers = '?page=rankready-ai-llm-seo&tab=crawlers';
		$tab_content  = '?page=rankready-ai-llm-seo&tab=content';
		$tab_authority = '?page=rankready-ai-llm-seo&tab=authority';
		$tab_settings  = '?page=rankready-ai-llm-seo&tab=settings';
		$tab_insights_freshness = '?page=rankready-ai-llm-seo&tab=insights&sub=freshness';
		$tab_insights_bot       = '?page=rankready-ai-llm-seo&tab=insights&sub=bot-activity';
		$tab_insights_citation  = '?page=rankready-ai-llm-seo&tab=insights&sub=citation';
		$tab_insights_referral  = '?page=rankready-ai-llm-seo&tab=insights&sub=referral';

		return array(
			// ── Discovery (5) ────────────────────────────────────────────────
			array(
				'group'    => __( 'Discovery', 'rankready-ai-llm-seo' ),
				'label'    => __( 'llms.txt', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ),
				'deeplink' => $tab_crawlers,
			),
			array(
				'group'    => __( 'Discovery', 'rankready-ai-llm-seo' ),
				'label'    => __( 'llms-full.txt', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ),
				'deeplink' => $tab_crawlers,
			),
			array(
				'group'    => __( 'Discovery', 'rankready-ai-llm-seo' ),
				'label'    => __( '.md routes', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ),
				'deeplink' => $tab_crawlers,
			),
			array(
				'group'    => __( 'Discovery', 'rankready-ai-llm-seo' ),
				'label'    => __( 'robots.txt rules', 'rankready-ai-llm-seo' ),
				'active'   => (bool) get_option( RNRD_OPT_ROBOTS_ENABLE, false ),
				'deeplink' => $tab_crawlers,
			),
			array(
				'group'    => __( 'Discovery', 'rankready-ai-llm-seo' ),
				'label'    => __( 'WebMCP manifest', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_MCP_ENABLE, 'off' ),
				'deeplink' => $tab_crawlers,
			),

			// ── Content AI (4) ───────────────────────────────────────────────
			array(
				'group'    => __( 'Content AI', 'rankready-ai-llm-seo' ),
				'label'    => __( 'AI Summary', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_AUTO_GENERATE, 'off' ) || $has_any_summary,
				'deeplink' => $tab_content,
			),
			array(
				'group'    => __( 'Content AI', 'rankready-ai-llm-seo' ),
				'label'    => __( 'FAQ Generation', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_FAQ_AUTO_GENERATE, 'off' ) || $has_any_faq,
				'deeplink' => $tab_content,
			),
			array(
				'group'    => __( 'Content AI', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Content Signals', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_CONTENT_SIGNALS_ENABLE, 'off' ),
				'deeplink' => $tab_crawlers,
			),
			array(
				'group'    => __( 'Content AI', 'rankready-ai-llm-seo' ),
				'label'    => __( 'max-snippet:-1 default', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_MAX_SNIPPET_DEFAULT, 'on' ),
				'deeplink' => $tab_crawlers,
			),

			// ── Brand Authority (4) ──────────────────────────────────────────
			array(
				'group'    => __( 'Brand Authority', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Site name set', 'rankready-ai-llm-seo' ),
				'active'   => '' !== trim( (string) get_option( RNRD_OPT_LLMS_SITE_NAME, '' ) ),
				'deeplink' => $tab_crawlers,
			),
			array(
				'group'    => __( 'Brand Authority', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Summary set', 'rankready-ai-llm-seo' ),
				'active'   => '' !== trim( (string) get_option( RNRD_OPT_LLMS_SUMMARY, '' ) ),
				'deeplink' => $tab_crawlers,
			),
			array(
				'group'    => __( 'Brand Authority', 'rankready-ai-llm-seo' ),
				'label'    => __( 'About set', 'rankready-ai-llm-seo' ),
				'active'   => '' !== trim( (string) get_option( RNRD_OPT_LLMS_ABOUT, '' ) ),
				'deeplink' => $tab_crawlers,
			),
			array(
				'group'    => __( 'Brand Authority', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Brand terms set', 'rankready-ai-llm-seo' ),
				'active'   => '' !== trim( (string) get_option( RNRD_OPT_BRAND_TERMS, '' ) ),
				'deeplink' => $tab_crawlers,
			),

			// ── Provider (2) ─────────────────────────────────────────────────
			array(
				'group'    => __( 'Provider', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Active AI provider key', 'rankready-ai-llm-seo' ),
				'active'   => class_exists( 'RNRD_LLM' ) && RNRD_LLM::active_provider_ready(),
				'deeplink' => $tab_settings,
			),
			array(
				'group'    => __( 'Provider', 'rankready-ai-llm-seo' ),
				'label'    => __( 'DataForSEO credentials', 'rankready-ai-llm-seo' ),
				'active'   => '' !== (string) get_option( RNRD_OPT_DFS_LOGIN, '' )
				            && '' !== (string) get_option( RNRD_OPT_DFS_PASSWORD, '' ),
				'deeplink' => $tab_settings,
			),

			// ── Engagement (3) ───────────────────────────────────────────────
			array(
				'group'    => __( 'Engagement', 'rankready-ai-llm-seo' ),
				'label'    => __( 'AI Referral tracking', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_AI_REFERRAL_ENABLE, 'off' ),
				'deeplink' => $tab_crawlers,
			),
			array(
				'group'    => __( 'Engagement', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Author Box', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_AUTHOR_ENABLE, 'on' ),
				'deeplink' => $tab_authority,
			),
			array(
				'group'    => __( 'Engagement', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Article schema', 'rankready-ai-llm-seo' ),
				'active'   => 'on' === get_option( RNRD_OPT_SCHEMA_ARTICLE, 'on' ),
				'deeplink' => $tab_authority,
			),

			// ── Tracking (4) ─────────────────────────────────────────────────
			array(
				'group'    => __( 'Tracking', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Citation hits > 0', 'rankready-ai-llm-seo' ),
				'active'   => $citation_hits > 0,
				'deeplink' => $tab_insights_citation,
				'cta_kind' => 'tracking',
			),
			array(
				'group'    => __( 'Tracking', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Training hits > 0', 'rankready-ai-llm-seo' ),
				'active'   => $training_hits > 0,
				'deeplink' => $tab_insights_bot,
				'cta_kind' => 'tracking',
			),
			array(
				'group'    => __( 'Tracking', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Crawler log live', 'rankready-ai-llm-seo' ),
				'active'   => $crawler_rows > 0,
				'deeplink' => $tab_insights_bot,
				'cta_kind' => 'tracking',
			),
			array(
				'group'    => __( 'Tracking', 'rankready-ai-llm-seo' ),
				'label'    => __( 'Freshness scanned', 'rankready-ai-llm-seo' ),
				'active'   => $freshness_scanned,
				'deeplink' => $tab_insights_freshness,
				'cta_kind' => 'freshness',
			),
		);
	}

	/**
	 * Render the Agentic Ready Scorecard card (Dashboard tab, rc.6).
	 */
	private static function render_card_scorecard(): void {
		$signals = self::get_scorecard_signals();
		$total   = count( $signals );
		$active  = 0;
		foreach ( $signals as $s ) {
			if ( ! empty( $s['active'] ) ) {
				$active++;
			}
		}
		$pct = $total > 0 ? (int) round( ( $active / $total ) * 100 ) : 0;

		// Group signals.
		$groups = array();
		foreach ( $signals as $s ) {
			$groups[ $s['group'] ][] = $s;
		}
		?>
		<div class="rnrd-card rnrd-scorecard" style="margin-bottom:24px;">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Agentic Ready Scorecard', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( '22 signals across 6 groups — your site\'s AI-readiness at a glance.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Every signal RankReady ships, at a glance. Tick = active. Click any row to jump straight to the setting.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<div class="rnrd-scorecard-progress" style="position:relative;height:10px;background:#e5e5e7;border-radius:6px;overflow:hidden;margin:10px 0 6px;">
				<div class="rnrd-scorecard-bar" style="height:100%;width:<?php echo (int) $pct; ?>%;background:linear-gradient(90deg,#2DE3A8 0%,#0F9C70 100%);transition:width 0.4s ease;"></div>
			</div>
			<p class="rnrd-scorecard-meta" style="margin:0 0 16px;font-size:13px;color:#646970;">
				<?php
				echo esc_html( sprintf(
					/* translators: 1: active count, 2: total count, 3: percentage */
					__( '%1$d of %2$d signals active — %3$d%%', 'rankready-ai-llm-seo' ),
					$active,
					$total,
					$pct
				) );
				?>
			</p>

			<div class="rnrd-scorecard-groups" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
				<?php foreach ( $groups as $group_label => $group_signals ) : ?>
					<div class="rnrd-scorecard-group">
						<h3 style="margin:0 0 8px;font-size:13px;font-weight:700;color:#1d2327;text-transform:uppercase;letter-spacing:0.04em;"><?php echo esc_html( $group_label ); ?></h3>
						<ul style="margin:0;padding:0;list-style:none;font-size:13px;line-height:1.7;">
							<?php foreach ( $group_signals as $s ) :
								$is_on    = ! empty( $s['active'] );
								$icon     = $is_on ? '✓' : '○';
								$icon_col = $is_on ? '#00a32a' : '#a7aaad';
								$cta_kind = isset( $s['cta_kind'] ) ? $s['cta_kind'] : 'config';
								if ( 'tracking' === $cta_kind ) {
									$cta_label = $is_on ? __( 'View →', 'rankready-ai-llm-seo' ) : __( 'View →', 'rankready-ai-llm-seo' );
								} elseif ( 'freshness' === $cta_kind ) {
									$cta_label = $is_on ? __( 'View →', 'rankready-ai-llm-seo' ) : __( 'Scan →', 'rankready-ai-llm-seo' );
								} else {
									$cta_label = $is_on ? __( 'Configure →', 'rankready-ai-llm-seo' ) : __( 'Enable →', 'rankready-ai-llm-seo' );
								}
								$href = admin_url( 'admin.php' . $s['deeplink'] );
								?>
								<li style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:4px 0;">
									<span>
										<span class="rnrd-tick" style="display:inline-block;width:18px;color:<?php echo esc_attr( $icon_col ); ?>;font-weight:700;"><?php echo esc_html( $icon ); ?></span>
										<?php echo esc_html( $s['label'] ); ?>
									</span>
									<a href="<?php echo esc_url( $href ); ?>" style="font-size:12px;color:#0F9C70;text-decoration:none;white-space:nowrap;">
										<?php echo esc_html( $cta_label ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Content AI — AI Summary + FAQ Generator merged
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_content_ai(): void {
		?>
		<?php settings_errors(); ?>
		<?php self::render_tab_intro(
			__( 'Content AI', 'rankready-ai-llm-seo' ),
			__( 'Write AI Summaries and FAQ schema for any post.', 'rankready-ai-llm-seo' ),
			__( 'Choose how summaries and FAQs appear on the front end. Manual generation is unlimited. Auto-generate and bulk runs are Coming Soon.', 'rankready-ai-llm-seo' )
		); ?>

		<?php /* Active AI provider summary — quick visibility on the Content AI
		         tab into what model is currently powering Summary + FAQ, with
		         a one-click jump to Settings to change provider/key. */ ?>
		<div class="rnrd-card" style="margin-bottom:20px;background:linear-gradient(135deg,#f6f7f7 0%,#eef0f2 100%);">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Active AI Provider', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'The LLM that powers Summary + FAQ generation.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc" style="margin-bottom:8px;">
				<?php
				$provider_label = RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() );
				$active_model   = RNRD_LLM::get_model( RNRD_LLM::get_active_provider() );
				$key_set        = RNRD_LLM::active_provider_ready();
				echo esc_html( sprintf(
					/* translators: 1: provider name, 2: model id */
					__( '%1$s — %2$s', 'rankready-ai-llm-seo' ),
					$provider_label,
					$active_model
				) );
				if ( $key_set ) {
					echo ' <span style="color:#00a32a;font-weight:600;">' . esc_html__( '(key configured ✓)', 'rankready-ai-llm-seo' ) . '</span>';
				} else {
					echo ' <span style="color:#d63638;font-weight:600;">' . esc_html__( '(no key — Summary + FAQ won\'t run)', 'rankready-ai-llm-seo' ) . '</span>';
				}
				?>
			</p>
			<p style="margin:0;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=settings' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Change provider / Add API key →', 'rankready-ai-llm-seo' ); ?>
				</a>
			</p>
		</div>

		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::CONTENT_GROUP ); ?>

			<?php self::render_tab_summary(); ?>
		</form>

		<?php
		// Bulk Regenerate — AI Summaries renders directly under its parent
		// AI Summary card (rc.16 relocation per user direction). AJAX-driven
		// UI, sits outside the settings form.
		self::render_card_bulk_summary();
		?>

		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::CONTENT_GROUP ); ?>

			<?php self::render_tab_faq(); ?>
		</form>

		<?php
		// Bulk Regenerate — FAQ renders directly under its parent FAQ
		// Generator card. AJAX-driven, separate from the settings form.
		self::render_card_bulk_faq();
		?>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Authority — Author Box + Schema Automation merged
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_authority(): void {
		?>
		<?php settings_errors(); ?>
		<?php self::render_tab_intro(
			__( 'E-E-A-T', 'rankready-ai-llm-seo' ),
			__( 'Prove who wrote it — so ChatGPT, Claude, and Perplexity trust the citation.', 'rankready-ai-llm-seo' ),
			__( 'Author bio, EEAT schema, and Article JSON-LD. Works alongside Rank Math, Yoast, and AIOSEO — RankReady merges into their schema graph instead of duplicating it.', 'rankready-ai-llm-seo' )
		); ?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::AUTHORITY_GROUP ); ?>

			<?php self::render_tab_author(); ?>
			<?php self::render_tab_schema(); ?>
		</form>

		<?php
		// ── Bulk Author Changer (relocated from Advanced tab in rc.6) ──────
		// AJAX-driven, no form wrapper needed. All field IDs preserved.
		self::render_card_bulk_author();
		?>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Advanced — Headless + Tools + Info merged
	// ═══════════════════════════════════════════════════════════════════════════

	// ══════════════════════════════════════════════════════════════════════════
	// TAB: Insights — Bot Activity / AI Citation / AI Referral / Freshness
	// ══════════════════════════════════════════════════════════════════════════
	// v1.2.0-beta.7 — Splits the three signals that were conflated on the
	// AI Crawlers tab:
	//   • Training-bot crawl  (inbound, slow — GPTBot/Google-Extended/ClaudeBot
	//     indexing for future model training)
	//   • Citation-bot crawl  (inbound, live — ChatGPT-User/OAI-SearchBot/
	//     PerplexityBot fetching to answer a real user query NOW)
	//   • AI Referral traffic (outbound — users clicking from ChatGPT.com /
	//     Perplexity.ai / etc. back to your site)
	// Each has its own sub-section header explaining the stage of the funnel.

	private static function render_tab_insights(): void {
		$sub = isset( $_GET['sub'] ) ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : 'bot-activity';
		$is_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();
		$sub_tabs = array(
			'bot-activity' => __( 'Training Bots', 'rankready-ai-llm-seo' ),
			'citation'     => __( 'Citation Bots', 'rankready-ai-llm-seo' ),
			'referral'     => __( 'Real AI Referrals', 'rankready-ai-llm-seo' ),
			'freshness'    => __( 'Content Fresh', 'rankready-ai-llm-seo' ),
			'mentions'     => __( 'AI Mention', 'rankready-ai-llm-seo' ) . ' (Coming Soon)',
		);
		if ( ! isset( $sub_tabs[ $sub ] ) ) {
			$sub = 'bot-activity';
		}
		?>
		<?php settings_errors(); ?>
		<?php self::render_tab_intro(
			__( 'Insights', 'rankready-ai-llm-seo' ),
			__( 'Who reads your site, what they cite, and what brings users back.', 'rankready-ai-llm-seo' ),
			__( 'Training bots (GPTBot, ClaudeBot, Google-Extended) index you for tomorrow. Citation bots (ChatGPT-User, PerplexityBot, OAI-SearchBot) read you live to answer questions right now. Real AI Referrals track users clicking through from chatgpt.com, perplexity.ai, and claude.ai. Counts start at zero on a fresh install.', 'rankready-ai-llm-seo' )
		); ?>

		<!-- Sub-tab navigation + Demo toggle button -->
		<div class="rnrd-insights-toolbar">
			<nav class="rnrd-insights-subnav">
				<?php foreach ( $sub_tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => 'insights', 'sub' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
					   class="<?php echo $sub === $slug ? 'is-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php
			$is_demo  = self::is_demo_mode();
			$demo_url = $is_demo
				? esc_url( remove_query_arg( 'rnrd_demo' ) )
				: esc_url( add_query_arg( 'rnrd_demo', '1' ) );
			?>
			<a href="<?php echo esc_url( $demo_url ); ?>" class="rnrd-demo-toggle <?php echo $is_demo ? 'is-active' : ''; ?>" title="<?php esc_attr_e( 'Toggle dummy data preview', 'rankready-ai-llm-seo' ); ?>">
				<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
				<?php echo $is_demo
					? esc_html__( 'Exit preview', 'rankready-ai-llm-seo' )
					: esc_html__( 'Preview with sample data', 'rankready-ai-llm-seo' ); ?>
			</a>
		</div>

		<?php
		switch ( $sub ) {
			case 'bot-activity':   self::render_insights_bot_activity();   break;
			case 'citation':       self::render_insights_citation();        break;
			case 'referral':       self::render_insights_referral();        break;
			case 'freshness':      self::render_insights_freshness();       break;
			case 'mentions':       self::render_insights_mention_tracker(); break;
		}
	}

	/**
	 * Insights → AI Mention Tracker preview (Coming Soon).
	 *
	 * Placeholder card describing the planned mention-tracking feature.
	 * No upgrade prompt, no external CTA — purely informational.
	 *
	 * @since 1.2.0-rc.14
	 */
	private static function render_insights_mention_tracker(): void {
		?>
		<div class="rnrd-card">
			<h2 class="rnrd-card-title">
				<?php esc_html_e( 'AI Mention Tracker', 'rankready-ai-llm-seo' ); ?>
				<span class="rnrd-soon-badge"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span>
			</h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Monitor brand mentions across ChatGPT, Perplexity, Claude, and Gemini.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Ask each major LLM your target queries on a weekly cadence and track position changes over time. No action required from you today — the feature will appear here automatically once it ships.', 'rankready-ai-llm-seo' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Insights → Bot Activity sub-tab.
	 *
	 * Splits the existing Crawler Log into TWO clearly-labelled panels:
	 *   • Training (inbound, slow, model-training value)
	 *   • Citation (inbound, live, ~1:1 with AI answer citations)
	 * so users can finally tell which signal matters for which goal.
	 */

	/**
	 * Demo mode — fills the Insights tab with realistic fake data so the
	 * admin can preview the populated layout before real bots hit the site.
	 *
	 * Activated by appending `?rnrd_demo=1` to any Insights URL. Admin-only
	 * (gated by manage_options). Demo data is ephemeral — nothing is written
	 * to the DB. Banner is shown so the user never confuses preview for real.
	 *
	 * @since 1.2.0-rc.16
	 */
	private static function is_demo_mode(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return isset( $_GET['rnrd_demo'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['rnrd_demo'] ) );
	}

	/**
	 * Render the demo-mode banner above each Insights sub-tab. Shows once
	 * per page-load with a "Exit demo" link.
	 */
	private static function render_demo_banner(): void {
		// FREE-104 — informational only. Exit action lives in the toolbar
		// toggle (single canonical control). Banner explains state; toolbar
		// changes state.
		?>
		<div class="rnrd-demo-banner" role="status">
			<strong><?php esc_html_e( 'Demo data', 'rankready-ai-llm-seo' ); ?></strong>
			<?php esc_html_e( '— previewing the populated layout with sample numbers. Use the toggle in the toolbar above to exit.', 'rankready-ai-llm-seo' ); ?>
		</div>
		<?php
	}

	/**
	 * Render Prev / N of M / Next pagination strip below tables that can
	 * grow unbounded (bot activity, citation candidates, freshness scans).
	 *
	 * @param int    $current Current page (1-indexed).
	 * @param int    $pages   Total pages.
	 * @param string $param   GET parameter name (e.g. 'bot_p', 'cite_p').
	 * @since FREE-100
	 */
	private static function render_pagination( int $current, int $pages, string $param ): void {
		if ( $pages <= 1 ) {
			return;
		}
		$prev = max( 1, $current - 1 );
		$next = min( $pages, $current + 1 );
		?>
		<nav class="rnrd-pagination" aria-label="<?php esc_attr_e( 'Pagination', 'rankready-ai-llm-seo' ); ?>">
			<a class="rnrd-pagination__btn <?php echo $current <= 1 ? 'is-disabled' : ''; ?>"
			   href="<?php echo esc_url( add_query_arg( $param, $prev ) ); ?>"
			   aria-disabled="<?php echo $current <= 1 ? 'true' : 'false'; ?>">
				<?php esc_html_e( '← Prev', 'rankready-ai-llm-seo' ); ?>
			</a>
			<span class="rnrd-pagination__info">
				<?php
				/* translators: 1: current page, 2: total pages */
				echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'rankready-ai-llm-seo' ), (int) $current, (int) $pages ) );
				?>
			</span>
			<a class="rnrd-pagination__btn <?php echo $current >= $pages ? 'is-disabled' : ''; ?>"
			   href="<?php echo esc_url( add_query_arg( $param, $next ) ); ?>"
			   aria-disabled="<?php echo $current >= $pages ? 'true' : 'false'; ?>">
				<?php esc_html_e( 'Next →', 'rankready-ai-llm-seo' ); ?>
			</a>
		</nav>
		<?php
	}

	private static function render_insights_bot_activity(): void {
		$demo = self::is_demo_mode();

		if ( $demo ) {
			$citation_hits = 412;
			$training_hits = 2841;
			$total_30d     = $citation_hits + $training_hits;
			$unique_pages  = 48;
			$bot_stats     = array(
				array( 'bot_name' => 'GPTBot',          'total' => 1420 ),
				array( 'bot_name' => 'ClaudeBot',       'total' => 892  ),
				array( 'bot_name' => 'Google-Extended', 'total' => 414  ),
				array( 'bot_name' => 'PerplexityBot',   'total' => 168  ),
				array( 'bot_name' => 'OAI-SearchBot',   'total' => 124  ),
				array( 'bot_name' => 'CCBot',           'total' => 115  ),
				array( 'bot_name' => 'ChatGPT-User',    'total' => 76   ),
				array( 'bot_name' => 'Claude-Web',      'total' => 28   ),
				array( 'bot_name' => 'DuckAssistBot',   'total' => 16   ),
			);
		} else {
			$citation_hits = (int) RNRD_Crawler_Log::get_citation_hits_total( 30 );
			$training_hits = (int) RNRD_Crawler_Log::get_training_hits_total( 30 );
			$total_30d     = (int) RNRD_Crawler_Log::get_total( 30 );
			$unique_pages  = (int) RNRD_Crawler_Log::get_unique_pages( 30 );
			$bot_stats     = RNRD_Crawler_Log::get_bot_stats( 30 );
		}

		if ( $demo ) { self::render_demo_banner(); }


		// Split bot list by intent for footnote counts.
		$citation_bots_seen = 0;
		$training_bots_seen = 0;
		$max_hits           = 0;
		foreach ( $bot_stats as $row ) {
			$intent = RNRD_Crawler_Log::bot_intent( $row['bot_name'] );
			if ( 'citation' === $intent ) {
				$citation_bots_seen++;
			} elseif ( 'training' === $intent ) {
				$training_bots_seen++;
			}
			$max_hits = max( $max_hits, (int) $row['total'] );
		}

		// Reference counts — how many bots of each type RankReady CAN detect.
		// These match the bot pattern lists in RNRD_Crawler_Log.
		$citation_bots_tracked = 5; // ChatGPT-User, OAI-SearchBot, PerplexityBot, Claude-Web, DuckAssistBot
		$training_bots_tracked = 8; // GPTBot, ClaudeBot, Google-Extended, Bytespider, CCBot, Applebot-Extended, AI2Bot, Diffbot

		// Total published posts for "pages read of X" context.
		$total_posts = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'page' )->publish;

		$has_data = $total_30d > 0;
		?>
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Bot Activity', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Who reads your site — and what they do with it.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Citation hits = a live AI answer fetched your content mid-response. Training hits = an AI crawler ingested your content for future model training. Pages read = how much of your library AI has seen. Total hits = combined crawler volume over the last 30 days.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'Bot activity key metrics', 'rankready-ai-llm-seo' ); ?>">
				<!-- KPI 1: Citation hits — the primary actionable metric -->
				<div class="rnrd-kpi" data-intent="citation">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Citation hits', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $citation_hits ) ); ?></div>
					<div class="rnrd-kpi__foot">
						<?php if ( $has_data && $citation_hits > 0 ) :
							/* translators: 1: bots seen, 2: total citation bots tracked */
							echo esc_html( sprintf( __( 'fetched by %1$d of %2$d citation bots', 'rankready-ai-llm-seo' ), (int) $citation_bots_seen, (int) $citation_bots_tracked ) );
						else :
							esc_html_e( 'First citation typically arrives 2–6 weeks after enabling llms.txt', 'rankready-ai-llm-seo' );
						endif; ?>
					</div>
				</div>

				<!-- KPI 2: Training hits — secondary context, longer payoff -->
				<div class="rnrd-kpi" data-intent="training">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Training hits', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $training_hits ) ); ?></div>
					<div class="rnrd-kpi__foot">
						<?php if ( $has_data && $training_hits > 0 ) :
							/* translators: 1: bots seen, 2: total training bots tracked */
							echo esc_html( sprintf( __( 'ingested by %1$d of %2$d training bots', 'rankready-ai-llm-seo' ), (int) $training_bots_seen, (int) $training_bots_tracked ) );
						else :
							esc_html_e( 'First training crawl typically arrives 24–72 hours after enabling llms.txt', 'rankready-ai-llm-seo' );
						endif; ?>
					</div>
				</div>

				<!-- KPI 3: Pages read — coverage of your library -->
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Pages read', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $unique_pages ) ); ?></div>
					<div class="rnrd-kpi__foot">
						<?php if ( $has_data ) :
							/* translators: %s: total posts + pages count */
							echo esc_html( sprintf( __( 'of %s posts + pages published', 'rankready-ai-llm-seo' ), number_format_i18n( $total_posts ) ) );
						else :
							esc_html_e( 'How much of your library AI has indexed', 'rankready-ai-llm-seo' );
						endif; ?>
					</div>
				</div>

				<!-- KPI 4: Total hits — overall volume -->
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Total hits', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total_30d ) ); ?></div>
					<div class="rnrd-kpi__foot">
						<?php if ( $has_data ) :
							printf(
								/* translators: %d: unique bots */
								esc_html__( 'across %d unique bots', 'rankready-ai-llm-seo' ),
								count( $bot_stats )
							);
						else :
							esc_html_e( '20+ AI crawlers tracked across all endpoints', 'rankready-ai-llm-seo' );
						endif; ?>
					</div>
				</div>
			</div>

			<?php if ( ! $has_data ) : ?>
				<p class="rnrd-kpi-empty">
					<?php esc_html_e( 'No AI bot visits recorded yet. Counts populate automatically once any tracked AI crawler hits your llms.txt, /*.md, or homepage endpoints — no setup required.', 'rankready-ai-llm-seo' ); ?>
				</p>
			<?php else :
				// FREE-100 — Training Bots tab shows TRAINING-intent rows only.
				// Citation bots live on the dedicated Citation Bots sub-tab so
				// the two intents never mix in one table.
				$training_rows = array();
				foreach ( $bot_stats as $row ) {
					if ( 'training' === RNRD_Crawler_Log::bot_intent( $row['bot_name'] ) ) {
						$training_rows[] = $row;
					}
				}
				$training_max = ! empty( $training_rows ) ? max( array_column( $training_rows, 'total' ) ) : 1;
				// FREE-100 — pagination: page-size 10, GET param `bot_p`.
				$bot_page  = isset( $_GET['bot_p'] ) ? max( 1, (int) $_GET['bot_p'] ) : 1;
				$per_page  = 10;
				$total_rows = count( $training_rows );
				$pages      = (int) max( 1, ceil( $total_rows / $per_page ) );
				$bot_page   = min( $bot_page, $pages );
				$slice      = array_slice( $training_rows, ( $bot_page - 1 ) * $per_page, $per_page );
				?>
				<h3 class="rnrd-subsection-title" style="margin-top:24px;"><?php esc_html_e( 'Per-bot breakdown', 'rankready-ai-llm-seo' ); ?></h3>
				<?php if ( empty( $training_rows ) ) : ?>
					<p class="rnrd-kpi-empty"><?php esc_html_e( 'No training bot activity yet. Training crawlers (GPTBot, ClaudeBot, Google-Extended) typically arrive within 24–72 hours of enabling llms.txt.', 'rankready-ai-llm-seo' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat striped rnrd-bot-table">
						<thead>
							<tr>
								<th style="width:40%;"><?php esc_html_e( 'Bot', 'rankready-ai-llm-seo' ); ?></th>
								<th><?php esc_html_e( 'Share of hits', 'rankready-ai-llm-seo' ); ?></th>
								<th style="width:12%;text-align:right;"><?php esc_html_e( 'Hits', 'rankready-ai-llm-seo' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $slice as $row ) :
								$hits = (int) $row['total'];
								$pct  = $training_max > 0 ? min( 100, round( ( $hits / $training_max ) * 100 ) ) : 0;
								?>
								<tr>
									<td><strong><?php echo esc_html( $row['bot_name'] ); ?></strong></td>
									<td>
										<div class="rnrd-bar-track" aria-hidden="true">
											<div class="rnrd-bar-fill rnrd-bar-fill--training" style="width:<?php echo (int) $pct; ?>%;"></div>
										</div>
									</td>
									<td style="text-align:right;"><strong><?php echo esc_html( number_format_i18n( $hits ) ); ?></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php self::render_pagination( $bot_page, $pages, 'bot_p' ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_insights_citation(): void {
		$demo = self::is_demo_mode();

		if ( $demo ) {
			$citation_pages = array(
				array( 'post_id' => 1, 'post_title' => 'How to set up llms.txt on WordPress', 'post_type' => 'post', 'url_path' => '/blog/llms-txt-wordpress', 'hits' => 84, 'unique_bots' => 4, 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - 1200 ) ),
				array( 'post_id' => 2, 'post_title' => 'AI Citation Tracking — what counts', 'post_type' => 'post', 'url_path' => '/blog/ai-citation-tracking', 'hits' => 61, 'unique_bots' => 3, 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - 4800 ) ),
				array( 'post_id' => 3, 'post_title' => 'PerplexityBot user-agent guide', 'post_type' => 'post', 'url_path' => '/docs/perplexity-bot', 'hits' => 47, 'unique_bots' => 3, 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - 18000 ) ),
				array( 'post_id' => 4, 'post_title' => 'WebMCP manifest specification', 'post_type' => 'page', 'url_path' => '/well-known/mcp', 'hits' => 38, 'unique_bots' => 5, 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - 32400 ) ),
				array( 'post_id' => 5, 'post_title' => 'Pricing & plans', 'post_type' => 'page', 'url_path' => '/pricing', 'hits' => 22, 'unique_bots' => 2, 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ),
				array( 'post_id' => 6, 'post_title' => 'Markdown endpoints — why post.md matters', 'post_type' => 'post', 'url_path' => '/blog/markdown-endpoints', 'hits' => 18, 'unique_bots' => 2, 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - 172800 ) ),
			);
		} else {
			$citation_pages = RNRD_Crawler_Log::get_citation_top_pages( 30, 25 );
		}

		$total_pages_cited = count( $citation_pages );
		$total_hits        = (int) array_sum( array_column( $citation_pages, 'hits' ) );
		$unique_bots       = array_sum( array_column( $citation_pages, 'unique_bots' ) ) > 0
			? (int) max( array_column( $citation_pages, 'unique_bots' ) )
			: 0;
		$max_hits          = $total_pages_cited > 0 ? max( array_column( $citation_pages, 'hits' ) ) : 1;
		$has_data          = $total_pages_cited > 0;

		if ( $demo ) { self::render_demo_banner(); }
		?>
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Citation Candidates', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Pages AI is already citing — the ones already winning.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Pages most fetched by citation-intent bots (ChatGPT-User, OAI-SearchBot, PerplexityBot, Claude-Web, DuckAssistBot) in the last 30 days. Each hit is a live AI answer that retrieved the page as a source. Refresh these first — they are already winning.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'Citation candidates summary', 'rankready-ai-llm-seo' ); ?>">
				<div class="rnrd-kpi" data-intent="citation">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Pages cited', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total_pages_cited ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php
						echo $has_data
							? esc_html__( 'unique URLs fetched by citation bots', 'rankready-ai-llm-seo' )
							: esc_html__( 'First citation typically arrives 2–6 weeks after enabling RankReady', 'rankready-ai-llm-seo' );
					?></div>
				</div>
				<div class="rnrd-kpi" data-intent="citation">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Total citation hits', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total_hits ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'each hit = one AI answer using your content', 'rankready-ai-llm-seo' ); ?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Top page hits', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $has_data ? $max_hits : 0 ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php
						echo $has_data
							? esc_html__( 'on your single best-cited URL', 'rankready-ai-llm-seo' )
							: esc_html__( 'How concentrated your AI traffic is', 'rankready-ai-llm-seo' );
					?></div>
				</div>
			</div>

			<?php if ( ! $has_data ) : ?>
				<p class="rnrd-kpi-empty">
					<?php esc_html_e( 'No citation bot hits yet. ChatGPT-User, OAI-SearchBot, PerplexityBot, Claude-Web, and DuckAssistBot only fetch your pages when a real user asks the AI something your content might answer. Speed it up by adding FAQs to your top 10 posts and keeping content fresh.', 'rankready-ai-llm-seo' ); ?>
				</p>
			<?php else :
				// FREE-100 — pagination: 10 rows per page, GET param `cite_p`.
				$cite_page  = isset( $_GET['cite_p'] ) ? max( 1, (int) $_GET['cite_p'] ) : 1;
				$per_page   = 10;
				$total_rows = count( $citation_pages );
				$pages      = (int) max( 1, ceil( $total_rows / $per_page ) );
				$cite_page  = min( $cite_page, $pages );
				$slice      = array_slice( $citation_pages, ( $cite_page - 1 ) * $per_page, $per_page );
				?>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Top cited pages', 'rankready-ai-llm-seo' ); ?></h3>
				<table class="wp-list-table widefat striped rnrd-bot-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:18%;"><?php esc_html_e( 'Share of hits', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;text-align:right;"><?php esc_html_e( 'Hits', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;text-align:right;"><?php esc_html_e( 'Bots', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:14%;"><?php esc_html_e( 'Last fetched', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:8%;"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $slice as $cp ) :
							$cp_post_id = (int) ( $cp['post_id'] ?? 0 );
							$cp_title   = (string) ( $cp['post_title'] ?? '(no title)' );
							$cp_type    = (string) ( $cp['post_type'] ?? '' );
							$cp_path    = (string) ( $cp['url_path'] ?? '' );
							$cp_hits    = (int) ( $cp['hits'] ?? 0 );
							$cp_bots    = (int) ( $cp['unique_bots'] ?? 0 );
							$cp_seen    = (string) ( $cp['last_seen'] ?? '' );
							$cp_seen_ts = $cp_seen ? strtotime( $cp_seen ) : 0;
							$pct        = $max_hits > 0 ? min( 100, round( ( $cp_hits / $max_hits ) * 100 ) ) : 0;
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $cp_title ); ?></strong>
									<?php if ( $cp_type ) : ?>
										<span class="rnrd-badge rnrd-badge--neutral" style="margin-left:8px;font-size:10px;padding:1px 6px;"><?php echo esc_html( $cp_type ); ?></span>
									<?php endif; ?>
									<div><code><?php echo esc_html( $cp_path ); ?></code></div>
								</td>
								<td>
									<div class="rnrd-bar-track" aria-hidden="true">
										<div class="rnrd-bar-fill rnrd-bar-fill--citation" style="width:<?php echo (int) $pct; ?>%;"></div>
									</div>
								</td>
								<td style="text-align:right;"><strong><?php echo esc_html( number_format_i18n( $cp_hits ) ); ?></strong></td>
								<td style="text-align:right;"><?php echo esc_html( number_format_i18n( $cp_bots ) ); ?></td>
								<td><?php echo $cp_seen_ts ? esc_html( human_time_diff( $cp_seen_ts ) . ' ' . __( 'ago', 'rankready-ai-llm-seo' ) ) : '—'; ?></td>
								<td>
									<?php if ( $cp_post_id > 0 && ! $demo ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $cp_post_id ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'rankready-ai-llm-seo' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php self::render_pagination( $cite_page, $pages, 'cite_p' ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_insights_referral(): void {
		$demo = self::is_demo_mode();

		if ( $demo ) {
			$counts = array(
				'chatgpt'    => 184,
				'perplexity' => 96,
				'claude'     => 41,
				'gemini'     => 28,
				'copilot'    => 12,
			);
		} else {
			$counts = class_exists( 'RNRD_AI_Referral' ) ? RNRD_AI_Referral::aggregate_last_n_days( 30 ) : array();
		}

		$total       = (int) array_sum( $counts );
		$top_source  = '';
		$top_count   = 0;
		foreach ( $counts as $source => $count ) {
			if ( (int) $count > $top_count ) {
				$top_count  = (int) $count;
				$top_source = $source;
			}
		}
		$unique_sources = (int) count( array_filter( $counts ) );
		$max_count      = $total > 0 ? max( $counts ) : 1;
		$source_labels  = array(
			'chatgpt'    => 'ChatGPT',
			'perplexity' => 'Perplexity',
			'gemini'     => 'Gemini',
			'claude'     => 'Claude',
			'copilot'    => 'Copilot',
		);

		if ( $demo ) { self::render_demo_banner(); }
		?>
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Referral Traffic', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Real humans who clicked from an AI answer to your site.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Different from bot activity. This counts visits from chatgpt.com, perplexity.ai, claude.ai, gemini.google.com, and copilot.microsoft.com via the HTTP Referer header — 100% server-side, no third-party scripts, no UTM tagging required.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'AI referral summary', 'rankready-ai-llm-seo' ); ?>">
				<div class="rnrd-kpi" data-intent="citation">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'AI-sourced visits', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php
						echo $total > 0
							? esc_html__( 'across ChatGPT, Perplexity, Claude, Gemini, Copilot', 'rankready-ai-llm-seo' )
							: esc_html__( 'First AI referral typically arrives 2–6 weeks after enabling RankReady', 'rankready-ai-llm-seo' );
					?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Top source', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value">
						<?php echo $total > 0 ? esc_html( $source_labels[ $top_source ] ?? ucfirst( $top_source ) ) : '—'; ?>
					</div>
					<div class="rnrd-kpi__foot"><?php
						if ( $total > 0 ) {
							/* translators: 1: top-source visit count, 2: total visit count */
							echo esc_html( sprintf( __( '%1$s of %2$s referrals', 'rankready-ai-llm-seo' ), number_format_i18n( $top_count ), number_format_i18n( $total ) ) );
						} else {
							esc_html_e( 'Which AI sends the most readers', 'rankready-ai-llm-seo' );
						}
					?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Sources reached', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $unique_sources ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'of 5 tracked AI engines', 'rankready-ai-llm-seo' ); ?></div>
				</div>
			</div>

			<?php if ( $total > 0 ) : ?>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Per-source breakdown', 'rankready-ai-llm-seo' ); ?></h3>
				<table class="wp-list-table widefat striped rnrd-bot-table">
					<thead>
						<tr>
							<th style="width:30%;"><?php esc_html_e( 'AI engine', 'rankready-ai-llm-seo' ); ?></th>
							<th><?php esc_html_e( 'Share of referrals', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:12%;text-align:right;"><?php esc_html_e( 'Visits', 'rankready-ai-llm-seo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $counts as $source => $count ) :
							if ( (int) $count < 1 ) { continue; }
							$pct = $max_count > 0 ? min( 100, round( ( $count / $max_count ) * 100 ) ) : 0;
							?>
							<tr>
								<td><strong><?php echo esc_html( $source_labels[ $source ] ?? ucfirst( $source ) ); ?></strong></td>
								<td>
									<div class="rnrd-bar-track" aria-hidden="true">
										<div class="rnrd-bar-fill rnrd-bar-fill--citation" style="width:<?php echo (int) $pct; ?>%;"></div>
									</div>
								</td>
								<td style="text-align:right;"><strong><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="rnrd-kpi-empty">
					<?php esc_html_e( 'No AI-sourced visits yet. Tracking is already live — counters fill in as ChatGPT, Perplexity, Claude, Gemini, or Copilot send their first visitor through to your site.', 'rankready-ai-llm-seo' ); ?>
				</p>
			<?php endif; ?>

			<details style="margin-top:16px;">
				<summary><?php esc_html_e( 'How is this different from Bot Activity?', 'rankready-ai-llm-seo' ); ?></summary>
				<p style="margin:10px 0 0;">
					<strong><?php esc_html_e( 'Bot Activity', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'measures AI crawlers reading your site (no human involved) — it answers "is RankReady in the AI retrieval pool?"', 'rankready-ai-llm-seo' ); ?>
				</p>
				<p style="margin:6px 0 0;">
					<strong><?php esc_html_e( 'AI Referral Traffic', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'measures real humans who read an AI answer and clicked through — it answers "is AI sending users to my site?"', 'rankready-ai-llm-seo' ); ?>
				</p>
			</details>
		</div>
		<?php
	}

	private static function render_insights_freshness(): void {
		// Merged in rc.16 — single card carries the intro, scan tool, and
		// the segmented widget (render_card_freshness_alerts now renders
		// RNRD_Freshness::render_widget() inside its closing </div>).
		self::render_card_freshness_alerts();
	}

	private static function render_tab_advanced(): void {
		// v1.2.0-rc.5 — Advanced tab simplified.
		// REMOVED: Headless / Public API section (Dashboard explains plugin scope).
		// REMOVED: How It Works + Quick Stats cards (Dashboard already shows tab purposes).
		// REPLACED: Health Check card → new live 22-probe Diagnostics card.
		// KEPT IN PLACE FOR rc.5: Bulk Summary, Bulk FAQ, Bulk Author, API Usage,
		// Freshness Alerts — these will relocate to their proper tabs in rc.6
		// alongside the card-merge refactor (preserving form field names + data).
		?>
		<?php self::render_tab_intro(
			__( 'Advanced', 'rankready-ai-llm-seo' ),
			__( 'Verify everything works — and see what it cost.', 'rankready-ai-llm-seo' ),
			__( 'Live probes confirm llms.txt, robots.txt, .md routes, and the WebMCP manifest are reachable from the open web. Detects cache, page-builder, and SEO-plugin conflicts. Tracks token spend per provider.', 'rankready-ai-llm-seo' )
		); ?>
		<?php self::render_tab_tools(); ?>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: API Keys
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_api(): void {
		$active_provider = RNRD_LLM::get_active_provider();

		// Helper closure to mask saved keys for display.
		$mask = static function( $val ) {
			return ! empty( $val ) ? substr( (string) $val, 0, 7 ) . str_repeat( '••••', 6 ) : '';
		};

		$openai_disp    = $mask( get_option( RNRD_OPT_KEY, '' ) );
		$anthropic_disp = $mask( get_option( RNRD_OPT_ANTHROPIC_KEY, '' ) );
		$gemini_disp    = $mask( get_option( RNRD_OPT_GEMINI_KEY, '' ) );
		$deepseek_disp  = $mask( get_option( RNRD_OPT_DEEPSEEK_KEY, '' ) );
		?>
		<?php settings_errors(); ?>
		<?php self::render_tab_intro(
			__( 'Settings', 'rankready-ai-llm-seo' ),
			__( 'Pick your AI provider and drop in the API key.', 'rankready-ai-llm-seo' ),
			__( 'Choose ChatGPT, Claude, Gemini, or DeepSeek to power Summary and FAQ generation. Only the active provider needs a key. Add DataForSEO credentials too if you want the FAQ Generator to discover real user questions.', 'rankready-ai-llm-seo' )
		); ?>

		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::SETTINGS_GROUP ); ?>

			<!-- LLM Provider Picker -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Pick the LLM that powers Summary + FAQ — then drop in its API key.', 'rankready-ai-llm-seo' ); ?></p>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Switch any time. Only the selected provider needs an API key — the others stay dormant.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Active provider', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<fieldset id="rnrd-llm-provider-picker" class="rnrd-radio-list">
								<?php
								$providers = array(
									'openai'    => array( 'OpenAI',    __( 'GPT-4o, GPT-4o mini', 'rankready-ai-llm-seo' ) ),
									'anthropic' => array( 'Claude',    __( 'Haiku 4.5, Sonnet 4.6, Opus 4.7', 'rankready-ai-llm-seo' ) ),
									'gemini'    => array( 'Gemini',    __( 'Gemini 2.5 Flash, 2.5 Pro', 'rankready-ai-llm-seo' ) ),
									'deepseek'  => array( 'DeepSeek',  __( 'V4 Flash, V4 Pro', 'rankready-ai-llm-seo' ) ),
								);
								foreach ( $providers as $id => $info ) :
									?>
									<label class="rnrd-radio-list__item">
										<input type="radio" name="<?php echo esc_attr( RNRD_OPT_LLM_PROVIDER ); ?>" value="<?php echo esc_attr( $id ); ?>" <?php checked( $active_provider, $id ); ?> data-rnrd-provider-radio />
										<span class="rnrd-radio-list__label">
											<strong><?php echo esc_html( $info[0] ); ?></strong>
											<span class="rnrd-radio-list__meta">— <?php echo esc_html( $info[1] ); ?></span>
										</span>
									</label>
									<?php
								endforeach;
								?>
							</fieldset>
						</td>
					</tr>
				</table>

				<?php
				// Helper for the per-provider verify button row.
				$render_verify_row = function ( $provider_id ) {
					?>
					<div class="rnrd-verify-row">
						<button type="button" class="button button-secondary" data-rnrd-verify-provider="<?php echo esc_attr( $provider_id ); ?>">
							<?php esc_html_e( 'Verify Key', 'rankready-ai-llm-seo' ); ?>
						</button>
						<span class="rnrd-verify-row__status" data-rnrd-verify-status="<?php echo esc_attr( $provider_id ); ?>"></span>
					</div>
					<?php
				};
				?>

			<!-- OpenAI -->
			<div class="rnrd-provider-card-inner" data-rnrd-provider="openai" <?php echo 'openai' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'OpenAI', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Powers AI Summary generation and FAQ answer writing.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_api_key"><?php esc_html_e( 'API Key', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="password" id="rnrd_api_key" name="<?php echo esc_attr( RNRD_OPT_KEY ); ?>"
								   value="<?php echo esc_attr( $openai_disp ); ?>" class="regular-text"
								   autocomplete="new-password" spellcheck="false"
								   data-rnrd-key-for="openai" />
							<?php $render_verify_row( 'openai' ); ?>
							<p class="description"><?php esc_html_e( 'Your OpenAI secret key (sk-...). Stored server-side only. Get one at platform.openai.com/api-keys.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_model"><?php esc_html_e( 'Model', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RNRD_OPT_MODEL ); ?>" id="rnrd_model">
								<?php $current_model = (string) get_option( RNRD_OPT_MODEL, 'gpt-4o-mini' ); ?>
								<?php foreach ( RNRD_LLM::get_models_for( 'openai' ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_model, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'gpt-4o-mini is the cheapest and recommended for most sites.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Anthropic (Claude) -->
			<div class="rnrd-provider-card-inner" data-rnrd-provider="anthropic" <?php echo 'anthropic' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Claude (Anthropic)', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Claude is exceptional at following content rules and producing factual, citation-quality output for AI summaries and FAQs.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_anthropic_key"><?php esc_html_e( 'API Key', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="password" id="rnrd_anthropic_key" name="<?php echo esc_attr( RNRD_OPT_ANTHROPIC_KEY ); ?>"
								   value="<?php echo esc_attr( $anthropic_disp ); ?>" class="regular-text"
								   autocomplete="new-password" spellcheck="false"
								   data-rnrd-key-for="anthropic" />
							<?php $render_verify_row( 'anthropic' ); ?>
							<p class="description"><?php esc_html_e( 'Your Anthropic API key (sk-ant-...). Get one at console.anthropic.com.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_anthropic_model"><?php esc_html_e( 'Model', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RNRD_OPT_ANTHROPIC_MODEL ); ?>" id="rnrd_anthropic_model">
								<?php $cur = (string) get_option( RNRD_OPT_ANTHROPIC_MODEL, 'claude-haiku-4-5' ); ?>
								<?php foreach ( RNRD_LLM::get_models_for( 'anthropic' ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $cur, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Haiku 4.5 is the cheapest and fastest. Sonnet 4.6 is the balanced pick. Opus 4.7 is highest quality (most expensive).', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Gemini -->
			<div class="rnrd-provider-card-inner" data-rnrd-provider="gemini" <?php echo 'gemini' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Gemini (Google)', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Gemini is the cheapest of the major providers and ships native JSON output. Great default for high-volume sites.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_gemini_key"><?php esc_html_e( 'API Key', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="password" id="rnrd_gemini_key" name="<?php echo esc_attr( RNRD_OPT_GEMINI_KEY ); ?>"
								   value="<?php echo esc_attr( $gemini_disp ); ?>" class="regular-text"
								   autocomplete="new-password" spellcheck="false"
								   data-rnrd-key-for="gemini" />
							<?php $render_verify_row( 'gemini' ); ?>
							<p class="description"><?php esc_html_e( 'Your Google AI Studio API key (AIza...). Get one at aistudio.google.com/apikey.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_gemini_model"><?php esc_html_e( 'Model', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RNRD_OPT_GEMINI_MODEL ); ?>" id="rnrd_gemini_model">
								<?php $cur = (string) get_option( RNRD_OPT_GEMINI_MODEL, 'gemini-2.5-flash' ); ?>
								<?php foreach ( RNRD_LLM::get_models_for( 'gemini' ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $cur, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Gemini 2.5 Flash is recommended for both summaries and FAQ.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- DeepSeek -->
			<div class="rnrd-provider-card-inner" data-rnrd-provider="deepseek" <?php echo 'deepseek' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'DeepSeek', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Cost-efficient open-source models. V4 Flash for everyday generation, V4 Pro when you need higher quality. (The legacy `deepseek-chat` and `deepseek-reasoner` aliases are being retired by DeepSeek — switch to V4 IDs.)', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_deepseek_key"><?php esc_html_e( 'API Key', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="password" id="rnrd_deepseek_key" name="<?php echo esc_attr( RNRD_OPT_DEEPSEEK_KEY ); ?>"
								   value="<?php echo esc_attr( $deepseek_disp ); ?>" class="regular-text"
								   autocomplete="new-password" spellcheck="false"
								   data-rnrd-key-for="deepseek" />
							<?php $render_verify_row( 'deepseek' ); ?>
							<p class="description"><?php esc_html_e( 'Your DeepSeek API key (sk-...). Get one at platform.deepseek.com/api_keys.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_deepseek_model"><?php esc_html_e( 'Model', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RNRD_OPT_DEEPSEEK_MODEL ); ?>" id="rnrd_deepseek_model">
								<?php $cur = (string) get_option( RNRD_OPT_DEEPSEEK_MODEL, 'deepseek-v4-flash' ); ?>
								<?php foreach ( RNRD_LLM::get_models_for( 'deepseek' ) as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $cur, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
			</div>
				<?php submit_button( __( 'Save AI', 'rankready-ai-llm-seo' ), 'primary', 'submit_ai', false ); ?>
			</div><!-- /.rnrd-card "AI" (merged: Provider picker + Provider config) -->

			<?php // Provider visibility toggler moved into assets/admin.js (rc.16, WP.org Rule #3 — no inline <script> in PHP). ?>

			<!-- Product Context — REMOVED in rc.3.
			     The "About" field on the Brand Identity card (AI Crawlers tab) now serves
			     this purpose. RNRD_Generator + RNRD_Faq inject Brand Identity About into the
			     AI prompts, so admins only fill in one place. -->

			<!-- DataForSEO -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'DataForSEO', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Question discovery for FAQ generation. Optional — only needed if FAQ is on.', 'rankready-ai-llm-seo' ); ?></p>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Powers FAQ question discovery via keyword suggestions and related keywords. Sign up at dataforseo.com.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_dfs_login"><?php esc_html_e( 'API Login', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="text" id="rnrd_dfs_login" name="<?php echo esc_attr( RNRD_OPT_DFS_LOGIN ); ?>"
							       value="<?php echo esc_attr( (string) get_option( RNRD_OPT_DFS_LOGIN, '' ) ); ?>"
							       class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Your DataForSEO API login email.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_dfs_password"><?php esc_html_e( 'API Password', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $dfs_pw = (string) get_option( RNRD_OPT_DFS_PASSWORD, '' ); ?>
							<?php $dfs_pw_display = ! empty( $dfs_pw ) ? str_repeat( '••••', 4 ) : ''; ?>
							<input type="password" id="rnrd_dfs_password" name="<?php echo esc_attr( RNRD_OPT_DFS_PASSWORD ); ?>"
							       value="<?php echo esc_attr( $dfs_pw_display ); ?>"
							       class="regular-text" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Your DataForSEO API password. Enter a new value to change.', 'rankready-ai-llm-seo' ); ?></p>
							<div class="rnrd-verify-row">
								<button type="button" id="rnrd-verify-dfs" class="button button-secondary">
									<?php esc_html_e( 'Verify DataForSEO', 'rankready-ai-llm-seo' ); ?>
								</button>
								<span id="rnrd-verify-dfs-status" class="rnrd-verify-row__status"></span>
							</div>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save DataForSEO', 'rankready-ai-llm-seo' ), 'primary', 'submit_dfs', false ); ?>
			</div>

			<!-- Data Retention card — MOVED to Advanced tab in rc.3 (renders inside render_tab_advanced). -->
		</form>

		<!-- Connection Status card removed in rc.16 — was duplicate of state
		     already shown inside the AI card (Verify Key buttons + provider
		     selector). User opted to keep only the actionable form. -->

		<?php
		// ── API Usage card (relocated from Advanced tab in rc.6) ───────────
		// Read-only stats panel — no settings form needed. All option keys
		// (rnrd_token_usage, rnrd_dfs_usage) and JS hooks (#rr-tokens-load,
		// #rr-tokens-tbody, etc.) preserved verbatim from rc.5.
		self::render_card_api_usage();
		?>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: AI Summary
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_summary(): void {
		?>
			<!-- Merged in rc.6: single "AI Summary" card containing two H3 subsections
			     (Generation + Display). All form-field names preserved verbatim. -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'AI Summary', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Auto-generate Key Takeaways for every post — the lines ChatGPT and Perplexity quote directly.', 'rankready-ai-llm-seo' ); ?></p>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Configure which posts get AI summaries, how they are generated, and how they appear on the frontend.', 'rankready-ai-llm-seo' ); ?></p>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Generation', 'rankready-ai-llm-seo' ); ?></h3>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Post Types', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $selected_types = (array) get_option( RNRD_OPT_POST_TYPES, array( 'post' ) ); ?>
							<div class="rnrd-checkboxes-inline">
								<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_POST_TYPES ); ?>[]"
											   value="<?php echo esc_attr( $slug ); ?>"
											   <?php checked( in_array( $slug, $selected_types, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Summaries will only auto-generate for selected post types.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_custom_prompt"><?php esc_html_e( 'Custom Prompt', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( RNRD_OPT_CUSTOM_PROMPT ); ?>" id="rnrd_custom_prompt"
									  rows="4" class="large-text"
									  placeholder="<?php esc_attr_e( 'Leave empty to use the default optimized prompt.', 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( (string) get_option( RNRD_OPT_CUSTOM_PROMPT, '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional. Extra instructions appended to the AI prompt.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<?php
					$is_auto_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();
					$auto_gen    = (string) get_option( RNRD_OPT_AUTO_GENERATE, 'off' );
					?>
					<tr<?php echo $is_auto_pro ? '' : ' class="rnrd-row-locked"'; ?>>
						<th scope="row"><?php esc_html_e( 'Auto-Generate on Publish', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php if ( $is_auto_pro ) : ?>
								<label>
									<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_AUTO_GENERATE ); ?>" value="off" />
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AUTO_GENERATE ); ?>" value="on" <?php checked( $auto_gen, 'on' ); ?> />
									<?php esc_html_e( 'Automatically generate Key Takeaways when a post is published or updated', 'rankready-ai-llm-seo' ); ?>
								</label>
							<?php else : ?>
								<label style="display:inline-flex;align-items:center;gap:8px;color:#1d2327;">
									<?php echo wp_kses_post( self::locked_checkbox_indicator() ); ?>
									<?php esc_html_e( 'Automatically generate Key Takeaways when a post is published or updated', 'rankready-ai-llm-seo' ); ?>
									<span class="rnrd-soon-badge"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span>
								</label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Off by default. When off, summaries are only generated via the Regenerate button, Gutenberg block, or Bulk Generate.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Display', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Control how AI Summaries appear on the frontend. Can also use the Gutenberg block or Elementor widget instead.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto Display', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $auto_display = (string) get_option( RNRD_OPT_AUTO_DISPLAY, 'off' ); ?>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_AUTO_DISPLAY ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AUTO_DISPLAY ); ?>" value="on" <?php checked( $auto_display, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Automatically inject summary into post content', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Off = show only via Gutenberg block, Elementor widget, or shortcode.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Position', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $display_pos = (string) get_option( RNRD_OPT_DISPLAY_POSITION, 'before' ); ?>
							<select name="<?php echo esc_attr( RNRD_OPT_DISPLAY_POSITION ); ?>">
								<option value="before" <?php selected( $display_pos, 'before' ); ?>><?php esc_html_e( 'Before content', 'rankready-ai-llm-seo' ); ?></option>
								<option value="after"  <?php selected( $display_pos, 'after' ); ?>><?php esc_html_e( 'After content', 'rankready-ai-llm-seo' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Heading Tag', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $current_tag = (string) get_option( RNRD_OPT_HEADING_TAG, 'h4' ); ?>
							<select name="<?php echo esc_attr( RNRD_OPT_HEADING_TAG ); ?>">
								<?php foreach ( array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6', 'p' => 'P' ) as $tag => $label ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $current_tag, $tag ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show Label', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SHOW_LABEL ); ?>" value="1"
									   <?php checked( get_option( RNRD_OPT_SHOW_LABEL, '1' ), '1' ); ?> />
								<?php esc_html_e( 'Show the label heading above the summary bullets', 'rankready-ai-llm-seo' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Label Text', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="text" name="<?php echo esc_attr( RNRD_OPT_LABEL ); ?>"
								   value="<?php echo esc_attr( (string) get_option( RNRD_OPT_LABEL, 'Key Takeaways' ) ); ?>"
								   class="regular-text" />
							<p class="description"><?php esc_html_e( 'e.g. "Key Takeaways", "Article Summary", "TL;DR"', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save AI Summary Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_summary', false ); ?>
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
		$enable        = (string) get_option( RNRD_OPT_AUTHOR_ENABLE, 'on' );
		$auto_display  = (string) get_option( RNRD_OPT_AUTHOR_AUTO_DISPLAY, 'off' );
		$layout        = (string) get_option( RNRD_OPT_AUTHOR_LAYOUT, 'card' );
		$heading       = (string) get_option( RNRD_OPT_AUTHOR_HEADING, 'About the Author' );
		$heading_tag   = (string) get_option( RNRD_OPT_AUTHOR_HEADING_TAG, 'h3' );
		$schema_enable = (string) get_option( RNRD_OPT_AUTHOR_SCHEMA_ENABLE, 'on' );
		$editorial     = (string) get_option( RNRD_OPT_AUTHOR_EDITORIAL_URL, '' );
		$factcheck     = (string) get_option( RNRD_OPT_AUTHOR_FACTCHECK_URL, '' );
		$post_types    = (array) get_option( RNRD_OPT_AUTHOR_POST_TYPES, array( 'post' ) );
		$trust_enable  = (string) get_option( RNRD_OPT_AUTHOR_TRUST_ENABLE, 'off' );

		$has_rankmath = defined( 'RANK_MATH_VERSION' );
		$has_yoast    = defined( 'WPSEO_VERSION' );
		$has_aioseo   = defined( 'AIOSEO_VERSION' );
		$seo_plugin   = $has_rankmath ? 'Rank Math' : ( $has_yoast ? 'Yoast SEO' : ( $has_aioseo ? 'AIOSEO' : '' ) );

		$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
			<!-- Merged in rc.6: single "Author Box (E-E-A-T)" card with 4 H3 subsections.
			     Intro card prose → .rnrd-card-desc. All option keys preserved verbatim. -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Author Box (E-E-A-T)', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Show the real people behind your content — the trust check ChatGPT, Claude, and Perplexity run before citing you.', 'rankready-ai-llm-seo' ); ?></p>
				<?php if ( $seo_plugin ) : ?>
					<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin-top:12px;">
						<strong><?php echo esc_html( $seo_plugin ); ?></strong> <?php esc_html_e( 'is active. RankReady will not emit a duplicate Person node. Instead, it enhances the existing Person schema in', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'with RankReady data via the plugin\'s filter hooks. Zero conflict.', 'rankready-ai-llm-seo' ); ?>
					</div>
				<?php endif; ?>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'General', 'rankready-ai-llm-seo' ); ?></h3>
				<table class="form-table rnrd-form-table">
					<tr>
						<th><?php esc_html_e( 'Enable Author Box', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AUTHOR_ENABLE ); ?>" value="on" <?php checked( $enable, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Master toggle for the Author Box feature (block, Elementor widget, schema, auto-display).', 'rankready-ai-llm-seo' ); ?></span>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="rnrd_author_auto_display"><?php esc_html_e( 'Auto-display', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RNRD_OPT_AUTHOR_AUTO_DISPLAY ); ?>" id="rnrd_author_auto_display">
								<option value="off"    <?php selected( $auto_display, 'off' ); ?>><?php esc_html_e( 'Off — use block/widget only', 'rankready-ai-llm-seo' ); ?></option>
								<option value="before" <?php selected( $auto_display, 'before' ); ?>><?php esc_html_e( 'Before content', 'rankready-ai-llm-seo' ); ?></option>
								<option value="after"  <?php selected( $auto_display, 'after' ); ?>><?php esc_html_e( 'After content', 'rankready-ai-llm-seo' ); ?></option>
								<option value="both"   <?php selected( $auto_display, 'both' ); ?>><?php esc_html_e( 'Both (above and below)', 'rankready-ai-llm-seo' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Append the author box automatically on singular pages. Skipped when the Author Box block/Elementor widget is already in the content.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Post Types', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php foreach ( $all_post_types as $pt ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AUTHOR_POST_TYPES ); ?>[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $post_types, true ) ); ?> />
									<?php echo esc_html( $pt->labels->singular_name ); ?> <code><?php echo esc_html( $pt->name ); ?></code>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Post types where auto-display is allowed and the per-post "Author Trust" panel appears.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rnrd_author_layout"><?php esc_html_e( 'Default Layout', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RNRD_OPT_AUTHOR_LAYOUT ); ?>" id="rnrd_author_layout">
								<option value="card"    <?php selected( $layout, 'card' ); ?>><?php esc_html_e( 'Card (full end-of-article box)', 'rankready-ai-llm-seo' ); ?></option>
								<option value="compact" <?php selected( $layout, 'compact' ); ?>><?php esc_html_e( 'Compact (small, sidebar-friendly)', 'rankready-ai-llm-seo' ); ?></option>
								<option value="inline"  <?php selected( $layout, 'inline' ); ?>><?php esc_html_e( 'Inline byline (headline-style)', 'rankready-ai-llm-seo' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Default layout for auto-display and new blocks/widgets. Individual blocks/widgets can override this.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rnrd_author_heading"><?php esc_html_e( 'Default Heading', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="text" name="<?php echo esc_attr( RNRD_OPT_AUTHOR_HEADING ); ?>" id="rnrd_author_heading" value="<?php echo esc_attr( $heading ); ?>" class="regular-text" />
							<select name="<?php echo esc_attr( RNRD_OPT_AUTHOR_HEADING_TAG ); ?>">
								<?php foreach ( array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ) as $tag ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $heading_tag, $tag ); ?>><?php echo esc_html( strtoupper( $tag ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Heading text shown above the box in Card and Compact layouts. Individual blocks/widgets can override.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<?php
				$_author_is_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();
				if ( $_author_is_pro ) :
				?>
				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Schema', 'rankready-ai-llm-seo' ); ?></h3>
				<table class="form-table rnrd-form-table">
					<tr>
						<th><?php esc_html_e( 'Emit Person Schema', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AUTHOR_SCHEMA_ENABLE ); ?>" value="on" <?php checked( $schema_enable, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Include RankReady Person data (sameAs, knowsAbout, credentials, memberOf, awards) in schema output.', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description">
								<?php esc_html_e( 'When no SEO plugin is active, RankReady emits a standalone Person node on author archives (via wp_head — no visible page changes) and inline in Article.author on posts. When an SEO plugin is active, RankReady merges its Person fields into the plugin\'s existing schema graph.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="rnrd_author_editorial_url"><?php esc_html_e( 'Editorial Policy URL', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="url" name="<?php echo esc_attr( RNRD_OPT_AUTHOR_EDITORIAL_URL ); ?>" id="rnrd_author_editorial_url" value="<?php echo esc_attr( $editorial ); ?>" class="regular-text" placeholder="https://" />
							<p class="description"><?php esc_html_e( 'Site-wide editorial standards page. Emits as Person.publishingPrinciples on every author. Also shown as a footer link in the Card layout.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rnrd_author_factcheck_url"><?php esc_html_e( 'Fact-Check Policy URL', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="url" name="<?php echo esc_attr( RNRD_OPT_AUTHOR_FACTCHECK_URL ); ?>" id="rnrd_author_factcheck_url" value="<?php echo esc_attr( $factcheck ); ?>" class="regular-text" placeholder="https://" />
							<p class="description"><?php esc_html_e( 'Optional "How we fact-check" page URL. Shown as a footer link in the Card layout. Purely UI — not in schema.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Trust Panel (optional)', 'rankready-ai-llm-seo' ); ?></h3>
				<table class="form-table rnrd-form-table">
					<tr>
						<th><?php esc_html_e( 'Enable Trust Panel', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_AUTHOR_TRUST_ENABLE ); ?>" value="on" <?php checked( $trust_enable, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Add per-post "Fact-checked by", "Reviewed by", and "Last reviewed" fields to the post editor.', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default. Only enable if you have a formal editorial process where a second person fact-checks or medically/legally reviews posts. When on, these fields emit as Article.reviewedBy[] and Article.lastReviewed — the Healthline / WebMD EEAT pattern. When off, the fields are not registered, do not appear in the block editor, and RankReady emits zero reviewer schema. Leave it off for regular blogs and documentation sites that do not need a separate reviewer.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php else : ?>
				<?php
					self::render_pro_gate(
						__( 'Person Schema (EEAT)', 'rankready-ai-llm-seo' ),
						__( 'Emit Person JSON-LD with sameAs, knowsAbout, credentials, memberOf, and awards — the schema fields AI systems use to verify authorship and increase citation probability.', 'rankready-ai-llm-seo' )
					);
					self::render_pro_gate(
						__( 'Editorial & Fact-Check Policy URLs', 'rankready-ai-llm-seo' ),
						__( 'Link your editorial standards and fact-check policy pages into the schema graph. The Healthline / WebMD EEAT pattern — signals editorial integrity to Google and LLMs.', 'rankready-ai-llm-seo' )
					);
					self::render_pro_gate(
						__( 'Author Trust Panel (Reviewed By)', 'rankready-ai-llm-seo' ),
						__( 'Add "Fact-checked by" and "Reviewed by" fields to every post editor. Emits as Article.reviewedBy[] and Article.lastReviewed — the full medical/legal EEAT pattern.', 'rankready-ai-llm-seo' )
					);
				?>
				<?php endif; ?>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'How to use', 'rankready-ai-llm-seo' ); ?></h3>
				<ol style="margin-left:18px;">
					<li><?php esc_html_e( 'Go to Users → your profile and fill in the "RankReady Author Box" section — Bio, headshot, job title, and year started are free.', 'rankready-ai-llm-seo' ); ?></li>
					<li><?php esc_html_e( 'Add the "RankReady Author Box" Gutenberg block to posts, or use the Elementor widget, or enable auto-display above.', 'rankready-ai-llm-seo' ); ?></li>
					<?php if ( $_author_is_pro ) : ?>
					<li><?php esc_html_e( 'Fill in Credentials, Verified Identity (Wikidata, ORCID), and Social links to emit a full Person schema graph.', 'rankready-ai-llm-seo' ); ?></li>
					<li><?php esc_html_e( 'Enable the Author Trust Panel above if you have a formal fact-checker / reviewer workflow.', 'rankready-ai-llm-seo' ); ?></li>
					<li><?php esc_html_e( 'Verify schema output with Google\'s Rich Results Test — Person node appears in the graph.', 'rankready-ai-llm-seo' ); ?></li>
					<?php endif; ?>
				</ol>
				<?php submit_button( __( 'Save Author Box Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_author', false ); ?>
			</div>

		<?php
	}

	private static function render_tab_schema(): void {
		$is_pro    = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();
		$article   = (string) get_option( RNRD_OPT_SCHEMA_ARTICLE, 'on' );
		$faq       = (string) get_option( RNRD_OPT_SCHEMA_FAQ, 'on' );
		$howto     = (string) get_option( RNRD_OPT_SCHEMA_HOWTO, 'on' );
		$itemlist  = (string) get_option( RNRD_OPT_SCHEMA_ITEMLIST, 'on' );
		$speakable = (string) get_option( RNRD_OPT_SCHEMA_SPEAKABLE, 'on' );

		// Detect active SEO plugins.
		// Detect SEO plugin (expanded list per rc.7 — also covers SEOPress + TSF).
		$has_rankmath  = defined( 'RANK_MATH_VERSION' );
		$has_yoast     = defined( 'WPSEO_VERSION' );
		$has_aioseo    = defined( 'AIOSEO_VERSION' );
		$has_seopress  = ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) );
		$has_tsf       = defined( 'THE_SEO_FRAMEWORK_VERSION' );
		$seo_plugin    = '';
		if ( $has_rankmath )     $seo_plugin = 'Rank Math';
		elseif ( $has_yoast )    $seo_plugin = 'Yoast SEO';
		elseif ( $has_aioseo )   $seo_plugin = 'All in One SEO';
		elseif ( $has_seopress ) $seo_plugin = 'SEOPress';
		elseif ( $has_tsf )      $seo_plugin = 'The SEO Framework';
		?>
			<!-- SEO Plugin Detection -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'SEO Plugin Compatibility', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'RankReady detects your active SEO plugin and merges schema — never duplicate tags.', 'rankready-ai-llm-seo' ); ?></p>

				<?php
				// v1.2.0-rc.7 — Inline 1-liner replacing the deleted "How Schema
				// Works" card. Stays in DOM regardless of plugin presence so the
				// user always sees what's happening.
				if ( $seo_plugin ) {
					printf(
						'<p class="rnrd-info-callout"><span class="dashicons dashicons-info"></span> %s</p>',
						esc_html( sprintf(
							/* translators: %s: detected SEO plugin name */
							__( '%s detected — RankReady merges schema into its graph. No duplicate tags.', 'rankready-ai-llm-seo' ),
							$seo_plugin
						) )
					);
				} else {
					echo '<p class="rnrd-info-callout"><span class="dashicons dashicons-info"></span> '
						. esc_html__( 'No SEO plugin detected — RankReady emits standalone Article + Speakable schema.', 'rankready-ai-llm-seo' )
						. '</p>';
				}
				?>
				<?php if ( ! empty( $seo_plugin ) ) : ?>
					<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin-bottom:16px;">
						<strong><?php echo esc_html( $seo_plugin ); ?></strong> <?php esc_html_e( 'is active.', 'rankready-ai-llm-seo' ); ?>
						<?php esc_html_e( 'RankReady automatically adjusts schema output to avoid duplicates:', 'rankready-ai-llm-seo' ); ?>
						<ul style="margin:8px 0 0 20px;list-style:disc;">
							<li><?php esc_html_e( 'Article schema — Handled by', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?>. <?php esc_html_e( 'RankReady skips it automatically.', 'rankready-ai-llm-seo' ); ?></li>
							<li><?php esc_html_e( 'FAQPage schema — RankReady injects only when no', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'FAQ block exists in the post.', 'rankready-ai-llm-seo' ); ?></li>
							<li><?php esc_html_e( 'HowTo schema — RankReady injects only when no', 'rankready-ai-llm-seo' ); ?> <?php echo esc_html( $seo_plugin ); ?> <?php esc_html_e( 'HowTo block exists in the post.', 'rankready-ai-llm-seo' ); ?></li>
							<li><?php esc_html_e( 'ItemList schema — Always handled by RankReady (no SEO plugin does this).', 'rankready-ai-llm-seo' ); ?></li>
						</ul>
					</div>
				<?php else : ?>
					<div style="background:#fcf9e8;border-left:4px solid #dba617;padding:12px 16px;margin-bottom:16px;">
						<?php esc_html_e( 'No SEO plugin detected. RankReady will handle all schema types (Article, FAQ, HowTo, ItemList).', 'rankready-ai-llm-seo' ); ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Schema Toggles -->
			<?php
			// v1.2.0-rc.7 — "All-off" master gate: if every schema toggle is OFF
			// the card body becomes a locked preview prompting the user to
			// enable at least Article. Otherwise the full schema-types table
			// renders unchanged.
			$rnrd_any_schema_on = ( 'on' === $article )
				|| ( 'on' === $faq )
				|| ( 'on' === $howto )
				|| ( 'on' === $itemlist )
				|| ( 'on' === $speakable );
			?>
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Schema Types', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Auto-emit Article + Speakable + HowTo + ItemList JSON-LD. No manual schema work.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">

					<!-- Article + Speakable -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Article Schema', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SCHEMA_ARTICLE ); ?>"
									   value="on" <?php checked( $article, 'on' ); ?>
									   <?php echo ! empty( $seo_plugin ) ? 'disabled' : ''; ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Article/BlogPosting JSON-LD with author, publisher, dateModified', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<?php if ( ! empty( $seo_plugin ) ) : ?>
								<p class="description" style="margin-top:4px;color:#666;">
									<?php
									/* translators: %s: detected SEO plugin name */
									echo esc_html( sprintf( __( 'Disabled — %s handles Article schema.', 'rankready-ai-llm-seo' ), $seo_plugin ) );
									?>
								</p>
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_SCHEMA_ARTICLE ); ?>" value="on" />
							<?php else : ?>
								<p class="description" style="margin-top:4px;">
									<?php esc_html_e( 'Injects Article JSON-LD on all published posts/pages. Includes headline, author, publisher, image, description, about (categories), mentions (tags).', 'rankready-ai-llm-seo' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<!-- Speakable -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Speakable', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SCHEMA_SPEAKABLE ); ?>"
									   value="on" <?php checked( $speakable, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Add speakable markup for voice search and AI assistants', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'Marks the title and excerpt as speakable content. Helps Google Assistant, Alexa, and AI voice queries read your content aloud. Works with both RankReady and SEO plugin Article schema.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>

					<!-- FAQPage -->
					<tr>
						<th scope="row"><?php esc_html_e( 'FAQPage Schema', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SCHEMA_FAQ ); ?>"
									   value="on" <?php checked( $faq, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Inject FAQPage JSON-LD when FAQ data exists', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'When RankReady FAQ data exists for a post, FAQPage schema is injected automatically. Pages with FAQPage schema are 3.2x more likely to appear in AI Overviews.', 'rankready-ai-llm-seo' ); ?>
							</p>
							<?php if ( ! empty( $seo_plugin ) ) : ?>
								<p class="description" style="color:#666;">
									<?php
									/* translators: %s: detected SEO plugin name */
									echo esc_html( sprintf( __( 'Auto-skips when a %s FAQ block is present in the post content to prevent duplicates.', 'rankready-ai-llm-seo' ), $seo_plugin ) );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<!-- HowTo — Pro only -->
					<?php if ( $is_pro ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'HowTo Schema', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SCHEMA_HOWTO ); ?>"
									   value="on" <?php checked( $howto, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Auto-detect step-by-step content and inject HowTo JSON-LD', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'Scans posts with "How to", "Tutorial", "Step by Step", or "Guide to" in the title. Extracts steps from your existing headings (Step 1, Step 2...) or ordered lists. No content changes needed.', 'rankready-ai-llm-seo' ); ?>
							</p>
							<details style="margin-top:8px;">
								<summary style="cursor:pointer;color:#2271b1;font-weight:500;"><?php esc_html_e( 'How detection works', 'rankready-ai-llm-seo' ); ?></summary>
								<div style="margin-top:8px;padding:12px;background:#f9f9f9;border-radius:4px;">
									<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Title must contain one of:', 'rankready-ai-llm-seo' ); ?></strong> "how to", "how-to", "step by step", "step-by-step", "tutorial", "guide to"</p>
									<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Steps detected from (in priority order):', 'rankready-ai-llm-seo' ); ?></strong></p>
									<ol style="margin:0 0 8px 20px;">
										<li><?php esc_html_e( 'Headings with "Step N" — e.g., <h2>Step 1: Install the Plugin</h2>', 'rankready-ai-llm-seo' ); ?></li>
										<li><?php esc_html_e( 'Numbered headings — e.g., <h2>1. Install the Plugin</h2>', 'rankready-ai-llm-seo' ); ?></li>
										<li><?php esc_html_e( 'Ordered lists — <ol><li>Install the Plugin</li></ol>', 'rankready-ai-llm-seo' ); ?></li>
									</ol>
									<p style="margin:0;"><strong><?php esc_html_e( 'Minimum:', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( '2 steps required. Extracts step name, description, and images automatically.', 'rankready-ai-llm-seo' ); ?></p>
								</div>
							</details>
							<?php if ( ! empty( $seo_plugin ) ) : ?>
								<p class="description" style="margin-top:4px;color:#666;">
									<?php
									/* translators: %s: detected SEO plugin name */
									echo esc_html( sprintf( __( 'Auto-skips when a %s HowTo block is present in the post content.', 'rankready-ai-llm-seo' ), $seo_plugin ) );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; // is_pro — HowTo ?>

					<!-- ItemList — Pro only -->
					<?php if ( $is_pro ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'ItemList Schema', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_SCHEMA_ITEMLIST ); ?>"
									   value="on" <?php checked( $itemlist, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Auto-detect listicle posts and inject ItemList JSON-LD', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description" style="margin-top:4px;">
								<?php esc_html_e( 'Scans posts with "Best N", "Top N", "N Best Plugins", etc. in the title. Extracts list items from numbered headings. Perfect for "best of" and comparison posts that AI models use for recommendations.', 'rankready-ai-llm-seo' ); ?>
							</p>
							<details style="margin-top:8px;">
								<summary style="cursor:pointer;color:#2271b1;font-weight:500;"><?php esc_html_e( 'How detection works', 'rankready-ai-llm-seo' ); ?></summary>
								<div style="margin-top:8px;padding:12px;background:#f9f9f9;border-radius:4px;">
									<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Title must match one of these patterns:', 'rankready-ai-llm-seo' ); ?></strong></p>
									<ul style="margin:0 0 8px 20px;list-style:disc;">
										<li><?php esc_html_e( 'Number + qualifier: "10 Best WordPress Plugins", "Top 5 Elementor Addons"', 'rankready-ai-llm-seo' ); ?></li>
										<li><?php esc_html_e( 'Qualifier + number: "Best 10 Tools for SEO"', 'rankready-ai-llm-seo' ); ?></li>
										<li><?php esc_html_e( 'Number + noun: "7 Plugins Every Developer Needs", "15 Tips for Speed"', 'rankready-ai-llm-seo' ); ?></li>
										<li><?php esc_html_e( 'Qualifier without number: "Best Elementor Addons", "Top WordPress Themes"', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
									<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Items extracted from:', 'rankready-ai-llm-seo' ); ?></strong></p>
									<ol style="margin:0 0 8px 20px;">
										<li><?php esc_html_e( 'Numbered headings — e.g., <h2>1. Essential Addons</h2>', 'rankready-ai-llm-seo' ); ?></li>
										<li><?php esc_html_e( 'Consecutive headings — 3+ h2/h3 headings in sequence', 'rankready-ai-llm-seo' ); ?></li>
									</ol>
									<p style="margin:0;"><strong><?php esc_html_e( 'Per item:', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'Extracts name, URL (from links), description (first paragraph), and image. Minimum 3 items required.', 'rankready-ai-llm-seo' ); ?></p>
								</div>
							</details>
							<p class="description" style="margin-top:4px;color:#666;">
								<?php esc_html_e( 'Mutually exclusive with HowTo — if the title matches both, HowTo takes priority. No SEO plugin provides automatic ItemList detection.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>
					<?php endif; // is_pro — ItemList ?>
				</table>
				<?php submit_button( __( 'Save Schema Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_schema', false ); ?>
			</div>

			<?php if ( ! $is_pro ) : ?>
			<!-- HowTo + ItemList Pro gate (shown below the free schema options) -->
			<div style="margin-top:0;">
				<?php self::render_pro_gate(
					__( 'HowTo Schema', 'rankready-ai-llm-seo' ),
					__( 'Auto-detect step-by-step posts and inject HowTo JSON-LD. Triggered by "How to", "Tutorial", "Step by Step", or "Guide to" in the title — steps extracted from your existing headings.', 'rankready-ai-llm-seo' )
				); ?>
				<?php self::render_pro_gate(
					__( 'ItemList Schema', 'rankready-ai-llm-seo' ),
					__( 'Auto-detect "Best N / Top N" listicle posts and inject ItemList JSON-LD. No SEO plugin does this automatically — it\'s what makes your recommendation posts AI-readable.', 'rankready-ai-llm-seo' )
				); ?>
			</div>
			<?php endif; ?>

			<?php
			// v1.2.0-rc.7 — "How Schema Detection Works" card removed; replaced
			// by the inline 1-line conditional callout at the top of the SEO
			// Plugin Compatibility card above.
			?>

		<?php
	}

	// TAB: LLM Optimization
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_llm(): void {
		?>
		<?php settings_errors(); ?>
		<?php self::render_tab_intro(
			__( 'AI Crawlers', 'rankready-ai-llm-seo' ),
			__( 'Decide what 31+ AI crawlers can see — and what they cannot.', 'rankready-ai-llm-seo' ),
			__( 'Brand Identity, llms.txt, llms-full.txt, Markdown endpoints, robots.txt AI rules, Content Signals, and the WebMCP manifest — every surface ChatGPT, Claude, Perplexity, and Gemini read. The card below shows which signals are live.', 'rankready-ai-llm-seo' )
		); ?>

		<!-- ── Agent Visibility Status (read-only summary, v1.2.0-beta.3) ───── -->
		<?php
		$rnrd_status_signals = array(
			array(
				'label' => __( 'llms.txt', 'rankready-ai-llm-seo' ),
				'on'    => 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ),
				'url'   => home_url( '/llms.txt' ),
			),
			array(
				'label' => __( 'llms-full.txt', 'rankready-ai-llm-seo' ),
				'on'    => 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ),
				'url'   => home_url( '/llms-full.txt' ),
			),
			array(
				'label' => __( '.md routes', 'rankready-ai-llm-seo' ),
				'on'    => 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ),
			),
			array(
				'label' => __( 'AI hint in body', 'rankready-ai-llm-seo' ),
				'on'    => 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) && 'on' === get_option( RNRD_OPT_MD_HINT_DIV, 'on' ),
			),
			array(
				'label' => __( 'AI bot auto-serve', 'rankready-ai-llm-seo' ),
				'on'    => 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) && 'on' === get_option( RNRD_OPT_MD_BOT_AUTO_SERVE, 'on' ),
			),
			array(
				'label' => __( 'robots.txt AI rules', 'rankready-ai-llm-seo' ),
				'on'    => 'on' === get_option( RNRD_OPT_ROBOTS_ENABLE, 'on' ),
			),
			array(
				'label' => __( 'Content Signals', 'rankready-ai-llm-seo' ),
				'on'    => 'on' === get_option( RNRD_OPT_CONTENT_SIGNALS_ENABLE, 'off' ),
			),
			// max-snippet signal hidden from scorecard — the meta tag is
			// auto-emitted by default (see RNRD_OPT_MAX_SNIPPET_DEFAULT and
			// class-rnrd-snippet.php). No UI toggle is exposed, so showing
			// it as a "signal" in the coverage card was confusing users.
			array(
				'label' => __( 'AI Referral tracking', 'rankready-ai-llm-seo' ),
				'on'    => 'on' === get_option( RNRD_OPT_AI_REFERRAL_ENABLE, 'on' ),
			),
			array(
				'label' => __( 'WebMCP manifest', 'rankready-ai-llm-seo' ),
				'on'    => 'on' === get_option( RNRD_OPT_MCP_ENABLE, 'off' ),
				'url'   => home_url( '/.well-known/mcp.json' ),
			),
			array(
				'label' => __( 'Brand Terms set', 'rankready-ai-llm-seo' ),
				'on'    => '' !== trim( (string) get_option( RNRD_OPT_BRAND_TERMS, '' ) ),
			),
		);
		$rnrd_status_on  = count( array_filter( $rnrd_status_signals, function( $s ) { return $s['on']; } ) );
		$rnrd_status_all = count( $rnrd_status_signals );
		$rnrd_status_pct = (int) round( ( $rnrd_status_on / max( 1, $rnrd_status_all ) ) * 100 );
		?>
		<div class="rnrd-card rnrd-agent-status">
			<div class="rnrd-agent-status__head">
				<div class="rnrd-agent-status__copy">
					<h2 class="rnrd-card-title"><?php esc_html_e( 'Agent Visibility', 'rankready-ai-llm-seo' ); ?></h2>
					<p class="rnrd-card-goal"><?php esc_html_e( 'What\'s exposed to AI — every signal ChatGPT, Claude, Perplexity, and Gemini can read.', 'rankready-ai-llm-seo' ); ?></p>
					<p class="rnrd-card-desc">
						<?php
						printf(
							/* translators: 1: signals on, 2: signals total */
							esc_html__( '%1$d of %2$d agent signals active. The more enabled, the more visible your site is to ChatGPT, Perplexity, Claude, Gemini, and Google AI.', 'rankready-ai-llm-seo' ),
							(int) $rnrd_status_on,
							(int) $rnrd_status_all
						);
						?>
					</p>
				</div>
				<div class="rnrd-agent-status__pct">
					<div class="rnrd-agent-status__pct-value"><?php echo esc_html( $rnrd_status_pct ); ?>%</div>
					<div class="rnrd-agent-status__pct-label"><?php esc_html_e( 'Coverage', 'rankready-ai-llm-seo' ); ?></div>
				</div>
			</div>

			<ul class="rnrd-signal-list">
				<?php foreach ( $rnrd_status_signals as $sig ) :
					$on = ! empty( $sig['on'] );
					?>
					<li class="rnrd-signal-list__item rnrd-signal-list__item--<?php echo $on ? 'on' : 'off'; ?>">
						<span class="rnrd-signal-list__mark dashicons <?php echo $on ? 'dashicons-yes' : 'dashicons-marker'; ?>" aria-hidden="true"></span>
						<span class="rnrd-signal-list__label"><?php echo esc_html( $sig['label'] ); ?></span>
						<?php if ( $on && ! empty( $sig['url'] ) ) : ?>
							<a href="<?php echo esc_url( $sig['url'] ); ?>" target="_blank" rel="noopener" class="rnrd-signal-list__link" aria-label="<?php echo esc_attr( $sig['label'] ); ?>">
								<span class="dashicons dashicons-external" aria-hidden="true"></span>
							</a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<!-- ── /Agent Visibility Status ───────────────────────────────────── -->

		<!-- ── Brand Identity (v1.2.0-beta.4 — unified) ───────────────────── -->
		<?php
		$rnrd_brand           = RNRD_Llms_Txt::get_brand_identity();
		$rnrd_brand_name      = (string) get_option( RNRD_OPT_LLMS_SITE_NAME, '' );  // raw value, not the fallback
		$rnrd_brand_summary   = (string) get_option( RNRD_OPT_LLMS_SUMMARY, '' );
		$rnrd_brand_about     = (string) get_option( RNRD_OPT_LLMS_ABOUT, '' );
		$rnrd_brand_terms_raw = (string) get_option( RNRD_OPT_BRAND_TERMS, '' );
		$rnrd_brand_complete  = ( '' !== trim( $rnrd_brand_name ) || '' !== trim( $rnrd_brand_summary ) )
			&& '' !== trim( $rnrd_brand_terms_raw );
		?>
		<form method="post" action="options.php" novalidate="novalidate" class="rnrd-brand-form">
			<?php settings_fields( self::BRAND_GROUP ); /* v1.2.0-rc.3 — isolated group prevents LLMS settings from being null'd on save. */ ?>

			<div class="rnrd-card">
				<h2 class="rnrd-card-title">
					<?php esc_html_e( 'Brand Identity', 'rankready-ai-llm-seo' ); ?>
					<span class="rnrd-badge rnrd-badge--neutral"><?php esc_html_e( 'Single source of truth', 'rankready-ai-llm-seo' ); ?></span>
					<?php if ( $rnrd_brand_complete ) : ?>
						<span class="rnrd-badge rnrd-badge--ok"><?php esc_html_e( 'Complete', 'rankready-ai-llm-seo' ); ?></span>
					<?php else : ?>
						<span class="rnrd-badge rnrd-badge--warn"><?php esc_html_e( 'Incomplete', 'rankready-ai-llm-seo' ); ?></span>
					<?php endif; ?>
				</h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Tell ChatGPT, Claude, Perplexity, and Gemini who you are — once. Powers llms.txt, robots.txt comment, FAQ prompt, AI summary prompt, MCP abilities, homepage markdown.', 'rankready-ai-llm-seo' ); ?></p>
				<p class="rnrd-card-desc" style="margin-top:4px;">
					<?php esc_html_e( 'How AI engines see your brand. Four fields, one place. Every consumer below reads the same values — fill these once and every llms.txt, robots.txt comment, FAQ prompt, AI summary prompt, MCP ability, and homepage Markdown stays consistent.', 'rankready-ai-llm-seo' ); ?>
				</p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><label for="rnrd_llms_site_name"><?php esc_html_e( 'Site / brand name', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="text" id="rnrd_llms_site_name" name="<?php echo esc_attr( RNRD_OPT_LLMS_SITE_NAME ); ?>"
								   value="<?php echo esc_attr( $rnrd_brand_name ); ?>"
								   class="regular-text"
								   placeholder="<?php esc_attr_e( 'Your Brand Name', 'rankready-ai-llm-seo' ); ?>" />
							<p class="description"><?php esc_html_e( 'Canonical name. Used as the H1 in llms.txt and in the Brand line everywhere. Falls back to your WordPress site title when empty.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="rnrd_llms_summary"><?php esc_html_e( 'One-line summary', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea id="rnrd_llms_summary" name="<?php echo esc_attr( RNRD_OPT_LLMS_SUMMARY ); ?>"
									  rows="2" class="large-text"
									  maxlength="160"
									  placeholder="<?php esc_attr_e( 'The elevator pitch an AI engine quotes in 1 sentence. Max 160 chars.', 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( $rnrd_brand_summary ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Renders as the blockquote under the H1 in llms.txt + llms-full.txt. Keep it tight — this is the line AI engines repeat.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="rnrd_llms_about"><?php esc_html_e( 'About', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea id="rnrd_llms_about" name="<?php echo esc_attr( RNRD_OPT_LLMS_ABOUT ); ?>"
									  rows="4" class="large-text"
									  maxlength="500"
									  placeholder="<?php esc_attr_e( 'Longer description (≤ 500 chars). What is this site about? Who is it for? Markdown supported.', 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( $rnrd_brand_about ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Detailed context. Appears below the summary in llms.txt and llms-full.txt. Used by AI engines for deeper site comprehension.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="rnrd_brand_terms"><?php esc_html_e( 'Canonical brand terms', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea id="rnrd_brand_terms"
									  name="<?php echo esc_attr( RNRD_OPT_BRAND_TERMS ); ?>"
									  rows="4"
									  class="large-text"
									  placeholder="<?php esc_attr_e( "One name per line. Examples:\nYour Brand Name\nYour Product Name\nA Common Misspelling To Catch", 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( $rnrd_brand_terms_raw ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Use the exact capitalisation and spacing you want AI engines to use. One canonical name per line — any variants you list get unified into a single recognised entity.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<div style="margin-top:14px;padding:12px 14px;background:var(--rnrd-color-surface-2,#f6f7f7);border-radius:var(--rnrd-radius-md,6px);font-size:12px;line-height:1.7;color:var(--rnrd-color-ink-soft,#3c434a);">
					<strong style="display:block;margin-bottom:6px;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;color:var(--rnrd-color-text-muted,#646970;">
						<?php esc_html_e( 'Where these 4 fields are used', 'rankready-ai-llm-seo' ); ?>
					</strong>
					<ul style="margin:0;padding-left:18px;list-style:disc;">
						<li><code>/llms.txt</code> &mdash; <?php esc_html_e( 'H1 (name), blockquote (summary), about paragraph, Brand line (terms)', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>/llms-full.txt</code> &mdash; <?php esc_html_e( 'same header block + every page below', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>/robots.txt</code> &mdash; <?php esc_html_e( 'Brand comment inside RankReady AI block', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'AI Summary system prompt', 'rankready-ai-llm-seo' ); ?> &mdash; <?php esc_html_e( 'canonical naming forced into every summary', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'FAQ generation prompt', 'rankready-ai-llm-seo' ); ?> &mdash; <?php esc_html_e( 'brand context, deduped against per-FAQ legacy field', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>rankready/get-site-info</code> &mdash; <?php esc_html_e( 'WebMCP ability returns name + brand_terms to Claude Desktop / Cursor / VS Code', 'rankready-ai-llm-seo' ); ?></li>
						<li><code>rankready/get-brand-terms</code> &mdash; <?php esc_html_e( 'WebMCP ability returns terms array directly', 'rankready-ai-llm-seo' ); ?></li>
						<li><?php esc_html_e( 'Homepage Markdown (Accept: text/markdown)', 'rankready-ai-llm-seo' ); ?> &mdash; <?php esc_html_e( 'site overview uses name + summary', 'rankready-ai-llm-seo' ); ?></li>
					</ul>
				</div>

				<?php submit_button( __( 'Save Brand Identity', 'rankready-ai-llm-seo' ) ); ?>
			</div>
		</form>
		<!-- ── /Brand Identity ───────────────────────────────────────────── -->

		<!-- AI Crawler Access Log card removed in rc.16 — duplicate of the
		     Insights → Training Bots / Citation Bots tabs. Users access live
		     bot activity via the Insights main tab nav (no shortcut needed). -->


		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::LLMS_GROUP ); ?>

			<!-- AI Referral Traffic card removed from AI Crawlers tab in rc.16.
			     Counts live on Insights → Real AI Referrals sub-tab; tracking
			     itself is always on (no toggle, no opt-out per rc.16 spec).
			     Hidden input below preserves the legacy option value for any
			     external integration that checks RNRD_OPT_AI_REFERRAL_ENABLE. -->
			<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_AI_REFERRAL_ENABLE ); ?>" value="on" />

			<!-- ── WebMCP (v1.2.0) ─────────────────────────────────────────────── -->
			<?php
			$rnrd_mcp_enable     = (string) get_option( RNRD_OPT_MCP_ENABLE, 'off' );
			$rnrd_abilities_api  = function_exists( 'wp_register_ability' );
			$rnrd_manifest_url   = home_url( '/.well-known/mcp.json' );
			?>
			<div class="rnrd-card" style="margin-bottom:24px;">
				<h2 class="rnrd-card-title">
					<?php esc_html_e( 'WebMCP — Agent Tooling', 'rankready-ai-llm-seo' ); ?>
					<span style="font-size:11px;background:var(--rnrd-color-info-bg,#e5f1f9);color:var(--rnrd-color-info-text,#135e96);padding:2px 8px;border-radius:9999px;margin-left:6px;vertical-align:middle;font-weight:600;">NEW</span>
				</h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Expose 16 read-only abilities at /.well-known/mcp.json — Claude Desktop, Cursor, VS Code read your site.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable WebMCP', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MCP_ENABLE ); ?>" value="on" <?php checked( $rnrd_mcp_enable, 'on' ); ?> />
								<span><?php esc_html_e( 'Serve /.well-known/mcp.json + register WordPress Abilities', 'rankready-ai-llm-seo' ); ?></span>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php if ( 'on' === $rnrd_mcp_enable ) : ?>
								<p style="margin:0 0 6px;">
									<span style="display:inline-block;padding:2px 8px;border-radius:9999px;background:var(--rnrd-color-success-bg,#d1ecdf);color:var(--rnrd-color-success-text,#0a6c39);font-size:11px;font-weight:600;">✓ <?php esc_html_e( 'Manifest live', 'rankready-ai-llm-seo' ); ?></span>
								</p>
								<p style="margin:6px 0 0;">
									<?php if ( $rnrd_abilities_api ) : ?>
										<span style="display:inline-block;padding:2px 8px;border-radius:9999px;background:var(--rnrd-color-success-bg,#d1ecdf);color:var(--rnrd-color-success-text,#0a6c39);font-size:11px;font-weight:600;">✓ <?php esc_html_e( 'WordPress Abilities API detected — 16 abilities registered', 'rankready-ai-llm-seo' ); ?></span>
									<?php else : ?>
										<span style="display:inline-block;padding:2px 8px;border-radius:9999px;background:var(--rnrd-color-warning-bg,#fcf9e8);color:var(--rnrd-color-warning-text,#674c00);font-size:11px;font-weight:600;">⚠ <?php esc_html_e( 'Abilities API plugin not active — manifest still works for raw MCP discovery', 'rankready-ai-llm-seo' ); ?></span>
										<br />
										<a href="https://github.com/WordPress/abilities-api" target="_blank" rel="noopener" style="font-size:11px;"><?php esc_html_e( 'Install Abilities API plugin →', 'rankready-ai-llm-seo' ); ?></a>
									<?php endif; ?>
								</p>
							<?php else : ?>
								<p style="margin:0;">
									<span style="display:inline-block;padding:2px 8px;border-radius:9999px;background:var(--rnrd-color-surface-3,#f0f0f1);color:var(--rnrd-color-text-muted,#646970);font-size:11px;font-weight:600;">○ <?php esc_html_e( 'Disabled', 'rankready-ai-llm-seo' ); ?></span>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( 'on' === $rnrd_mcp_enable ) :
						$rnrd_exposure   = class_exists( 'RNRD_MCP' ) ? RNRD_MCP::exposure_state() : array();
						$rnrd_detected_cpts = class_exists( 'RNRD_MCP' ) ? RNRD_MCP::detected_cpts() : array();
						$rnrd_enabled_cpts  = (array) get_option( RNRD_OPT_MCP_EXPOSE_CPTS, array() );

						// Helper closure for rendering a single resource toggle row.
						$render_toggle = function ( $opt, $label, $desc, $tone, $current ) {
							$tones = array(
								'safe'    => array( 'bg' => 'var(--rnrd-color-success-bg,#d1ecdf)', 'fg' => 'var(--rnrd-color-success-text,#0a6c39)', 'pill' => 'Safe' ),
								'caution' => array( 'bg' => 'var(--rnrd-color-warning-bg,#fcf9e8)', 'fg' => 'var(--rnrd-color-warning-text,#674c00)', 'pill' => 'Caution' ),
								'risky'   => array( 'bg' => 'var(--rnrd-color-danger-bg,#fcebe6)', 'fg' => 'var(--rnrd-color-danger-text,#a72e1f)', 'pill' => 'Risky' ),
							);
							$t = $tones[ $tone ] ?? $tones['safe'];
							?>
							<div style="display:grid;grid-template-columns:32px 1fr;gap:10px;padding:10px 12px;border-radius:var(--rnrd-radius-md,6px);background:var(--rnrd-color-surface-2,#f6f7f7);">
								<label style="display:flex;align-items:center;cursor:pointer;">
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>" value="on" <?php checked( 'on', $current ); ?> />
								</label>
								<div>
									<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:2px;">
										<strong style="font-size:13px;"><?php echo esc_html( $label ); ?></strong>
										<span style="display:inline-block;padding:1px 7px;border-radius:9999px;background:<?php echo esc_attr( $t['bg'] ); ?>;color:<?php echo esc_attr( $t['fg'] ); ?>;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;"><?php echo esc_html( $t['pill'] ); ?></span>
									</div>
									<p style="margin:0;font-size:12px;color:var(--rnrd-color-text-muted,#646970);line-height:1.5;"><?php echo esc_html( $desc ); ?></p>
								</div>
							</div>
							<?php
						};
						?>
						<tr>
							<th scope="row"><?php esc_html_e( 'MCP manifest URL', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<input type="text" readonly value="<?php echo esc_attr( $rnrd_manifest_url ); ?>" onclick="this.select();" style="width:100%;max-width:520px;font-family:monospace;font-size:12px;" />
								<p class="description">
									<?php esc_html_e( 'Paste this URL into Claude Desktop / Cursor / VS Code MCP settings.', 'rankready-ai-llm-seo' ); ?>
									<a href="<?php echo esc_url( $rnrd_manifest_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open manifest →', 'rankready-ai-llm-seo' ); ?></a>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row" style="vertical-align:top;">
								<?php esc_html_e( 'Resources exposed', 'rankready-ai-llm-seo' ); ?>
								<br /><span style="font-weight:400;font-size:11px;color:var(--rnrd-color-text-muted,#646970);text-transform:uppercase;letter-spacing:0.04em;"><?php esc_html_e( 'Per-resource toggle', 'rankready-ai-llm-seo' ); ?></span>
							</th>
							<td>
								<p class="description" style="margin:0 0 12px;">
									<?php esc_html_e( 'Choose what AI agents can see. Public content (posts, pages, authors, taxonomies) is safe to expose. PII / stack-revealing resources are OFF by default — opt in only if your use case requires it.', 'rankready-ai-llm-seo' ); ?>
								</p>

								<details style="margin-bottom:14px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:var(--rnrd-color-success-text,#0a6c39);margin-bottom:8px;"><?php esc_html_e( '✓ Public content (safe defaults)', 'rankready-ai-llm-seo' ); ?></summary>
									<div style="display:flex;flex-direction:column;gap:6px;">
										<?php
										$render_toggle( RNRD_OPT_MCP_EXPOSE_POSTS,      __( 'Posts', 'rankready-ai-llm-seo' ),         __( 'list-recent-posts, search-posts, get-post, get-post-by-url — full Markdown content + AI summary + FAQ.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_POSTS, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_PAGES,      __( 'Pages', 'rankready-ai-llm-seo' ),         __( 'list-pages — static pages with parent hierarchy.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_PAGES, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_AUTHORS,    __( 'Authors (EEAT)', 'rankready-ai-llm-seo' ), __( 'get-author — Person schema fields (bio, credentials, awards, socials). No emails or login info.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_AUTHORS, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_TAXONOMIES, __( 'Categories & tags', 'rankready-ai-llm-seo' ), __( 'list-categories, list-tags — topical graph for agent navigation.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_TAXONOMIES, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_SITEMAP,    __( 'Sitemap', 'rankready-ai-llm-seo' ),       __( 'get-sitemap — parsed URL + lastmod for cold crawls.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_SITEMAP, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_LLMS_TXT,   __( 'llms.txt inline', 'rankready-ai-llm-seo' ), __( 'get-llms-txt — rendered llms.txt content without HTTP round-trip.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_LLMS_TXT, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_FRESHNESS,  __( 'Freshness signal', 'rankready-ai-llm-seo' ), __( 'get-fresh-content — posts/pages modified in last N days.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_FRESHNESS, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_RR_AI,      __( 'RankReady AI data', 'rankready-ai-llm-seo' ), __( 'get-post-summary, get-post-faq, get-brand-terms — the AI-citation surface RankReady generates.', 'rankready-ai-llm-seo' ), 'safe', get_option( RNRD_OPT_MCP_EXPOSE_RR_AI, 'off' ) );
										?>
									</div>
								</details>

								<?php if ( ! empty( $rnrd_detected_cpts ) ) : ?>
								<details style="margin-bottom:14px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:var(--rnrd-color-info-text,#135e96);margin-bottom:8px;">
										<?php esc_html_e( 'Custom post types — auto-detected', 'rankready-ai-llm-seo' ); ?>
										<span style="font-weight:400;font-size:11px;margin-left:6px;"><?php echo count( $rnrd_detected_cpts ); ?> <?php esc_html_e( 'found', 'rankready-ai-llm-seo' ); ?></span>
									</summary>
									<p class="description" style="margin:6px 0 8px;font-size:12px;"><?php esc_html_e( 'Opt in per CPT. Posts and Pages are toggled above — these are extras your theme or plugins registered.', 'rankready-ai-llm-seo' ); ?></p>
									<div style="display:flex;flex-direction:column;gap:6px;">
										<?php foreach ( $rnrd_detected_cpts as $slug => $label ) : ?>
											<div style="display:grid;grid-template-columns:32px 1fr;gap:10px;padding:8px 12px;border-radius:var(--rnrd-radius-md,6px);background:var(--rnrd-color-surface-2,#f6f7f7);">
												<label style="display:flex;align-items:center;cursor:pointer;">
													<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MCP_EXPOSE_CPTS ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $rnrd_enabled_cpts, true ) ); ?> />
												</label>
												<div>
													<strong style="font-size:13px;"><?php echo esc_html( $label ); ?></strong>
													<code style="font-size:11px;color:var(--rnrd-color-text-muted,#646970);margin-left:6px;"><?php echo esc_html( $slug ); ?></code>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								</details>
								<?php endif; ?>

								<details style="margin-bottom:14px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.04em;color:var(--rnrd-color-warning-text,#674c00);margin-bottom:8px;"><?php esc_html_e( '⚠ Sensitive resources (off by default)', 'rankready-ai-llm-seo' ); ?></summary>
									<p class="description" style="margin:6px 0 8px;font-size:12px;color:var(--rnrd-color-warning-text,#674c00);"><strong><?php esc_html_e( 'Warning:', 'rankready-ai-llm-seo' ); ?></strong> <?php esc_html_e( 'These expose PII, heavy content, or your tech stack. Only enable if your use case explicitly requires it. Off by default for a reason.', 'rankready-ai-llm-seo' ); ?></p>
									<div style="display:flex;flex-direction:column;gap:6px;">
										<?php
										$render_toggle( RNRD_OPT_MCP_EXPOSE_COMMENTS, __( 'Comments', 'rankready-ai-llm-seo' ),  __( 'list-comments, get-comment — PII risk: comment author names, emails, IPs.', 'rankready-ai-llm-seo' ), 'caution', get_option( RNRD_OPT_MCP_EXPOSE_COMMENTS, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_MEDIA,    __( 'Media library', 'rankready-ai-llm-seo' ), __( 'list-media — heavy + may contain non-attached private uploads.', 'rankready-ai-llm-seo' ), 'caution', get_option( RNRD_OPT_MCP_EXPOSE_MEDIA, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_USERS,    __( 'Users (full list)', 'rankready-ai-llm-seo' ),  __( 'list-users — PII risk: email addresses, roles, last login. Note: get-author already covers display names + bios safely.', 'rankready-ai-llm-seo' ), 'risky', get_option( RNRD_OPT_MCP_EXPOSE_USERS, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_PLUGINS,  __( 'Installed plugins', 'rankready-ai-llm-seo' ), __( 'list-plugins — reveals your tech stack and possible attack surface.', 'rankready-ai-llm-seo' ), 'risky', get_option( RNRD_OPT_MCP_EXPOSE_PLUGINS, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_THEMES,   __( 'Themes', 'rankready-ai-llm-seo' ),   __( 'list-themes — reveals your tech stack.', 'rankready-ai-llm-seo' ), 'risky', get_option( RNRD_OPT_MCP_EXPOSE_THEMES, 'off' ) );
										$render_toggle( RNRD_OPT_MCP_EXPOSE_SETTINGS, __( 'Site settings', 'rankready-ai-llm-seo' ), __( 'get-settings — may leak API keys, secrets, internal URLs. Strongly discouraged.', 'rankready-ai-llm-seo' ), 'risky', get_option( RNRD_OPT_MCP_EXPOSE_SETTINGS, 'off' ) );
										?>
									</div>
								</details>

								<p style="margin:0;padding:10px 12px;background:var(--rnrd-color-brand-soft,#f0f6fc);border-left:3px solid var(--rnrd-color-brand,#2271b1);border-radius:0 var(--rnrd-radius-md,6px) var(--rnrd-radius-md,6px) 0;font-size:11px;color:var(--rnrd-color-info-text,#135e96);line-height:1.5;">
									<strong><?php esc_html_e( 'Write abilities', 'rankready-ai-llm-seo' ); ?></strong> &mdash; <?php esc_html_e( 'create / update / delete operations are not yet exposed. v1.4 will add governed write abilities (draft-faq, refresh-post) with capability checks, nonce verification, audit log, and rate limiting per action.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Active abilities', 'rankready-ai-llm-seo' ); ?>
								<br /><span style="font-weight:400;font-size:11px;color:var(--rnrd-color-text-muted,#646970);text-transform:uppercase;letter-spacing:0.04em;"><?php esc_html_e( 'live in manifest', 'rankready-ai-llm-seo' ); ?></span>
							</th>
							<td>
								<details style="margin-bottom:8px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);margin-bottom:6px;"><?php esc_html_e( 'Site & metadata (3)', 'rankready-ai-llm-seo' ); ?></summary>
									<ul style="margin:6px 0 12px;padding-left:18px;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);line-height:1.7;">
										<li><code>rankready/get-site-info</code> &mdash; <?php esc_html_e( 'site name, description, about, brand terms, language', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-brand-terms</code> &mdash; <?php esc_html_e( 'canonical brand names array', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/list-content-types</code> &mdash; <?php esc_html_e( 'every public post type + published count + archive URL', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<details style="margin-bottom:8px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);margin-bottom:6px;"><?php esc_html_e( 'Content retrieval (4)', 'rankready-ai-llm-seo' ); ?></summary>
									<ul style="margin:6px 0 12px;padding-left:18px;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);line-height:1.7;">
										<li><code>rankready/get-post</code> &mdash; <strong><?php esc_html_e( 'full Markdown content', 'rankready-ai-llm-seo' ); ?></strong> + <?php esc_html_e( 'title, URL, author, summary, FAQ, schema in one call', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-post-by-url</code> &mdash; <?php esc_html_e( 'resolve any permalink (incl. .md / /category/ / /tag/) to content', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-post-summary</code> &mdash; <?php esc_html_e( 'AI summary bullets only', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-post-faq</code> &mdash; <?php esc_html_e( 'FAQ Q&amp;A pairs only', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<details style="margin-bottom:8px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);margin-bottom:6px;"><?php esc_html_e( 'Discovery & navigation (5)', 'rankready-ai-llm-seo' ); ?></summary>
									<ul style="margin:6px 0 12px;padding-left:18px;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);line-height:1.7;">
										<li><code>rankready/search-posts</code> &mdash; <?php esc_html_e( 'keyword search across published posts', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/list-pages</code> &mdash; <?php esc_html_e( 'static pages + parent_id hierarchy', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/list-recent-posts</code> &mdash; <?php esc_html_e( 'paginated recent-posts feed', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/list-categories</code> &mdash; <?php esc_html_e( 'topical hierarchy: name, slug, parent, count, URL', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/list-tags</code> &mdash; <?php esc_html_e( 'tags ordered by post count', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<details style="margin-bottom:8px;">
									<summary style="cursor:pointer;font-weight:600;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);margin-bottom:6px;"><?php esc_html_e( 'AI-native (4)', 'rankready-ai-llm-seo' ); ?></summary>
									<ul style="margin:6px 0 12px;padding-left:18px;font-size:12px;color:var(--rnrd-color-ink-soft,#3c434a);line-height:1.7;">
										<li><code>rankready/get-llms-txt</code> &mdash; <?php esc_html_e( 'rendered llms.txt or llms-full.txt content inline', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-sitemap</code> &mdash; <?php esc_html_e( 'parsed sitemap (URL + lastmod) for cold crawls', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-fresh-content</code> &mdash; <?php esc_html_e( 'posts/pages modified in last N days', 'rankready-ai-llm-seo' ); ?></li>
										<li><code>rankready/get-author</code> &mdash; <?php esc_html_e( 'EEAT Person schema fields for an author (credentials, awards, socials)', 'rankready-ai-llm-seo' ); ?></li>
									</ul>
								</details>

								<p class="description" style="margin-top:8px;"><?php esc_html_e( 'All abilities are read-only. No write access exposed.', 'rankready-ai-llm-seo' ); ?>
								<a href="<?php echo esc_url( $rnrd_manifest_url ); ?>" target="_blank" rel="noopener" style="margin-left:6px;"><?php esc_html_e( 'View manifest JSON →', 'rankready-ai-llm-seo' ); ?></a></p>
							</td>
						</tr>
					<?php endif; ?>
				</table>

				<?php submit_button( __( 'Save WebMCP Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_mcp', false ); ?>
			</div>
			<!-- ── /WebMCP ──────────────────────────────────────────────────────── -->

			<!-- LLMs.txt -->
			<?php $llms_enable = (string) get_option( RNRD_OPT_LLMS_ENABLE, 'off' ); ?>
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'LLMs.txt Generator', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Serve the llmstxt.org site index at /llms.txt and /llms-full.txt for AI engines.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable LLMs.txt', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_ENABLE ); ?>"
									   value="on" <?php checked( $llms_enable, 'on' ); ?>
									   data-toggle-target="rnrd-llms-fields" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Serve /llms.txt on your site', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<?php if ( 'on' === $llms_enable ) : ?>
								<p class="description" style="margin-top:8px;">
									<?php esc_html_e( 'Live at:', 'rankready-ai-llm-seo' ); ?>
									<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank"><code><?php echo esc_html( home_url( '/llms.txt' ) ); ?></code></a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<div id="rnrd-llms-fields" class="rnrd-conditional-fields" <?php echo 'on' !== $llms_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rnrd-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Include Post Types', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php $llms_types = (array) get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) ); ?>
								<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_POST_TYPES ); ?>[]"
											   value="<?php echo esc_attr( $slug ); ?>"
											   <?php checked( in_array( $slug, $llms_types, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Which post types to list in llms.txt as file lists.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rnrd_llms_max"><?php esc_html_e( 'Max Posts per Type', 'rankready-ai-llm-seo' ); ?></label></th>
							<td>
								<input type="number" id="rnrd_llms_max" name="<?php echo esc_attr( RNRD_OPT_LLMS_MAX_POSTS ); ?>"
									   value="<?php echo esc_attr( (string) get_option( RNRD_OPT_LLMS_MAX_POSTS, 100 ) ); ?>"
									   min="10" max="500" step="10" class="small-text" />
								<p class="description"><?php esc_html_e( 'Maximum number of posts per post type to include.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Exclude Categories', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php
								$exclude_cats = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_CATS, array() );
								$all_cats     = get_categories( array( 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
								?>
								<?php if ( ! empty( $all_cats ) && ! is_wp_error( $all_cats ) ) : ?>
									<fieldset style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:8px 12px;border-radius:4px;">
										<?php foreach ( $all_cats as $cat ) : ?>
											<label style="display:block;margin-bottom:4px;">
												<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_EXCLUDE_CATS ); ?>[]"
													   value="<?php echo esc_attr( $cat->term_id ); ?>"
													   <?php checked( in_array( (int) $cat->term_id, $exclude_cats, true ) ); ?> />
												<?php echo esc_html( $cat->name ); ?> <span style="color:#999;">(<?php echo esc_html( $cat->count ); ?>)</span>
											</label>
										<?php endforeach; ?>
									</fieldset>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'No categories found.', 'rankready-ai-llm-seo' ); ?></p>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Posts in checked categories will be excluded from llms.txt. Useful for filtering out demo, test, or irrelevant content.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Exclude Tags', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php
								$exclude_tags = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_TAGS, array() );
								$all_tags     = get_tags( array( 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
								?>
								<?php if ( ! empty( $all_tags ) && ! is_wp_error( $all_tags ) ) : ?>
									<fieldset style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:8px 12px;border-radius:4px;">
										<?php foreach ( $all_tags as $tag ) : ?>
											<label style="display:block;margin-bottom:4px;">
												<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_EXCLUDE_TAGS ); ?>[]"
													   value="<?php echo esc_attr( $tag->term_id ); ?>"
													   <?php checked( in_array( (int) $tag->term_id, $exclude_tags, true ) ); ?> />
												<?php echo esc_html( $tag->name ); ?> <span style="color:#999;">(<?php echo esc_html( $tag->count ); ?>)</span>
											</label>
										<?php endforeach; ?>
									</fieldset>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'No tags found.', 'rankready-ai-llm-seo' ); ?></p>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Posts with checked tags will be excluded from llms.txt.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Show Categories Section', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php $show_cats = (string) get_option( RNRD_OPT_LLMS_SHOW_CATEGORIES, 'on' ); ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_SHOW_CATEGORIES ); ?>"
										   value="on" <?php checked( $show_cats, 'on' ); ?> />
									<?php esc_html_e( 'Show "Optional" categories section at the bottom of llms.txt', 'rankready-ai-llm-seo' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Cache Duration', 'rankready-ai-llm-seo' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( RNRD_OPT_LLMS_CACHE_TTL ); ?>">
									<?php $current_ttl = (int) get_option( RNRD_OPT_LLMS_CACHE_TTL, 3600 ); ?>
									<?php foreach ( array(
										900   => __( '15 minutes', 'rankready-ai-llm-seo' ),
										3600  => __( '1 hour', 'rankready-ai-llm-seo' ),
										21600 => __( '6 hours', 'rankready-ai-llm-seo' ),
										86400 => __( '24 hours', 'rankready-ai-llm-seo' ),
									) as $seconds => $label ) : ?>
										<option value="<?php echo esc_attr( $seconds ); ?>" <?php selected( $current_ttl, $seconds ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'How long to cache the generated llms.txt output.', 'rankready-ai-llm-seo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Full Version', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php $llms_full = (string) get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ); ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_LLMS_FULL_ENABLE ); ?>"
										   value="on" <?php checked( $llms_full, 'on' ); ?> />
									<?php esc_html_e( 'Also serve /llms-full.txt with full post content inlined', 'rankready-ai-llm-seo' ); ?>
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
				<?php submit_button( __( 'Save LLMs.txt Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_llms', false ); ?>
			</div>

			<!-- Markdown Endpoints -->
			<?php $md_enable = (string) get_option( RNRD_OPT_MD_ENABLE, 'off' ); ?>
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Markdown Endpoints', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Every post as clean Markdown for AI bots — via .md URL or Accept: text/markdown.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable .md Endpoints', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_ENABLE ); ?>"
									   value="on" <?php checked( $md_enable, 'on' ); ?>
									   data-toggle-target="rnrd-md-fields" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Add .md endpoint to each post URL', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<?php if ( 'on' === $md_enable ) : ?>
								<p class="description" style="margin-top:8px;">
									<?php esc_html_e( 'Example:', 'rankready-ai-llm-seo' ); ?>
									<code>yoursite.com/sample-post.md</code>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<div id="rnrd-md-fields" class="rnrd-conditional-fields" <?php echo 'on' !== $md_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rnrd-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Post Types', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php $md_types = (array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) ); ?>
								<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_POST_TYPES ); ?>[]"
											   value="<?php echo esc_attr( $slug ); ?>"
											   <?php checked( in_array( $slug, $md_types, true ) ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Include Metadata', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_INCLUDE_META ); ?>"
										   value="1" <?php checked( get_option( RNRD_OPT_MD_INCLUDE_META, '1' ), '1' ); ?> />
									<?php esc_html_e( 'Add YAML frontmatter (title, date, author, excerpt, tags)', 'rankready-ai-llm-seo' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'AI hint in body', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_HINT_DIV ); ?>"
										   value="on" <?php checked( get_option( RNRD_OPT_MD_HINT_DIV, 'on' ), 'on' ); ?> />
									<?php esc_html_e( 'Inject a hidden div pointing AI agents at the .md version', 'rankready-ai-llm-seo' ); ?>
								</label>
								<p class="description" style="font-size:11px;">
									<?php esc_html_e( 'Visually invisible (clip-path + aria-hidden). Raw-HTML scrapers see "AI agents: a clean Markdown version is at URL.md". Evil Martians technique — got their docs cited by Claude.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Auto-serve to AI bots', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_MD_BOT_AUTO_SERVE ); ?>"
										   value="on" <?php checked( get_option( RNRD_OPT_MD_BOT_AUTO_SERVE, 'on' ), 'on' ); ?> />
									<?php esc_html_e( 'Serve Markdown to GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended (12 bots total)', 'rankready-ai-llm-seo' ); ?>
								</label>
								<p class="description" style="font-size:11px;">
									<?php esc_html_e( 'Detected via User-Agent header. Disable to restrict markdown to explicit Accept: text/markdown requests only.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
				<?php submit_button( __( 'Save Markdown Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_md', false ); ?>
			</div>

			<!-- LLM Crawler Access (robots.txt) -->
			<?php $robots_enable = (string) get_option( RNRD_OPT_ROBOTS_ENABLE, 'on' ); ?>
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'LLM Crawler Access (robots.txt)', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Allow or block 31 named AI crawlers — auto-syncs to physical /robots.txt.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Crawler Rules', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_ROBOTS_ENABLE ); ?>"
									   value="on" <?php checked( $robots_enable, 'on' ); ?>
									   data-toggle-target="rnrd-robots-fields" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Append LLM crawler rules to robots.txt', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Adds per-crawler User-agent blocks with Allow directives. Safe — appends only, never touches existing rules.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<div id="rnrd-robots-fields" class="rnrd-conditional-fields" <?php echo 'on' !== $robots_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rnrd-form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Allow Crawlers', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<?php
								$enabled_crawlers = (array) get_option( RNRD_OPT_ROBOTS_CRAWLERS, array_keys( self::get_llm_crawlers() ) );
								$all_crawlers     = self::get_llm_crawlers();
								$current_company  = '';
								?>
								<fieldset style="max-height:400px;overflow-y:auto;border:1px solid #ddd;padding:12px 16px;border-radius:4px;">
									<p style="margin:0 0 8px;"><strong>
										<label><input type="checkbox" id="rnrd-crawlers-select-all" /> <?php esc_html_e( 'Select / Deselect All', 'rankready-ai-llm-seo' ); ?></label>
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
											<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_ROBOTS_CRAWLERS ); ?>[]"
												   value="<?php echo esc_attr( $ua ); ?>"
												   class="rnrd-crawler-checkbox"
												   <?php checked( in_array( $ua, $enabled_crawlers, true ) ); ?> />
											<code style="font-size:12px;"><?php echo esc_html( $ua ); ?></code>
											<span style="color:#666;font-size:12px;"> — <?php echo esc_html( $info[1] ); ?></span>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description" style="margin-top:8px;">
									<?php esc_html_e( 'Checked crawlers get "User-agent: X / Allow: /" appended to robots.txt. Helps AI search engines, AI Overviews, and answer engines discover and cite your content.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
				<?php submit_button( __( 'Save Robots Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_robots', false ); ?>
			</div>

			<!-- Content Signals -->
			<?php $signals_enable = (string) get_option( RNRD_OPT_CONTENT_SIGNALS_ENABLE, 'off' ); ?>
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Content Signals', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Tell AI engines what your content may be used for (ai-train / search / ai-input).', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Content Signals', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_CONTENT_SIGNALS_ENABLE ); ?>"
									   value="on" <?php checked( $signals_enable, 'on' ); ?>
									   data-toggle-target="rnrd-content-signals-fields" />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Add Content Signals directives to robots.txt', 'rankready-ai-llm-seo' ); ?></span>
							</label>
						</td>
					</tr>
				</table>

				<div id="rnrd-content-signals-fields" class="rnrd-conditional-fields" <?php echo 'on' !== $signals_enable ? 'style="display:none;"' : ''; ?>>
					<table class="form-table rnrd-form-table">
						<?php
						$signal_options = array(
							RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN => array(
								'label' => __( 'ai-train', 'rankready-ai-llm-seo' ),
								'desc'  => __( 'May AI systems use this content to train models?', 'rankready-ai-llm-seo' ),
							),
							RNRD_OPT_CONTENT_SIGNALS_SEARCH   => array(
								'label' => __( 'search', 'rankready-ai-llm-seo' ),
								'desc'  => __( 'May AI systems use this content in search results?', 'rankready-ai-llm-seo' ),
							),
							RNRD_OPT_CONTENT_SIGNALS_AI_INPUT => array(
								'label' => __( 'ai-input', 'rankready-ai-llm-seo' ),
								'desc'  => __( 'May AI systems use this content as RAG/context input?', 'rankready-ai-llm-seo' ),
							),
						);
						foreach ( $signal_options as $opt_key => $info ) :
							$val = (string) get_option( $opt_key, 'allow' );
							?>
							<tr>
								<th scope="row"><code><?php echo esc_html( $info['label'] ); ?></code></th>
								<td>
									<select name="<?php echo esc_attr( $opt_key ); ?>">
										<option value="allow" <?php selected( $val, 'allow' ); ?>><?php esc_html_e( 'allow', 'rankready-ai-llm-seo' ); ?></option>
										<option value="deny"  <?php selected( $val, 'deny' ); ?>><?php esc_html_e( 'deny', 'rankready-ai-llm-seo' ); ?></option>
									</select>
									<p class="description"><?php echo esc_html( $info['desc'] ); ?></p>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>
				<?php submit_button( __( 'Save Content Signals', 'rankready-ai-llm-seo' ), 'primary', 'submit_signals', false ); ?>
			</div>


		</form>

		<!-- Cache Controls (outside form) -->
		<div class="rnrd-card rnrd-card--subtle">
			<h3 class="rnrd-card-title" style="font-size:14px;"><?php esc_html_e( 'Cache Management', 'rankready-ai-llm-seo' ); ?></h3>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Flush the llms.txt cache + any page-cache plugin entries when content changes.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="description"><?php esc_html_e( 'Clear cached LLMs.txt output to regenerate with latest content.', 'rankready-ai-llm-seo' ); ?></p>
			<p style="margin-top:10px;">
				<button id="rnrd-flush-llms-cache" class="button button-secondary">
					<?php esc_html_e( 'Flush LLMs.txt Cache', 'rankready-ai-llm-seo' ); ?>
				</button>
				<span id="rnrd-flush-status" style="margin-left:10px;font-size:13px;color:#00a32a;display:none;">
					<?php esc_html_e( 'Cache cleared.', 'rankready-ai-llm-seo' ); ?>
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
			<!-- Merged in rc.6: single "FAQ Generator" card containing two H3 subsections
			     (Generation + Display). All form-field names preserved verbatim. -->
			<div class="rnrd-card">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'FAQ Generator', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Discover real user questions and answer them with AI. Outputs FAQPage schema that Google AI Overviews and Perplexity preferentially cite.', 'rankready-ai-llm-seo' ); ?></p>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Configure how FAQs are generated and displayed. Uses DataForSEO for question discovery and your active AI provider for answers with brand entity injection.', 'rankready-ai-llm-seo' ); ?></p>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Generation', 'rankready-ai-llm-seo' ); ?></h3>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Post Types', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $faq_types = (array) get_option( RNRD_OPT_FAQ_POST_TYPES, array( 'post' ) ); ?>
							<?php foreach ( self::get_allowed_post_types() as $slug => $label ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_FAQ_POST_TYPES ); ?>[]"
									       value="<?php echo esc_attr( $slug ); ?>"
									       <?php checked( in_array( $slug, $faq_types, true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'FAQ will be generated for these post types.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_faq_count"><?php esc_html_e( 'FAQ Count', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<input type="number" id="rnrd_faq_count" name="<?php echo esc_attr( RNRD_OPT_FAQ_COUNT ); ?>"
							       value="<?php echo esc_attr( (string) get_option( RNRD_OPT_FAQ_COUNT, 5 ) ); ?>"
							       min="3" max="10" step="1" class="small-text" />
							<p class="description"><?php esc_html_e( 'Number of FAQ items to generate per post (3-10). More FAQs = more API calls.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rnrd_faq_brand_terms"><?php esc_html_e( 'Brand Terms', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<textarea id="rnrd_faq_brand_terms" name="<?php echo esc_attr( RNRD_OPT_FAQ_BRAND_TERMS ); ?>"
							          rows="3" class="large-text"
							          placeholder="<?php esc_attr_e( 'Your Brand Name, Your Product Name (one per line or comma-separated)', 'rankready-ai-llm-seo' ); ?>"
							><?php echo esc_textarea( (string) get_option( RNRD_OPT_FAQ_BRAND_TERMS, '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Brand/product names to inject as semantic triples in FAQ answers. This builds brand-entity association for LLMs (+642% AI citation lift).', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<?php
					$is_faq_auto_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();
					$faq_auto_gen    = (string) get_option( RNRD_OPT_FAQ_AUTO_GENERATE, 'off' );
					?>
					<tr<?php echo $is_faq_auto_pro ? '' : ' class="rnrd-row-locked"'; ?>>
						<th scope="row"><?php esc_html_e( 'Auto-Generate on Publish', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php if ( $is_faq_auto_pro ) : ?>
								<label>
									<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_FAQ_AUTO_GENERATE ); ?>" value="off" />
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_FAQ_AUTO_GENERATE ); ?>" value="on" <?php checked( $faq_auto_gen, 'on' ); ?> />
									<?php esc_html_e( 'Automatically generate FAQs when a post is published or updated', 'rankready-ai-llm-seo' ); ?>
								</label>
							<?php else : ?>
								<label style="display:inline-flex;align-items:center;gap:8px;color:#1d2327;">
									<?php echo wp_kses_post( self::locked_checkbox_indicator() ); ?>
									<?php esc_html_e( 'Automatically generate FAQs when a post is published or updated', 'rankready-ai-llm-seo' ); ?>
									<span class="rnrd-soon-badge"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span>
								</label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Off by default. When off, FAQs are only generated via the Gutenberg block, Elementor widget, or Bulk Generate.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>

				<h3 class="rnrd-subsection-title"><?php esc_html_e( 'Display', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-card-desc"><?php esc_html_e( 'Control how FAQs appear on the frontend. Can also use the Gutenberg block or Elementor widget instead.', 'rankready-ai-llm-seo' ); ?></p>

				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto Display', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $faq_auto = (string) get_option( RNRD_OPT_FAQ_AUTO_DISPLAY, 'off' ); ?>
							<label class="rnrd-toggle">
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_FAQ_AUTO_DISPLAY ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_FAQ_AUTO_DISPLAY ); ?>" value="on" <?php checked( $faq_auto, 'on' ); ?> />
								<span class="rnrd-toggle-label"><?php esc_html_e( 'Automatically inject FAQ into post content', 'rankready-ai-llm-seo' ); ?></span>
							</label>
							<p class="description"><?php esc_html_e( 'Off = show only via Gutenberg block, Elementor widget, or shortcode.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Position', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $faq_pos = (string) get_option( RNRD_OPT_FAQ_POSITION, 'after' ); ?>
							<select name="<?php echo esc_attr( RNRD_OPT_FAQ_POSITION ); ?>">
								<option value="before" <?php selected( $faq_pos, 'before' ); ?>><?php esc_html_e( 'Before content', 'rankready-ai-llm-seo' ); ?></option>
								<option value="after"  <?php selected( $faq_pos, 'after' ); ?>><?php esc_html_e( 'After content', 'rankready-ai-llm-seo' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Heading Tag', 'rankready-ai-llm-seo' ); ?></label></th>
						<td>
							<?php $faq_tag = (string) get_option( RNRD_OPT_FAQ_HEADING_TAG, 'h3' ); ?>
							<select name="<?php echo esc_attr( RNRD_OPT_FAQ_HEADING_TAG ); ?>">
								<?php foreach ( array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6' ) as $tag => $label ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>" <?php selected( $faq_tag, $tag ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show "Reviewed" Date', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $show_reviewed = (string) get_option( RNRD_OPT_FAQ_SHOW_REVIEWED, 'on' ); ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_FAQ_SHOW_REVIEWED ); ?>"
								       value="on" <?php checked( $show_reviewed, 'on' ); ?> />
								<?php esc_html_e( 'Show "Last reviewed: [date]" below the FAQ section', 'rankready-ai-llm-seo' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Signals content freshness to LLMs and users.', 'rankready-ai-llm-seo' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save FAQ Settings', 'rankready-ai-llm-seo' ), 'primary', 'submit_faq', false ); ?>
			</div>

		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Headless / Public API
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_headless(): void {
		// ── Pro gate — free users see a locked preview ────────────────────────
		if ( ! ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() ) ) {
			self::render_pro_gate(
				__( 'Headless WordPress Public API', 'rankready-ai-llm-seo' ),
				__( 'Expose FAQ, summaries, and JSON-LD schema via a read-only REST API for Next.js, Nuxt, Astro, SvelteKit, Gatsby, and other headless frontends. Includes CORS control, CDN cache headers, on-demand revalidation webhooks, rate limiting, and WPGraphQL integration.', 'rankready-ai-llm-seo' )
			);
			self::render_pro_gate(
				__( 'On-Demand Revalidation (Next.js / Nuxt)', 'rankready-ai-llm-seo' ),
				__( 'When FAQ or summary data changes, RankReady pings your frontend to revalidate the affected page — fire-and-forget, never blocks the editor.', 'rankready-ai-llm-seo' )
			);
			self::render_pro_gate(
				__( 'WPGraphQL Integration', 'rankready-ai-llm-seo' ),
				__( 'Adds rankready_faq, rankready_summary, and rankready_schema fields to the WPGraphQL schema — zero config required when WPGraphQL is active.', 'rankready-ai-llm-seo' )
			);
			return;
		}

		$enabled          = 'on' === get_option( RNRD_OPT_HEADLESS_ENABLE, 'off' );
		$cors_origins     = (string) get_option( RNRD_OPT_HEADLESS_CORS_ORIGINS, '' );
		$expose_meta      = 'on' === get_option( RNRD_OPT_HEADLESS_EXPOSE_META, 'on' );
		$cache_ttl        = (int) get_option( RNRD_OPT_HEADLESS_CACHE_TTL, 300 );
		$rate_limit       = (int) get_option( RNRD_OPT_HEADLESS_RATE_LIMIT, 120 );
		$revalidate_url   = (string) get_option( RNRD_OPT_HEADLESS_REVALIDATE_URL, '' );
		$revalidate_sec   = (string) get_option( RNRD_OPT_HEADLESS_REVALIDATE_SEC, '' );
		$graphql          = 'on' === get_option( RNRD_OPT_HEADLESS_GRAPHQL, 'off' );
		$graphql_active   = class_exists( 'WPGraphQL' ) || function_exists( 'register_graphql_field' );
		$secret_masked    = ! empty( $revalidate_sec ) ? str_repeat( "\xE2\x80\xA2", 16 ) : '';
		$site_url         = rest_url( 'rankready/v1/public/' );
		?>
		<form method="post" action="options.php" class="rnrd-form">
			<?php settings_fields( self::HEADLESS_GROUP ); ?>

			<?php /* Single Headless card. All sub-sections (Public API, On-Demand
			         Revalidation, WPGraphQL, Endpoint Reference) live inside ONE
			         outer rnrd-card to match the rest of the plugin's UI density.
			         The master "Enable Public API" toggle controls visibility of
			         every setting below it via #rr-headless-inner. */ ?>
			<div class="rnrd-card">
				<div class="rnrd-card-header">
					<h2><?php esc_html_e( 'Headless WordPress Public API', 'rankready-ai-llm-seo' ); ?></h2>
					<p class="rnrd-card-subtitle">
						<?php esc_html_e( 'Expose FAQ, summaries, and JSON-LD schema via a read-only REST API for Next.js, Nuxt, Astro, SvelteKit, Gatsby, and other headless frontends.', 'rankready-ai-llm-seo' ); ?>
					</p>
				</div>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Public API', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<label class="rnrd-toggle">
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_ENABLE ); ?>" value="on" <?php checked( $enabled ); ?>
									data-toggle-target="rnrd-headless-inner" />
								<span class="rnrd-toggle-slider"></span>
							</label>
							<p class="description">
								<?php esc_html_e( 'Turn on the public REST endpoints. Off by default for security. Settings appear once enabled.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<div id="rnrd-headless-inner" class="rnrd-conditional-fields" <?php echo $enabled ? '' : 'style="display:none;"'; ?>>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Expose in Core REST', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_EXPOSE_META ); ?>" value="on" <?php checked( $expose_meta ); ?> />
									<?php esc_html_e( 'Add rankready_faq, rankready_summary, rankready_schema to /wp/v2/posts/{id}', 'rankready-ai-llm-seo' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Recommended for Faust.js, headless themes, and anything that already consumes core WP REST.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'CORS Allowed Origins', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<textarea name="<?php echo esc_attr( RNRD_OPT_HEADLESS_CORS_ORIGINS ); ?>" rows="3" class="large-text code" placeholder="https://www.example.com, https://staging.example.com"><?php echo esc_textarea( $cors_origins ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Comma-separated list of allowed frontend origins. Leave empty to allow all origins (wildcard). Use specific origins in production.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'CDN Cache TTL (seconds)', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<input type="number" min="0" max="31536000" step="1" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_CACHE_TTL ); ?>" value="<?php echo esc_attr( (string) $cache_ttl ); ?>" class="small-text" />
								<p class="description">
									<?php esc_html_e( 'Cache-Control: public, s-maxage=N, stale-while-revalidate=86400. Default 300 (5 min). Set higher for stable content.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Rate Limit (req/min per IP)', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<input type="number" min="0" max="10000" step="1" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_RATE_LIMIT ); ?>" value="<?php echo esc_attr( (string) $rate_limit ); ?>" class="small-text" />
								<p class="description">
									<?php esc_html_e( '0 disables rate limiting. Authenticated editors are always exempt. IP is detected from Cloudflare / X-Forwarded-For / X-Real-IP.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<h3 class="rnrd-subsection-title" style="margin:24px 0 4px;font-size:15px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'On-Demand Revalidation (Next.js / Nuxt)', 'rankready-ai-llm-seo' ); ?></h3>
					<p class="rnrd-subsection-desc" style="margin:0 0 8px;color:#646970;font-size:13px;">
						<?php esc_html_e( 'When FAQ or summary data changes, RankReady pings your frontend to revalidate the affected page. Fire-and-forget, never blocks the editor.', 'rankready-ai-llm-seo' ); ?>
					</p>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Webhook URL', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<input type="url" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_REVALIDATE_URL ); ?>" value="<?php echo esc_attr( $revalidate_url ); ?>" class="large-text" placeholder="https://www.example.com/api/revalidate" />
								<p class="description">
									<?php esc_html_e( 'Your Next.js / Nuxt revalidation endpoint. POST receives JSON { post_id, slug, reason, ts, site } and header X-RR-Secret.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Shared Secret', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<input type="text" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_REVALIDATE_SEC ); ?>" value="<?php echo esc_attr( $secret_masked ); ?>" class="regular-text" autocomplete="off" />
								<p class="description">
									<?php esc_html_e( 'Shared secret sent as X-RR-Secret header. Use hash_equals() to verify on the frontend. Leave blank to clear.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<h3 class="rnrd-subsection-title" style="margin:24px 0 4px;font-size:15px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'WPGraphQL Integration', 'rankready-ai-llm-seo' ); ?></h3>
					<p class="rnrd-subsection-desc" style="margin:0 0 8px;color:#646970;font-size:13px;">
						<?php esc_html_e( 'Register rankReadyFaq, rankReadySummary, rankReadySchema as GraphQL fields on every public post type.', 'rankready-ai-llm-seo' ); ?>
					</p>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Register GraphQL Fields', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<label class="rnrd-toggle">
									<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_HEADLESS_GRAPHQL ); ?>" value="on" <?php checked( $graphql ); ?> <?php disabled( ! $graphql_active ); ?> />
									<span class="rnrd-toggle-slider"></span>
								</label>
								<?php if ( ! $graphql_active ) : ?>
									<p class="description" style="color:#d63638;">
										<?php esc_html_e( 'WPGraphQL plugin is not active. Install and activate it to enable this option.', 'rankready-ai-llm-seo' ); ?>
									</p>
								<?php else : ?>
									<p class="description">
										<?php esc_html_e( 'WPGraphQL detected. Fields will be available on all GraphQL post types.', 'rankready-ai-llm-seo' ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					</table>

					<?php if ( $enabled ) : ?>
					<h3 class="rnrd-subsection-title" style="margin:24px 0 4px;font-size:15px;font-weight:600;color:#1d2327;"><?php esc_html_e( 'Endpoint Reference', 'rankready-ai-llm-seo' ); ?></h3>

					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Base URL', 'rankready-ai-llm-seo' ); ?></th>
							<td><code><?php echo esc_html( $site_url ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Available Routes', 'rankready-ai-llm-seo' ); ?></th>
							<td>
								<ul class="rnrd-endpoint-list">
									<li><code>GET  faq/{id}</code> &mdash; <?php esc_html_e( 'FAQ items for a post', 'rankready-ai-llm-seo' ); ?></li>
									<li><code>GET  summary/{id}</code> &mdash; <?php esc_html_e( 'AI summary for a post', 'rankready-ai-llm-seo' ); ?></li>
									<li><code>GET  schema/{id}</code> &mdash; <?php esc_html_e( 'Ready-to-inject JSON-LD', 'rankready-ai-llm-seo' ); ?></li>
									<li><code>GET  post/{id}</code> &mdash; <?php esc_html_e( 'Combined (FAQ + summary + schema)', 'rankready-ai-llm-seo' ); ?></li>
									<li><code>GET  post-by-slug/{slug}?post_type=post&amp;lang=en</code></li>
									<li><code>GET  list?post_type=post&amp;per_page=20&amp;page=1&amp;since=ISO8601</code></li>
									<li><code>POST revalidate</code> &mdash; <?php esc_html_e( 'Manual revalidation trigger (requires secret)', 'rankready-ai-llm-seo' ); ?></li>
								</ul>
								<p class="description">
									<?php esc_html_e( 'All responses include ETag, Last-Modified, Cache-Control s-maxage + stale-while-revalidate, and X-RR-Request-Id headers. 304 Not Modified is returned on matching If-None-Match / If-Modified-Since.', 'rankready-ai-llm-seo' ); ?>
								</p>
							</td>
						</tr>
					</table>
					<?php endif; ?>

				</div><?php /* /#rr-headless-inner */ ?>
			</div><?php /* /.rnrd-card — single Headless container */ ?>

			<?php submit_button( __( 'Save Headless Settings', 'rankready-ai-llm-seo' ) ); ?>
		</form>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Tools
	// ═══════════════════════════════════════════════════════════════════════════

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Advanced — Tools (refactored into named card helpers in rc.6).
	// Each card is now an independent render_card_*() method so it can be
	// relocated to its proper tab (Content AI / E-E-A-T / Settings / Insights)
	// without duplicating HTML. Field names, JS hook IDs, and option keys
	// are preserved verbatim from rc.5 — no data migration required.
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_tools(): void {
		self::render_card_diagnostics();
		self::render_card_error_log();
		// Branding placeholder card removed in rc.16 — empty Coming Soon card not useful for users.
		// self::render_card_branding();
		self::render_card_data_retention();
	}

	// ── Branding placeholder (added in rc.11) ───────────────────────────────
	private static function render_card_branding(): void {
		$hide_value = (string) get_option( RNRD_OPT_HIDE_BRANDING, 'off' );
		?>
		<div class="rnrd-card" id="rnrd-branding-card">
			<h2 class="rnrd-card-title" style="display:flex;align-items:center;gap:8px;">
				<?php esc_html_e( 'Branding', 'rankready-ai-llm-seo' ); ?>
				<span style="background:#2271b1;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:3px;letter-spacing:.5px;">
					<?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?>
				</span>
			</h2>
			<p class="rnrd-card-goal">
				<?php esc_html_e( 'Hide the "Generated from RankReady" credit line on /llms.txt and /llms-full.txt.', 'rankready-ai-llm-seo' ); ?>
			</p>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'The credit line is a single unbranded sentence — no URL, no version, no marketing. It appears at the bottom of /llms.txt and /llms-full.txt only (robots.txt has technical BEGIN/END markers that stay regardless, similar to "# BEGIN WordPress").', 'rankready-ai-llm-seo' ); ?>
			</p>

			<table class="form-table rnrd-form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Hide RankReady credit', 'rankready-ai-llm-seo' ); ?></th>
					<td>
						<label style="opacity:0.5;">
							<input type="checkbox"
							       name="<?php echo esc_attr( RNRD_OPT_HIDE_BRANDING ); ?>"
							       value="on"
							       <?php checked( $hide_value, 'on' ); ?>
							       disabled />
							<?php esc_html_e( 'Remove "Generated from RankReady" line from llms.txt + llms-full.txt', 'rankready-ai-llm-seo' ); ?>
						</label>
						<p class="description" style="margin-top:6px;">
							<?php esc_html_e( 'This toggle is planned for a future release. Today the credit line always shows.', 'rankready-ai-llm-seo' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<div style="background:#f0f6fc;border-left:3px solid #2271b1;padding:10px 14px;margin-top:8px;font-size:13px;color:#1d2327;border-radius:3px;">
				<strong><?php esc_html_e( 'Note:', 'rankready-ai-llm-seo' ); ?></strong>
				<?php esc_html_e( 'The credit line ("Generated from RankReady") contains no URL, version number, or marketing.', 'rankready-ai-llm-seo' ); ?>
			</div>
		</div>
		<?php
	}

	// ── Bulk Regenerate — AI Summaries (Content AI tab in rc.6) ────────────
	private static function render_card_bulk_summary(): void {
		$post_types   = self::get_allowed_post_types();
		$tools_is_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();
		// Always render the same card structure — controls are disabled in
		// Free with a locked-checkbox indicator + COMING SOON badge in the
		// title. When the upgrade flag flips later, the disabled state drops
		// and the existing JS handlers run the bulk job.
		$locked = ! $tools_is_pro;
		?>

		<div class="rnrd-card<?php echo $locked ? ' rnrd-card--locked' : ''; ?>">
			<h2 class="rnrd-card-title">
				<?php esc_html_e( 'Bulk Regenerate — AI Summaries', 'rankready-ai-llm-seo' ); ?>
				<?php if ( $locked ) : ?>
					<?php echo wp_kses_post( self::locked_checkbox_indicator() ); ?>
					<span class="rnrd-soon-badge"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span>
				<?php endif; ?>
			</h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Run summaries across every published post in one job. Resumable, skip-on-unchanged.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Generate AI summaries across all existing published posts. Skips posts with unchanged content. Processes 5 posts at a time.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<table class="form-table rnrd-form-table" style="width:auto;">
				<tr>
					<th style="padding:10px 20px 10px 0;"><?php esc_html_e( 'Post Types', 'rankready-ai-llm-seo' ); ?></th>
					<td>
						<?php foreach ( $post_types as $slug => $label ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" class="rnrd-bulk-type" value="<?php echo esc_attr( $slug ); ?>" <?php checked( ! $locked ); ?> <?php disabled( $locked ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th></th>
					<td>
						<button id="rnrd-bulk-start" class="button button-primary" <?php disabled( $locked ); ?>><?php esc_html_e( 'Start Bulk Generate', 'rankready-ai-llm-seo' ); ?></button>
						<button id="rnrd-bulk-resume" class="button button-secondary" style="margin-left:8px;" <?php disabled( $locked ); ?>><?php esc_html_e( 'Resume', 'rankready-ai-llm-seo' ); ?></button>
						<button id="rnrd-bulk-stop" class="button button-secondary" style="display:none;margin-left:8px;"><?php esc_html_e( 'Stop', 'rankready-ai-llm-seo' ); ?></button>
						<p class="description" style="margin-top:4px;">
							<?php if ( $locked ) : ?>
								<?php esc_html_e( 'Manual summaries (Regenerate button + Gutenberg block) remain unlimited. Bulk processing is planned for a future release.', 'rankready-ai-llm-seo' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Resume picks up from where you stopped.', 'rankready-ai-llm-seo' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>

			<div id="rnrd-bulk-progress" style="display:none;margin-top:16px;">
				<div class="rnrd-progress-track">
					<div id="rnrd-bulk-bar" class="rnrd-progress-fill"></div>
				</div>
				<p id="rnrd-bulk-status" class="rnrd-progress-label"><?php esc_html_e( 'Preparing...', 'rankready-ai-llm-seo' ); ?></p>
			</div>
		</div>

		<?php
	}

	// ── Bulk Generate FAQs (Content AI tab in rc.6) ────────────────────────
	private static function render_card_bulk_faq(): void {
		$post_types   = self::get_allowed_post_types();
		$tools_is_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();
		$locked       = ! $tools_is_pro;

		// Always render — Free shows it locked, Pro shows it interactive.
		?>

		<div class="rnrd-card<?php echo $locked ? ' rnrd-card--locked' : ''; ?>">
			<h2 class="rnrd-card-title">
				<?php esc_html_e( 'Bulk Regenerate — FAQ', 'rankready-ai-llm-seo' ); ?>
				<?php if ( $locked ) : ?>
					<?php echo wp_kses_post( self::locked_checkbox_indicator() ); ?>
					<span class="rnrd-soon-badge"><?php esc_html_e( 'COMING SOON', 'rankready-ai-llm-seo' ); ?></span>
				<?php endif; ?>
			</h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Generate FAQ Q&A pairs for every existing post. Requires DataForSEO + AI provider keys.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Generate FAQ Q&A pairs for all existing published posts using DataForSEO + your active AI provider. Requires both API keys to be configured.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<table class="form-table rnrd-form-table" style="width:auto;">
				<tr>
					<th style="padding:10px 20px 10px 0;"><?php esc_html_e( 'Post Types', 'rankready-ai-llm-seo' ); ?></th>
					<td>
						<?php foreach ( $post_types as $slug => $label ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" class="rnrd-faq-bulk-type" value="<?php echo esc_attr( $slug ); ?>" <?php checked( ! $locked ); ?> <?php disabled( $locked ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th></th>
					<td>
						<button id="rnrd-faq-bulk-start" class="button button-primary" <?php disabled( $locked ); ?>><?php esc_html_e( 'Start Bulk FAQ Generate', 'rankready-ai-llm-seo' ); ?></button>
						<button id="rnrd-faq-bulk-resume" class="button button-secondary" style="margin-left:8px;" <?php disabled( $locked ); ?>><?php esc_html_e( 'Resume', 'rankready-ai-llm-seo' ); ?></button>
						<button id="rnrd-faq-bulk-stop" class="button button-secondary" style="display:none;margin-left:8px;"><?php esc_html_e( 'Stop', 'rankready-ai-llm-seo' ); ?></button>
						<p class="description" style="margin-top:4px;">
							<?php if ( $locked ) : ?>
								<?php esc_html_e( 'Manual FAQ generation (Gutenberg block + Elementor widget) remains unlimited. Bulk processing is planned for a future release.', 'rankready-ai-llm-seo' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Skips posts with unchanged content. Resume picks up from where you stopped.', 'rankready-ai-llm-seo' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>

			<div id="rnrd-faq-bulk-progress" style="display:none;margin-top:16px;">
				<div class="rnrd-progress-track">
					<div id="rnrd-faq-bulk-bar" class="rnrd-progress-fill"></div>
				</div>
				<p id="rnrd-faq-bulk-status" class="rnrd-progress-label"><?php esc_html_e( 'Preparing...', 'rankready-ai-llm-seo' ); ?></p>
			</div>
		</div>
		<?php
	}

	// ── Bulk Author Changer (E-E-A-T tab in rc.6) ──────────────────────────
	private static function render_card_bulk_author(): void {
		$users = self::get_authors();
		?>

		<!-- Bulk Author Changer -->
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Bulk Author Changer', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Reassign authors across any post type — preview count before executing.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Reassign authors across any post type. Preview the affected count before executing.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<table class="form-table rnrd-form-table">
				<!-- Post Types -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Types', 'rankready-ai-llm-seo' ); ?></th>
					<td>
						<div class="rnrd-checkboxes-inline">
							<?php foreach ( self::get_author_post_types() as $slug => $label ) : ?>
								<label>
									<input type="checkbox" class="rnrd-bac-pt" value="<?php echo esc_attr( $slug ); ?>" />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Select one or more post types to affect.', 'rankready-ai-llm-seo' ); ?></p>
					</td>
				</tr>
				<!-- From Author -->
				<tr>
					<th scope="row"><label for="rnrd-bac-from"><?php esc_html_e( 'Current Author (From)', 'rankready-ai-llm-seo' ); ?></label></th>
					<td>
						<select id="rnrd-bac-from" class="regular-text">
							<option value=""><?php esc_html_e( '-- All authors --', 'rankready-ai-llm-seo' ); ?></option>
							<?php foreach ( $users as $user ) : ?>
								<option value="<?php echo esc_attr( $user->ID ); ?>">
									<?php echo esc_html( $user->display_name . ' (@' . $user->user_login . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Leave blank to reassign posts regardless of current author.', 'rankready-ai-llm-seo' ); ?></p>
					</td>
				</tr>
				<!-- To Author -->
				<tr>
					<th scope="row">
						<label for="rnrd-bac-to"><?php esc_html_e( 'New Author (To)', 'rankready-ai-llm-seo' ); ?> <span style="color:#d63638;">*</span></label>
					</th>
					<td>
						<select id="rnrd-bac-to" class="regular-text">
							<option value=""><?php esc_html_e( '-- Select new author --', 'rankready-ai-llm-seo' ); ?></option>
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
					<th scope="row"><?php esc_html_e( 'Date Range', 'rankready-ai-llm-seo' ); ?></th>
					<td>
						<div class="rnrd-date-range">
							<div>
								<label for="rnrd-bac-date-from"><?php esc_html_e( 'After', 'rankready-ai-llm-seo' ); ?></label>
								<input type="date" id="rnrd-bac-date-from" />
							</div>
							<div>
								<label for="rnrd-bac-date-to"><?php esc_html_e( 'Before', 'rankready-ai-llm-seo' ); ?></label>
								<input type="date" id="rnrd-bac-date-to" />
							</div>
						</div>
						<p class="description"><?php esc_html_e( 'Optional — leave blank to include all dates.', 'rankready-ai-llm-seo' ); ?></p>
					</td>
				</tr>
				<!-- Actions -->
				<tr>
					<th></th>
					<td>
						<div class="rnrd-tool-actions">
							<button id="rnrd-bac-preview" class="button button-secondary"><?php esc_html_e( 'Preview Count', 'rankready-ai-llm-seo' ); ?></button>
							<button id="rnrd-bac-execute" class="button button-primary" disabled><?php esc_html_e( 'Execute', 'rankready-ai-llm-seo' ); ?></button>
							<button id="rnrd-bac-stop" class="button" style="display:none;"><?php esc_html_e( 'Stop', 'rankready-ai-llm-seo' ); ?></button>
						</div>
					</td>
				</tr>
			</table>

			<!-- Preview result -->
			<div id="rnrd-bac-preview-result" style="display:none;" class="rnrd-notice rnrd-notice--info"></div>

			<!-- Progress -->
			<div id="rnrd-bac-progress" style="display:none;margin-top:16px;">
				<div class="rnrd-progress-track">
					<div id="rnrd-bac-bar" class="rnrd-progress-fill"></div>
				</div>
				<p id="rnrd-bac-status" class="rnrd-progress-label"></p>
			</div>

			<!-- Done -->
			<div id="rnrd-bac-done" style="display:none;" class="rnrd-notice rnrd-notice--success"></div>
		</div>
		<?php
	}

	// ── API Usage (Settings tab in rc.6) ───────────────────────────────────
	private static function render_card_api_usage(): void {
		// Token Usage data
		$token_usage = (array) get_option( 'rnrd_token_usage', array(
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
		// you have active. See RNRD_LLM for the per-model price reference.
		$est_cost = ( $total_tokens / 1000000 ) * 0.30;

		// DataForSEO usage.
		$dfs_usage = (array) get_option( 'rnrd_dfs_usage', array(
			'total_calls' => 0,
			'total_cost'  => 0,
		) );
		$dfs_calls = isset( $dfs_usage['total_calls'] ) ? (int) $dfs_usage['total_calls'] : 0;
		$dfs_cost  = isset( $dfs_usage['total_cost'] ) ? (float) $dfs_usage['total_cost'] : 0;
		?>
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'API Usage', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Cumulative tokens, calls, and estimated cost since tracking began.', 'rankready-ai-llm-seo' ); ?></p>

			<?php
			$active_provider_label = RNRD_LLM::get_provider_label( RNRD_LLM::get_active_provider() );
			$total_blended         = (float) $est_cost + (float) $dfs_cost;
			?>

			<div class="rnrd-kpi-row" style="margin-top:14px;">
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Total cost', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'AI + DataForSEO', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value">$<?php echo esc_html( number_format( $total_blended, 4 ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'Blended estimate', 'rankready-ai-llm-seo' ); ?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'Total tokens', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php echo esc_html( $active_provider_label ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total_tokens ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php
						printf(
							/* translators: 1: summary token count, 2: FAQ token count */
							esc_html__( '%1$s summary · %2$s FAQ', 'rankready-ai-llm-seo' ),
							esc_html( number_format_i18n( $summary_tokens ) ),
							esc_html( number_format_i18n( $faq_tokens ) )
						);
					?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'AI API calls', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php echo esc_html( $active_provider_label ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $total_calls ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'Cumulative', 'rankready-ai-llm-seo' ); ?></div>
				</div>
				<div class="rnrd-kpi">
					<div class="rnrd-kpi__label"><?php esc_html_e( 'DataForSEO calls', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__period"><?php esc_html_e( 'FAQ question discovery', 'rankready-ai-llm-seo' ); ?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $dfs_calls ) ); ?></div>
					<div class="rnrd-kpi__foot">$<?php echo esc_html( number_format( $dfs_cost, 4 ) ); ?> <?php esc_html_e( 'spent', 'rankready-ai-llm-seo' ); ?></div>
				</div>
			</div>

			<p style="margin-top:16px;">
				<button type="button" id="rnrd-tokens-load" class="button button-secondary"><?php esc_html_e( 'Load Per-Post Details', 'rankready-ai-llm-seo' ); ?></button>
				<span id="rnrd-tokens-count" style="margin-left:10px;font-size:13px;display:none;"></span>
			</p>
			<div id="rnrd-tokens-list" style="display:none;margin-top:12px;max-height:400px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Type', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Tokens Used', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:15%;"><?php esc_html_e( 'Actions', 'rankready-ai-llm-seo' ); ?></th>
						</tr>
					</thead>
					<tbody id="rnrd-tokens-tbody"></tbody>
				</table>
			</div>
		</div>
		<?php
	}

	// ── Content Freshness Alerts (Insights → Freshness sub-tab in rc.6) ────
	private static function render_card_freshness_alerts(): void {
		?>

		<!-- Content Freshness Alerts (merged with intro paragraph in rc.16) -->
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Content Freshness', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Pages refreshed within 60 days are prioritised by ChatGPT, Perplexity, and Gemini.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Fresh content earns about 28% more AI citations (multiple 2026 studies) and 65% of AI citations target content updated within the past year. Use the scan tool below to surface stale posts, then the buckets to refresh them in priority order.', 'rankready-ai-llm-seo' ); ?>
			</p>
			<table class="form-table rnrd-form-table" style="width:auto;">
				<tr>
					<th style="padding:10px 20px 10px 0;">
						<label for="rnrd-freshness-days"><?php esc_html_e( 'Stale Threshold', 'rankready-ai-llm-seo' ); ?></label>
					</th>
					<td>
						<select id="rnrd-freshness-days">
							<option value="60"><?php esc_html_e( '60 days', 'rankready-ai-llm-seo' ); ?></option>
							<option value="90" selected><?php esc_html_e( '90 days (recommended)', 'rankready-ai-llm-seo' ); ?></option>
							<option value="180"><?php esc_html_e( '180 days', 'rankready-ai-llm-seo' ); ?></option>
							<option value="365"><?php esc_html_e( '1 year', 'rankready-ai-llm-seo' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th></th>
					<td>
						<button type="button" id="rnrd-freshness-scan" class="button button-primary"><?php esc_html_e( 'Scan Content Freshness', 'rankready-ai-llm-seo' ); ?></button>
						<span id="rnrd-freshness-status" style="margin-left:10px;font-size:13px;display:none;"></span>
					</td>
				</tr>
			</table>
			<div id="rnrd-freshness-summary" style="display:none;margin-top:12px;"></div>
			<div id="rnrd-freshness-results" style="display:none;margin-top:16px;max-height:500px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th style="width:5%;"><?php esc_html_e( 'Urgency', 'rankready-ai-llm-seo' ); ?></th>
							<th><?php esc_html_e( 'Post', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Type', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Last Updated', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Days Stale', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Summary', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'FAQ', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:8%;"><?php esc_html_e( 'Actions', 'rankready-ai-llm-seo' ); ?></th>
						</tr>
					</thead>
					<tbody id="rnrd-freshness-tbody"></tbody>
				</table>
			</div>

			<?php
			// Freshness widget — segmented Stale/Going stale/Fresh tabs +
			// per-post checklist. Lives INSIDE the same card so the design
			// reads as one consolidated panel, not two stacked boxes.
			if ( class_exists( 'RNRD_Freshness' ) ) {
				RNRD_Freshness::render_widget();
			}
			?>
		</div>
		<?php
	}

	// ── Diagnostics (stays in Advanced) ────────────────────────────────────
	private static function render_card_diagnostics(): void {
		?>

		<!-- Diagnostics — replaced legacy Health Check in v1.2.0-rc.5.
		     22 real endpoint probes + conflict detection + 1-click copy report
		     for support. JS handler lives in assets/admin.js. -->
		<div class="rnrd-card" id="rnrd-diagnostics-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Diagnostics', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Live probes that actually fetch your endpoints + detect plugin conflicts. Every failure ships with a fix.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Live probes that actually fetch /llms.txt, /robots.txt, /.well-known/mcp.json and every Markdown route — then detect cache/builder/SEO plugin conflicts. Every failure ships with a one-line fix.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<p>
				<button type="button" id="rnrd-diag-run" class="button button-primary">
					<?php esc_html_e( 'Run Diagnostics', 'rankready-ai-llm-seo' ); ?>
				</button>
				<label style="margin-left:14px;font-size:13px;color:#646970;">
					<input type="checkbox" id="rnrd-diag-include-api" />
					<?php esc_html_e( 'Also test LLM provider keys (uses 1 API call per provider)', 'rankready-ai-llm-seo' ); ?>
				</label>
				<span id="rnrd-diag-status" style="margin-left:10px;font-size:13px;display:none;"></span>
			</p>

			<!-- Summary chips (filled after first run) -->
			<div id="rnrd-diag-summary" style="display:none;margin-top:12px;font-size:13px;"></div>

			<!-- Results table -->
			<div id="rnrd-diag-results" style="display:none;margin-top:16px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th style="width:5%;"></th>
							<th style="width:28%;"><?php esc_html_e( 'Check', 'rankready-ai-llm-seo' ); ?></th>
							<th><?php esc_html_e( 'Result + Fix', 'rankready-ai-llm-seo' ); ?></th>
						</tr>
					</thead>
					<tbody id="rnrd-diag-tbody"></tbody>
				</table>
			</div>

			<?php
			// FREE-99 — Server-level cache bypass snippets.
			// Surfaced because the probe_edge_cache_hit check can detect server
			// caches (LSWS, nginx FastCGI, Varnish) that runtime PHP cannot fix.
			// Hidden in a collapsed accordion so it stays out of the way when
			// not needed — appears below the run-diagnostics results.
			if ( class_exists( 'RNRD_Cache' ) ) :
				$htaccess_snippet   = RNRD_Cache::apache_htaccess_snippet();
				$nginx_snippet      = RNRD_Cache::nginx_snippet();
				$cloudflare_snippet = RNRD_Cache::cloudflare_cache_rule_snippet();
			?>
			<details class="rnrd-diag-snippet" style="margin-top:16px;padding:12px 14px;border:1px solid #E5E7E0;border-radius:8px;background:#fafbfa;">
				<summary style="cursor:pointer;font-weight:600;font-size:13px;color:#1d2327;">
					<?php esc_html_e( 'Server / CDN cache bypass snippets (advanced)', 'rankready-ai-llm-seo' ); ?>
				</summary>
				<p style="margin:10px 0 6px;font-size:12px;color:#646970;line-height:1.5;">
					<?php esc_html_e( 'Apply these only when a cache layer is serving stale or wrong-type responses to AI crawlers (e.g. Cloudflare returning HTML to Accept: text/markdown requests, or LSWS caching /llms.txt before PHP runs).', 'rankready-ai-llm-seo' ); ?>
				</p>
				<p style="margin:14px 0 4px;font-size:12px;font-weight:600;color:#1d2327;">
					<?php esc_html_e( 'Cloudflare — paste into Cache Rules → Custom filter expression', 'rankready-ai-llm-seo' ); ?>
				</p>
				<textarea readonly class="rnrd-diag-snippet-text" style="width:100%;height:140px;font-family:Menlo,Consolas,monospace;font-size:11px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;"><?php echo esc_textarea( $cloudflare_snippet ); ?></textarea>
				<p style="margin:14px 0 4px;font-size:12px;font-weight:600;color:#1d2327;">
					<?php esc_html_e( 'Apache / LiteSpeed — paste at top of .htaccess', 'rankready-ai-llm-seo' ); ?>
				</p>
				<textarea readonly class="rnrd-diag-snippet-text" style="width:100%;height:140px;font-family:Menlo,Consolas,monospace;font-size:11px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;"><?php echo esc_textarea( $htaccess_snippet ); ?></textarea>
				<p style="margin:14px 0 4px;font-size:12px;font-weight:600;color:#1d2327;">
					<?php esc_html_e( 'nginx — add inside your server { } block', 'rankready-ai-llm-seo' ); ?>
				</p>
				<textarea readonly class="rnrd-diag-snippet-text" style="width:100%;height:140px;font-family:Menlo,Consolas,monospace;font-size:11px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:8px;"><?php echo esc_textarea( $nginx_snippet ); ?></textarea>
			</details>
			<?php endif; ?>

			<!-- Copy support report -->
			<div id="rnrd-diag-copy-row" style="display:none;margin-top:14px;padding-top:14px;border-top:1px solid #e5e5e5;">
				<p style="margin:0 0 6px;font-size:13px;color:#1d2327;">
					<strong><?php esc_html_e( 'Need help?', 'rankready-ai-llm-seo' ); ?></strong>
					<?php esc_html_e( 'Click below to copy a full diagnostic report with active plugins, versions, and conflict details — paste into any support channel or include with a bug report.', 'rankready-ai-llm-seo' ); ?>
				</p>
				<button type="button" id="rnrd-diag-copy" class="button button-secondary">
					<span class="dashicons dashicons-clipboard" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php esc_html_e( 'Copy Diagnostic Report', 'rankready-ai-llm-seo' ); ?>
				</button>
				<span id="rnrd-diag-copy-status" style="margin-left:10px;font-size:13px;display:none;"></span>
				<details style="margin-top:10px;">
					<summary style="cursor:pointer;font-size:12px;color:#646970;">
						<?php esc_html_e( 'Preview report (plaintext)', 'rankready-ai-llm-seo' ); ?>
					</summary>
					<textarea id="rnrd-diag-report-preview" readonly style="width:100%;height:220px;font-family:Menlo,Consolas,monospace;font-size:11px;margin-top:8px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:10px;" placeholder="<?php esc_attr_e( 'Run diagnostics, then click Copy to see report here.', 'rankready-ai-llm-seo' ); ?>"></textarea>
				</details>
			</div>
		</div>
		<?php
	}

	// ── Error Log (stays in Advanced) ──────────────────────────────────────
	private static function render_card_error_log(): void {
		?>

		<!-- Error Log -->
		<div class="rnrd-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Error Log', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-goal"><?php esc_html_e( 'Recent API errors from OpenAI, Anthropic, Gemini, DeepSeek, DataForSEO. Last 50 entries.', 'rankready-ai-llm-seo' ); ?></p>
			<p class="rnrd-card-desc"><?php esc_html_e( 'Recent API errors from OpenAI and DataForSEO. Shows the last 50 entries.', 'rankready-ai-llm-seo' ); ?></p>
			<p>
				<button type="button" id="rnrd-errors-load" class="button button-secondary"><?php esc_html_e( 'Load Error Log', 'rankready-ai-llm-seo' ); ?></button>
				<button type="button" id="rnrd-errors-clear" class="button" style="margin-left:8px;"><?php esc_html_e( 'Clear Log', 'rankready-ai-llm-seo' ); ?></button>
				<span id="rnrd-errors-status" style="margin-left:10px;font-size:13px;display:none;"></span>
			</p>
			<div id="rnrd-errors-list" style="display:none;margin-top:16px;max-height:400px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th style="width:15%;"><?php esc_html_e( 'When', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:12%;"><?php esc_html_e( 'Source', 'rankready-ai-llm-seo' ); ?></th>
							<th><?php esc_html_e( 'Error', 'rankready-ai-llm-seo' ); ?></th>
							<th style="width:10%;"><?php esc_html_e( 'Post', 'rankready-ai-llm-seo' ); ?></th>
						</tr>
					</thead>
					<tbody id="rnrd-errors-tbody"></tbody>
				</table>
			</div>
		</div>
		<?php
	}

	// ── Data Retention (stays in Advanced — isolated DATA_GROUP form) ──────
	private static function render_card_data_retention(): void {
		?>

		<!-- ── Data Retention (moved here from Settings tab in rc.3) ──────── -->
		<form method="post" action="options.php" novalidate="novalidate" class="rnrd-data-form">
			<?php settings_fields( self::DATA_GROUP ); /* Isolated group — saves only the uninstall toggle. */ ?>

			<div class="rnrd-card" style="margin-bottom:24px;">
				<h2 class="rnrd-card-title"><?php esc_html_e( 'Data Retention', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-card-goal"><?php esc_html_e( 'Choose what happens to RankReady data when the plugin is uninstalled. Default keeps everything.', 'rankready-ai-llm-seo' ); ?></p>
				<p class="rnrd-card-desc">
					<?php esc_html_e( 'Control what happens to your RankReady data when the plugin is deleted.', 'rankready-ai-llm-seo' ); ?>
				</p>
				<table class="form-table rnrd-form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'On Deactivate', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<p style="margin:0;">
								<span class="dashicons dashicons-shield" style="color:#46b450;"></span>
								<strong><?php esc_html_e( 'Nothing is deleted on deactivation.', 'rankready-ai-llm-seo' ); ?></strong>
							</p>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'Deactivating RankReady only pauses its hooks and clears scheduled cron jobs. All settings, API keys, AI summaries, FAQ data, Author Box profiles, freshness history, and post meta stay exactly where they are. You can reactivate any time and pick up where you left off.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'On Uninstall (Delete)', 'rankready-ai-llm-seo' ); ?></th>
						<td>
							<?php $delete_on_uninstall = (string) get_option( RNRD_OPT_DELETE_ON_UNINSTALL, 'off' ); ?>
							<label>
								<input type="hidden" name="<?php echo esc_attr( RNRD_OPT_DELETE_ON_UNINSTALL ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RNRD_OPT_DELETE_ON_UNINSTALL ); ?>" value="on" <?php checked( $delete_on_uninstall, 'on' ); ?> />
								<?php esc_html_e( 'Delete all RankReady data when the plugin is uninstalled', 'rankready-ai-llm-seo' ); ?>
							</label>
							<p class="description" style="margin-top:6px;">
								<?php esc_html_e( 'OFF by default. Uninstalling preserves all your data — API keys, settings, every AI Summary, every FAQ, every Author Box profile, all post meta. Reinstalling RankReady brings everything back automatically.', 'rankready-ai-llm-seo' ); ?>
							</p>
							<p class="description" style="margin-top:6px;color:var(--rnrd-color-danger,#d63638);">
								<strong><?php esc_html_e( 'Warning:', 'rankready-ai-llm-seo' ); ?></strong>
								<?php esc_html_e( 'When ON, uninstall permanently removes every RankReady option, post meta, and user meta. Cannot be undone. Leave OFF unless you need a completely clean slate.', 'rankready-ai-llm-seo' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Data Retention', 'rankready-ai-llm-seo' ) ); ?>
			</div>
		</form>
		<?php
	}

	// (TAB: Info removed in rc.15 — "How It Works" + "Quick Stats" cards were
	// moved to Dashboard scorecard in rc.5/rc.6. The old render_tab_info()
	// method was orphan code with no callers — full removal here.)

	// ── Per-post meta box ─────────────────────────────────────────────────────

	public static function register_meta_box(): void {
		// Union of every post type RankReady touches — keeps the consolidated
		// meta box visible wherever any RankReady feature applies. Reduces
		// "where do I tick exclude from llms.txt?" support tickets.
		$pts = array();
		foreach ( array(
			(array) get_option( RNRD_OPT_POST_TYPES, array( 'post' ) ),
			(array) get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) ),
			(array) get_option( RNRD_OPT_MD_POST_TYPES, array( 'post', 'page' ) ),
		) as $list ) {
			foreach ( $list as $pt ) {
				$pts[ $pt ] = true;
			}
		}

		foreach ( array_keys( $pts ) as $pt ) {
			add_meta_box(
				'rnrd_summary_meta',
				__( 'RankReady — Agent Visibility', 'rankready-ai-llm-seo' ),
				array( self::class, 'render_meta_box' ),
				$pt,
				'side',
				'default'
			);
		}
	}

	public static function render_meta_box( $post ): void {
		$disabled        = (bool) get_post_meta( $post->ID, RNRD_META_DISABLE, true );
		$llms_excluded   = '1' === (string) get_post_meta( $post->ID, RNRD_META_LLMS_EXCLUDE, true );
		$snippet_pref    = (string) get_post_meta( $post->ID, RNRD_META_MAX_SNIPPET, true );
		$snippet_default = 'on' === get_option( RNRD_OPT_MAX_SNIPPET_DEFAULT, 'on' );
		$summary         = (string) get_post_meta( $post->ID, RNRD_META_SUMMARY, true );
		$generated       = (int) get_post_meta( $post->ID, RNRD_META_GENERATED, true );
		$faq             = (string) get_post_meta( $post->ID, RNRD_META_FAQ, true );

		// ── Compute status banner ────────────────────────────────────────
		// Decide tone (ok / warn / muted) + plain-English headline + sub.
		$status = self::compute_meta_box_status( $post, $disabled, $llms_excluded, ! empty( $summary ), ! empty( $faq ) );

		wp_nonce_field( 'rnrd_meta_box', 'rnrd_meta_nonce' );
		// Meta-box CSS now lives in assets/admin.css §40 (WP.org Rule #3 —
		// no inline <style> in PHP). Enqueued on post-edit screens via the
		// admin_enqueue_scripts hook in enqueue_admin_assets().
		?>

		<div class="rnrd-mb">
			<div class="rnrd-mb__status rnrd-mb__status--<?php echo esc_attr( $status['tone'] ); ?>">
				<span class="rnrd-mb__icon" aria-hidden="true"><?php echo esc_html( $status['icon'] ); ?></span>
				<span>
					<span class="rnrd-mb__title"><?php echo esc_html( $status['title'] ); ?></span>
					<span class="rnrd-mb__sub"><?php echo esc_html( $status['sub'] ); ?></span>
				</span>
			</div>

			<?php
			// Compact summary preview when a summary exists.
			if ( ! empty( $summary ) ) :
				$decoded = RNRD_Generator::decode_summary( $summary );
				if ( 'bullets' === $decoded['type'] && ! empty( $decoded['data'] ) ) : ?>
					<div class="rnrd-mb__preview">
						<ul>
							<?php foreach ( array_slice( (array) $decoded['data'], 0, 3 ) as $bullet ) : ?>
								<li><?php echo esc_html( $bullet ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif;
			endif; ?>

			<details<?php echo ( $disabled || $llms_excluded || '' !== $snippet_pref ) ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Advanced options', 'rankready-ai-llm-seo' ); ?></summary>
				<div class="rnrd-mb__advanced">

					<div class="rnrd-mb__field">
						<label class="rnrd-mb__field-label" for="rnrd_max_snippet"><?php esc_html_e( 'AI snippet', 'rankready-ai-llm-seo' ); ?></label>
						<select name="rnrd_max_snippet" id="rnrd_max_snippet">
							<option value="" <?php selected( $snippet_pref, '' ); ?>>
								<?php
								printf(
								/* translators: %s: name of the default summary mode */
									esc_html__( 'Use default (%s)', 'rankready-ai-llm-seo' ),
									$snippet_default ? esc_html__( 'Allow full snippet', 'rankready-ai-llm-seo' ) : esc_html__( 'Standard snippet', 'rankready-ai-llm-seo' )
								);
								?>
							</option>
							<option value="on" <?php selected( $snippet_pref, 'on' ); ?>>
								<?php esc_html_e( 'Allow full snippet (max-snippet:-1)', 'rankready-ai-llm-seo' ); ?>
							</option>
							<option value="off" <?php selected( $snippet_pref, 'off' ); ?>>
								<?php esc_html_e( 'Standard snippet only', 'rankready-ai-llm-seo' ); ?>
							</option>
						</select>
						<p class="rnrd-mb__hint"><?php esc_html_e( 'How much of this page AI engines may quote.', 'rankready-ai-llm-seo' ); ?></p>
					</div>

					<div class="rnrd-mb__field">
						<label>
							<input type="checkbox" name="rnrd_llms_exclude" value="1" <?php checked( $llms_excluded ); ?> />
							<?php esc_html_e( 'Exclude this post from llms.txt', 'rankready-ai-llm-seo' ); ?>
						</label>
					</div>

					<div class="rnrd-mb__field">
						<label>
							<input type="checkbox" name="rnrd_disable_summary" value="1" <?php checked( $disabled ); ?> />
							<?php esc_html_e( 'Disable AI summary on publish', 'rankready-ai-llm-seo' ); ?>
						</label>
					</div>

					<?php if ( $generated ) : ?>
						<p class="rnrd-mb__hint">
							<?php
							printf(
							/* translators: %s: human-readable age like "3 days" */
								esc_html__( 'Summary generated %s ago', 'rankready-ai-llm-seo' ),
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
			if ( $disabled )      { $flags[] = __( 'AI summary disabled', 'rankready-ai-llm-seo' ); }
			if ( $llms_excluded ) { $flags[] = __( 'excluded from llms.txt', 'rankready-ai-llm-seo' ); }
			return array(
				'tone'  => 'warn',
				'icon'  => '⚠',
				'title' => __( 'AI visibility reduced', 'rankready-ai-llm-seo' ),
				'sub'   => implode( ' • ', $flags ),
			);
		}

		if ( $has_summary && $has_faq ) {
			return array(
				'tone'  => 'ok',
				'icon'  => '✓',
				'title' => __( 'Optimised for AI', 'rankready-ai-llm-seo' ),
				'sub'   => __( 'Summary + FAQ ready. ChatGPT, Perplexity & Claude can cite this page.', 'rankready-ai-llm-seo' ),
			);
		}

		if ( $has_summary ) {
			return array(
				'tone'  => 'ok',
				'icon'  => '✓',
				'title' => __( 'AI summary ready', 'rankready-ai-llm-seo' ),
				'sub'   => __( 'Add an FAQ to boost citation rate.', 'rankready-ai-llm-seo' ),
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return array(
				'tone'  => 'muted',
				'icon'  => '○',
				'title' => __( 'Generation runs on publish', 'rankready-ai-llm-seo' ),
				'sub'   => __( 'RankReady generates summary + FAQ after this post goes live.', 'rankready-ai-llm-seo' ),
			);
		}

		return array(
			'tone'  => 'muted',
			'icon'  => '○',
			'title' => __( 'Not yet optimised', 'rankready-ai-llm-seo' ),
			'sub'   => __( 'Open the AI tab to generate summary + FAQ.', 'rankready-ai-llm-seo' ),
		);
	}

	public static function save_meta_box( $post_id ): void {
		if ( ! isset( $_POST['rnrd_meta_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rnrd_meta_nonce'] ) ), 'rnrd_meta_box' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// AI summary disable.
		$disabled = isset( $_POST['rnrd_disable_summary'] ) ? '1' : '';
		update_post_meta( $post_id, RNRD_META_DISABLE, $disabled );

		// llms.txt per-post exclusion (v1.2.0).
		$llms_excluded = isset( $_POST['rnrd_llms_exclude'] ) ? '1' : '';
		update_post_meta( $post_id, RNRD_META_LLMS_EXCLUDE, $llms_excluded );

		// max-snippet preference (v1.2.0) — '' inherits sitewide default.
		$snippet = isset( $_POST['rnrd_max_snippet'] ) ? sanitize_key( wp_unslash( $_POST['rnrd_max_snippet'] ) ) : '';
		if ( ! in_array( $snippet, array( '', 'on', 'off' ), true ) ) {
			$snippet = '';
		}
		if ( '' === $snippet ) {
			delete_post_meta( $post_id, RNRD_META_MAX_SNIPPET );
		} else {
			update_post_meta( $post_id, RNRD_META_MAX_SNIPPET, $snippet );
		}
	}

	// ── Test connection ───────────────────────────────────────────────────────

	private static function test_connection_url(): string {
		return add_query_arg( array(
			'page'           => self::MENU_SLUG,
			'tab'            => 'api',
			self::NONCE_FIELD => wp_create_nonce( self::NONCE_ACTION ),
			'rnrd_action'      => 'test',
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
			. '<strong>' . esc_html__( 'RankReady: Pretty Permalinks required', 'rankready-ai-llm-seo' ) . '</strong> — '
			. esc_html__( 'Your site is using plain permalinks (?p=123). RankReady\'s LLM endpoints (llms.txt, llms-full.txt, and per-post .md files) will return 404 until you enable pretty permalinks.', 'rankready-ai-llm-seo' )
			. ' <a href="' . esc_url( $permalink_url ) . '">'
			. esc_html__( 'Fix it in Settings → Permalinks →', 'rankready-ai-llm-seo' )
			. '</a>'
			. '</p></div>';
	}

	public static function connection_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}
		if ( empty( $_GET['rnrd_action'] ) || 'test' !== $_GET['rnrd_action'] ) {
			return;
		}
		if ( ! isset( $_GET[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			wp_die( esc_html__( 'Security check failed.', 'rankready-ai-llm-seo' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'rankready-ai-llm-seo' ) );
		}
		$result = RNRD_Generator::test_api_connection();
		if ( true === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Connection successful! Your OpenAI API key is valid.', 'rankready-ai-llm-seo' )
				. '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'Connection failed: ', 'rankready-ai-llm-seo' )
				. esc_html( $result )
				. '</p></div>';
		}
	}

	// ── Plugin action links ───────────────────────────────────────────────────

	public static function action_links( array $links ): array {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">'
			. esc_html__( 'Settings', 'rankready-ai-llm-seo' )
			. '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	// ── Post list status column ───────────────────────────────────────────

	public static function add_status_column( array $columns ): array {
		$columns['rnrd_status'] = __( 'RankReady', 'rankready-ai-llm-seo' );
		return $columns;
	}

	public static function render_status_column( string $column, int $post_id ): void {
		if ( 'rnrd_status' !== $column ) {
			return;
		}

		$summary   = get_post_meta( $post_id, RNRD_META_SUMMARY, true );
		$faq       = get_post_meta( $post_id, RNRD_META_FAQ, true );
		$disabled  = get_post_meta( $post_id, RNRD_META_DISABLE, true );
		$faq_off   = get_post_meta( $post_id, RNRD_META_FAQ_DISABLE, true );

		$parts = array();

		if ( $disabled ) {
			$parts[] = '<span style="color:#d63638;" title="' . esc_attr__( 'Summary disabled', 'rankready-ai-llm-seo' ) . '">S: off</span>';
		} elseif ( ! empty( $summary ) ) {
			$parts[] = '<span style="color:#00a32a;" title="' . esc_attr__( 'Summary generated', 'rankready-ai-llm-seo' ) . '">S: &#10003;</span>';
		} else {
			$parts[] = '<span style="color:#999;" title="' . esc_attr__( 'No summary', 'rankready-ai-llm-seo' ) . '">S: —</span>';
		}

		if ( $faq_off ) {
			$parts[] = '<span style="color:#d63638;" title="' . esc_attr__( 'FAQ disabled', 'rankready-ai-llm-seo' ) . '">F: off</span>';
		} elseif ( ! empty( $faq ) ) {
			$parts[] = '<span style="color:#00a32a;" title="' . esc_attr__( 'FAQ generated', 'rankready-ai-llm-seo' ) . '">F: &#10003;</span>';
		} else {
			$parts[] = '<span style="color:#999;" title="' . esc_attr__( 'No FAQ', 'rankready-ai-llm-seo' ) . '">F: —</span>';
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

	// ═══════════════════════════════════════════════════════════════════════════
	// v1.2.0-rc.7 — Universal locked-state pattern.
	// Every togglable card uses the same UX: header + toggle + goal line always
	// visible. When the master toggle is OFF, the card body shows a "locked
	// preview" — bullet list of what the feature delivers + an Enable button
	// that POSTs the toggle to 'on' via handle_quick_enable().
	// ═══════════════════════════════════════════════════════════════════════════

	/**
	 * v1.0.1 — Locked-preview pattern removed.
	 *
	 * Previously this rendered a "What you get when enabled" bullet list with
	 * a separate Enable button. The pattern added a redundant intermediate
	 * step: user had to click Enable, get redirected, then come back and click
	 * Save. Users repeatedly asked for the simpler flow — tick the master
	 * checkbox, hit the Save button at the bottom, done.
	 *
	 * Kept as a no-op so existing call sites (`if (!$enable) render_locked_preview(...)`)
	 * remain valid without me having to touch every callsite. The actual
	 * settings table that used to be in the `else` branch now always renders
	 * once the user is on the page. The master-toggle row at the top of every
	 * card still controls whether the rest of the settings collapse on save.
	 *
	 * @param array $config Unused. Kept for back-compat of existing callers.
	 */
	private static function render_locked_preview( array $config ): void {
		// Intentionally empty — no Enable button, no bullets, no preview card.
		// The card's master toggle checkbox + Save button is the entire flow now.
		unset( $config ); // Silence "unused parameter" linters.
	}

	/**
	 * Handles the Enable click from a locked-preview card.
	 *
	 * GET-based: the locked preview emits a nonce-protected link rather than
	 * a nested <form> (which would be invalid HTML inside the tab's outer
	 * settings form). Wired to admin_init so wp_safe_redirect() fires before
	 * any output. Validates: capability, per-option nonce, whitelisted option
	 * key. Then writes the option and redirects to ?rnrd_enabled=<key> for a
	 * one-time success notice.
	 */
	public static function handle_quick_enable(): void {
		if ( empty( $_GET['rnrd_enable_action'] ) || empty( $_GET['_rnrd_enable_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$option_key = sanitize_key( wp_unslash( $_GET['rnrd_enable_action'] ) );
		$nonce_raw  = isset( $_GET['_rnrd_enable_nonce'] ) ? wp_unslash( $_GET['_rnrd_enable_nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce_raw, 'rnrd_enable_' . $option_key ) ) {
			return;
		}
		// Whitelist — only these options can be flipped via the Enable buttons.
		$allowed = array(
			'rnrd_llms_enable',
			'rnrd_md_enable',
			'rnrd_robots_enable',
			'rnrd_content_signals_enable',
			'rnrd_mcp_enable',
			'rnrd_auto_generate',
			'rnrd_faq_auto_generate',
			'rnrd_schema_article',
			'rnrd_schema_faq',
			'rnrd_schema_howto',
			'rnrd_schema_itemlist',
			'rnrd_schema_speakable',
			'rnrd_ai_referral_enable',
			'rnrd_max_snippet_default',
			'rnrd_author_enable',
		);
		if ( ! in_array( $option_key, $allowed, true ) ) {
			return;
		}
		$value = isset( $_GET['rnrd_enable_value'] )
			? sanitize_text_field( wp_unslash( $_GET['rnrd_enable_value'] ) )
			: 'on';
		update_option( $option_key, $value );

		// Redirect back to the current admin page (drops nonce + action args),
		// keeping the active tab and adding ?rnrd_enabled=<key> for the banner.
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		$redirect = add_query_arg(
			array(
				'page'       => self::MENU_SLUG,
				'tab'        => $tab,
				'rnrd_enabled' => $option_key,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Human-readable labels for the quick-enable success banner.
	 * Maps whitelisted option key → translated feature name.
	 */
	private static function get_quick_enable_labels(): array {
		return array(
			'rnrd_llms_enable'             => __( 'LLMs.txt', 'rankready-ai-llm-seo' ),
			'rnrd_md_enable'               => __( 'Markdown Endpoints', 'rankready-ai-llm-seo' ),
			'rnrd_robots_enable'           => __( 'LLM Crawler Access (robots.txt)', 'rankready-ai-llm-seo' ),
			'rnrd_content_signals_enable'  => __( 'Content Signals', 'rankready-ai-llm-seo' ),
			'rnrd_mcp_enable'              => __( 'WebMCP Manifest', 'rankready-ai-llm-seo' ),
			'rnrd_auto_generate'           => __( 'AI Summary auto-generation', 'rankready-ai-llm-seo' ),
			'rnrd_faq_auto_generate'       => __( 'FAQ auto-generation', 'rankready-ai-llm-seo' ),
			'rnrd_schema_article'          => __( 'Article schema', 'rankready-ai-llm-seo' ),
			'rnrd_schema_faq'              => __( 'FAQPage schema', 'rankready-ai-llm-seo' ),
			'rnrd_schema_howto'            => __( 'HowTo schema', 'rankready-ai-llm-seo' ),
			'rnrd_schema_itemlist'         => __( 'ItemList schema', 'rankready-ai-llm-seo' ),
			'rnrd_schema_speakable'        => __( 'Speakable schema', 'rankready-ai-llm-seo' ),
			'rnrd_ai_referral_enable'      => __( 'AI Referral Tracking', 'rankready-ai-llm-seo' ),
			'rnrd_max_snippet_default'     => __( 'max-snippet:-1 default', 'rankready-ai-llm-seo' ),
			'rnrd_author_enable'           => __( 'Author Box (E-E-A-T)', 'rankready-ai-llm-seo' ),
		);
	}

	/**
	 * Renders the one-time success banner after a quick-enable redirect.
	 * Called from render_page() right after the tab nav.
	 */
	private static function render_quick_enable_banner(): void {
		if ( empty( $_GET['rnrd_enabled'] ) ) {
			return;
		}
		$enabled_key = sanitize_key( wp_unslash( $_GET['rnrd_enabled'] ) );
		$labels      = self::get_quick_enable_labels();
		if ( ! isset( $labels[ $enabled_key ] ) ) {
			return;
		}
		$label = $labels[ $enabled_key ];
		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html( $label ),
			esc_html__( 'enabled. Scroll down to configure.', 'rankready-ai-llm-seo' )
		);
	}
}
