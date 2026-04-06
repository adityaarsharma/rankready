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
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
		add_action( 'wp_enqueue_scripts',          array( self::class, 'enqueue_frontend_assets' ) );
		add_action( 'wp_head',                     array( self::class, 'maybe_inject_schema' ), 1 );
		add_filter( 'the_content',                 array( self::class, 'maybe_auto_display' ), 99 );
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
				// Bullet style
				'bulletColor'       => array( 'type' => 'string',  'default' => '' ),
				'bulletFontSize'    => array( 'type' => 'number',  'default' => 0 ),
				'bulletLineHeight'  => array( 'type' => 'number',  'default' => 0 ),
				'bulletSpacing'     => array( 'type' => 'number',  'default' => 0 ),
				'bulletMarkerColor' => array( 'type' => 'string',  'default' => '' ),
			),
		) );
	}

	// ── FAQ Block registration ────────────────────────────────────────────────

	public static function register_faq_block(): void {
		register_block_type( 'rankready/faq', array(
			'api_version'     => 3,
			'render_callback' => array( self::class, 'render_faq' ),
			'attributes'      => array(
				// Content
				'showTitle'        => array( 'type' => 'boolean', 'default' => true ),
				'titleText'        => array( 'type' => 'string',  'default' => 'Frequently Asked Questions' ),
				'headingTag'       => array( 'type' => 'string',  'default' => 'h3' ),
				'showReviewed'     => array( 'type' => 'boolean', 'default' => true ),
				'keyword'          => array( 'type' => 'string',  'default' => '' ),
				// Box style
				'boxBgColor'       => array( 'type' => 'string',  'default' => '' ),
				'boxBorderColor'   => array( 'type' => 'string',  'default' => '' ),
				'boxBorderWidth'   => array( 'type' => 'number',  'default' => 0 ),
				'boxBorderRadius'  => array( 'type' => 'number',  'default' => 0 ),
				'boxPadding'       => array( 'type' => 'number',  'default' => 0 ),
				// Question style
				'questionColor'    => array( 'type' => 'string',  'default' => '' ),
				'questionFontSize' => array( 'type' => 'number',  'default' => 0 ),
				// Answer style
				'answerColor'      => array( 'type' => 'string',  'default' => '' ),
				'answerFontSize'   => array( 'type' => 'number',  'default' => 0 ),
				// Divider
				'dividerColor'     => array( 'type' => 'string',  'default' => '' ),
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

		// Question styles.
		$q_styles = array();
		if ( ! empty( $attrs['questionColor'] ) ) {
			$q_styles[] = 'color:' . self::sanitize_color( $attrs['questionColor'] );
		}
		if ( ! empty( $attrs['questionFontSize'] ) ) {
			$q_styles[] = 'font-size:' . (int) $attrs['questionFontSize'] . 'px';
		}
		$q_style_attr = ! empty( $q_styles ) ? ' style="' . esc_attr( implode( ';', $q_styles ) ) . '"' : '';

		// Answer styles.
		$a_styles = array();
		if ( ! empty( $attrs['answerColor'] ) ) {
			$a_styles[] = 'color:' . self::sanitize_color( $attrs['answerColor'] );
		}
		if ( ! empty( $attrs['answerFontSize'] ) ) {
			$a_styles[] = 'font-size:' . (int) $attrs['answerFontSize'] . 'px';
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
			$out .= '<p class="rr-faq-answer"' . $a_style_attr . '>' . wp_kses_post( $a ) . '</p>';
			$out .= '</div>';
		}

		$out .= '</div>';

		if ( $show_reviewed ) {
			$generated = get_post_meta( $post_id, RR_META_FAQ_GENERATED, true );
			if ( ! empty( $generated ) ) {
				$date = wp_date( get_option( 'date_format' ), (int) $generated );
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

		// Label inline styles
		$label_styles = array();
		if ( ! empty( $attrs['labelColor'] ) ) {
			$label_styles[] = 'color:' . self::sanitize_color( $attrs['labelColor'] );
		}
		if ( ! empty( $attrs['labelFontSize'] ) ) {
			$label_styles[] = 'font-size:' . (int) $attrs['labelFontSize'] . 'px';
		}
		$label_style_attr = ! empty( $label_styles ) ? ' style="' . esc_attr( implode( ';', $label_styles ) ) . '"' : '';

		// Bullet inline styles
		$bullet_styles = array();
		if ( ! empty( $attrs['bulletColor'] ) ) {
			$bullet_styles[] = 'color:' . self::sanitize_color( $attrs['bulletColor'] );
		}
		if ( ! empty( $attrs['bulletFontSize'] ) ) {
			$bullet_styles[] = 'font-size:' . (int) $attrs['bulletFontSize'] . 'px';
		}
		if ( ! empty( $attrs['bulletLineHeight'] ) ) {
			$bullet_styles[] = 'line-height:' . number_format( (float) $attrs['bulletLineHeight'], 2 ) ;
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

		// Check post type
		$enabled_types = (array) get_option( RR_OPT_POST_TYPES, array( 'post' ) );
		$post          = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, $enabled_types, true ) ) {
			return $content;
		}

		// Skip if block is already in content
		if ( has_block( 'rankready/ai-summary', $post ) ) {
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

		wp_localize_script( 'rr-block-editor', 'rrBlockData', array(
			'defaults' => array(
				'label'      => (string) get_option( RR_OPT_LABEL, 'Key Takeaways' ),
				'showLabel'  => (bool) get_option( RR_OPT_SHOW_LABEL, '1' ),
				'headingTag' => (string) get_option( RR_OPT_HEADING_TAG, 'h4' ),
			),
		) );
	}

	public static function enqueue_frontend_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		// Only enqueue if summary/FAQ exists or auto-display is on
		$has_summary    = ! empty( get_post_meta( $post_id, RR_META_SUMMARY, true ) );
		$has_faq        = ! empty( get_post_meta( $post_id, RR_META_FAQ, true ) );
		$auto_display   = 'on' === get_option( RR_OPT_AUTO_DISPLAY, 'off' );
		$faq_auto       = 'on' === get_option( RR_OPT_FAQ_AUTO_DISPLAY, 'off' );
		$has_block      = has_block( 'rankready/ai-summary' );
		$has_faq_block  = has_block( 'rankready/faq' );

		$needs_style = ( $has_summary && ( $has_block || $auto_display ) )
			|| ( $has_faq && ( $has_faq_block || $faq_auto ) );

		if ( $needs_style ) {
			wp_enqueue_style( 'rankready-style', RR_URL . 'assets/style.css', array(), RR_VERSION );
		}
	}

	// ── Schema injection ──────────────────────────────────────────────────────

	public static function maybe_inject_schema(): void {
		// Skip if major SEO plugins handle schema.
		if ( defined( 'RANK_MATH_VERSION' ) )  return;
		if ( defined( 'WPSEO_VERSION' ) )      return;
		if ( defined( 'AIOSEO_VERSION' ) )     return;
		if ( defined( 'SEOPRESS_VERSION' ) )   return;
		if ( ! is_singular() )                 return;

		// Allow developers to skip schema injection via filter.
		if ( ! apply_filters( 'rankready_inject_schema', true ) ) return;

		$post_id = get_the_ID();
		if ( ! $post_id ) return;

		// Only inject schema for enabled post types.
		$post = get_post( $post_id );
		if ( ! $post ) return;
		$enabled_types = (array) get_option( RR_OPT_POST_TYPES, array( 'post' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) return;

		$raw = (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
		if ( empty( $raw ) ) return;

		$summary     = RR_Generator::decode_summary( $raw );
		$post        = get_post( $post_id );
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
			'speakable'     => array(
				'@type'       => 'SpeakableSpecification',
				'cssSelector' => array( '.rr-summary', 'h1', '.entry-title' ),
			),
		);

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

	// ── Helpers ───────────────────────────────────────────────────────────────

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
}
