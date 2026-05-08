<?php
/**
 * RankReady Free Tier Monthly Limits
 *
 * Tracks and enforces calendar-month usage limits for free-tier AI features.
 * Limits reset on the 1st of each calendar month.
 *
 * Free limits:
 *   - AI Summaries:  RR_FREE_SUMMARY_LIMIT (5/month)
 *   - FAQ Generator: RR_FREE_FAQ_LIMIT     (5/month)
 *
 * When Pro license is active (rr_is_pro()), all limits are bypassed.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Limits {

	// ── Option key helpers ─────────────────────────────────────────────────

	/**
	 * Returns the wp_options key for the given feature and current calendar month.
	 * e.g. rr_usage_summary_2026_04
	 *
	 * @param string $feature 'summary' | 'faq'
	 * @return string
	 */
	private static function option_key( string $feature ): string {
		return 'rr_usage_' . $feature . '_' . gmdate( 'Y_m' );
	}

	// ── Usage getters ──────────────────────────────────────────────────────

	/**
	 * How many AI summaries have been generated this calendar month.
	 *
	 * @return int
	 */
	public static function summary_used(): int {
		return (int) get_option( self::option_key( 'summary' ), 0 );
	}

	/**
	 * How many FAQ sets have been generated this calendar month.
	 *
	 * @return int
	 */
	public static function faq_used(): int {
		return (int) get_option( self::option_key( 'faq' ), 0 );
	}

	// ── Remaining helpers ──────────────────────────────────────────────────

	/**
	 * AI summaries remaining this month.
	 *
	 * @return int  0 = limit reached.
	 */
	public static function summary_remaining(): int {
		if ( self::is_pro() ) {
			return PHP_INT_MAX; // unlimited
		}
		return max( 0, RR_FREE_SUMMARY_LIMIT - self::summary_used() );
	}

	/**
	 * FAQ generations remaining this month.
	 *
	 * @return int  0 = limit reached.
	 */
	public static function faq_remaining(): int {
		if ( self::is_pro() ) {
			return PHP_INT_MAX; // unlimited
		}
		return max( 0, RR_FREE_FAQ_LIMIT - self::faq_used() );
	}

	// ── Limit checks (call BEFORE making API request) ──────────────────────

	/**
	 * Returns true if the site can generate another AI summary this month.
	 *
	 * @return bool
	 */
	public static function can_generate_summary(): bool {
		if ( self::is_pro() ) {
			return true;
		}
		return self::summary_used() < RR_FREE_SUMMARY_LIMIT;
	}

	/**
	 * Returns true if the site can generate another FAQ this month.
	 *
	 * @return bool
	 */
	public static function can_generate_faq(): bool {
		if ( self::is_pro() ) {
			return true;
		}
		return self::faq_used() < RR_FREE_FAQ_LIMIT;
	}

	// ── Usage incrementors (call AFTER successful API response) ───────────

	/**
	 * Record one AI summary generation.
	 *
	 * @return void
	 */
	public static function record_summary(): void {
		$key     = self::option_key( 'summary' );
		$current = (int) get_option( $key, 0 );
		update_option( $key, $current + 1, false ); // autoload = false, not needed on every page
	}

	/**
	 * Record one FAQ generation.
	 *
	 * @return void
	 */
	public static function record_faq(): void {
		$key     = self::option_key( 'faq' );
		$current = (int) get_option( $key, 0 );
		update_option( $key, $current + 1, false );
	}

	// ── WP_Error factory (use in generator/FAQ when limit hit) ────────────

	/**
	 * Returns a WP_Error for a blocked summary generation.
	 * Copy is loss-aversion framed (see marketing psychology brief).
	 *
	 * @return WP_Error
	 */
	public static function summary_limit_error(): \WP_Error {
		$unpublished = self::count_posts_without_summary();
		$message     = sprintf(
			/* translators: 1: free limit, 2: posts without summary count */
			__(
				'You\'ve used all %1$d free AI summaries this month. %2$d posts on your site don\'t have AI summaries yet. Limit resets on the 1st of next month. Unlimited generation is coming in RankReady Pro.',
				'rankready'
			),
			RR_FREE_SUMMARY_LIMIT,
			$unpublished
		);
		return new \WP_Error( 'rr_limit_summary', $message );
	}

	/**
	 * Returns a WP_Error for a blocked FAQ generation.
	 *
	 * @return WP_Error
	 */
	public static function faq_limit_error(): \WP_Error {
		$unpublished = self::count_posts_without_faq();
		$message     = sprintf(
			/* translators: 1: free limit, 2: posts without FAQ count */
			__(
				'You\'ve used all %1$d free FAQ generations this month. %2$d posts on your site have no FAQ schema yet. Limit resets on the 1st of next month. Unlimited generation is coming in RankReady Pro.',
				'rankready'
			),
			RR_FREE_FAQ_LIMIT,
			$unpublished
		);
		return new \WP_Error( 'rr_limit_faq', $message );
	}

	// ── Post-win upsell notice data (Peak-End Rule) ────────────────────────

	/**
	 * Returns upsell notice HTML to show AFTER a successful summary generation.
	 * Triggered at peak positive moment — highest conversion.
	 *
	 * @return string  Safe HTML string (already escaped).
	 */
	public static function post_win_summary_notice(): string {
		if ( self::is_pro() ) {
			return '';
		}
		$remaining = self::summary_remaining();
		if ( $remaining > 3 ) {
			return ''; // Don't show on first use — let them enjoy the win clean.
		}
		$message = sprintf(
			/* translators: 1: remaining count, 2: word form */
			__(
				'✅ AI Summary saved. You have <strong>%1$d free %2$s left this month</strong>. Auto-generate on every publish is coming in RankReady Pro.',
				'rankready'
			),
			$remaining,
			( 1 === $remaining ) ? __( 'summary', 'rankready' ) : __( 'summaries', 'rankready' )
		);
		return '<div class="rr-notice rr-notice--upsell rr-notice--dismissible">' . wp_kses_post( $message ) . '</div>';
	}

	/**
	 * Returns upsell notice HTML to show AFTER a successful FAQ generation.
	 *
	 * @return string
	 */
	public static function post_win_faq_notice(): string {
		if ( self::is_pro() ) {
			return '';
		}
		$remaining = self::faq_remaining();
		if ( $remaining > 3 ) {
			return '';
		}
		$message = sprintf(
			/* translators: 1: remaining count, 2: word form */
			__(
				'✅ FAQ generated. You have <strong>%1$d free FAQ %2$s left this month</strong>. Auto-generate on every publish is coming in RankReady Pro.',
				'rankready'
			),
			$remaining,
			( 1 === $remaining ) ? __( 'generation', 'rankready' ) : __( 'generations', 'rankready' )
		);
		return '<div class="rr-notice rr-notice--upsell rr-notice--dismissible">' . wp_kses_post( $message ) . '</div>';
	}

	// ── Usage stats for admin UI display ──────────────────────────────────

	/**
	 * Returns usage stats array for display in admin UI.
	 *
	 * @return array{
	 *   summary_used: int,
	 *   summary_limit: int,
	 *   summary_remaining: int,
	 *   faq_used: int,
	 *   faq_limit: int,
	 *   faq_remaining: int,
	 *   is_pro: bool,
	 *   reset_date: string
	 * }
	 */
	public static function get_stats(): array {
		$is_pro     = self::is_pro();
		$next_month = mktime( 0, 0, 0, (int) gmdate( 'n' ) + 1, 1 );
		return array(
			'summary_used'      => self::summary_used(),
			'summary_limit'     => $is_pro ? -1 : RR_FREE_SUMMARY_LIMIT,
			'summary_remaining' => self::summary_remaining(),
			'faq_used'          => self::faq_used(),
			'faq_limit'         => $is_pro ? -1 : RR_FREE_FAQ_LIMIT,
			'faq_remaining'     => self::faq_remaining(),
			'is_pro'            => $is_pro,
			'reset_date'        => gmdate( 'M j', $next_month ), // e.g. "May 1"
		);
	}

	// ── REST API data for admin JS ─────────────────────────────────────────

	/**
	 * Registers REST endpoint for usage stats (used by admin.js).
	 *
	 * @return void
	 */
	public static function register_rest(): void {
		register_rest_route(
			'rankready/v1',
			'/limits',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_limits' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * REST callback: return usage stats as JSON.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_limits(): \WP_REST_Response {
		return rest_ensure_response( self::get_stats() );
	}

	// ── Private helpers ────────────────────────────────────────────────────

	/**
	 * Proxy for rr_is_pro() — stub for when Pro license class isn't loaded yet.
	 *
	 * @return bool
	 */
	private static function is_pro(): bool {
		return function_exists( 'rr_is_pro' ) && rr_is_pro();
	}

	/**
	 * Count published posts that have no RankReady AI summary meta.
	 * Used in loss-aversion upsell copy.
	 *
	 * @return int
	 */
	private static function count_posts_without_summary(): int {
		global $wpdb;
		$post_types = get_option( RR_OPT_POST_TYPES, array( 'post' ) );
		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			$post_types = array( 'post' );
		}
		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID)
			 FROM {$wpdb->posts} p
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ({$placeholders})
			   AND NOT EXISTS (
			       SELECT 1 FROM {$wpdb->postmeta} pm
			       WHERE pm.post_id = p.ID
			         AND pm.meta_key = '_rr_summary'
			         AND pm.meta_value != ''
			   )",
			...$post_types
		) );
		return (int) $count;
	}

	/**
	 * Count published posts that have no RankReady FAQ meta.
	 *
	 * @return int
	 */
	private static function count_posts_without_faq(): int {
		global $wpdb;
		$post_types = get_option( RR_OPT_POST_TYPES, array( 'post' ) );
		if ( empty( $post_types ) || ! is_array( $post_types ) ) {
			$post_types = array( 'post' );
		}
		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID)
			 FROM {$wpdb->posts} p
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ({$placeholders})
			   AND NOT EXISTS (
			       SELECT 1 FROM {$wpdb->postmeta} pm
			       WHERE pm.post_id = p.ID
			         AND pm.meta_key = '_rr_faq_items'
			         AND pm.meta_value != ''
			   )",
			...$post_types
		) );
		return (int) $count;
	}
}
