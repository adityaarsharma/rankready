<?php
/**
 * FAQ Generator — DataForSEO keyword research + OpenAI generation.
 *
 * Generates FAQ Q&A pairs using:
 * - DataForSEO API for keyword/question discovery (People Also Ask, related keywords)
 * - OpenAI API for answer generation with brand term injection (semantic triples)
 * - Focus keyword from Rank Math/Yoast or manual input
 *
 * Outputs:
 * - FAQPage JSON-LD schema (compound with Article, skipped if Rank Math FAQ block exists)
 * - Gutenberg block / Elementor widget / auto-display
 * - FAQ section in .md Markdown endpoints
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Faq {

	public static function init(): void {
		// Auto-display FAQ via the_content filter.
		add_filter( 'the_content', array( self::class, 'auto_display_faq' ), 95 );

		// Inject FAQPage schema into wp_head.
		add_action( 'wp_head', array( self::class, 'inject_faq_schema' ), 20 );
	}

	// ── Auto-display ─────────────────────────────────────────────────────────

	/**
	 * Auto-append FAQ below/above content if enabled.
	 */
	public static function auto_display_faq( string $content ): string {
		if ( 'on' !== get_option( RR_OPT_FAQ_AUTO_DISPLAY, 'off' ) ) {
			return $content;
		}

		if ( ! is_singular() || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return $content;
		}

		// Check post type.
		$enabled_types = (array) get_option( RR_OPT_FAQ_POST_TYPES, array( 'post' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return $content;
		}

		// Per-post disable.
		if ( get_post_meta( $post->ID, RR_META_FAQ_DISABLE, true ) ) {
			return $content;
		}

		$faq_data = self::get_faq_data( $post->ID );
		if ( empty( $faq_data ) ) {
			return $content;
		}

		$faq_html = self::render_faq_html( $faq_data, $post->ID );

		$position = get_option( RR_OPT_FAQ_POSITION, 'after' );
		if ( 'before' === $position ) {
			return $faq_html . $content;
		}

		return $content . $faq_html;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render FAQ HTML for display.
	 *
	 * @param array $faq_data Array of {question, answer} objects.
	 * @param int   $post_id  Post ID.
	 * @return string HTML output.
	 */
	public static function render_faq_html( array $faq_data, int $post_id = 0 ): string {
		if ( empty( $faq_data ) ) {
			return '';
		}

		$heading_tag = get_option( RR_OPT_FAQ_HEADING_TAG, 'h3' );
		$allowed_tags = array( 'h2', 'h3', 'h4', 'h5', 'h6' );
		if ( ! in_array( $heading_tag, $allowed_tags, true ) ) {
			$heading_tag = 'h3';
		}

		$html  = '<div class="rr-faq-wrapper">';
		$html .= '<' . $heading_tag . ' class="rr-faq-title">';
		$html .= esc_html__( 'Frequently Asked Questions', 'rankready' );
		$html .= '</' . $heading_tag . '>';
		$html .= '<div class="rr-faq-list">';

		foreach ( $faq_data as $i => $item ) {
			$q = isset( $item['question'] ) ? $item['question'] : '';
			$a = isset( $item['answer'] ) ? $item['answer'] : '';
			if ( empty( $q ) || empty( $a ) ) {
				continue;
			}

			$html .= '<div class="rr-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">';
			$html .= '<h4 class="rr-faq-question" itemprop="name">' . esc_html( $q ) . '</h4>';
			$html .= '<div class="rr-faq-answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">';
			$html .= '<div itemprop="text">' . wp_kses_post( $a ) . '</div>';
			$html .= '</div>';
			$html .= '</div>';
		}

		$html .= '</div>';

		// Optional "Last reviewed" text.
		if ( 'on' === get_option( RR_OPT_FAQ_SHOW_REVIEWED, 'off' ) && $post_id > 0 ) {
			$generated = get_post_meta( $post_id, RR_META_FAQ_GENERATED, true );
			if ( ! empty( $generated ) ) {
				$date = wp_date( get_option( 'date_format' ), (int) $generated );
				$html .= '<p class="rr-faq-reviewed">';
				$html .= esc_html( sprintf( __( 'Last reviewed: %s', 'rankready' ), $date ) );
				$html .= '</p>';
			}
		}

		$html .= '</div>';

		return $html;
	}

	// ── Schema ────────────────────────────────────────────────────────────────

	/**
	 * Inject FAQPage JSON-LD schema on singular views.
	 *
	 * Skips if:
	 * - Rank Math FAQ block is present in content (it outputs its own schema)
	 * - Yoast FAQ block is present
	 * - AIOSEO FAQ schema exists
	 * - No FAQ data exists for the post
	 */
	public static function inject_faq_schema(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// Check post type is enabled.
		$enabled_types = (array) get_option( RR_OPT_FAQ_POST_TYPES, array( 'post' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		// Per-post disable.
		if ( get_post_meta( $post->ID, RR_META_FAQ_DISABLE, true ) ) {
			return;
		}

		$faq_data = self::get_faq_data( $post->ID );
		if ( empty( $faq_data ) ) {
			return;
		}

		// Duplicate detection: skip if another plugin's FAQ schema exists.
		if ( self::has_existing_faq_schema( $post ) ) {
			return;
		}

		// Build FAQPage JSON-LD.
		$main_entity = array();
		foreach ( $faq_data as $item ) {
			$q = isset( $item['question'] ) ? $item['question'] : '';
			$a = isset( $item['answer'] ) ? $item['answer'] : '';
			if ( empty( $q ) || empty( $a ) ) {
				continue;
			}
			$main_entity[] = array(
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wp_strip_all_tags( $a ),
				),
			);
		}

		if ( empty( $main_entity ) ) {
			return;
		}

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $main_entity,
		);

		echo '<script type="application/ld+json">'
			. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
			. '</script>' . "\n";
	}

	/**
	 * Check if another plugin already outputs FAQ schema for this post.
	 *
	 * Detects:
	 * - rank-math/faq-block in content (Rank Math outputs its own FAQPage schema)
	 * - yoast/faq-block in content
	 * - AIOSEO FAQ schema
	 */
	private static function has_existing_faq_schema( WP_Post $post ): bool {
		$content = $post->post_content;

		// Rank Math FAQ block.
		if ( false !== strpos( $content, 'rank-math/faq-block' ) ) {
			return true;
		}

		// Yoast FAQ block.
		if ( false !== strpos( $content, 'yoast-seo/faq' ) || false !== strpos( $content, 'yoast/faq' ) ) {
			return true;
		}

		// AIOSEO FAQ.
		if ( false !== strpos( $content, 'aioseo/faq' ) ) {
			return true;
		}

		return false;
	}

	// ── DataForSEO API ────────────────────────────────────────────────────────

	/**
	 * Fetch related questions from DataForSEO API.
	 *
	 * Calls keyword_suggestions and related_keywords to find question-format queries.
	 *
	 * @param string $keyword Focus keyword.
	 * @return array Array of question strings.
	 */
	public static function fetch_dataforseo_questions( string $keyword ): array {
		$login    = get_option( RR_OPT_DFS_LOGIN, '' );
		$password = get_option( RR_OPT_DFS_PASSWORD, '' );

		if ( empty( $login ) || empty( $password ) ) {
			return array();
		}

		$questions = array();

		// Call 1: Keyword suggestions (question-type).
		$suggestions = self::dfs_api_call(
			'https://api.dataforseo.com/v3/dataforseo_labs/google/keyword_suggestions/live',
			array(
				array(
					'keyword'          => $keyword,
					'language_code'    => 'en',
					'location_code'    => 2840, // US
					'include_seed_keyword' => true,
					'limit'            => 20,
					'filters'          => array(
						array( 'keyword_data.keyword_info.search_volume', '>', 0 ),
					),
				),
			),
			$login,
			$password
		);

		if ( ! empty( $suggestions ) ) {
			foreach ( $suggestions as $item ) {
				$kw = isset( $item['keyword_data']['keyword'] ) ? $item['keyword_data']['keyword'] : '';
				// Prioritize question-format keywords.
				if ( ! empty( $kw ) && preg_match( '/^(how|what|why|when|where|which|can|does|is|are|do|should|will)\b/i', $kw ) ) {
					$questions[] = $kw;
				}
			}
		}

		// Call 2: Related keywords for additional questions.
		$related = self::dfs_api_call(
			'https://api.dataforseo.com/v3/dataforseo_labs/google/related_keywords/live',
			array(
				array(
					'keyword'       => $keyword,
					'language_code' => 'en',
					'location_code' => 2840,
					'limit'         => 15,
				),
			),
			$login,
			$password
		);

		if ( ! empty( $related ) ) {
			foreach ( $related as $item ) {
				$kw = isset( $item['keyword_data']['keyword'] ) ? $item['keyword_data']['keyword'] : '';
				if ( ! empty( $kw ) && preg_match( '/^(how|what|why|when|where|which|can|does|is|are|do|should|will)\b/i', $kw ) ) {
					if ( ! in_array( $kw, $questions, true ) ) {
						$questions[] = $kw;
					}
				}
			}
		}

		// Limit to top 15 questions.
		return array_slice( $questions, 0, 15 );
	}

	/**
	 * Make a DataForSEO API call.
	 */
	private static function dfs_api_call( string $url, array $post_data, string $login, string $password ): array {
		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $login . ':' . $password ),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $post_data ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['tasks'][0]['result'][0]['items'] ) ) {
			return array();
		}

		return $body['tasks'][0]['result'][0]['items'];
	}

	// ── OpenAI FAQ Generation ─────────────────────────────────────────────────

	/**
	 * Generate FAQ for a post using DataForSEO questions + OpenAI.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $keyword  Focus keyword (optional, auto-detected from Rank Math/Yoast).
	 * @param int    $count    Number of FAQs to generate (3-10).
	 * @return array|WP_Error Array of {question, answer} or WP_Error.
	 */
	public static function generate_faq( int $post_id, string $keyword = '', int $count = 0 ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new \WP_Error( 'invalid_post', 'Post not found.' );
		}

		// Resolve focus keyword.
		if ( empty( $keyword ) ) {
			$keyword = self::get_focus_keyword( $post_id );
		}

		// Resolve FAQ count.
		if ( $count < 3 || $count > 10 ) {
			$count = (int) get_option( RR_OPT_FAQ_COUNT, 5 );
			if ( $count < 3 || $count > 10 ) {
				$count = 5;
			}
		}

		// Content hash check.
		$content  = wp_strip_all_tags( do_shortcode( $post->post_content ) );
		$new_hash = md5( $content . $keyword . $count );
		$old_hash = (string) get_post_meta( $post_id, RR_META_FAQ_HASH, true );

		if ( $new_hash === $old_hash && ! empty( get_post_meta( $post_id, RR_META_FAQ, true ) ) ) {
			return self::get_faq_data( $post_id );
		}

		// Get brand terms.
		$brand_terms = (string) get_option( RR_OPT_FAQ_BRAND_TERMS, '' );
		if ( empty( $brand_terms ) ) {
			$brand_terms = get_bloginfo( 'name' );
		}

		// Fetch questions from DataForSEO (if credentials available).
		$dfs_questions = array();
		if ( ! empty( $keyword ) ) {
			$dfs_questions = self::fetch_dataforseo_questions( $keyword );
		}

		// Get internal links from post content for doc references.
		$internal_links = self::extract_internal_links( $post );

		// Build OpenAI prompt.
		$prompt = self::build_faq_prompt( $post, $keyword, $brand_terms, $dfs_questions, $internal_links, $count );

		// Call OpenAI.
		$api_key = get_option( RR_OPT_KEY, '' );
		$model   = get_option( RR_OPT_MODEL, 'gpt-4o-mini' );

		if ( empty( $api_key ) ) {
			return new \WP_Error( 'no_api_key', 'OpenAI API key not configured.' );
		}

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'       => $model,
				'messages'    => array(
					array( 'role' => 'system', 'content' => 'You are an FAQ content writer. Write in simple conversational tone. No em dashes. No filler phrases. No hallucination. Only state facts from the provided page content. Always respond with valid JSON only.' ),
					array( 'role' => 'user',   'content' => $prompt ),
				),
				'temperature' => 0.7,
				'max_tokens'  => 2000,
			) ),
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['choices'][0]['message']['content'] ) ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Empty response from OpenAI.';
			return new \WP_Error( 'openai_error', $error_msg );
		}

		$raw = $body['choices'][0]['message']['content'];

		// Parse JSON from response (handle markdown code blocks).
		$raw = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
		$raw = preg_replace( '/\s*```$/i', '', $raw );

		$faq_data = json_decode( $raw, true );

		if ( ! is_array( $faq_data ) ) {
			return new \WP_Error( 'parse_error', 'Failed to parse FAQ response.' );
		}

		// Normalize structure.
		$clean_faq = array();
		foreach ( $faq_data as $item ) {
			if ( isset( $item['question'] ) && isset( $item['answer'] ) ) {
				$clean_faq[] = array(
					'question' => sanitize_text_field( $item['question'] ),
					'answer'   => wp_kses_post( $item['answer'] ),
				);
			}
		}

		if ( empty( $clean_faq ) ) {
			return new \WP_Error( 'empty_faq', 'No valid FAQ items generated.' );
		}

		// Save to post meta.
		update_post_meta( $post_id, RR_META_FAQ, wp_json_encode( $clean_faq ) );
		update_post_meta( $post_id, RR_META_FAQ_HASH, $new_hash );
		update_post_meta( $post_id, RR_META_FAQ_GENERATED, time() );
		update_post_meta( $post_id, RR_META_FAQ_KEYWORD, $keyword );

		// Bump dateModified by touching the post (legitimate content update).
		wp_update_post( array(
			'ID'            => $post_id,
			'post_modified' => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', true ),
		) );

		return $clean_faq;
	}

	/**
	 * Build the OpenAI prompt for FAQ generation.
	 * Uses semantic triples pattern for brand term injection.
	 */
	private static function build_faq_prompt(
		WP_Post $post,
		string $keyword,
		string $brand_terms,
		array $dfs_questions,
		array $internal_links,
		int $count
	): string {
		$title   = get_the_title( $post );
		$content = wp_strip_all_tags( do_shortcode( $post->post_content ) );
		// Truncate content to ~3000 words to stay within token limits.
		$words = preg_split( '/\s+/', $content );
		if ( count( $words ) > 3000 ) {
			$content = implode( ' ', array_slice( $words, 0, 3000 ) ) . '...';
		}

		$prompt = "Generate exactly {$count} FAQ items for this page.\n\n";
		$prompt .= "PAGE TITLE: {$title}\n";

		if ( ! empty( $keyword ) ) {
			$prompt .= "FOCUS KEYWORD: {$keyword}\n";
		}

		$prompt .= "BRAND TERMS: {$brand_terms}\n\n";

		if ( ! empty( $dfs_questions ) ) {
			$prompt .= "RELATED SEARCH QUESTIONS (use these when relevant):\n";
			foreach ( $dfs_questions as $q ) {
				$prompt .= "- {$q}\n";
			}
			$prompt .= "\n";
		}

		if ( ! empty( $internal_links ) ) {
			$prompt .= "INTERNAL LINKS (reference these in answers where relevant):\n";
			foreach ( array_slice( $internal_links, 0, 10 ) as $link ) {
				$prompt .= "- [{$link['text']}]({$link['url']})\n";
			}
			$prompt .= "\n";
		}

		$prompt .= "PAGE CONTENT:\n{$content}\n\n";

		$prompt .= "RULES:\n";
		$prompt .= "1. Each answer MUST be 40-60 words (optimal for AI extraction)\n";
		$prompt .= "2. Use semantic triples: '{$brand_terms} (subject) provides/enables/offers (predicate) [specific feature] (object)'\n";
		$prompt .= "3. Mention brand terms naturally in answers, not forced, not in every answer\n";
		$prompt .= "4. Answers must be factual and match the page content. Do NOT hallucinate features or details not on the page\n";
		$prompt .= "5. Each answer must be self-contained (readable without surrounding context)\n";
		$prompt .= "6. Include specific details, numbers, or feature names where possible\n";
		$prompt .= "7. Do NOT use promotional language or superlatives\n";
		$prompt .= "8. If internal links are provided, reference relevant ones naturally using markdown links\n";
		$prompt .= "9. Questions should be genuine user questions, not keyword-stuffed\n";
		$prompt .= "10. Write in simple, conversational tone matching how a website copy reads. No em dashes. No filler words. Stick to the vocabulary and terminology used on the page itself\n";
		$prompt .= "11. Keep answers plain and direct. No 'certainly', 'indeed', 'absolutely', 'it is worth noting'. Just state the fact\n\n";

		$prompt .= "FORMAT: Return a JSON array of objects with 'question' and 'answer' keys.\n";
		$prompt .= "Example: [{\"question\": \"How does...\", \"answer\": \"...\"}]\n";

		return $prompt;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Get FAQ data for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array of {question, answer} items.
	 */
	public static function get_faq_data( int $post_id ): array {
		$raw = get_post_meta( $post_id, RR_META_FAQ, true );
		if ( empty( $raw ) ) {
			return array();
		}

		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Get focus keyword from SEO plugins or manual input.
	 */
	public static function get_focus_keyword( int $post_id ): string {
		// Rank Math.
		$rm = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
		if ( ! empty( $rm ) ) {
			// Rank Math stores multiple keywords comma-separated, take the first.
			$parts = explode( ',', $rm );
			return trim( $parts[0] );
		}

		// Yoast.
		$yoast = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		if ( ! empty( $yoast ) ) {
			return trim( $yoast );
		}

		// AIOSEO.
		$aioseo = get_post_meta( $post_id, '_aioseo_keywords', true );
		if ( ! empty( $aioseo ) ) {
			return trim( $aioseo );
		}

		// SEOPress.
		$seopress = get_post_meta( $post_id, '_seopress_analysis_target_kw', true );
		if ( ! empty( $seopress ) ) {
			return trim( $seopress );
		}

		// Fallback: use post title.
		return get_the_title( $post_id );
	}

	/**
	 * Extract internal links from post content.
	 *
	 * @param WP_Post $post The post.
	 * @return array Array of {url, text} items.
	 */
	private static function extract_internal_links( WP_Post $post ): array {
		$links = array();
		$home  = home_url();

		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', $post->post_content, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$url  = $match[1];
			$text = wp_strip_all_tags( $match[2] );

			// Only internal links.
			if ( 0 !== strpos( $url, $home ) && 0 !== strpos( $url, '/' ) ) {
				continue;
			}

			if ( ! empty( $text ) && strlen( $text ) > 3 ) {
				$links[] = array(
					'url'  => $url,
					'text' => $text,
				);
			}
		}

		return array_slice( $links, 0, 15 );
	}

	/**
	 * Get FAQ data formatted for Markdown output.
	 *
	 * @param int $post_id Post ID.
	 * @return string Markdown FAQ section.
	 */
	public static function get_faq_markdown( int $post_id ): string {
		$faq_data = self::get_faq_data( $post_id );
		if ( empty( $faq_data ) ) {
			return '';
		}

		$lines = array();
		$lines[] = '## Frequently Asked Questions';
		$lines[] = '';

		foreach ( $faq_data as $item ) {
			$q = isset( $item['question'] ) ? $item['question'] : '';
			$a = isset( $item['answer'] ) ? wp_strip_all_tags( $item['answer'] ) : '';
			if ( empty( $q ) || empty( $a ) ) {
				continue;
			}
			$lines[] = '**Q: ' . $q . '**';
			$lines[] = 'A: ' . $a;
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}
