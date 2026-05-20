<?php
/**
 * RankReady — AI snippet preview controls.
 *
 * Injects `<meta name="robots" content="max-snippet:-1, max-image-preview:large">`
 * on singular post views so AI engines (ChatGPT, Perplexity, Google AI Overview)
 * can quote the full passage instead of being capped at ~160 characters.
 *
 * Per Zyppy's 23-factor AI citation study, "Preview Control" is the 4th
 * highest ranking factor (9.2/10). Sites that allow unlimited snippets are
 * more likely to be cited verbatim instead of summarised away from the source.
 *
 * Resolution order per post:
 *   1. Per-post meta `_rr_max_snippet` if set ('on' | 'off').
 *   2. Site-wide default `rr_max_snippet_default` (defaults to 'on').
 *
 * No-op when:
 *   - The post is marked noindex by Yoast / RankMath / AIOSEO / SEOPress.
 *   - The user explicitly set 'off' on the post.
 *
 * @package RankReady
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

class RR_Snippet {

	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'emit_meta_robots' ), 1 );
	}

	/**
	 * Decide and emit the robots meta tag for the current request.
	 *
	 * Runs at priority 1 on wp_head so it appears near the top of <head>
	 * — important because some crawlers stop reading <head> after a fixed
	 * byte budget.
	 */
	public static function emit_meta_robots(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		// Bail when the post is already noindex via a major SEO plugin —
		// don't fight their decision, don't emit a conflicting directive.
		if ( self::is_noindex( $post->ID ) ) {
			return;
		}

		$pref = (string) get_post_meta( $post->ID, RR_META_MAX_SNIPPET, true );
		if ( '' === $pref ) {
			$pref = 'on' === get_option( RR_OPT_MAX_SNIPPET_DEFAULT, 'on' ) ? 'on' : 'off';
		}

		if ( 'on' !== $pref ) {
			return;
		}

		echo '<meta name="robots" content="max-snippet:-1, max-image-preview:large, max-video-preview:-1" />' . "\n";
	}

	/**
	 * Returns true when a major SEO plugin has already flagged this post as
	 * noindex. Mirrors RR_Llms_Txt::should_exclude_from_llms() — same logic,
	 * different purpose.
	 */
	private static function is_noindex( int $post_id ): bool {
		if ( defined( 'WPSEO_VERSION' ) && '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}
		if ( defined( 'AIOSEO_VERSION' ) && '1' === (string) get_post_meta( $post_id, '_aioseo_noindex', true ) ) {
			return true;
		}
		if ( defined( 'SEOPRESS_VERSION' ) && 'yes' === (string) get_post_meta( $post_id, '_seopress_robots_index', true ) ) {
			return true;
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_robots = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) ) {
				return true;
			}
		}
		return false;
	}
}
