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
		// v1.2.0-rc.1 — register at priority 999 so any SEO plugin's robots
		// meta lands first. We then filter and merge our directives into it
		// rather than emit a duplicate tag. (Audit beta.3 #4.)
		add_action( 'wp_head', array( self::class, 'emit_meta_robots' ), 999 );

		// When Yoast / RankMath / AIOSEO have their own robots meta filter,
		// merge into it instead of double-emitting.
		add_filter( 'wpseo_robots_array',  array( self::class, 'merge_into_yoast' ), 20 );
		add_filter( 'rank_math/frontend/robots', array( self::class, 'merge_into_rankmath' ), 20 );
		add_filter( 'aioseo_robots_meta', array( self::class, 'merge_into_aioseo' ), 20 );
	}

	/**
	 * Detect whether another SEO plugin already emitted a robots meta tag
	 * in this request. We do this by checking if their robots filter was
	 * even applied (Yoast/RankMath/AIOSEO/SEOPress) since we've hooked it.
	 *
	 * Tracked via static flag set by our merge_into_* filters.
	 */
	private static $seo_plugin_handled = false;

	public static function merge_into_yoast( array $robots ): array {
		self::$seo_plugin_handled = true;
		if ( self::should_emit_for_current_post() ) {
			$robots['max-snippet']       = 'max-snippet:-1';
			$robots['max-image-preview'] = 'max-image-preview:large';
			$robots['max-video-preview'] = 'max-video-preview:-1';
		}
		return $robots;
	}

	public static function merge_into_rankmath( $robots ) {
		self::$seo_plugin_handled = true;
		if ( ! is_array( $robots ) ) {
			return $robots;
		}
		if ( self::should_emit_for_current_post() ) {
			$robots['max-snippet']       = 'max-snippet:-1';
			$robots['max-image-preview'] = 'max-image-preview:large';
			$robots['max-video-preview'] = 'max-video-preview:-1';
		}
		return $robots;
	}

	public static function merge_into_aioseo( $robots ) {
		self::$seo_plugin_handled = true;
		if ( is_string( $robots ) && self::should_emit_for_current_post() ) {
			$additions = array( 'max-snippet:-1', 'max-image-preview:large', 'max-video-preview:-1' );
			foreach ( $additions as $directive ) {
				if ( false === stripos( $robots, $directive ) ) {
					$robots = rtrim( $robots, ', ' ) . ', ' . $directive;
				}
			}
		}
		return $robots;
	}

	/**
	 * Check current post state without emitting. Used by the merge filters
	 * so we don't duplicate the noindex / per-post / sitewide logic.
	 */
	private static function should_emit_for_current_post(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}
		if ( self::is_noindex( $post->ID ) ) {
			return false;
		}
		$pref = (string) get_post_meta( $post->ID, RR_META_MAX_SNIPPET, true );
		if ( '' === $pref ) {
			$pref = 'on' === get_option( RR_OPT_MAX_SNIPPET_DEFAULT, 'on' ) ? 'on' : 'off';
		}
		return 'on' === $pref;
	}

	/**
	 * Decide and emit the robots meta tag for the current request.
	 *
	 * Runs at priority 1 on wp_head so it appears near the top of <head>
	 * — important because some crawlers stop reading <head> after a fixed
	 * byte budget.
	 */
	public static function emit_meta_robots(): void {
		// v1.2.0-rc.1 — if an SEO plugin's robots filter already ran (which
		// means they emitted a robots meta), we've already merged via the
		// merge_into_* filters. Don't double-emit.
		if ( self::$seo_plugin_handled ) {
			return;
		}

		if ( ! self::should_emit_for_current_post() ) {
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
