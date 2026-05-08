<?php
/**
 * Summary generator — triggers on publish/update, async via shutdown + cron fallback.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Generator {

	/** Minimum seconds between API calls for the same post. */
	private const MIN_INTERVAL = 30;

	public static function init(): void {
		add_action( 'wp_after_insert_post', array( self::class, 'schedule_generation' ), 10, 4 );
		add_action( RR_CRON_HOOK, array( self::class, 'run_generation' ) );
	}

	// ── Trigger ───────────────────────────────────────────────────────────────

	/** Guard against re-entrant calls from wp_update_post inside generators. */
	public static $generating = false;

	public static function schedule_generation( $post_id, $post, $update, $post_before ): void {
		$post_id = (int) $post_id;

		// Block re-entrant calls (FAQ generate_faq calls wp_update_post which re-fires this).
		if ( self::$generating ) {
			return;
		}

		// Auto-generate toggle: if off, only generate via manual/bulk actions.
		if ( 'on' !== get_option( RR_OPT_AUTO_GENERATE, 'off' ) ) {
			return;
		}

		// Pro gate — auto-generate on publish is a Pro feature.
		if ( ! ( function_exists( 'rr_is_pro' ) && rr_is_pro() ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// REST API autosave check (Gutenberg)
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? $GLOBALS['wp']->query_vars['rest_route'] : '';
			if ( false !== strpos( $route, '/autosaves' ) ) {
				return;
			}
		}

		// Only run for public post types (skip attachments, nav_menu_item, etc.).
		$public_types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! isset( $public_types[ $post->post_type ] ) || 'attachment' === $post->post_type ) {
			return;
		}

		// Per-post disable
		if ( get_post_meta( $post_id, RR_META_DISABLE, true ) ) {
			return;
		}

		// No API key
		if ( empty( get_option( RR_OPT_KEY ) ) ) {
			return;
		}

		// Hash check: only call API if content changed
		$content  = self::get_content_string( $post );
		$new_hash = md5( $content );
		$old_hash = (string) get_post_meta( $post_id, RR_META_HASH, true );

		if ( $new_hash === $old_hash ) {
			return;
		}

		update_post_meta( $post_id, RR_META_HASH, $new_hash );

		// Use WP-Cron only (single path, no double-fire).
		wp_clear_scheduled_hook( RR_CRON_HOOK, array( $post_id ) );
		wp_schedule_single_event( time() + 5, RR_CRON_HOOK, array( $post_id ) );
		spawn_cron();
	}

	// ── Direct runner (fastcgi path) ──────────────────────────────────────────

	public static function run_generation_direct( $post_id ): void {
		$post_id = (int) $post_id;

		self::$generating = true;

		$last = (int) get_post_meta( $post_id, RR_META_GENERATED, true );
		if ( $last && ( time() - $last ) < self::MIN_INTERVAL ) {
			self::$generating = false;
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			self::$generating = false;
			return;
		}

		$api_key = (string) get_option( RR_OPT_KEY, '' );
		if ( empty( $api_key ) ) {
			self::$generating = false;
			return;
		}

		$content = self::get_content_string( $post );
		$result  = self::call_openai( $content, $post, $api_key );

		if ( $result ) {
			update_post_meta( $post_id, RR_META_SUMMARY,   $result );
			update_post_meta( $post_id, RR_META_GENERATED, time() );
		}

		self::$generating = false;
	}

	// ── WP-Cron runner (fallback) ─────────────────────────────────────────────

	public static function run_generation( $post_id ): void {
		self::$generating = true;

		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			self::$generating = false;
			return;
		}

		// Per-post disable check
		if ( get_post_meta( $post_id, RR_META_DISABLE, true ) ) {
			self::$generating = false;
			return;
		}

		$api_key = (string) get_option( RR_OPT_KEY, '' );
		if ( empty( $api_key ) ) {
			self::$generating = false;
			return;
		}

		$last = (int) get_post_meta( $post_id, RR_META_GENERATED, true );
		if ( $last && ( time() - $last ) < self::MIN_INTERVAL ) {
			self::$generating = false;
			return;
		}

		$content = self::get_content_string( $post );
		$result  = self::call_openai( $content, $post, $api_key );

		if ( $result ) {
			update_post_meta( $post_id, RR_META_SUMMARY,   $result );
			update_post_meta( $post_id, RR_META_GENERATED, time() );
		}

		self::$generating = false;
	}

	// ── Force generation (REST) ───────────────────────────────────────────────

	/**
	 * Force-generate summary for a post.
	 *
	 * @param int  $post_id       Post ID.
	 * @param bool $skip_unchanged When true, skip if content hash matches (token saver).
	 * @return string|false Generated summary or false on failure.
	 */
	public static function force_generate( $post_id, $skip_unchanged = false ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		// ── Free tier limit check (before API call) ───────────────────────────
		if ( ! RR_Limits::can_generate_summary() ) {
			return RR_Limits::summary_limit_error();
		}

		$api_key = (string) get_option( RR_OPT_KEY, '' );
		if ( empty( $api_key ) ) {
			return false;
		}

		$content  = self::get_content_string( $post );
		$new_hash = md5( $content );

		// Skip if content unchanged and summary already exists.
		if ( $skip_unchanged ) {
			$old_hash = (string) get_post_meta( $post_id, RR_META_HASH, true );
			$existing = (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
			if ( $new_hash === $old_hash && ! empty( $existing ) ) {
				return $existing; // Return existing without API call.
			}
		}

		$result = self::call_openai( $content, $post, $api_key );

		if ( $result && ! is_wp_error( $result ) ) {
			update_post_meta( $post_id, RR_META_SUMMARY,   $result );
			update_post_meta( $post_id, RR_META_HASH,      $new_hash );
			update_post_meta( $post_id, RR_META_GENERATED, time() );
			// Record free tier usage after successful API call.
			RR_Limits::record_summary();
		}

		return $result;
	}

	// ── OpenAI call ───────────────────────────────────────────────────────────

	public static function call_openai( $content, $post, $api_key ) {
		$model = (string) get_option( RR_OPT_MODEL, 'gpt-4o-mini' );

		// Use regex-based word count — locale-safe for CJK, Arabic, etc.
		$word_count   = preg_match_all( '/\S+/', $content );
		$word_count   = false !== $word_count ? $word_count : 0;
		$bullet_count = 3;
		if ( $word_count >= 1500 ) {
			$bullet_count = 5;
		} elseif ( $word_count >= 600 ) {
			$bullet_count = 4;
		}

		$content = mb_substr( $content, 0, 12000 );

		$system_prompt  = "You extract key takeaways from blog posts. You produce factual, entity-rich bullet points.\n\n";
		$system_prompt .= "ABSOLUTE RULES:\n";
		$system_prompt .= "- You may ONLY state facts that appear word-for-word or are directly implied by the blog post text below.\n";
		$system_prompt .= "- NEVER add features, tools, integrations, platforms, pricing, or claims the post does not mention.\n";
		$system_prompt .= "- NEVER say a product works with a platform unless the post explicitly says so.\n";
		$system_prompt .= "- If you are unsure whether something is true, leave it out.\n";
		$system_prompt .= "- Use the exact product names, brand names, and version numbers from the post. No renaming, no generalizing.\n";
		$system_prompt .= "- No em dashes. No filler words (certainly, indeed, comprehensive, robust, leverage, utilize). No promotional language.";

		// Inject product context if set (shared across summary + FAQ).
		$product_context = (string) get_option( RR_OPT_PRODUCT_CONTEXT, '' );
		if ( ! empty( $product_context ) ) {
			$system_prompt .= "\n\nPRODUCT CONTEXT (use this as a fact-check reference — never contradict this, never add details beyond this):\n" . $product_context;
		}

		// Append custom prompt if set.
		$custom_prompt = (string) get_option( RR_OPT_CUSTOM_PROMPT, '' );
		if ( ! empty( $custom_prompt ) ) {
			$system_prompt .= "\n\nAdditional instructions:\n" . $custom_prompt;
		}

		$user_prompt = sprintf(
			'Extract exactly %d key takeaways from the blog post below.

Each takeaway must:
- Be one specific, valuable insight a reader would remember
- Start with a named entity (product name, feature name, tool name) or a strong action verb
- Contain at least one specific detail: a name, number, feature, or outcome
- Be factually accurate to the blog post — zero invention
- Be written in present tense, third person
- Be scannable in under 3 seconds

Do NOT:
- Start with "You can", "This post", "Learn how", "Find out", "Discover"
- Include vague takeaways like "improves performance" without specifics
- Mention any tool, platform, or integration the post does not explicitly discuss
- Repeat the same point in different words

Return ONLY valid JSON: {"bullets":["Takeaway 1.","Takeaway 2.","Takeaway 3."]}

Blog Post:
%s',
			$bullet_count,
			$content
		);

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout'    => 25,
			'user-agent' => 'RankReady/' . RR_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'    => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'             => $model,
				'messages'          => array(
					array( 'role' => 'system', 'content' => $system_prompt ),
					array( 'role' => 'user',   'content' => $user_prompt ),
				),
				'max_tokens'        => 500,
				'temperature'       => 0.2,
				'frequency_penalty' => 0.3,
				'response_format'   => array( 'type' => 'json_object' ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			self::log_error( 'OpenAI', $response->get_error_message(), $post->ID );
			return false;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_code ) {
			self::log_error( 'OpenAI', 'HTTP ' . $http_code . ': ' . wp_remote_retrieve_body( $response ), $post->ID );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$raw  = isset( $body['choices'][0]['message']['content'] ) ? trim( $body['choices'][0]['message']['content'] ) : '';

		// Track token usage.
		if ( isset( $body['usage']['total_tokens'] ) ) {
			self::track_tokens( (int) $body['usage']['total_tokens'], $post->ID, 'summary' );
		}

		if ( empty( $raw ) ) {
			return false;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['bullets'] ) || ! is_array( $decoded['bullets'] ) ) {
			self::log_error( 'OpenAI', 'Unexpected JSON structure: ' . mb_substr( $raw, 0, 200 ), $post->ID );
			return false;
		}

		$decoded['bullets'] = array_values(
			array_filter(
				array_map( 'sanitize_text_field', $decoded['bullets'] )
			)
		);

		return wp_json_encode( $decoded );
	}

	// ── Connection test ───────────────────────────────────────────────────────

	public static function test_api_connection() {
		$api_key = (string) get_option( RR_OPT_KEY, '' );
		if ( empty( $api_key ) ) {
			return __( 'No API key configured.', 'rankready' );
		}

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'      => 'gpt-4o-mini',
				'messages'   => array( array( 'role' => 'user', 'content' => 'Reply with OK only.' ) ),
				'max_tokens' => 5,
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return true;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function get_content_string( $post ): string {
		$body = wp_strip_all_tags( do_shortcode( $post->post_content ) );
		return $post->post_title . "\n\n" . $body;
	}

	public static function decode_summary( $raw ): array {
		if ( empty( $raw ) ) {
			return array( 'type' => 'empty', 'data' => array() );
		}

		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && ! empty( $decoded['bullets'] ) ) {
			return array( 'type' => 'bullets', 'data' => $decoded['bullets'] );
		}

		return array( 'type' => 'text', 'data' => $raw );
	}

	// ── Error logging ────────────────────────────────────────────────────────

	public static function log_error( string $source, string $message, int $post_id = 0 ): void {
		$log = (array) get_option( 'rr_error_log', array() );

		$log[] = array(
			'time'    => time(),
			'source'  => $source,
			'message' => mb_substr( $message, 0, 500 ),
			'post_id' => $post_id,
		);

		// Keep last 50 entries.
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, -50 );
		}

		update_option( 'rr_error_log', $log, false );
	}

	public static function get_error_log(): array {
		return (array) get_option( 'rr_error_log', array() );
	}

	public static function clear_error_log(): void {
		delete_option( 'rr_error_log' );
	}

	// ── Token tracking ───────────────────────────────────────────────────────

	public static function track_tokens( int $tokens, int $post_id, string $type ): void {
		// Per-post tracking.
		$meta_key = '_rr_tokens_used';
		$current  = (int) get_post_meta( $post_id, $meta_key, true );
		update_post_meta( $post_id, $meta_key, $current + $tokens );

		// Global cumulative tracking.
		$totals = (array) get_option( 'rr_token_usage', array(
			'summary_tokens' => 0,
			'faq_tokens'     => 0,
			'total_calls'    => 0,
		) );

		if ( 'summary' === $type ) {
			$totals['summary_tokens'] = ( isset( $totals['summary_tokens'] ) ? (int) $totals['summary_tokens'] : 0 ) + $tokens;
		} else {
			$totals['faq_tokens'] = ( isset( $totals['faq_tokens'] ) ? (int) $totals['faq_tokens'] : 0 ) + $tokens;
		}
		$totals['total_calls'] = ( isset( $totals['total_calls'] ) ? (int) $totals['total_calls'] : 0 ) + 1;

		update_option( 'rr_token_usage', $totals, false );
	}
}
