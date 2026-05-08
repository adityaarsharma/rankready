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

		// Auto-generate on publish/update (gated by RR_OPT_FAQ_AUTO_GENERATE option).
		add_action( 'wp_after_insert_post', array( self::class, 'schedule_faq_generation' ), 20, 4 );

		// FAQ cron runner (used by auto-generate, bulk, and manual triggers).
		add_action( 'rr_async_faq_generate', array( self::class, 'run_faq_generation' ) );
	}

	// ── Auto-generate FAQ on publish ─────────────────────────────────────────

	public static function schedule_faq_generation( $post_id, $post, $update, $post_before ): void {
		$post_id = (int) $post_id;

		// Auto-generate toggle: if off, only generate via manual/bulk actions.
		if ( 'on' !== get_option( RR_OPT_FAQ_AUTO_GENERATE, 'off' ) ) {
			return;
		}

		// Pro gate — auto-generate on publish is a Pro feature.
		if ( ! ( function_exists( 'rr_is_pro' ) && rr_is_pro() ) ) {
			return;
		}

		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// REST API autosave check (Gutenberg).
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
			if ( false !== strpos( $route, '/autosaves' ) ) {
				return;
			}
		}

		// Only run for public post types.
		$public_types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! isset( $public_types[ $post->post_type ] ) || 'attachment' === $post->post_type ) {
			return;
		}

		// Only run for FAQ-enabled post types from settings.
		$enabled_types = (array) get_option( RR_OPT_FAQ_POST_TYPES, array( 'post' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		// Per-post disable.
		if ( get_post_meta( $post_id, RR_META_FAQ_DISABLE, true ) ) {
			return;
		}

		// Need both API keys.
		if ( empty( get_option( RR_OPT_KEY ) ) || empty( get_option( RR_OPT_DFS_LOGIN ) ) || empty( get_option( RR_OPT_DFS_PASSWORD ) ) ) {
			return;
		}

		// Hash check: only call API if content changed.
		$content  = wp_strip_all_tags( do_shortcode( $post->post_content ) );
		$keyword  = self::get_focus_keyword( $post_id );
		$count    = (int) get_option( RR_OPT_FAQ_COUNT, 5 );
		$new_hash = md5( $content . $keyword . $count );
		$old_hash = (string) get_post_meta( $post_id, RR_META_FAQ_HASH, true );

		if ( $new_hash === $old_hash && ! empty( get_post_meta( $post_id, RR_META_FAQ, true ) ) ) {
			return;
		}

		// Schedule via cron (FAQ takes longer due to DataForSEO + OpenAI calls).
		wp_clear_scheduled_hook( 'rr_async_faq_generate', array( $post_id ) );
		wp_schedule_single_event( time() + 15, 'rr_async_faq_generate', array( $post_id ) );
		spawn_cron();
	}

	public static function run_faq_generation( $post_id ): void {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		// Per-post disable check.
		if ( get_post_meta( $post_id, RR_META_FAQ_DISABLE, true ) ) {
			return;
		}

		// Rate limit: don't re-generate if done very recently.
		$last = (int) get_post_meta( $post_id, RR_META_FAQ_GENERATED, true );
		if ( $last && ( time() - $last ) < 60 ) {
			return;
		}

		self::generate_faq( $post_id );
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

		// Check post type — all public CPTs.
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return $content;
		}

		// Per-post disable.
		if ( get_post_meta( $post->ID, RR_META_FAQ_DISABLE, true ) ) {
			return $content;
		}

		// Skip if a theme builder renders this page — display is handled by the widget.
		if ( class_exists( 'RR_Block' ) && RR_Block::is_theme_builder_page( $post->ID ) ) {
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
			$html .= '<div itemprop="text">' . wp_kses_post( self::convert_markdown_links( $a ) ) . '</div>';
			$html .= '</div>';
			$html .= '</div>';
		}

		$html .= '</div>';

		// Optional "Last reviewed" text — uses post modified date so it reflects
		// when the content was actually updated, not when the FAQ was generated.
		if ( 'on' === get_option( RR_OPT_FAQ_SHOW_REVIEWED, 'off' ) && $post_id > 0 ) {
			$modified_ts = get_the_modified_time( 'U', $post_id );
			if ( ! empty( $modified_ts ) ) {
				$date = wp_date( get_option( 'date_format' ), (int) $modified_ts );
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
		if ( 'on' !== get_option( RR_OPT_SCHEMA_FAQ, 'on' ) ) {
			return;
		}
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// Check post type — all public CPTs.
		if ( ! is_post_type_viewable( $post->post_type ) ) {
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
					'text'  => wp_strip_all_tags( self::convert_markdown_links( $a ) ),
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
	 * Multi-source question discovery:
	 * 1. People Also Ask (PAA) from SERP — real Google questions users see.
	 * 2. Keyword suggestions — question-format long-tail keywords with volume.
	 * 3. Related keywords — semantic cluster questions.
	 * 4. Google Autosuggest patterns — "vs", "vs alternative", comparison queries.
	 *
	 * Sorted by search volume, semantically deduplicated, capped at 20.
	 *
	 * @param string $keyword Focus keyword.
	 * @param string $page_type Page type context ('post', 'page', 'docs', 'landing').
	 * @return array Array of question strings with source metadata.
	 */
	public static function fetch_dataforseo_questions( string $keyword, string $page_type = 'post' ): array {
		$login    = get_option( RR_OPT_DFS_LOGIN, '' );
		$password = get_option( RR_OPT_DFS_PASSWORD, '' );

		if ( empty( $login ) || empty( $password ) ) {
			return array();
		}

		// Collect questions with metadata for smart ranking.
		$raw_questions = array(); // key = lowercase question, value = { keyword, volume, source }.

		// Question word patterns — expanded for docs/landing pages.
		$question_regex = '/^(how|what|why|when|where|which|can|does|is|are|do|should|will|would|could|has|have|need|want)\b/i';

		// Comparison/decision patterns — high-intent for landing pages.
		$comparison_regex = '/\b(vs\.?|versus|alternative|compared|comparison|difference|better|worth|review)\b/i';

		// NOTE: SERP PAA call removed — DataForSEO SERP endpoint is too slow
		// (15s+ timeouts with 0 bytes) and kills bulk operations on servers
		// with 30s max_execution_time. keyword_suggestions + related_keywords
		// already provide question-format queries with search volume data.

		// ── Call 1: Keyword suggestions (question-format long-tails) ──────────
		$suggestions = self::dfs_api_call(
			'https://api.dataforseo.com/v3/dataforseo_labs/google/keyword_suggestions/live',
			array(
				array(
					'keyword'              => $keyword,
					'language_code'        => 'en',
					'location_code'        => 2840,
					'include_seed_keyword' => true,
					'limit'                => 40,
					'filters'              => array(
						array( 'keyword_data.keyword_info.search_volume', '>', 0 ),
					),
					'order_by'             => array( 'keyword_data.keyword_info.search_volume,desc' ),
				),
			),
			$login,
			$password,
			5
		);

		if ( ! empty( $suggestions ) ) {
			foreach ( $suggestions as $item ) {
				$kw     = isset( $item['keyword_data']['keyword'] ) ? $item['keyword_data']['keyword'] : '';
				$volume = isset( $item['keyword_data']['keyword_info']['search_volume'] ) ? (int) $item['keyword_data']['keyword_info']['search_volume'] : 0;
				if ( empty( $kw ) ) continue;

				// Accept question-format OR comparison-format keywords.
				$is_question   = (bool) preg_match( $question_regex, $kw );
				$is_comparison = (bool) preg_match( $comparison_regex, $kw );

				if ( $is_question || $is_comparison ) {
					$key = strtolower( trim( $kw ) );
					if ( ! isset( $raw_questions[ $key ] ) || $volume > $raw_questions[ $key ]['volume'] ) {
						$raw_questions[ $key ] = array(
							'keyword' => $kw,
							'volume'  => $volume,
							'source'  => $is_comparison ? 'comparison' : 'suggestion',
						);
					}
				}
			}
		}

		// ── Call 3: Related keywords for semantic cluster questions ────────────
		$related = self::dfs_api_call(
			'https://api.dataforseo.com/v3/dataforseo_labs/google/related_keywords/live',
			array(
				array(
					'keyword'       => $keyword,
					'language_code' => 'en',
					'location_code' => 2840,
					'limit'         => 30,
				),
			),
			$login,
			$password,
			5
		);

		if ( ! empty( $related ) ) {
			foreach ( $related as $item ) {
				$kw     = isset( $item['keyword_data']['keyword'] ) ? $item['keyword_data']['keyword'] : '';
				$volume = isset( $item['keyword_data']['keyword_info']['search_volume'] ) ? (int) $item['keyword_data']['keyword_info']['search_volume'] : 0;
				if ( empty( $kw ) ) continue;

				if ( preg_match( $question_regex, $kw ) || preg_match( $comparison_regex, $kw ) ) {
					$key = strtolower( trim( $kw ) );
					if ( ! isset( $raw_questions[ $key ] ) || $volume > $raw_questions[ $key ]['volume'] ) {
						$raw_questions[ $key ] = array(
							'keyword' => $kw,
							'volume'  => $volume,
							'source'  => 'related',
						);
					}
				}
			}
		}

		// ── Smart ranking: prioritize by source quality + volume ──────────────
		// PAA > comparison > suggestion > related (with volume as tiebreaker).
		$source_weight = array( 'paa' => 10000, 'comparison' => 5000, 'suggestion' => 1000, 'related_search' => 3000, 'related' => 500 );
		uasort( $raw_questions, function ( $a, $b ) use ( $source_weight ) {
			$a_score = ( isset( $source_weight[ $a['source'] ] ) ? $source_weight[ $a['source'] ] : 0 ) + $a['volume'];
			$b_score = ( isset( $source_weight[ $b['source'] ] ) ? $source_weight[ $b['source'] ] : 0 ) + $b['volume'];
			return $b_score - $a_score;
		} );

		// ── Semantic deduplication (aggressive) ───────────────────────────────
		$stop_words = '/\b(how|what|why|when|where|which|can|does|is|are|do|should|will|would|could|has|have|to|the|a|an|of|for|in|on|with|and|or|my|your|i|you|it|this|that|best|good|need|want|get)\b/i';
		$seen_cores = array();
		$questions  = array();

		foreach ( $raw_questions as $entry ) {
			$core = preg_replace( $stop_words, '', strtolower( $entry['keyword'] ) );
			$core = preg_replace( '/[^a-z0-9\s]/', '', $core );
			$core = preg_replace( '/\s+/', ' ', trim( $core ) );

			// Skip if core matches an already-seen question (fuzzy dedup).
			$is_dup = false;
			foreach ( $seen_cores as $seen ) {
				if ( $core === $seen ) { $is_dup = true; break; }
				// Levenshtein similarity for near-duplicates (< 30% different).
				if ( strlen( $core ) > 5 && strlen( $seen ) > 5 ) {
					$dist = levenshtein( $core, $seen );
					$max_len = max( strlen( $core ), strlen( $seen ) );
					if ( $dist / $max_len < 0.3 ) { $is_dup = true; break; }
				}
			}
			if ( $is_dup ) continue;

			$seen_cores[] = $core;
			$questions[]  = $entry['keyword'];
			if ( count( $questions ) >= 20 ) break;
		}

		return $questions;
	}

	/**
	 * Make a DataForSEO API call.
	 *
	 * @param string $url      API endpoint URL.
	 * @param array  $post_data Request body.
	 * @param string $login    DFS login.
	 * @param string $password DFS password.
	 * @param int    $timeout  Request timeout in seconds (default 5).
	 * @return array Parsed items array or empty on failure.
	 */
	private static function dfs_api_call( string $url, array $post_data, string $login, string $password, int $timeout = 5 ): array {
		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $login . ':' . $password ),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $post_data ),
			'timeout' => $timeout,
		) );

		if ( is_wp_error( $response ) ) {
			RR_Generator::log_error( 'DataForSEO', $response->get_error_message() );
			return array();
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $http_code ) {
			$err = isset( $body['status_message'] ) ? $body['status_message'] : 'HTTP ' . $http_code;
			RR_Generator::log_error( 'DataForSEO', $err );
		}

		// Track DFS usage.
		$dfs_cost = 0;
		if ( isset( $body['cost'] ) ) {
			$dfs_cost = (float) $body['cost'];
		}
		self::track_dfs_usage( $dfs_cost );

		if ( empty( $body['tasks'][0]['result'][0]['items'] ) ) {
			return array();
		}

		return $body['tasks'][0]['result'][0]['items'];
	}

	/**
	 * Track DataForSEO API usage (calls + cost).
	 */
	private static function track_dfs_usage( float $cost ): void {
		$usage = (array) get_option( 'rr_dfs_usage', array(
			'total_calls' => 0,
			'total_cost'  => 0,
		) );

		$usage['total_calls'] = ( isset( $usage['total_calls'] ) ? (int) $usage['total_calls'] : 0 ) + 1;
		$usage['total_cost']  = ( isset( $usage['total_cost'] ) ? (float) $usage['total_cost'] : 0 ) + $cost;

		update_option( 'rr_dfs_usage', $usage, false );
	}

	// ── OpenAI FAQ Generation ─────────────────────────────────────────────────

	/**
	 * Generate FAQ for a post using DataForSEO questions + OpenAI.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $keyword  Focus keyword (optional, auto-detected from Rank Math/Yoast).
	 * @param int    $count    Number of FAQs to generate (3-10).
	 * @return array|\WP_Error Array of {question, answer} or WP_Error.
	 */
	public static function generate_faq( int $post_id, string $keyword = '', int $count = 0 ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new \WP_Error( 'invalid_post', 'Post not found.' );
		}

		// ── Free tier limit check (before API call) ───────────────────────────
		if ( ! RR_Limits::can_generate_faq() ) {
			return RR_Limits::faq_limit_error();
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

		// Detect page type for context-aware FAQ generation.
		$page_type = self::detect_page_type( $post );

		// Fetch questions from DataForSEO (if credentials available).
		$dfs_questions = array();
		if ( ! empty( $keyword ) ) {
			$dfs_questions = self::fetch_dataforseo_questions( $keyword, $page_type );
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

		// Build system prompt with product context.
		$faq_system  = "You write FAQ answers for web pages. You respond with valid JSON only.\n\n";
		$faq_system .= "YOUR GOAL: Generate FAQ optimized for LLM citation (ChatGPT, Perplexity, Gemini, Claude) AND Google Featured Snippets. Each Q&A must match a REAL search intent — something a person would actually type into Google, Reddit, or an AI chatbot.\n\n";
		$faq_system .= "SEARCH INTENT REQUIREMENT:\n";
		$faq_system .= "- Every question MUST pass this test: 'Would someone actually type this into Google or ask ChatGPT?'\n";
		$faq_system .= "- If a question only makes sense AFTER reading the page (e.g. 'What happens if I click this toggle?'), it fails the test. Real users don't search for UI toggles.\n";
		$faq_system .= "- Good search intent examples: troubleshooting errors, comparing tools, asking if X works with Y, asking about performance impact, asking about limitations.\n";
		$faq_system .= "- Bad search intent examples: rephrasing page headings, asking about specific UI elements, asking obvious yes/no questions about the product's own features.\n\n";
		$faq_system .= "ABSOLUTE RULES:\n";
		$faq_system .= "- Every answer must be based ONLY on facts stated in the page content provided. Zero invention.\n";
		$faq_system .= "- NEVER claim a product works with a platform, tool, or builder unless the page explicitly says so.\n";
		$faq_system .= "- NEVER add features, pricing, statistics, or compatibility details the page does not mention.\n";
		$faq_system .= "- Use the exact product names, brand names, and terminology from the page. No renaming.\n";
		$faq_system .= "- Write like a knowledgeable human on Reddit answering a question. Direct, specific, no fluff.\n";
		$faq_system .= "- No em dashes. No filler (certainly, indeed, it is worth noting, comprehensive, robust, leverage, utilize).\n";
		$faq_system .= "- No generic openers like 'Yes, ...', 'Yes, [Brand Name]...', 'Absolutely, ...', 'Great question!', 'To select...', 'After selecting...', 'Sure, ...', 'Of course, ...'\n";
		$faq_system .= "- If the page content does not have enough info to answer a question, SKIP IT and pick a different question. NEVER write an answer that says 'the page does not mention' or 'there is no information about' — just don't include that question at all.\n";
		$faq_system .= "- NEVER write answers that just say 'follow the steps' or 'following the provided steps' or 'use the recommended approach'. That is zero-value content.\n";
		$faq_system .= "- Each answer must TEACH something — add context, explain WHY, give a tip, mention a gotcha, or connect to a bigger picture. Don't just restate what the page says.\n";
		$faq_system .= "- Each answer should contain a SPECIFIC fact, number, name, or detail from the page. No vague statements.\n\n";
		$faq_system .= "BANNED QUESTION PATTERNS (never generate these):\n";
		$faq_system .= "- Questions that just rephrase a heading or menu step from the page (e.g. 'How do I select X?' when the page already shows the dropdown)\n";
		$faq_system .= "- Questions about UI clicks that only have one obvious answer (e.g. 'What happens after I click X button?')\n";
		$faq_system .= "- Questions where the answer is just 'do the thing the page already tells you to do'\n";
		$faq_system .= "- Overly narrow questions about a single dropdown or checkbox option\n";
		$faq_system .= "- Questions nobody would ever actually type into Google or ask an AI chatbot\n";
		$faq_system .= "- 'What do I need before starting...' or 'What are the prerequisites...' — boring, obvious, low-value\n";
		$faq_system .= "- 'What happens after I complete the steps...' or 'What happens when I finish...' — the page already shows this\n";
		$faq_system .= "- 'Is there an alternative way/method...' — unless the page actually discusses alternatives\n\n";
		$faq_system .= "BANNED ANSWER PATTERNS (never write these):\n";
		$faq_system .= "- 'The page does not mention...' or 'There is no information about...' — skip the question instead\n";
		$faq_system .= "- 'Follow the steps provided' or 'following the provided steps' or 'using the recommended approach'\n";
		$faq_system .= "- Answers that just rephrase the page instructions in slightly different words\n";
		$faq_system .= "- One-sentence answers with no depth or insight\n";
		$faq_system .= "- Answers that read like a manual ('Step 1: Go to... Step 2: Click...')\n\n";
		$faq_system .= "GOOD QUESTION PATTERNS (generate these):\n";
		$faq_system .= "- Troubleshooting: 'Why isn't X working?', 'X not showing up, how to fix?'\n";
		$faq_system .= "- Real-world use cases: 'Can I use X for [practical scenario]?'\n";
		$faq_system .= "- Compatibility: 'Does X work with [related technology]?'\n";
		$faq_system .= "- Comparison: 'What is the difference between X and Y?'\n";
		$faq_system .= "- Limitations: 'Are there any limitations with X?'\n";
		$faq_system .= "- Best practices: 'What settings work best for X use case?'\n";
		$faq_system .= "- Performance/impact: 'Does X slow down the page?', 'Any performance considerations?'\n";
		$faq_system .= "- Edge cases: 'Does X work on mobile/tablet?', 'What about RTL layouts?'\n";
		$faq_system .= "- Common mistakes: 'What do most people get wrong when setting up X?'\n";

		$product_context = (string) get_option( RR_OPT_PRODUCT_CONTEXT, '' );
		if ( ! empty( $product_context ) ) {
			$faq_system .= "\n\nPRODUCT CONTEXT (fact-check reference — never contradict this, never add details beyond what the page says):\n" . $product_context;
		}

		$custom_prompt = (string) get_option( RR_OPT_CUSTOM_PROMPT, '' );
		if ( ! empty( $custom_prompt ) ) {
			$faq_system .= "\n\nAdditional instructions:\n" . $custom_prompt;
		}

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'           => $model,
				'messages'        => array(
					array( 'role' => 'system', 'content' => $faq_system ),
					array( 'role' => 'user',   'content' => $prompt ),
				),
				'response_format' => array( 'type' => 'json_object' ),
				'temperature'     => 0.3,
				'max_tokens'      => 2000,
			) ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			RR_Generator::log_error( 'FAQ/OpenAI', $response->get_error_message(), $post_id );
			return $response;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $http_code ) {
			$err = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $http_code;
			RR_Generator::log_error( 'FAQ/OpenAI', $err, $post_id );
			return new \WP_Error( 'openai_http_error', $err );
		}

		// Track token usage.
		if ( isset( $body['usage']['total_tokens'] ) ) {
			RR_Generator::track_tokens( (int) $body['usage']['total_tokens'], $post_id, 'faq' );
		}

		if ( empty( $body['choices'][0]['message']['content'] ) ) {
			$error_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Empty response from OpenAI.';
			RR_Generator::log_error( 'FAQ/OpenAI', $error_msg, $post_id );
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

		// Handle wrapped responses: { "faq": [...] }, { "faqs": [...] }, { "questions": [...] } or flat array.
		if ( isset( $faq_data['faq'] ) && is_array( $faq_data['faq'] ) ) {
			$faq_data = $faq_data['faq'];
		} elseif ( isset( $faq_data['faqs'] ) && is_array( $faq_data['faqs'] ) ) {
			$faq_data = $faq_data['faqs'];
		} elseif ( isset( $faq_data['questions'] ) && is_array( $faq_data['questions'] ) ) {
			$faq_data = $faq_data['questions'];
		} elseif ( isset( $faq_data['items'] ) && is_array( $faq_data['items'] ) ) {
			$faq_data = $faq_data['items'];
		} elseif ( ! isset( $faq_data[0] ) ) {
			// Unknown wrapper key — try the first array value.
			$first = reset( $faq_data );
			if ( is_array( $first ) && isset( $first[0]['question'] ) ) {
				$faq_data = $first;
			}
		}

		// Normalize structure.
		$clean_faq = array();
		foreach ( $faq_data as $item ) {
			if ( isset( $item['question'] ) && isset( $item['answer'] ) ) {
				$q = sanitize_text_field( $item['question'] );
				$a = wp_kses_post( $item['answer'] );
				// Skip empty or too-short answers.
				if ( empty( $q ) || empty( $a ) || strlen( $a ) < 20 ) continue;
				$clean_faq[] = array(
					'question' => $q,
					'answer'   => $a,
				);
			}
		}

		// Post-generation validation — reject items that violate banned patterns.
		$clean_faq = self::validate_faq_items( $clean_faq );

		if ( empty( $clean_faq ) ) {
			return new \WP_Error( 'empty_faq', 'No valid FAQ items generated.' );
		}

		// Save to post meta.
		update_post_meta( $post_id, RR_META_FAQ, wp_json_encode( $clean_faq ) );
		update_post_meta( $post_id, RR_META_FAQ_HASH, $new_hash );
		update_post_meta( $post_id, RR_META_FAQ_GENERATED, time() );
		update_post_meta( $post_id, RR_META_FAQ_KEYWORD, $keyword );

		// Record free tier usage after successful FAQ generation.
		RR_Limits::record_faq();

		// Bump dateModified by touching the post (legitimate content update).
		// Set generator guard to prevent wp_update_post from re-triggering summary generation.
		RR_Generator::$generating = true;
		wp_update_post( array(
			'ID'            => $post_id,
			'post_modified' => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', true ),
		) );
		RR_Generator::$generating = false;

		return $clean_faq;
	}

	/**
	 * Detect page type from content and title patterns.
	 *
	 * Returns: 'docs', 'landing', 'comparison', 'tutorial', 'blog'
	 * Used to tailor FAQ question style and prompt for each page type.
	 */
	private static function detect_page_type( WP_Post $post ): string {
		$title = strtolower( get_the_title( $post ) );
		$type  = $post->post_type;
		$content_lower = strtolower( $post->post_content );

		// Documentation pages.
		if ( 'docs' === $type || 'doc' === $type || 'documentation' === $type
			|| preg_match( '/\b(documentation|api\s+reference|developer\s+guide|changelog|getting\s+started|configuration|setup\s+guide)\b/i', $title )
			|| preg_match( '/class="[^"]*(?:docs-|documentation|api-reference)[^"]*"/i', $content_lower )
		) {
			return 'docs';
		}

		// Landing pages.
		if ( 'page' === $type && (
			preg_match( '/\b(pricing|features|why\s+choose|get\s+started|sign\s+up|free\s+trial|demo|plans)\b/i', $title )
			|| preg_match( '/class="[^"]*(?:hero|cta|pricing-table|landing)[^"]*"/i', $content_lower )
			|| preg_match( '/<a[^>]*class="[^"]*(?:btn|button|cta)[^"]*"/i', $content_lower )
		) ) {
			return 'landing';
		}

		// Comparison / vs posts.
		if ( preg_match( '/\b(vs\.?|versus|alternative|compared|comparison)\b/i', $title ) ) {
			return 'comparison';
		}

		// Tutorial / How-to.
		if ( preg_match( '/\b(how\s+to|tutorial|step.by.step|guide\s+to)\b/i', $title ) ) {
			return 'tutorial';
		}

		return 'blog';
	}

	/**
	 * Build the OpenAI prompt for FAQ generation.
	 *
	 * Context-aware prompt that adapts to page type:
	 * - Docs: troubleshooting, "does it support X", config questions
	 * - Landing: buyer objections, pricing, comparisons, "is it worth it"
	 * - Comparison: "vs" questions, "which is better", migration
	 * - Tutorial: "what if X fails", prerequisites, alternatives
	 * - Blog: topical authority, Reddit-style questions, debate points
	 *
	 * Question sourcing priority:
	 * 1. People Also Ask (real Google PAA from SERP)
	 * 2. Comparison/decision queries (high commercial intent)
	 * 3. Long-tail keyword suggestions (with search volume)
	 * 4. Contextual questions generated from page content
	 *
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
		$title     = get_the_title( $post );
		$content   = wp_strip_all_tags( do_shortcode( $post->post_content ) );
		$page_type = self::detect_page_type( $post );

		// Truncate content to ~3500 words to stay within token limits.
		$words = preg_split( '/\s+/', $content );
		if ( count( $words ) > 3500 ) {
			$content = implode( ' ', array_slice( $words, 0, 3500 ) ) . '...';
		}

		// Extract headings for structural context.
		$headings = array();
		if ( preg_match_all( '/<h[2-3][^>]*>(.*?)<\/h[2-3]>/si', $post->post_content, $h_matches ) ) {
			foreach ( $h_matches[1] as $h ) {
				$headings[] = wp_strip_all_tags( trim( $h ) );
			}
		}

		$prompt = "Generate exactly {$count} FAQ items for this {$page_type} page.\n\n";
		$prompt .= "PAGE TITLE: {$title}\n";
		$prompt .= "PAGE TYPE: {$page_type}\n";

		if ( ! empty( $keyword ) ) {
			$prompt .= "FOCUS KEYWORD: {$keyword}\n";
		}

		$prompt .= "BRAND TERMS: {$brand_terms}\n";

		if ( ! empty( $headings ) ) {
			$prompt .= "PAGE STRUCTURE (H2/H3 headings):\n";
			foreach ( array_slice( $headings, 0, 15 ) as $h ) {
				$prompt .= "  - {$h}\n";
			}
		}
		$prompt .= "\n";

		if ( ! empty( $dfs_questions ) ) {
			$prompt .= "REAL SEARCH QUESTIONS (from Google PAA + keyword data, sorted by popularity):\n";
			foreach ( $dfs_questions as $i => $q ) {
				$num = $i + 1;
				$prompt .= "  {$num}. {$q}\n";
			}
			$prompt .= "\n";
		}

		if ( ! empty( $internal_links ) ) {
			$prompt .= "INTERNAL LINKS (reference in answers where relevant):\n";
			foreach ( array_slice( $internal_links, 0, 10 ) as $link ) {
				$prompt .= "  - [{$link['text']}]({$link['url']})\n";
			}
			$prompt .= "\n";
		}

		$prompt .= "PAGE CONTENT:\n{$content}\n\n";

		// ── Page-type-specific question guidance ──────────────────────────────
		$prompt .= "QUESTION STRATEGY FOR {$page_type} PAGES:\n";

		switch ( $page_type ) {
			case 'docs':
				$prompt .= "- NEVER just rephrase the documentation steps as questions. Users can already read the steps.\n";
				$prompt .= "- Ask troubleshooting questions: 'Why is [feature] not working?', '[feature] not showing, how to fix?'\n";
				$prompt .= "- Ask real-world use case questions: 'Can I use [feature] for [practical scenario]?'\n";
				$prompt .= "- Ask compatibility/limitation questions: 'Does [feature] work on mobile?', 'Any performance impact?'\n";
				$prompt .= "- Ask 'best settings for X use case' questions that help users make decisions.\n";
				$prompt .= "- Ask questions that someone would type into Google AFTER reading the docs and still being confused.\n";
				$prompt .= "- Think like a user who tried following the docs, something didn't work, and now they're asking on Reddit.\n";
				break;

			case 'landing':
				$prompt .= "- Ask buyer objection questions: 'Is {$brand_terms} worth it?', 'What makes it different?'\n";
				$prompt .= "- Ask decision-making questions: 'Who is this for?', 'What problem does it solve?'\n";
				$prompt .= "- Ask 'can I use it for X' questions based on listed features.\n";
				$prompt .= "- Ask pricing/value questions if pricing info exists on the page.\n";
				$prompt .= "- Think like a potential buyer comparing options on Reddit.\n";
				break;

			case 'comparison':
				$prompt .= "- Ask 'which is better for X use case' questions.\n";
				$prompt .= "- Ask migration questions: 'Can I switch from X to Y?'\n";
				$prompt .= "- Ask feature difference questions: 'Does X have Y feature?'\n";
				$prompt .= "- Ask real decision questions: 'Is X worth switching to from Y?'\n";
				$prompt .= "- Think like someone on Reddit asking for honest opinions.\n";
				break;

			case 'tutorial':
				$prompt .= "- Ask 'what if step X fails' troubleshooting questions.\n";
				$prompt .= "- Ask prerequisite questions: 'What do I need before starting?'\n";
				$prompt .= "- Ask alternative approach questions: 'Is there another way to do X?'\n";
				$prompt .= "- Ask outcome questions: 'What happens after I complete this?'\n";
				$prompt .= "- Think like someone following the tutorial who hits a snag.\n";
				break;

			default: // blog
				$prompt .= "- Ask debate/opinion questions: 'Is X really necessary?', 'Does X actually work?'\n";
				$prompt .= "- Ask practical application questions: 'How do I use X in my situation?'\n";
				$prompt .= "- Ask 'what most people get wrong about X' contrarian questions.\n";
				$prompt .= "- Ask Reddit-style direct questions: blunt, specific, no fluff.\n";
				$prompt .= "- Think like a curious person on Reddit, not a marketer.\n";
				break;
		}

		$prompt .= "\n";

		$prompt .= "QUESTION RULES:\n";
		$prompt .= "- Each question MUST address a DIFFERENT topic. Zero overlap or rephrasing.\n";

		if ( ! empty( $dfs_questions ) ) {
			$prompt .= "- USE the REAL SEARCH QUESTIONS above — these are actual Google queries with search volume. Adapt them to fit the page content. At least " . min( count( $dfs_questions ), (int) ceil( $count * 0.6 ) ) . " of your {$count} questions MUST be based on these.\n";
		}

		$prompt .= "- If a search question cannot be answered from the page content, skip it.\n";
		$prompt .= "- Fill remaining slots with questions a user would ACTUALLY type into Google or ask an AI chatbot.\n";
		$prompt .= "- NEVER stuff brand names into questions. Write them how a real person would ask: 'How do I add a contact form to a WordPress page?' NOT 'Can I use [Plugin Name] to add a contact form to a WordPress page?'\n";
		$prompt .= "- REQUIRED MIX: at least 1 troubleshooting question, 1 use-case/compatibility question, and 1 'best practice' or decision question.\n";
		$prompt .= "- NEVER ask generic questions like 'What is [topic]?' unless the page is specifically defining that topic.\n";
		$prompt .= "- NEVER ask questions that just rephrase a heading, menu option, or step from the page.\n";
		$prompt .= "- NEVER ask obvious questions where the answer is clear from the product name (e.g. 'Does [Elementor addon] work with page builders besides Elementor?' — obviously not).\n";
		$prompt .= "- TEST: Before including a question, ask yourself 'Would a real person type this into Google?' If not, discard it.\n";
		$prompt .= "- Ask questions an AI chatbot (ChatGPT, Perplexity, Gemini) would need answered to recommend this page.\n\n";

		$prompt .= "ANSWER RULES:\n";
		$prompt .= "- 50-100 words per answer. Every answer needs substance, not just a single sentence.\n";
		$prompt .= "- Structure: Direct answer first, THEN a specific detail or tip, THEN a practical note.\n";
		$prompt .= "- ONLY use facts from the PAGE CONTENT above. NEVER invent features, integrations, pricing, or claims.\n";
		$prompt .= "- If the page does not mention something, do not write it. Period.\n";
		$prompt .= "- Use exact product names, brand names, and terminology from the page.\n";
		$prompt .= "- Mention brand terms ({$brand_terms}) naturally where relevant using semantic triples:\n";
		$prompt .= "  '{$brand_terms} provides/enables/offers [specific feature from the page]'\n";
		$prompt .= "  '{$brand_terms} works with/supports/integrates [thing mentioned on page]'\n";
		$prompt .= "- NEVER start answers with: 'Yes, ...', 'Yes, you can...', 'Yes, [any brand]...', 'No, ...', 'To do X, ...', 'After doing X, ...', 'You need to...', 'You can...', 'The page does not...', 'Sure, ...', 'Of course, ...', 'Absolutely, ...'\n";
		$prompt .= "- NEVER write 'Make sure to follow the documentation' or 'refer to the documentation' or 'check the docs' — that's telling them to go read what they already read.\n";
		$prompt .= "- Start with the insight, the WHY, or a specific fact. Example: 'CSS mix-blend-mode powers the blend cursor, which means transparent backgrounds behave differently. Bump the Circle Z-Index above your header if the effect disappears on scroll.' NOT 'Yes, you can add a blend cursor by going to Settings and selecting...'\n";
		$prompt .= "- Add VALUE beyond what the page says: explain WHY a setting matters, WHEN to use it, WHAT happens if you don't, or a common GOTCHA.\n";
		$prompt .= "- Write like a senior dev answering on Reddit: helpful, opinionated, specific, with real-world context.\n";
		$prompt .= "- No promotional language, no superlatives, no filler phrases. No 'leverage', 'utilize', 'comprehensive', 'robust', 'seamless'.\n";
		$prompt .= "- Reference internal links as markdown links where relevant.\n";
		$prompt .= "- Make each answer quotable: an AI chatbot should be able to cite this answer directly.\n\n";

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
	 * Build FAQPage JSON-LD schema array for a post.
	 *
	 * Returns the raw array (not echoed) so it can be used by the public
	 * REST endpoint for headless WordPress setups. Returns empty array
	 * if no FAQ data exists.
	 *
	 * @param int $post_id Post ID.
	 * @return array FAQPage schema array (empty if no data).
	 */
	public static function build_faq_schema_array( int $post_id ): array {
		$faq_data = self::get_faq_data( $post_id );
		if ( empty( $faq_data ) ) {
			return array();
		}

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
					'text'  => wp_strip_all_tags( self::convert_markdown_links( $a ) ),
				),
			);
		}

		if ( empty( $main_entity ) ) {
			return array();
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $main_entity,
		);
	}

	/**
	 * Post-generation validation — reject FAQ items that violate banned patterns.
	 *
	 * Catches patterns that the LLM ignored despite prompt instructions.
	 * This is the safety net that ensures quality regardless of model compliance.
	 *
	 * @param array $faq_items Array of {question, answer} items.
	 * @return array Filtered array with bad items removed.
	 */
	private static function validate_faq_items( array $faq_items ): array {
		// Banned question patterns (regex).
		$banned_q_patterns = array(
			'/^what happens (if|when|after) (i|you) (don\'?t|do not|complete|finish|click|enable|disable|select|choose)/i',
			'/^what (do i|should i|will i) need (before|to get) (starting|started|begin)/i',
			'/^what are the prerequisites/i',
			'/^is there an alternative (way|method|approach)/i',
			'/^how do i (select|click|choose|pick|enable|disable|toggle) /i',
			'/^what happens after (completing|finishing|doing|following)/i',
		);

		// Banned answer opener patterns (first 50 chars).
		$banned_a_openers = array(
			'/^yes,?\s/i',
			'/^absolutely[,!.\s]/i',
			'/^great question/i',
			'/^sure[,!.\s]/i',
			'/^of course[,!.\s]/i',
			'/^to (select|click|choose|enable|disable|do this|do that|get started)/i',
			'/^after (selecting|clicking|choosing|enabling|completing)/i',
			'/^you (need to|should|must|can) (first |start by |begin by )?/i',
		);

		// Banned answer content patterns (anywhere in answer).
		$banned_a_content = array(
			'/the page does not mention/i',
			'/there is no information about/i',
			'/follow the (steps|instructions|documentation|guide) (provided|outlined|above|below|on the page)/i',
			'/following the (provided|outlined|recommended) (steps|approach|method)/i',
			'/use the recommended approach/i',
			'/\bleverage\b/i',
			'/\butilize\b/i',
			'/\bcomprehensive\b/i',
			'/\brobust\b/i',
			'/it is worth noting/i',
			'/it\'?s important to note/i',
		);

		$validated = array();

		foreach ( $faq_items as $item ) {
			$q = isset( $item['question'] ) ? $item['question'] : '';
			$a = isset( $item['answer'] ) ? $item['answer'] : '';

			if ( empty( $q ) || empty( $a ) ) {
				continue;
			}

			$rejected = false;

			// Check question against banned patterns.
			foreach ( $banned_q_patterns as $pattern ) {
				if ( preg_match( $pattern, $q ) ) {
					$rejected = true;
					break;
				}
			}

			if ( ! $rejected ) {
				// Check answer opener (first 60 chars).
				$a_start = substr( wp_strip_all_tags( $a ), 0, 60 );
				foreach ( $banned_a_openers as $pattern ) {
					if ( preg_match( $pattern, $a_start ) ) {
						$rejected = true;
						break;
					}
				}
			}

			if ( ! $rejected ) {
				// Check full answer content.
				$a_plain = wp_strip_all_tags( $a );
				foreach ( $banned_a_content as $pattern ) {
					if ( preg_match( $pattern, $a_plain ) ) {
						$rejected = true;
						break;
					}
				}
			}

			if ( ! $rejected ) {
				// Reject one-sentence answers (no period except at end = single sentence).
				$a_plain = trim( wp_strip_all_tags( $a ) );
				$sentence_count = preg_match_all( '/[.!?]+/', $a_plain );
				if ( $sentence_count <= 1 && strlen( $a_plain ) < 80 ) {
					$rejected = true;
				}
			}

			if ( ! $rejected ) {
				$validated[] = $item;
			}
		}

		return $validated;
	}

	/**
	 * Convert markdown-style links to HTML anchor tags.
	 *
	 * Handles [anchor text](url) format from OpenAI responses.
	 *
	 * @param string $text Text with possible markdown links.
	 * @return string Text with HTML anchor tags.
	 */
	public static function convert_markdown_links( string $text ): string {
		return preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/',
			function ( $matches ) {
				$anchor = esc_html( $matches[1] );
				$url    = esc_url( $matches[2] );
				return '<a href="' . $url . '">' . $anchor . '</a>';
			},
			$text
		);
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
