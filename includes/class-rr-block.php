<?php
/**
 * Gutenberg block registration, server-side render, schema injection, auto-display.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Block {

	public static function init(): void {
		add_action( 'init',                        array( self::class, 'register_block' ) );
		add_action( 'init',                        array( self::class, 'register_faq_block' ) );
		add_action( 'init',                        array( self::class, 'register_author_box_block' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
		add_action( 'wp_enqueue_scripts',          array( self::class, 'enqueue_frontend_assets' ) );
		add_action( 'wp_head',                     array( self::class, 'maybe_inject_schema' ), 1 );
		add_action( 'wp_head',                     array( self::class, 'output_auto_schema' ), 25 );

		// WP-Cron schema scanner.
		add_action( RR_SCHEMA_CRON_HOOK,           array( self::class, 'cron_schema_scan' ) );

		// Re-scan on post save (deferred to avoid blocking the editor).
		add_action( 'save_post',                   array( self::class, 'invalidate_schema_cache' ), 20, 2 );
		add_filter( 'the_content',                 array( self::class, 'maybe_auto_display' ), 99 );

		// Merge AI-friendly schema into ALL major SEO plugins.
		add_filter( 'rank_math/json_ld',                        array( self::class, 'merge_rankmath_schema' ), 99, 2 );
		add_filter( 'wpseo_schema_graph',                       array( self::class, 'merge_yoast_schema' ), 99 );
		add_filter( 'aioseo_schema_output',                     array( self::class, 'merge_aioseo_schema' ), 99 );
		add_filter( 'seopress_schemas_auto_article_json',       array( self::class, 'merge_seopress_schema' ), 99 );
		add_filter( 'seopress_pro_get_json_data_article',       array( self::class, 'merge_seopress_schema' ), 99 );
		add_filter( 'the_seo_framework_schema_graph_data',      array( self::class, 'merge_tsf_schema' ), 99 );
		add_filter( 'slim_seo_schema_graph',                    array( self::class, 'merge_slim_seo_schema' ), 99 );
	}

	// ── Block registration ────────────────────────────────────────────────────

	public static function register_block(): void {
		register_block_type( 'rankready/ai-summary', array(
			'api_version'     => 3,
			'render_callback' => array( self::class, 'render' ),
			'attributes'      => array(
				// Content
				'label'             => array( 'type' => 'string',  'default' => '' ),
				'showLabel'         => array( 'type' => 'boolean', 'default' => true ),
				'headingTag'        => array( 'type' => 'string',  'default' => '' ),
				// Box style
				'boxBgColor'        => array( 'type' => 'string',  'default' => '' ),
				'boxBorderColor'    => array( 'type' => 'string',  'default' => '' ),
				'boxBorderWidth'    => array( 'type' => 'number',  'default' => 3 ),
				'boxBorderRadius'   => array( 'type' => 'number',  'default' => 0 ),
				'boxBorderPosition' => array( 'type' => 'string',  'default' => 'left' ),
				'boxPadding'        => array( 'type' => 'number',  'default' => 0 ),
				// Label style
				'labelColor'        => array( 'type' => 'string',  'default' => '' ),
				'labelFontSize'     => array( 'type' => 'number',  'default' => 0 ),
				'labelFontFamily'   => array( 'type' => 'string',  'default' => '' ),
				'labelFontWeight'   => array( 'type' => 'string',  'default' => '' ),
				'labelLineHeight'   => array( 'type' => 'number',  'default' => 0 ),
				'labelLetterSpacing'=> array( 'type' => 'number',  'default' => 0 ),
				'labelTextTransform'=> array( 'type' => 'string',  'default' => '' ),
				// Bullet style
				'bulletColor'       => array( 'type' => 'string',  'default' => '' ),
				'bulletFontSize'    => array( 'type' => 'number',  'default' => 0 ),
				'bulletFontFamily'  => array( 'type' => 'string',  'default' => '' ),
				'bulletFontWeight'  => array( 'type' => 'string',  'default' => '' ),
				'bulletLineHeight'  => array( 'type' => 'number',  'default' => 0 ),
				'bulletLetterSpacing' => array( 'type' => 'number','default' => 0 ),
				'bulletSpacing'     => array( 'type' => 'number',  'default' => 0 ),
				'bulletMarkerColor' => array( 'type' => 'string',  'default' => '' ),
			),
		) );
	}

	// ── Author Box Block registration ────────────────────────────────────────

	public static function register_author_box_block(): void {
		register_block_type( 'rankready/author-box', array(
			'api_version'     => 3,
			'render_callback' => array( 'RR_Author_Box', 'render_block' ),
			'attributes'      => RR_Author_Box::block_attributes(),
		) );
	}

	// ── FAQ Block registration ────────────────────────────────────────────────

	public static function register_faq_block(): void {
		register_block_type( 'rankready/faq', array(
			'api_version'     => 3,
			'render_callback' => array( self::class, 'render_faq' ),
			'attributes'      => array(
				// Content
				'showTitle'          => array( 'type' => 'boolean', 'default' => true ),
				'titleText'          => array( 'type' => 'string',  'default' => 'Frequently Asked Questions' ),
				'headingTag'         => array( 'type' => 'string',  'default' => 'h3' ),
				'showReviewed'       => array( 'type' => 'boolean', 'default' => true ),
				'keyword'            => array( 'type' => 'string',  'default' => '' ),
				// Box style
				'boxBgColor'         => array( 'type' => 'string',  'default' => '' ),
				'boxBorderColor'     => array( 'type' => 'string',  'default' => '' ),
				'boxBorderWidth'     => array( 'type' => 'number',  'default' => 0 ),
				'boxBorderRadius'    => array( 'type' => 'number',  'default' => 0 ),
				'boxPadding'         => array( 'type' => 'number',  'default' => 0 ),
				// Question style
				'questionColor'      => array( 'type' => 'string',  'default' => '' ),
				'questionFontSize'   => array( 'type' => 'number',  'default' => 0 ),
				'questionFontFamily' => array( 'type' => 'string',  'default' => '' ),
				'questionFontWeight' => array( 'type' => 'string',  'default' => '' ),
				'questionLineHeight' => array( 'type' => 'number',  'default' => 0 ),
				// Answer style
				'answerColor'        => array( 'type' => 'string',  'default' => '' ),
				'answerFontSize'     => array( 'type' => 'number',  'default' => 0 ),
				'answerFontFamily'   => array( 'type' => 'string',  'default' => '' ),
				'answerFontWeight'   => array( 'type' => 'string',  'default' => '' ),
				'answerLineHeight'   => array( 'type' => 'number',  'default' => 0 ),
				// Divider
				'dividerColor'       => array( 'type' => 'string',  'default' => '' ),
			),
		) );
	}

	// ── FAQ server-side render ────────────────────────────────────────────────

	public static function render_faq( $attrs, $content = '', $block = null ): string {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$faq_data = RR_Faq::get_faq_data( $post_id );
		if ( empty( $faq_data ) ) {
			return '';
		}

		$heading_tag = self::validate_heading_tag( ! empty( $attrs['headingTag'] ) ? $attrs['headingTag'] : 'h3' );
		$show_title  = isset( $attrs['showTitle'] ) ? (bool) $attrs['showTitle'] : true;
		$title_text  = ! empty( $attrs['titleText'] ) ? sanitize_text_field( $attrs['titleText'] ) : __( 'Frequently Asked Questions', 'rankready' );
		$show_reviewed = isset( $attrs['showReviewed'] ) ? (bool) $attrs['showReviewed'] : true;

		// Box styles.
		$box_styles = array();
		if ( ! empty( $attrs['boxBgColor'] ) ) {
			$box_styles[] = 'background-color:' . self::sanitize_color( $attrs['boxBgColor'] );
		}
		if ( ! empty( $attrs['boxBorderColor'] ) && ! empty( $attrs['boxBorderWidth'] ) ) {
			$box_styles[] = 'border:' . (int) $attrs['boxBorderWidth'] . 'px solid ' . self::sanitize_color( $attrs['boxBorderColor'] );
		}
		if ( ! empty( $attrs['boxBorderRadius'] ) ) {
			$box_styles[] = 'border-radius:' . (int) $attrs['boxBorderRadius'] . 'px';
		}
		if ( ! empty( $attrs['boxPadding'] ) ) {
			$box_styles[] = 'padding:' . (int) $attrs['boxPadding'] . 'px';
		}
		$box_style_attr = ! empty( $box_styles ) ? ' style="' . esc_attr( implode( ';', $box_styles ) ) . '"' : '';

		// Question styles (full typography).
		$q_styles = array();
		if ( ! empty( $attrs['questionColor'] ) ) {
			$q_styles[] = 'color:' . self::sanitize_color( $attrs['questionColor'] );
		}
		if ( ! empty( $attrs['questionFontSize'] ) ) {
			$q_styles[] = 'font-size:' . (int) $attrs['questionFontSize'] . 'px';
		}
		if ( ! empty( $attrs['questionFontFamily'] ) ) {
			$q_styles[] = 'font-family:' . esc_attr( (string) $attrs['questionFontFamily'] );
		}
		if ( ! empty( $attrs['questionFontWeight'] ) ) {
			$q_styles[] = 'font-weight:' . esc_attr( (string) $attrs['questionFontWeight'] );
		}
		if ( ! empty( $attrs['questionLineHeight'] ) ) {
			$q_styles[] = 'line-height:' . number_format( (float) $attrs['questionLineHeight'], 2 );
		}
		$q_style_attr = ! empty( $q_styles ) ? ' style="' . esc_attr( implode( ';', $q_styles ) ) . '"' : '';

		// Answer styles (full typography).
		$a_styles = array();
		if ( ! empty( $attrs['answerColor'] ) ) {
			$a_styles[] = 'color:' . self::sanitize_color( $attrs['answerColor'] );
		}
		if ( ! empty( $attrs['answerFontSize'] ) ) {
			$a_styles[] = 'font-size:' . (int) $attrs['answerFontSize'] . 'px';
		}
		if ( ! empty( $attrs['answerFontFamily'] ) ) {
			$a_styles[] = 'font-family:' . esc_attr( (string) $attrs['answerFontFamily'] );
		}
		if ( ! empty( $attrs['answerFontWeight'] ) ) {
			$a_styles[] = 'font-weight:' . esc_attr( (string) $attrs['answerFontWeight'] );
		}
		if ( ! empty( $attrs['answerLineHeight'] ) ) {
			$a_styles[] = 'line-height:' . number_format( (float) $attrs['answerLineHeight'], 2 );
		}
		$a_style_attr = ! empty( $a_styles ) ? ' style="' . esc_attr( implode( ';', $a_styles ) ) . '"' : '';

		// Divider style.
		$d_style_attr = '';
		if ( ! empty( $attrs['dividerColor'] ) ) {
			$d_style_attr = ' style="border-bottom-color:' . esc_attr( self::sanitize_color( $attrs['dividerColor'] ) ) . '"';
		}

		// Build HTML.
		$wrapper = get_block_wrapper_attributes( array( 'class' => 'rr-faq-wrapper' ) );
		$out     = '<div ' . $wrapper . $box_style_attr . '>';

		if ( $show_title ) {
			$out .= '<' . $heading_tag . ' class="rr-faq-title"' . $q_style_attr . '>'
				. esc_html( $title_text )
				. '</' . $heading_tag . '>';
		}

		$out .= '<div class="rr-faq-list">';

		foreach ( $faq_data as $item ) {
			$q = isset( $item['question'] ) ? $item['question'] : '';
			$a = isset( $item['answer'] ) ? $item['answer'] : '';
			if ( empty( $q ) || empty( $a ) ) {
				continue;
			}

			$out .= '<div class="rr-faq-item"' . $d_style_attr . '>';
			$out .= '<h4 class="rr-faq-question"' . $q_style_attr . '>' . esc_html( $q ) . '</h4>';
			$out .= '<p class="rr-faq-answer"' . $a_style_attr . '>' . wp_kses_post( RR_Faq::convert_markdown_links( $a ) ) . '</p>';
			$out .= '</div>';
		}

		$out .= '</div>';

		if ( $show_reviewed ) {
			$modified_ts = get_the_modified_time( 'U', $post_id );
			if ( ! empty( $modified_ts ) ) {
				$date = wp_date( get_option( 'date_format' ), (int) $modified_ts );
				$out .= '<p class="rr-faq-reviewed">'
					. esc_html( sprintf( __( 'Last reviewed: %s', 'rankready' ), $date ) )
					. '</p>';
			}
		}

		$out .= '</div>';

		return $out;
	}

	// ── Server-side render ────────────────────────────────────────────────────

	public static function render( $attrs, $content = '', $block = null ): string {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$raw = (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
		if ( empty( $raw ) ) {
			return '';
		}

		return self::build_summary_html( $raw, $attrs, true );
	}

	// ── Build summary HTML ────────────────────────────────────────────────────

	public static function build_summary_html( $raw, $attrs = array(), $is_block = false ): string {
		$summary = RR_Generator::decode_summary( $raw );
		if ( 'empty' === $summary['type'] ) {
			return '';
		}

		// Resolve attributes with global defaults
		$show_label  = isset( $attrs['showLabel'] ) ? (bool) $attrs['showLabel'] : (bool) get_option( RR_OPT_SHOW_LABEL, '1' );
		$label_text  = ! empty( $attrs['label'] )
			? sanitize_text_field( $attrs['label'] )
			: (string) get_option( RR_OPT_LABEL, 'Key Takeaways' );
		$heading_tag = self::validate_heading_tag(
			! empty( $attrs['headingTag'] )
				? $attrs['headingTag']
				: (string) get_option( RR_OPT_HEADING_TAG, 'h4' )
		);

		// Build box inline styles
		$box_styles = array();
		if ( ! empty( $attrs['boxBgColor'] ) ) {
			$box_styles[] = 'background-color:' . self::sanitize_color( $attrs['boxBgColor'] );
		}

		$border_pos   = isset( $attrs['boxBorderPosition'] ) ? $attrs['boxBorderPosition'] : 'left';
		$border_width = isset( $attrs['boxBorderWidth'] ) ? (int) $attrs['boxBorderWidth'] : 3;
		$border_color = ! empty( $attrs['boxBorderColor'] ) ? self::sanitize_color( $attrs['boxBorderColor'] ) : '';

		if ( 'none' === $border_pos ) {
			$box_styles[] = 'border:none';
		} elseif ( 'all' === $border_pos ) {
			$box_styles[] = 'border-left:none';
			if ( $border_width ) {
				$box_styles[] = 'border:' . $border_width . 'px solid ' . ( $border_color ? $border_color : 'currentColor' );
			}
		} else {
			if ( $border_width ) {
				$box_styles[] = 'border-left-width:' . $border_width . 'px';
			}
			if ( $border_color ) {
				$box_styles[] = 'border-left-color:' . $border_color;
			}
		}

		if ( ! empty( $attrs['boxBorderRadius'] ) ) {
			$box_styles[] = 'border-radius:' . (int) $attrs['boxBorderRadius'] . 'px';
		}
		if ( ! empty( $attrs['boxPadding'] ) ) {
			$box_styles[] = 'padding:' . (int) $attrs['boxPadding'] . 'px';
		}
		if ( ! empty( $attrs['bulletMarkerColor'] ) ) {
			$box_styles[] = '--rr-marker-color:' . self::sanitize_color( $attrs['bulletMarkerColor'] );
		}

		$box_style_attr = ! empty( $box_styles ) ? ' style="' . esc_attr( implode( ';', $box_styles ) ) . '"' : '';

		// Label inline styles (full typography).
		$label_styles = array();
		if ( ! empty( $attrs['labelColor'] ) ) {
			$label_styles[] = 'color:' . self::sanitize_color( $attrs['labelColor'] );
		}
		if ( ! empty( $attrs['labelFontSize'] ) ) {
			$label_styles[] = 'font-size:' . (int) $attrs['labelFontSize'] . 'px';
		}
		if ( ! empty( $attrs['labelFontFamily'] ) ) {
			$label_styles[] = 'font-family:' . esc_attr( (string) $attrs['labelFontFamily'] );
		}
		if ( ! empty( $attrs['labelFontWeight'] ) ) {
			$label_styles[] = 'font-weight:' . esc_attr( (string) $attrs['labelFontWeight'] );
		}
		if ( ! empty( $attrs['labelLineHeight'] ) ) {
			$label_styles[] = 'line-height:' . number_format( (float) $attrs['labelLineHeight'], 2 );
		}
		if ( ! empty( $attrs['labelLetterSpacing'] ) ) {
			$label_styles[] = 'letter-spacing:' . number_format( (float) $attrs['labelLetterSpacing'], 2 ) . 'px';
		}
		if ( ! empty( $attrs['labelTextTransform'] ) ) {
			$label_styles[] = 'text-transform:' . esc_attr( (string) $attrs['labelTextTransform'] );
		}
		$label_style_attr = ! empty( $label_styles ) ? ' style="' . esc_attr( implode( ';', $label_styles ) ) . '"' : '';

		// Bullet inline styles (full typography).
		$bullet_styles = array();
		if ( ! empty( $attrs['bulletColor'] ) ) {
			$bullet_styles[] = 'color:' . self::sanitize_color( $attrs['bulletColor'] );
		}
		if ( ! empty( $attrs['bulletFontSize'] ) ) {
			$bullet_styles[] = 'font-size:' . (int) $attrs['bulletFontSize'] . 'px';
		}
		if ( ! empty( $attrs['bulletFontFamily'] ) ) {
			$bullet_styles[] = 'font-family:' . esc_attr( (string) $attrs['bulletFontFamily'] );
		}
		if ( ! empty( $attrs['bulletFontWeight'] ) ) {
			$bullet_styles[] = 'font-weight:' . esc_attr( (string) $attrs['bulletFontWeight'] );
		}
		if ( ! empty( $attrs['bulletLineHeight'] ) ) {
			$bullet_styles[] = 'line-height:' . number_format( (float) $attrs['bulletLineHeight'], 2 ) ;
		}
		if ( ! empty( $attrs['bulletLetterSpacing'] ) ) {
			$bullet_styles[] = 'letter-spacing:' . number_format( (float) $attrs['bulletLetterSpacing'], 2 ) . 'px';
		}
		if ( ! empty( $attrs['bulletSpacing'] ) ) {
			$bullet_styles[] = 'margin-bottom:' . (int) $attrs['bulletSpacing'] . 'px';
		}
		$bullet_style_attr = ! empty( $bullet_styles ) ? ' style="' . esc_attr( implode( ';', $bullet_styles ) ) . '"' : '';

		// Build HTML
		$class = 'rr-summary';
		if ( $is_block ) {
			$wrapper = get_block_wrapper_attributes( array( 'class' => $class ) );
			$out     = '<div ' . $wrapper . $box_style_attr . '>';
		} else {
			$out = '<div class="' . esc_attr( $class ) . '"' . $box_style_attr . '>';
		}

		if ( $show_label && ! empty( $label_text ) ) {
			$out .= '<' . $heading_tag . ' class="rr-label"' . $label_style_attr . '>'
				. esc_html( $label_text )
				. '</' . $heading_tag . '>';
		}

		if ( 'bullets' === $summary['type'] ) {
			$out .= '<ul class="rr-bullets">';
			foreach ( (array) $summary['data'] as $bullet ) {
				$out .= '<li class="rr-bullet"' . $bullet_style_attr . '>' . esc_html( $bullet ) . '</li>';
			}
			$out .= '</ul>';
		} else {
			$out .= '<p class="rr-text"' . $bullet_style_attr . '>' . esc_html( $summary['data'] ) . '</p>';
		}

		$out .= '</div>';

		return $out;
	}

	// ── Auto-display via the_content filter ───────────────────────────────────

	public static function maybe_auto_display( $content ): string {
		if ( ! is_singular() || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		if ( 'on' !== get_option( RR_OPT_AUTO_DISPLAY, 'off' ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		// Per-post disable
		if ( get_post_meta( $post_id, RR_META_DISABLE, true ) ) {
			return $content;
		}

		// Check post type — all public CPTs.
		$post = get_post( $post_id );
		if ( ! $post || ! is_post_type_viewable( $post->post_type ) ) {
			return $content;
		}

		// Skip if block is already in content
		if ( has_block( 'rankready/ai-summary', $post ) ) {
			return $content;
		}

		// Skip if a theme builder renders this page — display is handled by the widget.
		if ( self::is_theme_builder_page( $post_id ) ) {
			return $content;
		}

		$raw = (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
		if ( empty( $raw ) ) {
			return $content;
		}

		$summary_html = self::build_summary_html( $raw );
		$position     = get_option( RR_OPT_DISPLAY_POSITION, 'before' );

		if ( 'after' === $position ) {
			return $content . $summary_html;
		}

		return $summary_html . $content;
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public static function enqueue_editor_assets(): void {
		$deps = array(
			'wp-blocks', 'wp-element', 'wp-block-editor',
			'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n',
		);

		wp_enqueue_script(
			'rr-block-editor',
			RR_URL . 'assets/block.js',
			$deps,
			RR_VERSION,
			true
		);

		wp_enqueue_script(
			'rr-faq-block-editor',
			RR_URL . 'assets/faq-block.js',
			$deps,
			RR_VERSION,
			true
		);

		wp_enqueue_script(
			'rr-author-box-block-editor',
			RR_URL . 'assets/author-box-block.js',
			$deps,
			RR_VERSION,
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

		wp_localize_script( 'rr-block-editor', 'rrBlockData', array(
			'defaults' => array(
				'label'         => (string) get_option( RR_OPT_LABEL, 'Key Takeaways' ),
				'showLabel'     => (bool) get_option( RR_OPT_SHOW_LABEL, '1' ),
				'headingTag'    => (string) get_option( RR_OPT_HEADING_TAG, 'h4' ),
				'authorHeading' => (string) get_option( RR_OPT_AUTHOR_HEADING, 'About the Author' ),
				'authorTag'     => (string) get_option( RR_OPT_AUTHOR_HEADING_TAG, 'h3' ),
			),
			'users' => $users_data,
		) );
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
		// Display can come from: Gutenberg block, Elementor widget, theme builder widget, or auto-display.
		$has_summary    = ! empty( get_post_meta( $post_id, RR_META_SUMMARY, true ) );
		$has_faq        = ! empty( get_post_meta( $post_id, RR_META_FAQ, true ) );
		$has_author_box = has_block( 'rankready/author-box', $post_id )
			|| 'off' !== (string) get_option( RR_OPT_AUTHOR_AUTO_DISPLAY, 'off' );

		if ( $has_summary || $has_faq || $has_author_box ) {
			wp_enqueue_style( 'rankready-style', RR_URL . 'assets/style.css', array(), RR_VERSION );
		}
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SCHEMA — AI-FRIENDLY PROPERTIES MERGED INTO ANY SEO PLUGIN
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Standalone schema — only when NO SEO plugin is active.
	 * When any major SEO plugin is active, we merge via their filters instead.
	 */
	public static function maybe_inject_schema(): void {
		if ( 'on' !== get_option( RR_OPT_SCHEMA_ARTICLE, 'on' ) ) return;

		// These plugins have dedicated merge filters registered in init().
		if ( defined( 'RANK_MATH_VERSION' ) )  return;
		if ( defined( 'WPSEO_VERSION' ) )      return;
		if ( defined( 'AIOSEO_VERSION' ) )     return;
		if ( defined( 'SEOPRESS_VERSION' ) )   return;

		// The SEO Framework.
		if ( function_exists( 'the_seo_framework' ) ) return;

		// Slim SEO.
		if ( defined( 'SLIM_SEO_VER' ) ) return;

		if ( ! is_singular() ) return;
		if ( ! apply_filters( 'rankready_inject_schema', true ) ) return;

		$post_id = get_queried_object_id();
		if ( ! $post_id ) return;

		$post = get_post( $post_id );
		if ( ! $post || ! is_post_type_viewable( $post->post_type ) ) return;

		$raw = (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
		if ( empty( $raw ) ) return;

		$summary     = RR_Generator::decode_summary( $raw );
		$description = '';

		if ( 'bullets' === $summary['type'] && is_array( $summary['data'] ) ) {
			$description = implode( ' ', $summary['data'] );
		} elseif ( 'text' === $summary['type'] ) {
			$description = (string) $summary['data'];
		}

		if ( empty( $description ) ) return;

		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => get_the_title( $post_id ),
			'description'   => $description,
			'url'           => get_permalink( $post_id ),
			'datePublished' => get_post_time( 'c', true, $post ),
			'dateModified'  => get_post_modified_time( 'c', true, $post ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $post->post_author ),
			),
			'publisher'     => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url(),
			),
		);

		// Add all AI-friendly properties.
		$schema = array_merge( $schema, self::build_ai_schema_properties( $post_id, $summary ) );

		$tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
		if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
			$schema['keywords'] = implode( ', ', $tags );
		}

		if ( has_post_thumbnail( $post_id ) ) {
			$img = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'large' );
			if ( $img ) {
				$schema['image'] = array(
					'@type'  => 'ImageObject',
					'url'    => $img[0],
					'width'  => $img[1],
					'height' => $img[2],
				);
			}
		}

		$logo_id = get_theme_mod( 'custom_logo' );
		if ( $logo_id ) {
			$logo_img = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( $logo_img ) {
				$schema['publisher']['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $logo_img[0],
				);
			}
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	// ── Generic helper: find Article node and merge AI props ─────────────────

	private static function merge_into_article_node( array &$nodes, int $post_id ): void {
		$raw = (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
		if ( empty( $raw ) ) return;

		$summary  = RR_Generator::decode_summary( $raw );
		$ai_props = self::build_ai_schema_properties( $post_id, $summary );

		$article_types = array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report' );

		foreach ( $nodes as &$node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) continue;

			$type = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
			if ( ! array_intersect( $type, $article_types ) ) continue;

			// Merge — never overwrite existing properties from the SEO plugin.
			foreach ( $ai_props as $prop => $value ) {
				if ( ! isset( $node[ $prop ] ) ) {
					$node[ $prop ] = $value;
				}
			}
			break;
		}
		unset( $node );
	}

	// ── Rank Math: rank_math/json_ld ─────────────────────────────────────────

	public static function merge_rankmath_schema( $data, $jsonld ): array {
		if ( ! is_singular() || ! is_array( $data ) ) return $data;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::merge_into_article_node( $data, $post_id );
		return $data;
	}

	// ── Yoast SEO: wpseo_schema_graph ────────────────────────────────────────

	public static function merge_yoast_schema( $graph ): array {
		if ( ! is_singular() || ! is_array( $graph ) ) return $graph;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::merge_into_article_node( $graph, $post_id );
		return $graph;
	}

	// ── AIOSEO: aioseo_schema_output ─────────────────────────────────────────

	public static function merge_aioseo_schema( $graphs ): array {
		if ( ! is_singular() || ! is_array( $graphs ) ) return $graphs;
		$post_id = get_queried_object_id();
		if ( ! $post_id ) return $graphs;

		// AIOSEO wraps graphs differently — may have @graph array or flat.
		foreach ( $graphs as &$graph ) {
			if ( is_array( $graph ) && isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ) {
				self::merge_into_article_node( $graph['@graph'], $post_id );
			} elseif ( is_array( $graph ) && isset( $graph['@type'] ) ) {
				$single = array( &$graph );
				self::merge_into_article_node( $single, $post_id );
			}
		}
		unset( $graph );

		return $graphs;
	}

	// ── SEOPress: seopress_schemas_auto_article_json / seopress_pro_get_json_data_article

	public static function merge_seopress_schema( $schema ) {
		if ( ! is_singular() || ! is_array( $schema ) ) return $schema;
		$post_id = get_queried_object_id();
		if ( ! $post_id ) return $schema;

		$raw = (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
		if ( empty( $raw ) ) return $schema;

		$summary  = RR_Generator::decode_summary( $raw );
		$ai_props = self::build_ai_schema_properties( $post_id, $summary );

		// SEOPress passes the Article schema directly as an assoc array.
		foreach ( $ai_props as $prop => $value ) {
			if ( ! isset( $schema[ $prop ] ) ) {
				$schema[ $prop ] = $value;
			}
		}

		return $schema;
	}

	// ── The SEO Framework: the_seo_framework_schema_graph_data ───────────────

	public static function merge_tsf_schema( $graph ): array {
		if ( ! is_singular() || ! is_array( $graph ) ) return $graph;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::merge_into_article_node( $graph, $post_id );
		return $graph;
	}

	// ── Slim SEO: slim_seo_schema_graph ──────────────────────────────────────

	public static function merge_slim_seo_schema( $graph ): array {
		if ( ! is_singular() || ! is_array( $graph ) ) return $graph;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::merge_into_article_node( $graph, $post_id );
		return $graph;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// BUILD AI-FRIENDLY SCHEMA PROPERTIES (all dynamic, no hardcoding)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Returns all AI-optimization properties to merge into Article schema.
	 * Everything is extracted dynamically from post content and meta.
	 *
	 * Properties added (none generated by any SEO plugin):
	 *  - speakable:            Voice search (Google Assistant, Alexa, Siri)
	 *  - hasPart:              WebPageElement for Key Takeaways — LLMs extract this directly
	 *  - abstract:             Machine-readable summary for AI citation
	 *  - about:                Primary topic entities from categories
	 *  - mentions:             Secondary entities from tags
	 *  - lastReviewed:         Freshness/trust signal from FAQ review date
	 *  - reviewedBy:           E-E-A-T signal — who verified the content
	 *  - significantLink:      Important internal links (relationship graph)
	 *  - citation:             External authoritative links (fact-checking chain)
	 *  - accessibilityFeature: Structural signals (TOC, navigation, alt text)
	 */
	private static function build_ai_schema_properties( int $post_id, array $summary ): array {
		$props = array();
		$post  = get_post( $post_id );

		// ── 1. Speakable — voice search / Google Assistant ─────────────
		if ( 'on' === get_option( RR_OPT_SCHEMA_SPEAKABLE, 'on' ) ) {
			$speakable_selectors = array( 'h1', '.entry-title' );
			if ( ! empty( get_post_meta( $post_id, RR_META_SUMMARY, true ) ) ) {
				$speakable_selectors[] = '.rr-summary';
			}
			if ( ! empty( get_post_meta( $post_id, RR_META_FAQ, true ) ) ) {
				$speakable_selectors[] = '.rr-faq-wrapper';
			}
			$props['speakable'] = array(
				'@type'       => 'SpeakableSpecification',
				'cssSelector' => $speakable_selectors,
			);
		}

		// ── 2. hasPart — Key Takeaways as extractable WebPageElement ───
		// Note: `isAccessibleForFree` is deliberately NOT emitted. Schema.org
		// expects boolean, but Rank Math's schema normalizer coerces booleans
		// to the string "1" during serialization, which fails strict validation.
		// Omitting the property defaults to "freely accessible" per schema.org,
		// which is exactly what we want.
		if ( 'bullets' === $summary['type'] && is_array( $summary['data'] ) && ! empty( $summary['data'] ) ) {
			$label = (string) get_option( RR_OPT_LABEL, 'Key Takeaways' );
			$props['hasPart'] = array(
				array(
					'@type'       => 'WebPageElement',
					'cssSelector' => '.rr-summary',
					'name'        => $label,
					'text'        => implode( '. ', $summary['data'] ) . '.',
				),
			);

			// Also add FAQ section as a hasPart if it exists.
			$faq_data = get_post_meta( $post_id, RR_META_FAQ, true );
			if ( ! empty( $faq_data ) ) {
				$faq_items = json_decode( $faq_data, true );
				if ( is_array( $faq_items ) && ! empty( $faq_items ) ) {
					$faq_text = array();
					foreach ( array_slice( $faq_items, 0, 5 ) as $item ) {
						if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
							$faq_text[] = $item['question'] . ' ' . $item['answer'];
						}
					}
					if ( ! empty( $faq_text ) ) {
						$props['hasPart'][] = array(
							'@type'       => 'WebPageElement',
							'cssSelector' => '.rr-faq-wrapper',
							'name'        => 'Frequently Asked Questions',
							'text'        => implode( ' ', $faq_text ),
						);
					}
				}
			}
		}

		// ── 3. abstract — machine-readable summary for AI citation ─────
		if ( 'bullets' === $summary['type'] && is_array( $summary['data'] ) && ! empty( $summary['data'] ) ) {
			$props['abstract'] = implode( '. ', $summary['data'] ) . '.';
		} elseif ( 'text' === $summary['type'] && ! empty( $summary['data'] ) ) {
			$props['abstract'] = (string) $summary['data'];
		}

		// ── 4. about — primary topic entities from hierarchical taxonomies (categories) ──
		$about = array();
		if ( $post ) {
			$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
			foreach ( $taxonomies as $tax ) {
				if ( ! $tax->hierarchical || ! $tax->public ) continue;
				$terms = get_the_terms( $post_id, $tax->name );
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						if ( 'uncategorized' === $term->slug ) continue;
						$term_url = get_term_link( $term );
						if ( is_wp_error( $term_url ) ) continue;
						$about[] = array(
							'@type' => 'Thing',
							'name'  => $term->name,
							'url'   => $term_url,
						);
						if ( count( $about ) >= 5 ) break 2;
					}
				}
			}
		}
		if ( ! empty( $about ) ) {
			$props['about'] = count( $about ) === 1 ? $about[0] : $about;
		}

		// ── 5. mentions — secondary entities from non-hierarchical taxonomies (tags) ──
		$mentions = array();
		if ( $post ) {
			$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
			foreach ( $taxonomies as $tax ) {
				if ( $tax->hierarchical || ! $tax->public ) continue;
				$terms = get_the_terms( $post_id, $tax->name );
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						$term_url = get_term_link( $term );
						if ( is_wp_error( $term_url ) ) continue;
						$mentions[] = array(
							'@type' => 'Thing',
							'name'  => $term->name,
							'url'   => $term_url,
						);
						if ( count( $mentions ) >= 8 ) break 2;
					}
				}
			}
		}
		if ( ! empty( $mentions ) ) {
			$props['mentions'] = $mentions;
		}

		// ── 6. lastReviewed — freshness signal from post modified date ────
		// Uses post_modified so the schema reflects actual content updates,
		// not just when the AI last generated the FAQ.
		$modified_ts = get_the_modified_time( 'U', $post_id );
		if ( ! empty( $modified_ts ) ) {
			$props['lastReviewed'] = gmdate( 'Y-m-d', (int) $modified_ts );
		}

		// ── 7. reviewedBy — E-E-A-T signal: content author ────────────
		if ( $post ) {
			$author_name = get_the_author_meta( 'display_name', $post->post_author );
			$author_url  = get_author_posts_url( $post->post_author );
			$author_desc = get_the_author_meta( 'description', $post->post_author );

			if ( ! empty( $author_name ) ) {
				$reviewed_by = array(
					'@type' => 'Person',
					'name'  => $author_name,
					'url'   => $author_url,
				);
				if ( ! empty( $author_desc ) ) {
					$reviewed_by['description'] = wp_trim_words( $author_desc, 30 );
				}
				$props['reviewedBy'] = $reviewed_by;
			}
		}

		// ── 8 & 9. significantLink + citation — from post content links ──
		if ( $post && ! empty( $post->post_content ) ) {
			$links = self::extract_content_links( $post->post_content, $post_id );

			if ( ! empty( $links['internal'] ) ) {
				$props['significantLink'] = array_slice( $links['internal'], 0, 10 );
			}
			if ( ! empty( $links['external'] ) ) {
				$citations = array();
				foreach ( array_slice( $links['external'], 0, 10 ) as $ext_url ) {
					$citations[] = array(
						'@type' => 'CreativeWork',
						'url'   => $ext_url,
					);
				}
				$props['citation'] = $citations;
			}
		}

		// ── 10. accessibilityFeature — structural quality signals ──────
		$features = array();
		if ( $post && ! empty( $post->post_content ) ) {
			// Detect TOC (RankReady TOC, The Plus TOC widget, common TOC patterns).
			if ( preg_match( '/class="[^"]*(?:table-of-contents|toc-widget|ez-toc|lwptoc|rr-toc)[^"]*"/i', $post->post_content )
				|| preg_match( '/<!-- wp:rank-math\/toc-block/i', $post->post_content )
				|| has_block( 'rank-math/toc-block', $post )
			) {
				$features[] = 'tableOfContents';
			}

			// Detect headings (h2/h3) indicating structural navigation.
			if ( preg_match_all( '/<h[23][^>]*>/i', $post->post_content, $h_matches ) && count( $h_matches[0] ) >= 2 ) {
				$features[] = 'structuralNavigation';
			}

			// Detect alt text on images.
			if ( preg_match( '/<img[^>]+alt="[^"]+"/i', $post->post_content ) ) {
				$features[] = 'alternativeText';
			}
		}

		// Summary exists = we have a long description / abstract.
		if ( ! empty( $summary['data'] ) ) {
			$features[] = 'longDescription';
		}

		if ( ! empty( $features ) ) {
			$props['accessibilityFeature'] = array_unique( $features );
		}

		return apply_filters( 'rankready_ai_schema_properties', $props, $post_id );
	}

	// ── Extract links from post content ──────────────────────────────────────

	/**
	 * Parse all <a href> from post content and split into internal vs external.
	 * Returns array with 'internal' and 'external' URL arrays.
	 */
	private static function extract_content_links( string $content, int $post_id ): array {
		$result = array( 'internal' => array(), 'external' => array() );

		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			return $result;
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$self_url  = get_permalink( $post_id );
		$seen      = array();

		foreach ( $matches[1] as $url ) {
			$url = esc_url( $url );
			if ( empty( $url ) || isset( $seen[ $url ] ) ) continue;
			if ( $url === $self_url ) continue; // skip self-links
			if ( 0 === strpos( $url, '#' ) ) continue; // skip anchors

			$seen[ $url ] = true;
			$parsed_host  = wp_parse_url( $url, PHP_URL_HOST );

			if ( $parsed_host && $parsed_host === $site_host ) {
				$result['internal'][] = $url;
			} elseif ( $parsed_host && false === strpos( $url, 'javascript:' ) ) {
				// Skip common non-citation domains.
				$skip = array( 'facebook.com', 'twitter.com', 'x.com', 'instagram.com', 'linkedin.com', 'youtube.com', 'pinterest.com', 'tiktok.com', 'wa.me', 'whatsapp.com', 't.me', 'telegram.org', 'play.google.com', 'apps.apple.com' );
				$is_social = false;
				foreach ( $skip as $s ) {
					if ( false !== strpos( $parsed_host, $s ) ) {
						$is_social = true;
						break;
					}
				}
				if ( ! $is_social ) {
					$result['external'][] = $url;
				}
			}
		}

		return $result;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Check if a theme builder (Elementor Pro or Nexter) renders this page.
	 * The blog post itself is NOT built with Elementor — the theme builder template is.
	 * We detect this by checking if Elementor's single template action has fired,
	 * or if the conditions manager has a document assigned for 'single'.
	 */
	public static function is_theme_builder_page( int $post_id ): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		// Post itself built with Elementor (page/landing page edited in Elementor).
		if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return true;
		}

		// Elementor Pro theme builder is rendering a single template.
		// This action fires BEFORE the Post Content widget calls the_content.
		if ( did_action( 'elementor/theme/before_do_single' ) ) {
			return true;
		}

		// Fallback: check via Elementor Pro conditions manager.
		if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			$theme_module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
			if ( method_exists( $theme_module, 'get_conditions_manager' ) ) {
				$conditions = $theme_module->get_conditions_manager();
				if ( method_exists( $conditions, 'get_documents_for_location' ) ) {
					$docs = $conditions->get_documents_for_location( 'single' );
					if ( ! empty( $docs ) ) {
						return true;
					}
				}
			}
		}

		// Nexter Theme Builder detection.
		if ( did_action( 'nexter_single_builder_render' ) ) {
			return true;
		}
		// Nexter stores active template assignments; check for single template.
		$nxt_single = get_option( 'nexter_builder_single_template', '' );
		if ( ! empty( $nxt_single ) ) {
			return true;
		}

		return false;
	}

	public static function validate_heading_tag( $tag ): string {
		$allowed = array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' );
		return in_array( $tag, $allowed, true ) ? $tag : 'h4';
	}

	private static function sanitize_color( $color ): string {
		$color = trim( (string) $color );
		// Allow hex, rgb, rgba, hsl, hsla, CSS named colors
		if ( preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|[a-zA-Z]+)$/', $color ) ) {
			return $color;
		}
		return '';
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SCHEMA AUTO-DETECTION — WP-CRON BASED
	//
	// Detection runs in background via wp-cron.php (not on every page load).
	// Results stored in post meta. wp_head just reads cached meta — zero regex.
	//
	// Flow:
	// 1. WP-Cron fires every 5 min → cron_schema_scan()
	// 2. Queries posts with stale/missing schema hash (batch size from options)
	// 3. For each post: detect type → build schema → store in meta
	// 4. wp_head → output_auto_schema() reads meta → outputs JSON-LD
	// 5. save_post → invalidate_schema_cache() clears hash for re-scan
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Output auto-detected schema from post meta on frontend.
	 * This is the only thing that runs on every page load — a simple meta read.
	 */
	public static function output_auto_schema(): void {
		if ( ! is_singular() ) return;

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) return;

		$schema_type = (string) get_post_meta( $post->ID, RR_META_SCHEMA_TYPE, true );
		if ( empty( $schema_type ) ) return;

		// Check if the schema type is enabled.
		if ( 'howto' === $schema_type && 'on' !== get_option( RR_OPT_SCHEMA_HOWTO, 'on' ) ) return;
		if ( 'itemlist' === $schema_type && 'on' !== get_option( RR_OPT_SCHEMA_ITEMLIST, 'on' ) ) return;

		// Apply developer filters.
		if ( 'howto' === $schema_type && ! apply_filters( 'rankready_inject_howto_schema', true ) ) return;
		if ( 'itemlist' === $schema_type && ! apply_filters( 'rankready_inject_itemlist_schema', true ) ) return;

		$schema_data = get_post_meta( $post->ID, RR_META_SCHEMA_DATA, true );
		if ( empty( $schema_data ) || ! is_array( $schema_data ) ) return;

		// Allow developer customization.
		if ( 'itemlist' === $schema_type ) {
			$schema_data = apply_filters( 'rankready_itemlist_schema', $schema_data, $post );
		}

		echo '<script type="application/ld+json">'
			. wp_json_encode( $schema_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
			. '</script>' . "\n";
	}

	/**
	 * Invalidate schema cache when a post is saved — forces re-scan on next cron run.
	 */
	public static function invalidate_schema_cache( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		if ( 'publish' !== $post->post_status ) return;
		if ( ! is_post_type_viewable( $post->post_type ) ) return;

		// Delete the hash so cron picks it up for re-scanning.
		delete_post_meta( $post_id, RR_META_SCHEMA_HASH );
	}

	/**
	 * WP-Cron callback — scans a batch of posts for HowTo/ItemList schema.
	 * Runs every 5 minutes via wp-cron.php. Processes posts that have no
	 * schema hash or whose content has changed since last scan.
	 */
	public static function cron_schema_scan(): void {
		$batch_size = (int) get_option( RR_OPT_SCHEMA_BATCH_SIZE, 10 );
		if ( $batch_size < 1 ) $batch_size = 1;
		if ( $batch_size > 50 ) $batch_size = 50;

		// Get viewable post types.
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );
		if ( empty( $post_types ) ) return;

		global $wpdb;

		// Find posts that need scanning:
		// 1. No schema hash at all (never scanned)
		// 2. Schema hash doesn't match current content hash (content changed)
		// Build "%s,%s,..." placeholder list for the IN() clause. Only
		// placeholder tokens — never user input. Actual post type slugs
		// are passed to prepare() as trailing args.
		$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ({$type_placeholders})
			   AND (pm.meta_value IS NULL OR pm.meta_value = '')
			 ORDER BY p.post_modified DESC
			 LIMIT %d",
			array_merge( array( RR_META_SCHEMA_HASH ), $post_types, array( $batch_size ) )
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $post_ids ) ) return;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			self::scan_single_post( $post_id );
		}
	}

	/**
	 * Scan a single post for HowTo or ItemList schema and store results in meta.
	 *
	 * @param int $post_id The post ID to scan.
	 * @return string The detected schema type ('howto', 'itemlist', or '').
	 */
	public static function scan_single_post( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) return '';

		$title   = get_the_title( $post_id );
		$content = $post->post_content;

		// Generate content hash for change detection.
		$hash = md5( $title . '|' . $content );

		// Check if already scanned with same content.
		$stored_hash = (string) get_post_meta( $post_id, RR_META_SCHEMA_HASH, true );
		if ( $hash === $stored_hash ) {
			return (string) get_post_meta( $post_id, RR_META_SCHEMA_TYPE, true );
		}

		$schema_type = '';
		$schema_data = array();

		// ── Try HowTo detection first ─────────────────────────────────────
		if ( 'on' === get_option( RR_OPT_SCHEMA_HOWTO, 'on' ) ) {
			$schema_data = self::detect_howto_schema( $post );
			if ( ! empty( $schema_data ) ) {
				$schema_type = 'howto';
			}
		}

		// ── Try ItemList if not HowTo ─────────────────────────────────────
		if ( empty( $schema_type ) && 'on' === get_option( RR_OPT_SCHEMA_ITEMLIST, 'on' ) ) {
			$schema_data = self::detect_itemlist_schema( $post );
			if ( ! empty( $schema_data ) ) {
				$schema_type = 'itemlist';
			}
		}

		// Store results (even empty — so we know it was scanned).
		update_post_meta( $post_id, RR_META_SCHEMA_TYPE, $schema_type );
		update_post_meta( $post_id, RR_META_SCHEMA_DATA, $schema_data );
		update_post_meta( $post_id, RR_META_SCHEMA_HASH, $hash );

		return $schema_type;
	}

	/**
	 * Detect HowTo schema from post content.
	 * Returns complete schema array or empty array if not a HowTo post.
	 */
	private static function detect_howto_schema( \WP_Post $post ): array {
		// Skip if Rank Math/Yoast HowTo block exists.
		if ( false !== strpos( $post->post_content, 'rank-math/howto-block' ) ) return array();
		if ( false !== strpos( $post->post_content, 'yoast/how-to-block' ) ) return array();
		if ( false !== strpos( $post->post_content, 'yoast-seo/how-to' ) ) return array();

		$title = strtolower( get_the_title( $post->ID ) );
		$has_howto_title = (
			false !== strpos( $title, 'how to' ) ||
			false !== strpos( $title, 'how-to' ) ||
			false !== strpos( $title, 'step by step' ) ||
			false !== strpos( $title, 'step-by-step' ) ||
			false !== strpos( $title, 'tutorial' ) ||
			false !== strpos( $title, 'guide to' )
		);
		if ( ! $has_howto_title ) return array();

		$steps = self::extract_howto_steps( $post->post_content );
		if ( count( $steps ) < 2 ) return array();

		// Build schema.
		$schema_steps = array();
		$position = 1;
		foreach ( $steps as $step ) {
			$s = array(
				'@type'    => 'HowToStep',
				'position' => $position,
				'name'     => $step['name'],
			);
			if ( ! empty( $step['text'] ) )  $s['text']  = $step['text'];
			if ( ! empty( $step['image'] ) ) $s['image'] = $step['image'];
			$schema_steps[] = $s;
			$position++;
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'HowTo',
			'name'     => get_the_title( $post->ID ),
			'step'     => $schema_steps,
		);

		// Description from summary or excerpt.
		$raw_summary = (string) get_post_meta( $post->ID, RR_META_SUMMARY, true );
		if ( ! empty( $raw_summary ) ) {
			$summary = RR_Generator::decode_summary( $raw_summary );
			if ( 'bullets' === $summary['type'] && is_array( $summary['data'] ) ) {
				$schema['description'] = implode( '. ', $summary['data'] ) . '.';
			} elseif ( 'text' === $summary['type'] ) {
				$schema['description'] = (string) $summary['data'];
			}
		} elseif ( ! empty( $post->post_excerpt ) ) {
			$schema['description'] = wp_strip_all_tags( $post->post_excerpt );
		}

		// Featured image.
		if ( has_post_thumbnail( $post->ID ) ) {
			$img = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'large' );
			if ( $img ) {
				$schema['image'] = array(
					'@type' => 'ImageObject', 'url' => $img[0],
					'width' => $img[1], 'height' => $img[2],
				);
			}
		}

		return $schema;
	}

	/**
	 * Detect ItemList schema from post content.
	 * Returns complete schema array or empty array if not a listicle.
	 */
	private static function detect_itemlist_schema( \WP_Post $post ): array {
		$title_lower = strtolower( get_the_title( $post->ID ) );

		// Skip if title matches HowTo patterns (mutually exclusive).
		$is_howto = (
			false !== strpos( $title_lower, 'how to' ) ||
			false !== strpos( $title_lower, 'how-to' ) ||
			false !== strpos( $title_lower, 'step by step' ) ||
			false !== strpos( $title_lower, 'step-by-step' ) ||
			false !== strpos( $title_lower, 'tutorial' ) ||
			false !== strpos( $title_lower, 'guide to' )
		);
		if ( $is_howto ) return array();

		// Detect listicle title.
		$has_listicle = (bool) preg_match(
			'/\b(?:best|top|ultimate|essential|must.have|popular|leading|greatest|finest)\s+\d+|\d+\s+(?:best|top|must.have|essential|popular|leading|greatest|finest)\b/i',
			$title_lower
		);
		if ( ! $has_listicle ) {
			$has_listicle = (bool) preg_match(
				'/\b\d+\s+(?:things|tools|plugins|addons|add-ons|ways|tips|resources|options|alternatives|examples|features|reasons|tricks|methods|strategies|ideas|sites|themes|extensions|widgets|apps|services|solutions|products|picks)\b/i',
				$title_lower
			);
		}
		if ( ! $has_listicle ) {
			$has_listicle = (bool) preg_match(
				'/^(?:the\s+)?(?:best|top|ultimate|essential)\s+\w+/i',
				$title_lower
			);
		}
		if ( ! $has_listicle ) return array();

		$items = self::extract_listicle_items( $post->post_content );
		if ( count( $items ) < 3 ) return array();

		// Build schema.
		$list_items = array();
		$position = 1;
		foreach ( $items as $item ) {
			$el = array( '@type' => 'ListItem', 'position' => $position, 'name' => $item['name'] );
			if ( ! empty( $item['url'] ) )         $el['url']         = $item['url'];
			if ( ! empty( $item['description'] ) ) $el['description'] = $item['description'];
			if ( ! empty( $item['image'] ) )       $el['image']       = $item['image'];
			$list_items[] = $el;
			$position++;
		}

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => get_the_title( $post->ID ),
			'description'     => wp_strip_all_tags( get_the_excerpt( $post->ID ) ),
			'url'             => get_permalink( $post->ID ),
			'numberOfItems'   => count( $list_items ),
			'itemListElement' => $list_items,
		);
	}

	/**
	 * Get recommended batch size based on server resources.
	 * Called from admin UI to suggest optimal settings.
	 */
	public static function get_server_recommendation(): array {
		$memory_limit   = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ?: '128M' );
		$max_exec_time  = (int) ini_get( 'max_execution_time' ) ?: 30;
		$php_version    = PHP_VERSION;

		global $wpdb;
		$total_posts = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page')"
		);
		$unscanned = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ('post','page')
			   AND (pm.meta_value IS NULL OR pm.meta_value = '')",
			RR_META_SCHEMA_HASH
		) );

		// Recommendation logic.
		$recommended_batch = 10; // Safe default.

		if ( $memory_limit >= 512 * MB_IN_BYTES && $max_exec_time >= 60 ) {
			$recommended_batch = 25; // High-resource VPS.
		} elseif ( $memory_limit >= 256 * MB_IN_BYTES && $max_exec_time >= 30 ) {
			$recommended_batch = 15; // Mid-range server.
		} elseif ( $memory_limit < 128 * MB_IN_BYTES ) {
			$recommended_batch = 5;  // Shared hosting.
		}

		// Estimate time to complete.
		$batches_needed  = $unscanned > 0 ? ceil( $unscanned / $recommended_batch ) : 0;
		$minutes_to_complete = $batches_needed * 5; // 5 min interval.

		return array(
			'memory_limit'        => size_format( $memory_limit ),
			'max_execution_time'  => $max_exec_time . 's',
			'php_version'         => $php_version,
			'total_posts'         => $total_posts,
			'unscanned_posts'     => $unscanned,
			'scanned_posts'       => $total_posts - $unscanned,
			'recommended_batch'   => $recommended_batch,
			'current_batch'       => (int) get_option( RR_OPT_SCHEMA_BATCH_SIZE, 10 ),
			'est_minutes'         => $minutes_to_complete,
			'cron_next_run'       => wp_next_scheduled( RR_SCHEMA_CRON_HOOK )
				? human_time_diff( time(), wp_next_scheduled( RR_SCHEMA_CRON_HOOK ) )
				: __( 'Not scheduled', 'rankready' ),
			'server_tier'         => $memory_limit >= 512 * MB_IN_BYTES ? 'high' : ( $memory_limit >= 256 * MB_IN_BYTES ? 'mid' : 'low' ),
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// EXTRACTION HELPERS (used by both detect_howto_schema and detect_itemlist_schema)
	// ══════════════════════════════════════════════════════════════════════════

	private static function extract_howto_steps( string $content ): array {
		$steps = array();

		// Method 1: "Step N" headings.
		if ( preg_match_all(
			'/<h([2-4])[^>]*>\s*(?:Step\s+\d+\s*[:\-.\)]*\s*)(.+?)\s*<\/h\1>\s*([\s\S]*?)(?=<h[2-4][^>]*>\s*(?:Step\s+\d+|$)|$)/i',
			$content, $matches, PREG_SET_ORDER
		) && count( $matches ) >= 2 ) {
			foreach ( $matches as $m ) {
				$name = wp_strip_all_tags( trim( $m[2] ) );
				$body = isset( $m[3] ) ? trim( $m[3] ) : '';
				if ( empty( $name ) ) continue;
				$steps[] = array( 'name' => $name, 'text' => self::extract_step_text( $body ), 'image' => self::extract_step_image( $body ) );
			}
			if ( count( $steps ) >= 2 ) return $steps;
			$steps = array();
		}

		// Method 2: Numbered headings.
		if ( preg_match_all(
			'/<h([2-4])[^>]*>\s*(\d+)\s*[.\)\-:]+\s*(.+?)\s*<\/h\1>\s*([\s\S]*?)(?=<h[2-4][^>]*>\s*\d+\s*[.\)\-:]|$)/i',
			$content, $matches, PREG_SET_ORDER
		) && count( $matches ) >= 2 ) {
			foreach ( $matches as $m ) {
				$name = wp_strip_all_tags( trim( $m[3] ) );
				$body = isset( $m[4] ) ? trim( $m[4] ) : '';
				if ( empty( $name ) ) continue;
				$steps[] = array( 'name' => $name, 'text' => self::extract_step_text( $body ), 'image' => self::extract_step_image( $body ) );
			}
			if ( count( $steps ) >= 2 ) return $steps;
			$steps = array();
		}

		// Method 3: Ordered lists.
		if ( preg_match_all( '/<ol[^>]*>([\s\S]*?)<\/ol>/i', $content, $ol_matches ) ) {
			foreach ( $ol_matches[1] as $ol_content ) {
				if ( preg_match_all( '/<li[^>]*>([\s\S]*?)<\/li>/i', $ol_content, $li_matches ) ) {
					if ( count( $li_matches[1] ) < 2 ) continue;
					$ol_steps = array();
					foreach ( $li_matches[1] as $li ) {
						$text = wp_strip_all_tags( trim( $li ) );
						if ( empty( $text ) || strlen( $text ) < 5 ) continue;
						$first_period = strpos( $text, '. ' );
						if ( false !== $first_period && $first_period < 100 ) {
							$name = substr( $text, 0, $first_period );
							$desc = substr( $text, $first_period + 2 );
						} else {
							$name = $text;
							$desc = '';
						}
						$step = array( 'name' => $name, 'image' => self::extract_step_image( $li ) );
						if ( ! empty( $desc ) ) $step['text'] = $desc;
						$ol_steps[] = $step;
					}
					if ( count( $ol_steps ) > count( $steps ) ) $steps = $ol_steps;
				}
			}
		}
		return $steps;
	}

	private static function extract_listicle_items( string $content ): array {
		$items = array();

		// Method 1: Numbered headings.
		if ( preg_match_all(
			'/<h([2-3])[^>]*>\s*(?:#?\d+[\.\)\-:\s]+)\s*(.+?)\s*<\/h\1>\s*([\s\S]*?)(?=<h[2-3][^>]*>\s*(?:#?\d+[\.\)\-:\s])|$)/i',
			$content, $matches, PREG_SET_ORDER
		) && count( $matches ) >= 3 ) {
			foreach ( $matches as $m ) {
				$name = wp_strip_all_tags( trim( $m[2] ) );
				$body = isset( $m[3] ) ? trim( $m[3] ) : '';
				if ( empty( $name ) ) continue;
				$name = preg_replace( '/\s*[\-\–\—]\s*(?:Review|Overview|Pricing|Features).*$/i', '', $name );
				$name = rtrim( $name, ' :-–—' );
				$items[] = array(
					'name' => $name, 'url' => self::extract_item_url( $body, $name ),
					'description' => self::extract_item_description( $body ), 'image' => self::extract_step_image( $body ),
				);
			}
			if ( count( $items ) >= 3 ) return $items;
			$items = array();
		}

		// Method 2: Consecutive headings.
		if ( preg_match_all(
			'/<h([2-3])[^>]*>\s*(.+?)\s*<\/h\1>\s*([\s\S]*?)(?=<h[2-3][^>]*>|$)/i',
			$content, $matches, PREG_SET_ORDER
		) && count( $matches ) >= 3 ) {
			$skip = '/^(?:introduction|conclusion|summary|overview|what\s+is|why|how|faq|frequently|final\s+thoughts|wrap.up|table\s+of\s+contents|related|bonus|honorable|key\s+takeaways)/i';
			foreach ( $matches as $m ) {
				$name = wp_strip_all_tags( trim( $m[2] ) );
				$body = isset( $m[3] ) ? trim( $m[3] ) : '';
				if ( empty( $name ) || strlen( $name ) < 3 ) continue;
				if ( preg_match( $skip, $name ) ) continue;
				if ( preg_match( '/^step\s+\d+/i', $name ) ) continue;
				$name = rtrim( $name, ' :-–—' );
				$items[] = array(
					'name' => $name, 'url' => self::extract_item_url( $body, $name ),
					'description' => self::extract_item_description( $body ), 'image' => self::extract_step_image( $body ),
				);
			}
		}
		return $items;
	}

	private static function extract_step_text( string $body ): string {
		if ( empty( $body ) ) return '';
		$body = preg_replace( '/<h[1-6][^>]*>.*?<\/h[1-6]>/is', '', $body );
		$text = wp_strip_all_tags( $body );
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );
		if ( strlen( $text ) > 500 ) $text = substr( $text, 0, 497 ) . '...';
		return $text;
	}

	private static function extract_step_image( string $body ): string {
		if ( empty( $body ) ) return '';
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $body, $m ) ) return esc_url( $m[1] );
		return '';
	}

	private static function extract_item_url( string $body, string $name ): string {
		if ( empty( $body ) ) return '';
		$esc = preg_quote( $name, '/' );
		if ( preg_match( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>.*?' . $esc . '.*?<\/a>/is', $body, $m ) ) return esc_url( $m[1] );
		if ( preg_match( '/<a[^>]+href=["\'](https?:\/\/[^"\']+)["\']/', $body, $m ) ) return esc_url( $m[1] );
		return '';
	}

	private static function extract_item_description( string $body ): string {
		if ( empty( $body ) ) return '';
		if ( preg_match( '/<p[^>]*>([\s\S]*?)<\/p>/i', $body, $m ) ) {
			$text = wp_strip_all_tags( $m[1] );
		} else {
			$text = wp_strip_all_tags( $body );
		}
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );
		if ( strlen( $text ) > 200 ) $text = substr( $text, 0, 197 ) . '...';
		return $text;
	}
}
