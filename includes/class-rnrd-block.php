<?php
/**
 * Gutenberg block registration and shared editor/frontend assets.
 *
 * Feature runtime (render, auto-display, schema) lives in RNRD_Summary,
 * RNRD_Faq, and RNRD_Author_Box. Shared helpers live in RNRD_Util.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Block {

	public static function init(): void {
		add_action( 'init',                        array( self::class, 'register_summary_block' ) );
		add_action( 'init',                        array( self::class, 'register_faq_block' ) );
		add_action( 'init',                        array( self::class, 'register_author_box_block' ) );

		// Register (not enqueue) the one scoped stylesheet early so BOTH the
		// Gutenberg conditional enqueue AND the Elementor widgets' get_style_depends()
		// can reference it. This is what makes the CSS load ONLY on pages that
		// actually render a RankReady block/widget — no global asset weight.
		add_action( 'wp_enqueue_scripts',                 array( self::class, 'register_style_handle' ), 1 );
		add_action( 'elementor/frontend/after_register_styles', array( self::class, 'register_style_handle' ) );

		// Dedicated "RankReady" block-inserter category (mirrors the Elementor
		// panel category — keep both in sync).
		add_filter( 'block_categories_all',        array( self::class, 'register_block_category' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
		add_action( 'wp_enqueue_scripts',          array( self::class, 'enqueue_frontend_assets' ) );
		// NOTE: HowTo/ItemList auto-detection is a PRO engine. Its emitters and
		// scanners are registered by the Pro add-on, not here. Summary runtime
		// behavior (auto-display + schema merge) now lives in RNRD_Summary / RNRD_Schema.
	}

	/**
	 * Add a "RankReady" category to the block inserter so the AI Summary, FAQ,
	 * and Author Box blocks group together (mirrors the Elementor panel
	 * category). Appended so it sits BELOW the core categories, not pinned to
	 * the top of the inserter.
	 *
	 * @param array $categories Existing block categories.
	 * @return array
	 */
	public static function register_block_category( array $categories ): array {
		foreach ( $categories as $cat ) {
			if ( isset( $cat['slug'] ) && 'rankready' === $cat['slug'] ) {
				return $categories; // already registered
			}
		}
		$categories[] = array(
			'slug'  => 'rankready',
			'title' => __( 'RankReady', 'rankready-ai-llm-seo' ),
			'icon'  => null,
		);
		return $categories;
	}

	// ── Block registration ────────────────────────────────────────────────────

	public static function register_summary_block(): void {
		register_block_type( 'rankready/ai-summary', array(
			'api_version'     => 3,
			'render_callback' => array( 'RNRD_Summary', 'render_block' ),
			'attributes'      => RNRD_Summary::block_attributes(),
		) );
	}

	// ── Author Box Block registration ────────────────────────────────────────

	public static function register_author_box_block(): void {
		register_block_type( 'rankready/author-box', array(
			'api_version'     => 3,
			'render_callback' => array( 'RNRD_Author_Box', 'render_block' ),
			'attributes'      => RNRD_Author_Box::block_attributes(),
		) );
	}

	// ── FAQ Block registration ────────────────────────────────────────────────

	public static function register_faq_block(): void {
		register_block_type( 'rankready/faq', array(
			'api_version'     => 3,
			'render_callback' => array( 'RNRD_Faq', 'render_block' ),
			'attributes'      => RNRD_Faq::block_attributes(),
		) );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public static function enqueue_editor_assets(): void {
		$deps = array(
			'wp-blocks', 'wp-element', 'wp-block-editor',
			'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n',
		);

		wp_enqueue_script(
			'rnrd-block-editor',
			RNRD_URL . 'assets/block.js',
			$deps,
			RNRD_VERSION,
			true
		);

		wp_enqueue_script(
			'rnrd-faq-block-editor',
			RNRD_URL . 'assets/faq-block.js',
			$deps,
			RNRD_VERSION,
			true
		);

		wp_enqueue_script(
			'rnrd-author-box-block-editor',
			RNRD_URL . 'assets/author-box-block.js',
			$deps,
			RNRD_VERSION,
			true
		);

		// Light user list for the block author picker.
		$users_data = array();
		$users      = get_users( array(
			'number'  => 100,
			'fields'  => array( 'ID', 'display_name' ),
			'orderby' => 'display_name',
		) );
		foreach ( $users as $u ) {
			$users_data[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name );
		}

		wp_localize_script( 'rnrd-block-editor', 'rnrdBlockData', array(
			'defaults' => array(
				'label'         => (string) get_option( RNRD_OPT_LABEL, __( 'Key Takeaways', 'rankready-ai-llm-seo' ) ),
				'showLabel'     => (bool) get_option( RNRD_OPT_SHOW_LABEL, '1' ),
				'headingTag'    => (string) get_option( RNRD_OPT_HEADING_TAG, 'h4' ),
				'authorHeading' => (string) get_option( RNRD_OPT_AUTHOR_HEADING, 'About the Author' ),
				'authorTag'     => (string) get_option( RNRD_OPT_AUTHOR_HEADING_TAG, 'h3' ),
			),
			'users' => $users_data,
		) );
	}

	/**
	 * Register the single scoped stylesheet under the shared handle. Idempotent
	 * (wp_register_style is a no-op if already registered). Never enqueues here.
	 */
	public static function register_style_handle(): void {
		if ( ! wp_style_is( 'rankready-style', 'registered' ) ) {
			wp_register_style( 'rankready-style', RNRD_URL . 'assets/style.css', array(), RNRD_VERSION );
		}
	}

	public static function enqueue_frontend_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		// Use get_queried_object_id() — reliable even when a theme builder template
		// overrides the layout (get_the_ID() can return the template post ID instead).
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		// Always load styles when summary, FAQ, or author-box data may render.
		// Display can come from: Gutenberg block, Elementor widget, shortcode, or auto-display.
		$has_summary    = ! empty( get_post_meta( $post_id, RNRD_META_SUMMARY, true ) );
		$has_faq        = ! empty( get_post_meta( $post_id, RNRD_META_FAQ, true ) );
		$has_author_box = RNRD_Shortcode::post_has_manual( $post_id, 'rankready/author-box', RNRD_Shortcode::AUTHOR )
			|| 'off' !== (string) get_option( RNRD_OPT_AUTHOR_AUTO_DISPLAY, 'off' );

		if ( $has_summary || $has_faq || $has_author_box ) {
			self::register_style_handle();
			wp_enqueue_style( 'rankready-style' );
		}
	}

}
