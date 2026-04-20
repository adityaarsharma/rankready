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
		add_action( 'admin_notices',         array( self::class, 'connection_notice' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . RR_BASENAME, array( self::class, 'action_links' ) );
		add_action( 'add_meta_boxes',        array( self::class, 'register_meta_box' ) );
		add_action( 'save_post',             array( self::class, 'save_meta_box' ) );

		// Defer column registration to 'wp_loaded' so all CPTs are registered.
		add_action( 'wp_loaded', array( self::class, 'register_status_columns' ) );
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
		add_menu_page(
			__( 'RankReady', 'rankready' ),
			__( 'RankReady', 'rankready' ),
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-chart-area',
			81
		);
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public static function enqueue_admin_assets( $hook ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'rr-admin', RR_URL . 'assets/admin.css', array(), RR_VERSION );
		wp_enqueue_script( 'rr-admin', RR_URL . 'assets/admin.js', array(), RR_VERSION, true );
		wp_localize_script( 'rr-admin', 'rrAdmin', array(
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'apiBase' => rest_url( 'rankready/v1' ),
		) );
	}

	// ── Settings API ──────────────────────────────────────────────────────────

	public static function register_settings(): void {

		// ═══ Settings Tab ═════════════════════════════════════════════════════

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
				__( 'The API key format looks incorrect. It should start with sk-', 'rankready' ), 'error' );
			return (string) get_option( RR_OPT_KEY, '' );
		}
		return $value;
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
			'dashboard' => array( 'label' => __( 'Dashboard', 'rankready' ),   'icon' => 'dashicons-dashboard' ),
			'content'   => array( 'label' => __( 'Content AI', 'rankready' ),  'icon' => 'dashicons-welcome-write-blog' ),
			'authority' => array( 'label' => __( 'Authority', 'rankready' ),   'icon' => 'dashicons-admin-users' ),
			'crawlers'  => array( 'label' => __( 'AI Crawlers', 'rankready' ), 'icon' => 'dashicons-chart-area' ),
			'settings'  => array( 'label' => __( 'Settings', 'rankready' ),    'icon' => 'dashicons-admin-generic' ),
			'advanced'  => array( 'label' => __( 'Advanced', 'rankready' ),    'icon' => 'dashicons-admin-tools' ),
		);

		if ( ! array_key_exists( $active_tab, $tabs ) ) {
			$active_tab = 'dashboard';
		}
		?>
		<div class="wrap rr-wrap">
			<div class="rr-header">
				<h1 class="rr-title">
					<span class="dashicons dashicons-chart-area rr-title-icon"></span>
					<?php esc_html_e( 'RankReady', 'rankready' ); ?>
					<span class="rr-version">v<?php echo esc_html( RR_VERSION ); ?></span>
				</h1>
				<p class="rr-subtitle"><?php esc_html_e( 'LLM SEO, EEAT & AI Optimization for WordPress', 'rankready' ); ?></p>
			</div>

			<nav class="nav-tab-wrapper rr-tabs">
				<?php foreach ( $tabs as $slug => $tab ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $slug ) ); ?>"
					   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
						<?php echo esc_html( $tab['label'] ); ?>
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

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Dashboard — at-a-glance overview
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_dashboard(): void {
		global $wpdb;

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

		$llms_on      = 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' );
		$author_on    = 'on' === get_option( RR_OPT_AUTHOR_ENABLE, 'on' );
		$robots_on    = (bool) get_option( RR_OPT_ROBOTS_ENABLE, false );
		$md_on        = 'on' === get_option( RR_OPT_MD_ENABLE, 'off' );
		$auto_summary = 'on' === get_option( RR_OPT_AUTO_GENERATE, 'off' );
		$auto_faq     = 'on' === get_option( RR_OPT_FAQ_AUTO_GENERATE, 'off' );

		?>

		<!-- What Does This Plugin Do -->
		<div class="rr-card" style="margin-bottom:24px;padding:20px 24px;">
			<h2 style="margin:0 0 6px;font-size:15px;font-weight:600;"><?php esc_html_e( 'What does RankReady do?', 'rankready' ); ?></h2>
			<p style="margin:0 0 16px;color:#646970;font-size:13px;line-height:1.6;"><?php esc_html_e( 'RankReady helps your WordPress content get cited by AI tools like ChatGPT, Perplexity, and Google AI Overviews. It handles the full picture: AI-generated summaries and FAQs, the right schema markup injected automatically based on your content type, a proper author identity that AI can verify, and direct access for AI crawlers via LLMs.txt and Markdown endpoints.', 'rankready' ); ?></p>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;">
				<div style="background:#f6f7f7;border-radius:6px;padding:12px 14px;">
					<strong style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#1d2327;"><?php esc_html_e( 'Content AI', 'rankready' ); ?></strong>
					<p style="margin:4px 0 0;font-size:12px;color:#646970;line-height:1.5;"><?php esc_html_e( 'Generates AI summaries (key takeaways) and FAQs from your post content using OpenAI. FAQPage schema ships with every FAQ so it counts toward AI Overview eligibility.', 'rankready' ); ?></p>
				</div>
				<div style="background:#f6f7f7;border-radius:6px;padding:12px 14px;">
					<strong style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#1d2327;"><?php esc_html_e( 'Authority', 'rankready' ); ?></strong>
					<p style="margin:4px 0 0;font-size:12px;color:#646970;line-height:1.5;"><?php esc_html_e( 'Builds a full EEAT author identity with Person JSON-LD schema — credentials, Wikidata, ORCID, and social links. AI models use this to verify who wrote the content.', 'rankready' ); ?></p>
				</div>
				<div style="background:#f6f7f7;border-radius:6px;padding:12px 14px;">
					<strong style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#1d2327;"><?php esc_html_e( 'Schema', 'rankready' ); ?></strong>
					<p style="margin:4px 0 0;font-size:12px;color:#646970;line-height:1.5;"><?php esc_html_e( 'Auto-detects your content type and injects the right schema — HowTo for tutorials, ItemList for listicles, FAQPage for Q&A posts. No manual blocks needed.', 'rankready' ); ?></p>
				</div>
				<div style="background:#f6f7f7;border-radius:6px;padding:12px 14px;">
					<strong style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#1d2327;"><?php esc_html_e( 'AI Crawlers', 'rankready' ); ?></strong>
					<p style="margin:4px 0 0;font-size:12px;color:#646970;line-height:1.5;"><?php esc_html_e( 'Serves LLMs.txt, per-post Markdown endpoints, and per-crawler robots.txt controls for 31 AI bots. Gives crawlers a clean, structured way to read your site.', 'rankready' ); ?></p>
				</div>
			</div>
		</div>

		<!-- Stats row -->
		<div class="rr-stats-row" style="margin-bottom:24px;">
			<div class="rr-stat">
				<span class="rr-stat-number"><?php echo esc_html( number_format_i18n( $summary_count ) ); ?></span>
				<span class="rr-stat-label"><?php esc_html_e( 'Posts with Summary', 'rankready' ); ?></span>
			</div>
			<div class="rr-stat">
				<span class="rr-stat-number"><?php echo esc_html( number_format_i18n( $faq_count ) ); ?></span>
				<span class="rr-stat-label"><?php esc_html_e( 'Posts with FAQ', 'rankready' ); ?></span>
			</div>
			<div class="rr-stat">
				<span class="rr-stat-number" style="font-size:20px;">
					<?php if ( $auto_summary ) : ?>
						<span style="color:#00a32a;">&#10003;</span>
					<?php else : ?>
						<span style="color:#a7aaad;"><?php esc_html_e( 'Off', 'rankready' ); ?></span>
					<?php endif; ?>
				</span>
				<span class="rr-stat-label"><?php esc_html_e( 'Auto-Generate Summary', 'rankready' ); ?></span>
			</div>
			<div class="rr-stat">
				<span class="rr-stat-number" style="font-size:20px;">
					<?php if ( $auto_faq ) : ?>
						<span style="color:#00a32a;">&#10003;</span>
					<?php else : ?>
						<span style="color:#a7aaad;"><?php esc_html_e( 'Off', 'rankready' ); ?></span>
					<?php endif; ?>
				</span>
				<span class="rr-stat-label"><?php esc_html_e( 'Auto-Generate FAQ', 'rankready' ); ?></span>
			</div>
		</div>

		<!-- Feature status grid -->
		<div class="rr-info-grid" style="margin-bottom:24px;">

			<div class="rr-info-item rr-dash-feature">
				<span class="rr-info-icon dashicons dashicons-welcome-write-blog"></span>
				<h3><?php esc_html_e( 'Content AI', 'rankready' ); ?></h3>
				<p><?php
					// translators: %1$d = summary count, %2$d = FAQ count.
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $summary_count and $faq_count are cast to (int) above; integers are inherently safe.
					printf( esc_html__( '%1$d summaries · %2$d FAQ sets generated.', 'rankready' ), $summary_count, $faq_count );
				?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=content' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Configure', 'rankready' ); ?> &rarr;</a>
			</div>

			<div class="rr-info-item rr-dash-feature">
				<span class="rr-info-icon dashicons dashicons-admin-users"></span>
				<h3><?php esc_html_e( 'Authority', 'rankready' ); ?></h3>
				<p><?php
					if ( $author_on ) {
						esc_html_e( 'Author Box with EEAT Person schema is active.', 'rankready' );
					} else {
						esc_html_e( 'Author Box is disabled.', 'rankready' );
					}
				?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=authority' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Configure', 'rankready' ); ?> &rarr;</a>
			</div>

			<div class="rr-info-item rr-dash-feature">
				<span class="rr-info-icon dashicons dashicons-chart-area"></span>
				<h3><?php esc_html_e( 'AI Crawlers', 'rankready' ); ?></h3>
				<p><?php
					$parts = array();
					if ( $llms_on )   $parts[] = esc_html__( 'LLMs.txt on', 'rankready' );
					if ( $md_on )     $parts[] = esc_html__( 'Markdown on', 'rankready' );
					if ( $robots_on ) $parts[] = esc_html__( 'Robots on', 'rankready' );
					echo $parts ? esc_html( implode( ' · ', $parts ) ) : esc_html__( 'No crawler features active.', 'rankready' );
				?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=crawlers' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Configure', 'rankready' ); ?> &rarr;</a>
			</div>

			<div class="rr-info-item rr-dash-feature">
				<span class="rr-info-icon dashicons dashicons-admin-generic"></span>
				<h3><?php esc_html_e( 'Settings', 'rankready' ); ?></h3>
				<p><?php
					if ( ! empty( get_option( RR_OPT_KEY, '' ) ) ) {
						esc_html_e( 'OpenAI key configured.', 'rankready' );
					} else {
						esc_html_e( 'OpenAI key not set — AI features unavailable.', 'rankready' );
					}
				?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=settings' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Configure', 'rankready' ); ?> &rarr;</a>
			</div>

			<div class="rr-info-item rr-dash-feature">
				<span class="rr-info-icon dashicons dashicons-admin-tools"></span>
				<h3><?php esc_html_e( 'Advanced', 'rankready' ); ?></h3>
				<p><?php esc_html_e( 'Headless API, bulk tools, health check, and usage tracking.', 'rankready' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=advanced' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Open', 'rankready' ); ?> &rarr;</a>
			</div>

			<div class="rr-info-item rr-dash-feature">
				<span class="rr-info-icon dashicons dashicons-external"></span>
				<h3><?php esc_html_e( 'Plugin Info', 'rankready' ); ?></h3>
				<p>v<?php echo esc_html( RR_VERSION ); ?> &middot; GPL-2.0 &middot; <a href="https://github.com/adityaarsharma/rankready" target="_blank">GitHub</a></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&puc_check_for_updates=1&puc_slug=rankready' ) ); ?>" class="rr-dash-link"><?php esc_html_e( 'Check for updates', 'rankready' ); ?></a>
			</div>

		</div>

		<?php if ( empty( get_option( RR_OPT_KEY, '' ) ) ) : ?>
		<div class="rr-notice rr-notice--warn">
			<?php esc_html_e( 'No OpenAI API key set. AI Summary and FAQ Generator are inactive until you add a key in Settings.', 'rankready' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready&tab=settings' ) ); ?>" style="margin-left:8px;"><?php esc_html_e( 'Go to Settings', 'rankready' ); ?></a>
		</div>
		<?php endif; ?>

		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Content AI — AI Summary + FAQ Generator merged
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_content_ai(): void {
		?>
		<?php settings_errors(); ?>
		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::CONTENT_GROUP ); ?>

			<div class="rr-section-header">
				<span class="dashicons dashicons-editor-quote rr-section-icon"></span>
				<div>
					<h2 class="rr-section-title"><?php esc_html_e( 'AI Summary', 'rankready' ); ?></h2>
					<p class="rr-section-desc"><?php esc_html_e( 'Generate key takeaways from post content via OpenAI. Display via block, widget, or auto-inject.', 'rankready' ); ?></p>
				</div>
			</div>
			<?php self::render_tab_summary(); ?>

			<div class="rr-section-divider"></div>

			<div class="rr-section-header">
				<span class="dashicons dashicons-editor-help rr-section-icon"></span>
				<div>
					<h2 class="rr-section-title"><?php esc_html_e( 'FAQ Generator', 'rankready' ); ?></h2>
					<p class="rr-section-desc"><?php esc_html_e( 'Generate FAQPage schema and an expandable FAQ section using DataForSEO question discovery + OpenAI answers.', 'rankready' ); ?></p>
				</div>
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
				<span class="dashicons dashicons-admin-users rr-section-icon"></span>
				<div>
					<h2 class="rr-section-title"><?php esc_html_e( 'Author Box', 'rankready' ); ?></h2>
					<p class="rr-section-desc"><?php esc_html_e( 'EEAT-optimized author bio with Person JSON-LD schema. Smart-merges with Rank Math, Yoast, and other SEO plugins.', 'rankready' ); ?></p>
				</div>
			</div>
			<?php self::render_tab_author(); ?>

			<div class="rr-section-divider"></div>

			<div class="rr-section-header">
				<span class="dashicons dashicons-code-standards rr-section-icon"></span>
				<div>
					<h2 class="rr-section-title"><?php esc_html_e( 'Schema Automation', 'rankready' ); ?></h2>
					<p class="rr-section-desc"><?php esc_html_e( 'FAQPage, HowTo, ItemList, and Article JSON-LD — auto-detected from your content structure.', 'rankready' ); ?></p>
				</div>
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
		?>
		<div class="rr-section-header">
			<span class="dashicons dashicons-rest-api rr-section-icon"></span>
			<div>
				<h2 class="rr-section-title"><?php esc_html_e( 'Headless / Public API', 'rankready' ); ?></h2>
				<p class="rr-section-desc"><?php esc_html_e( 'REST endpoints, CORS, rate limiting, and on-demand revalidation for Next.js, Nuxt, and Astro sites.', 'rankready' ); ?></p>
			</div>
		</div>
		<?php self::render_tab_headless(); ?>

		<div class="rr-section-divider"></div>

		<div class="rr-section-header">
			<span class="dashicons dashicons-admin-tools rr-section-icon"></span>
			<div>
				<h2 class="rr-section-title"><?php esc_html_e( 'Tools', 'rankready' ); ?></h2>
				<p class="rr-section-desc"><?php esc_html_e( 'Bulk generate, health check, freshness alerts, API usage, and data retention.', 'rankready' ); ?></p>
			</div>
		</div>
		<?php self::render_tab_tools(); ?>

		<div class="rr-section-divider"></div>

		<?php self::render_tab_info(); ?>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: API Keys
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_api(): void {
		$key     = (string) get_option( RR_OPT_KEY, '' );
		$display = ! empty( $key ) ? substr( $key, 0, 7 ) . str_repeat( '••••', 6 ) : '';
		?>
		<?php settings_errors(); ?>

		<form method="post" action="options.php" novalidate="novalidate">
			<?php settings_fields( self::SETTINGS_GROUP ); ?>

			<!-- OpenAI -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'OpenAI', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Powers AI Summary generation and FAQ answer writing.', 'rankready' ); ?></p>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><label for="rr_api_key"><?php esc_html_e( 'API Key', 'rankready' ); ?></label></th>
						<td>
							<input type="password" id="rr_api_key" name="<?php echo esc_attr( RR_OPT_KEY ); ?>"
								   value="<?php echo esc_attr( $display ); ?>" class="regular-text"
								   autocomplete="new-password" spellcheck="false" />
							<p style="margin-top:8px;">
								<button type="button" id="rr-verify-key" class="button button-secondary">
									<?php esc_html_e( 'Verify Key', 'rankready' ); ?>
								</button>
								<span id="rr-verify-status" style="margin-left:10px;font-size:13px;display:none;"></span>
							</p>
							<p class="description"><?php esc_html_e( 'Your OpenAI secret key (sk-...). Stored server-side only.', 'rankready' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rr_model"><?php esc_html_e( 'Model', 'rankready' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( RR_OPT_MODEL ); ?>" id="rr_model">
								<?php $current_model = (string) get_option( RR_OPT_MODEL, 'gpt-4o-mini' ); ?>
								<?php foreach ( self::get_allowed_models() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_model, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'gpt-4o-mini recommended for both summaries and FAQ.', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

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
					<span class="rr-stat-number"><?php echo ! empty( get_option( RR_OPT_KEY, '' ) ) ? '&#10003;' : '&#10007;'; ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'OpenAI Key', 'rankready' ); ?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo ! empty( get_option( RR_OPT_DFS_LOGIN, '' ) ) && ! empty( get_option( RR_OPT_DFS_PASSWORD, '' ) ) ? '&#10003;' : '&#10007;'; ?></span>
					<span class="rr-stat-label"><?php esc_html_e( 'DataForSEO', 'rankready' ); ?></span>
				</div>
				<div class="rr-stat">
					<span class="rr-stat-number"><?php echo esc_html( get_option( RR_OPT_MODEL, 'gpt-4o-mini' ) ); ?></span>
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
						<th scope="row"><?php esc_html_e( 'Auto-Generate on Publish', 'rankready' ); ?></th>
						<td>
							<?php $auto_gen = (string) get_option( RR_OPT_AUTO_GENERATE, 'off' ); ?>
							<label>
								<input type="hidden" name="<?php echo esc_attr( RR_OPT_AUTO_GENERATE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_AUTO_GENERATE ); ?>" value="on" <?php checked( $auto_gen, 'on' ); ?> />
								<?php esc_html_e( 'Automatically generate Key Takeaways when a post is published or updated', 'rankready' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. When off, summaries are only generated via the Regenerate button, Gutenberg block, or Bulk Generate. Existing summaries are always kept.', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Auto Display -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Auto Display', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Automatically show the AI summary on posts without placing a block or widget.', 'rankready' ); ?></p>

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
								<?php esc_html_e( 'Off — Only show via Gutenberg block or Elementor widget', 'rankready' ); ?>
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
				</table>
			</div>

			<!-- Block & Widget Defaults (collapsible) -->
			<div class="rr-card">
				<details class="rr-details">
					<summary><?php esc_html_e( 'Display Options', 'rankready' ); ?></summary>

					<p class="description" style="margin:6px 0 12px;"><?php esc_html_e( 'Label and heading tag defaults for blocks, widgets, and auto-display.', 'rankready' ); ?></p>

					<table class="form-table rr-form-table">
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
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Label HTML Tag', 'rankready' ); ?></label></th>
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
					</table>
				</details>
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

			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'How to Use', 'rankready' ); ?></h2>
				<ol style="margin-left:18px;">
					<li><?php esc_html_e( 'Go to Users → your profile and fill in the "RankReady Author Box" section — every field is mapped to schema.', 'rankready' ); ?></li>
					<li><?php esc_html_e( 'Add the "RankReady Author Box" Gutenberg block to posts, or use the Elementor widget, or enable auto-display above.', 'rankready' ); ?></li>
					<li><?php esc_html_e( 'Optional: enable the "Author Trust Panel" above if you have a formal fact-checker / reviewer workflow.', 'rankready' ); ?></li>
					<li><?php esc_html_e( 'Verify schema output with Google\'s Rich Results Test — Person node appears in the graph.', 'rankready' ); ?></li>
				</ol>
			</div>

		<?php
	}

	private static function render_tab_schema(): void {
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

					<!-- HowTo -->
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

					<!-- ItemList -->
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
				</table>
			</div>

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

			<!-- Background Schema Scanner (WP-Cron) -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'Background Schema Scanner', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'HowTo and ItemList schema are detected via WP-Cron in the background. Zero performance impact on page loads. The scanner runs every 5 minutes and processes a batch of posts.', 'rankready' ); ?></p>

				<?php $rec = RR_Block::get_server_recommendation(); ?>

				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px;">
					<div style="background:#f0f6fc;padding:12px;border-radius:6px;text-align:center;">
						<div style="font-size:24px;font-weight:700;color:#2271b1;"><?php echo esc_html( $rec['scanned_posts'] ); ?>/<?php echo esc_html( $rec['total_posts'] ); ?></div>
						<div style="font-size:12px;color:#666;margin-top:4px;"><?php esc_html_e( 'Posts Scanned', 'rankready' ); ?></div>
					</div>
					<div style="background:#f0f6fc;padding:12px;border-radius:6px;text-align:center;">
						<div style="font-size:24px;font-weight:700;color:#d63638;"><?php echo esc_html( $rec['unscanned_posts'] ); ?></div>
						<div style="font-size:12px;color:#666;margin-top:4px;"><?php esc_html_e( 'Pending Scan', 'rankready' ); ?></div>
					</div>
					<div style="background:#f0f6fc;padding:12px;border-radius:6px;text-align:center;">
						<div style="font-size:24px;font-weight:700;color:#00a32a;"><?php echo esc_html( $rec['cron_next_run'] ); ?></div>
						<div style="font-size:12px;color:#666;margin-top:4px;"><?php esc_html_e( 'Next Cron Run', 'rankready' ); ?></div>
					</div>
					<?php if ( $rec['unscanned_posts'] > 0 ) : ?>
					<div style="background:#fcf9e8;padding:12px;border-radius:6px;text-align:center;">
						<div style="font-size:24px;font-weight:700;color:#dba617;">~<?php echo esc_html( $rec['est_minutes'] ); ?> <?php esc_html_e( 'min', 'rankready' ); ?></div>
						<div style="font-size:12px;color:#666;margin-top:4px;"><?php esc_html_e( 'Est. Time Left', 'rankready' ); ?></div>
					</div>
					<?php endif; ?>
				</div>

				<table class="form-table rr-form-table">
					<tr>
						<th scope="row"><label for="rr_schema_batch"><?php esc_html_e( 'Batch Size (per cron run)', 'rankready' ); ?></label></th>
						<td>
							<input type="number" id="rr_schema_batch"
								   name="<?php echo esc_attr( RR_OPT_SCHEMA_BATCH_SIZE ); ?>"
								   value="<?php echo esc_attr( (string) $rec['current_batch'] ); ?>"
								   min="1" max="50" step="1" class="small-text" />
							<span style="margin-left:8px;color:#2271b1;font-weight:500;">
								<?php echo esc_html( sprintf( __( 'Recommended: %d', 'rankready' ), $rec['recommended_batch'] ) ); ?>
							</span>
							<p class="description" style="margin-top:8px;">
								<?php esc_html_e( 'Number of posts scanned per WP-Cron tick (every 5 minutes). Higher = faster scan, more memory per tick.', 'rankready' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<!-- Server Resource Info -->
				<details style="margin-top:8px;">
					<summary style="cursor:pointer;color:#2271b1;font-weight:500;"><?php esc_html_e( 'Your Server Resources', 'rankready' ); ?></summary>
					<div style="margin-top:8px;padding:12px;background:#f9f9f9;border-radius:4px;">
						<table style="width:100%;border-collapse:collapse;">
							<tr><td style="padding:4px 12px 4px 0;color:#666;"><?php esc_html_e( 'PHP Version', 'rankready' ); ?></td><td style="padding:4px 0;font-weight:500;"><?php echo esc_html( $rec['php_version'] ); ?></td></tr>
							<tr><td style="padding:4px 12px 4px 0;color:#666;"><?php esc_html_e( 'Memory Limit', 'rankready' ); ?></td><td style="padding:4px 0;font-weight:500;"><?php echo esc_html( $rec['memory_limit'] ); ?></td></tr>
							<tr><td style="padding:4px 12px 4px 0;color:#666;"><?php esc_html_e( 'Max Execution Time', 'rankready' ); ?></td><td style="padding:4px 0;font-weight:500;"><?php echo esc_html( $rec['max_execution_time'] ); ?></td></tr>
							<tr><td style="padding:4px 12px 4px 0;color:#666;"><?php esc_html_e( 'Server Tier', 'rankready' ); ?></td><td style="padding:4px 0;font-weight:500;"><?php
								$tiers = array( 'high' => 'High-Resource VPS', 'mid' => 'Mid-Range Server', 'low' => 'Shared Hosting' );
								echo esc_html( isset( $tiers[ $rec['server_tier'] ] ) ? $tiers[ $rec['server_tier'] ] : $rec['server_tier'] );
							?></td></tr>
						</table>
						<p style="margin:8px 0 0;font-size:12px;color:#666;">
							<?php esc_html_e( 'Shared hosting (< 128 MB): batch 5 | Mid-range (256 MB): batch 15 | VPS (512 MB+): batch 25', 'rankready' ); ?>
						</p>
					</div>
				</details>
			</div>

		<?php
	}

	// TAB: LLM Optimization
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_llm(): void {
		?>
		<?php settings_errors(); ?>

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
			<!-- FAQ Settings -->
			<div class="rr-card">
				<h2 class="rr-card-title"><?php esc_html_e( 'FAQ Settings', 'rankready' ); ?></h2>
				<p class="rr-card-desc"><?php esc_html_e( 'Configure how FAQs are generated and displayed. Uses DataForSEO for question discovery and OpenAI for answers with brand entity injection.', 'rankready' ); ?></p>

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
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-Generate on Publish', 'rankready' ); ?></th>
						<td>
							<?php $faq_auto_gen = (string) get_option( RR_OPT_FAQ_AUTO_GENERATE, 'off' ); ?>
							<label>
								<input type="hidden" name="<?php echo esc_attr( RR_OPT_FAQ_AUTO_GENERATE ); ?>" value="off" />
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_FAQ_AUTO_GENERATE ); ?>" value="on" <?php checked( $faq_auto_gen, 'on' ); ?> />
								<?php esc_html_e( 'Automatically generate FAQs when a post is published or updated', 'rankready' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. When off, FAQs are only generated via the Gutenberg block, Elementor widget, or Bulk Generate. Existing FAQs are always kept. Each generation uses DataForSEO + OpenAI credits.', 'rankready' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

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

		<!-- Posts with FAQ Generated -->
		<div class="rr-card" style="margin-top:20px;">
			<h2 class="rr-card-title"><?php esc_html_e( 'Posts with FAQ', 'rankready' ); ?></h2>
			<p class="rr-card-desc"><?php esc_html_e( 'All posts that have FAQ content generated. Click to load the list.', 'rankready' ); ?></p>
			<p>
				<button type="button" id="rr-faq-load-posts" class="button button-secondary">
					<?php esc_html_e( 'Load FAQ Posts', 'rankready' ); ?>
				</button>
				<span id="rr-faq-posts-count" style="margin-left:10px;font-size:13px;color:#646970;display:none;"></span>
			</p>
			<div id="rr-faq-posts-list" style="display:none;margin-top:16px;max-height:400px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;">
				<table class="widefat striped" style="margin:0;">
					<thead>
						<tr>
							<th style="width:40%;"><?php esc_html_e( 'Post Title', 'rankready' ); ?></th>
							<th><?php esc_html_e( 'Type', 'rankready' ); ?></th>
							<th><?php esc_html_e( 'FAQ Generated', 'rankready' ); ?></th>
							<th style="width:15%;"><?php esc_html_e( 'Actions', 'rankready' ); ?></th>
						</tr>
					</thead>
					<tbody id="rr-faq-posts-tbody"></tbody>
				</table>
			</div>
		</div>
		<?php
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// TAB: Headless / Public API
	// ═══════════════════════════════════════════════════════════════════════════

	private static function render_tab_headless(): void {
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
								<input type="checkbox" name="<?php echo esc_attr( RR_OPT_HEADLESS_ENABLE ); ?>" value="on" <?php checked( $enabled ); ?> />
								<span class="rr-toggle-slider"></span>
							</label>
							<p class="description">
								<?php esc_html_e( 'Turn on the public REST endpoints. Off by default for security.', 'rankready' ); ?>
							</p>
						</td>
					</tr>

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
			</div>

			<div class="rr-card">
				<div class="rr-card-header">
					<h2><?php esc_html_e( 'On-Demand Revalidation (Next.js / Nuxt)', 'rankready' ); ?></h2>
					<p class="rr-card-subtitle">
						<?php esc_html_e( 'When FAQ or summary data changes, RankReady pings your frontend to revalidate the affected page. Fire-and-forget, never blocks the editor.', 'rankready' ); ?>
					</p>
				</div>

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
			</div>

			<div class="rr-card">
				<div class="rr-card-header">
					<h2><?php esc_html_e( 'WPGraphQL Integration', 'rankready' ); ?></h2>
					<p class="rr-card-subtitle">
						<?php esc_html_e( 'Register rankReadyFaq, rankReadySummary, rankReadySchema as GraphQL fields on every public post type.', 'rankready' ); ?>
					</p>
				</div>

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
			</div>

			<?php if ( $enabled ) : ?>
			<div class="rr-card">
				<div class="rr-card-header">
					<h2><?php esc_html_e( 'Endpoint Reference', 'rankready' ); ?></h2>
				</div>

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
			</div>
			<?php endif; ?>

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
		?>

		<!-- Bulk Regenerate Summaries -->
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'Bulk Regenerate Summaries', 'rankready' ); ?></h2>
			<p class="rr-card-desc">
				<?php esc_html_e( 'Generate AI summaries for all existing published posts. Processes 5 posts at a time.', 'rankready' ); ?>
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
						<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Skips posts with unchanged content. Resume picks up from where you stopped.', 'rankready' ); ?></p>
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

		<!-- Start Over — Bulk Clear + Regenerate -->
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'Start Over — Clear & Regenerate All', 'rankready' ); ?></h2>
			<p class="rr-card-desc">
				<?php esc_html_e( 'Clears ALL existing Key Takeaways and FAQ data, then regenerates both from scratch using current prompts. Ignores auto-generate setting.', 'rankready' ); ?>
			</p>

			<table class="form-table rr-form-table" style="width:auto;">
				<tr>
					<th style="padding:10px 20px 10px 0;"><?php esc_html_e( 'Post Types', 'rankready' ); ?></th>
					<td>
						<?php foreach ( $post_types as $slug => $label ) : ?>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" class="rr-startover-type" value="<?php echo esc_attr( $slug ); ?>" checked />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th></th>
					<td>
						<button type="button" id="rr-startover-btn" class="button button-primary"><?php esc_html_e( 'Start Over — Bulk Regenerate', 'rankready' ); ?></button>
						<button type="button" id="rr-startover-resume" class="button button-secondary" style="margin-left:8px;"><?php esc_html_e( 'Resume', 'rankready' ); ?></button>
						<button type="button" id="rr-startover-stop" class="button button-secondary" style="display:none;margin-left:8px;"><?php esc_html_e( 'Stop', 'rankready' ); ?></button>
						<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Deletes old data first, then generates fresh. Processes 1 post at a time. Resume picks up where you stopped.', 'rankready' ); ?></p>
					</td>
				</tr>
			</table>

			<div id="rr-startover-progress" style="display:none;margin-top:16px;">
				<div class="rr-progress-track">
					<div id="rr-startover-bar" class="rr-progress-fill"></div>
				</div>
				<p id="rr-startover-status" class="rr-progress-label"><?php esc_html_e( 'Preparing...', 'rankready' ); ?></p>
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

		// Estimated cost: GPT-4o-mini ~$0.15/1M input, $0.60/1M output.
		// Blended estimate ~$0.30/1M tokens (we track total, not split).
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
					<span style="font-size:13px;color:#646970;margin-left:6px;"><?php esc_html_e( 'Estimated Cost (GPT-4o-mini)', 'rankready' ); ?></span>
				</div>
				<?php if ( $dfs_calls > 0 || $dfs_cost > 0 ) : ?>
				<div style="padding:10px 16px;background:#fef8f0;border:1px solid #e8d8c0;border-radius:6px;display:inline-block;">
					<span style="font-size:22px;font-weight:700;color:#1d2327;">$<?php echo esc_html( number_format( $dfs_cost, 4 ) ); ?></span>
					<span style="font-size:13px;color:#646970;margin-left:6px;"><?php esc_html_e( 'DataForSEO Cost', 'rankready' ); ?></span>
				</div>
				<?php endif; ?>
			</div>

			<h3 style="font-size:14px;margin:16px 0 8px;color:#1d2327;"><?php esc_html_e( 'OpenAI', 'rankready' ); ?></h3>
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

		<!-- Bulk Generate FAQs -->
		<div class="rr-card">
			<h2 class="rr-card-title"><?php esc_html_e( 'Bulk Generate FAQs', 'rankready' ); ?></h2>
			<p class="rr-card-desc">
				<?php esc_html_e( 'Generate FAQ Q&A pairs for all existing published posts using DataForSEO + OpenAI. Requires both API keys to be configured.', 'rankready' ); ?>
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
					/* translators: %s: author link */
					esc_html__( 'RankReady v%1$s by %2$s', 'rankready' ),
					esc_html( RR_VERSION ),
					'<a href="https://github.com/adityaarsharma/rankready" target="_blank">Aditya Sharma</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	// ── Per-post meta box ─────────────────────────────────────────────────────

	public static function register_meta_box(): void {
		$post_types = (array) get_option( RR_OPT_POST_TYPES, array( 'post' ) );
		foreach ( $post_types as $pt ) {
			add_meta_box(
				'rr_summary_meta',
				__( 'RankReady -- AI Summary', 'rankready' ),
				array( self::class, 'render_meta_box' ),
				$pt,
				'side',
				'default'
			);
		}
	}

	public static function render_meta_box( $post ): void {
		$disabled  = (bool) get_post_meta( $post->ID, RR_META_DISABLE, true );
		$summary   = (string) get_post_meta( $post->ID, RR_META_SUMMARY, true );
		$generated = (int) get_post_meta( $post->ID, RR_META_GENERATED, true );

		wp_nonce_field( 'rr_meta_box', 'rr_meta_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="rr_disable_summary" value="1" <?php checked( $disabled ); ?> />
				<?php esc_html_e( 'Disable AI summary for this post', 'rankready' ); ?>
			</label>
		</p>
		<?php if ( $generated ) : ?>
			<p class="description">
				<?php
				printf(
					esc_html__( 'Last generated: %s ago', 'rankready' ),
					esc_html( human_time_diff( $generated ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( ! empty( $summary ) ) :
			$decoded = RR_Generator::decode_summary( $summary );
			if ( 'bullets' === $decoded['type'] ) : ?>
				<ul style="margin:8px 0 0;padding-left:16px;list-style:disc;">
					<?php foreach ( (array) $decoded['data'] as $bullet ) : ?>
						<li style="font-size:12px;margin-bottom:4px;"><?php echo esc_html( $bullet ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p style="font-size:12px;margin:8px 0 0;"><?php echo esc_html( $decoded['data'] ); ?></p>
			<?php endif;
		else : ?>
			<p class="description" style="font-style:italic;">
				<?php esc_html_e( 'No summary yet. It will generate on publish/update.', 'rankready' ); ?>
			</p>
		<?php endif;
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

		$disabled = isset( $_POST['rr_disable_summary'] ) ? '1' : '';
		update_post_meta( $post_id, RR_META_DISABLE, $disabled );
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
