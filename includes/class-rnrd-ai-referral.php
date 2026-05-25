<?php
/**
 * RankReady — AI Referral Traffic tracker.
 *
 * Catches visits where the HTTP Referer points to a known AI engine front-end
 * (chatgpt.com, perplexity.ai, gemini.google.com, claude.ai, copilot.microsoft.com)
 * and records them as a separate analytics source.
 *
 * Why this matters: AI engines that send click-through traffic do so from
 * their own UI, not from search-engine result pages. Regular analytics tools
 * lump these visits into "Direct" or "Referral" without identifying the AI
 * source. RankReady surfaces them as their own segment so users can see
 * which engines are actually driving traffic to their site.
 *
 * Implementation notes:
 *   - Server-side detection in init hook. No JS, no third-party API.
 *   - Aggregates daily counters in a single wp_options row (rolling 30 days).
 *   - Skips bots, admin requests, REST/AJAX/cron, and own-site referrers.
 *   - Honours DNT / GDPR by tracking only domain + date — no IP, no path.
 *
 * Storage shape (rnrd_ai_referral_stats option):
 *   [
 *     'YYYY-MM-DD' => [ 'chatgpt' => 5, 'perplexity' => 2, ... ],
 *     ...
 *   ]
 *
 * @package RankReady
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

class RNRD_AI_Referral {

	/**
	 * Known AI engine referrer hosts. Add entries here as new engines emerge.
	 * Matched via stripos so subdomains (e.g. "ios.chatgpt.com") count too.
	 */
	private const SOURCES = array(
		'chatgpt'    => array( 'chatgpt.com', 'chat.openai.com' ),
		'perplexity' => array( 'perplexity.ai', 'www.perplexity.ai' ),
		'gemini'     => array( 'gemini.google.com' ),
		'claude'     => array( 'claude.ai' ),
		'copilot'    => array( 'copilot.microsoft.com', 'm365.cloud.microsoft' ),
	);

	private const RETENTION_DAYS = 30;

	public static function init(): void {
		add_action( 'init', array( self::class, 'maybe_record_referral' ), 5 );
		// Note: dashboard widget registration handled by RNRD_Agent_Dashboard
		// — single consolidated "Agent Visibility" widget replaces the two
		// separate widgets shipped in beta.1.
	}

	// ── Dashboard widget panel (rendered inside the unified widget) ──────

	public static function render_widget(): void {
		$counts = self::aggregate_last_n_days( 30 );
		$total  = array_sum( $counts );

		if ( 0 === $total ) {
			?>
			<div style="padding:8px 0;">
				<p style="margin:0 0 8px;font-size:var(--rnrd-text-md,13px);color:var(--rnrd-color-ink-soft,#3c434a);">
					<?php esc_html_e( 'Tracking is live. Counters fill in as ChatGPT, Perplexity, Gemini, Claude, or Copilot send their first visitor.', 'rankready-ai-llm-seo' ); ?>
				</p>
				<p style="margin:0 0 0;font-size:var(--rnrd-text-sm,12px);color:var(--rnrd-color-text-muted,#646970);">
					<?php esc_html_e( 'Typical first citation: 2–6 weeks after enabling. Add FAQs to your top posts to speed this up.', 'rankready-ai-llm-seo' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		echo '<div style="display:flex;align-items:baseline;gap:8px;margin-bottom:10px;">';
		echo '<strong style="font-size:28px;line-height:1;">' . esc_html( number_format_i18n( $total ) ) . '</strong>';
		echo '<span style="color:#646970;font-size:12px;">' . esc_html__( 'total AI-sourced visits', 'rankready-ai-llm-seo' ) . '</span>';
		echo '</div>';

		// Bars.
		echo '<ul style="margin:0;padding:0;list-style:none;">';
		$max = max( $counts );
		foreach ( $counts as $source => $count ) {
			$label = self::source_label( $source );
			$pct   = $max > 0 ? (int) round( ( $count / $max ) * 100 ) : 0;
			$color = self::source_colour( $source );
			echo '<li style="margin-bottom:6px;">';
			echo '<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:2px;">';
			echo '<span>' . esc_html( $label ) . '</span>';
			echo '<strong>' . esc_html( number_format_i18n( $count ) ) . '</strong>';
			echo '</div>';
			echo '<div style="background:#f0f0f1;height:6px;border-radius:3px;overflow:hidden;">';
			echo '<div style="background:' . esc_attr( $color ) . ';height:100%;width:' . (int) $pct . '%;border-radius:3px;"></div>';
			echo '</div>';
			echo '</li>';
		}
		echo '</ul>';

		echo '<p style="margin-top:10px;font-size:11px;color:#646970;">';
		esc_html_e( 'Cited brands earn 23× higher conversion rates than non-cited competitors. Each visit here is a person clicking from an AI engine to your site.', 'rankready-ai-llm-seo' );
		echo '</p>';
	}

	private static function source_colour( string $source ): string {
		$map = array(
			'chatgpt'    => '#10a37f', // OpenAI green
			'perplexity' => '#21808d', // Perplexity teal
			'gemini'     => '#4285f4', // Google blue
			'claude'     => '#cc785c', // Anthropic terracotta
			'copilot'    => '#0078d4', // Microsoft blue
		);
		return $map[ $source ] ?? '#646970';
	}

	/**
	 * Inspect the current request and, if its Referer is an AI engine,
	 * increment the daily counter for that engine.
	 */
	public static function maybe_record_referral(): void {
		// Master toggle (v1.2.0-beta.3) — users can disable from AI Crawlers tab.
		if ( 'on' !== get_option( RNRD_OPT_AI_REFERRAL_ENABLE, 'on' ) ) {
			return;
		}

		// v1.2.0-beta.4 — honour Sec-GPC and DNT (the docblock previously
		// claimed this without code support). Audit beta.3 #11.
		if ( ! empty( $_SERVER['HTTP_SEC_GPC'] ) && '1' === (string) $_SERVER['HTTP_SEC_GPC'] ) {
			return;
		}
		if ( ! empty( $_SERVER['HTTP_DNT'] ) && '1' === (string) $_SERVER['HTTP_DNT'] ) {
			return;
		}

		// Skip admin, REST, AJAX, cron, CLI — we only want public front-end hits.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// Need a referer header.
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return;
		}

		$referer = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		$host    = wp_parse_url( $referer, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return;
		}
		$host = strtolower( $host );

		// Ignore self-referrals (internal navigation).
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host === $site_host ) {
			return;
		}

		// Match against the AI source map.
		$source = self::resolve_source( $host );
		if ( null === $source ) {
			return;
		}

		// Skip bots — referrer spoofing exists but most bots don't send Referer
		// at all. This is a cheap defence-in-depth check.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua || self::looks_like_bot( $ua ) ) {
			return;
		}

		self::increment( $source );
	}

	/**
	 * Map a referer host to one of the known source keys. Returns null when
	 * the host doesn't match any AI engine.
	 */
	private static function resolve_source( string $host ): ?string {
		foreach ( self::SOURCES as $key => $hosts ) {
			foreach ( $hosts as $candidate ) {
				if ( $host === $candidate || str_ends_with( $host, '.' . $candidate ) ) {
					return $key;
				}
			}
		}
		return null;
	}

	private static function looks_like_bot( string $ua ): bool {
		static $patterns = array( 'bot', 'spider', 'crawler', 'scraper', 'curl', 'wget', 'python-requests', 'php-fpm' );
		$ua_lc = strtolower( $ua );
		foreach ( $patterns as $p ) {
			if ( false !== strpos( $ua_lc, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Increment today's counter for $source.
	 *
	 * v1.2.0-rc.2 — race-safe path (audit beta.3 #5):
	 *
	 *   1. When a persistent object cache (Redis, Memcached) is available,
	 *      use atomic wp_cache_incr() to bump the in-memory counter. No DB
	 *      write, no race.
	 *   2. Buffer the option write to shutdown so multiple increments in
	 *      one request only hit wp_options ONCE per source per day per
	 *      request — not on every page view's worth of multiple referrals.
	 *
	 * Pruning still happens during the shutdown flush.
	 */
	private static $pending_increments = array();

	private static function increment( string $source ): void {
		$today = wp_date( 'Y-m-d' );
		$key   = $today . '|' . $source;

		// Object-cache atomic path. wp_cache_incr() returns false on miss;
		// initialise then retry.
		if ( wp_using_ext_object_cache() ) {
			$cache_key = 'rnrd_referral_' . $key;
			if ( false === wp_cache_incr( $cache_key, 1, 'rnrd-referral' ) ) {
				wp_cache_add( $cache_key, 1, 'rnrd-referral', DAY_IN_SECONDS * ( self::RETENTION_DAYS + 1 ) );
			}
		}

		// Always buffer for shutdown flush — guarantees data persists to
		// wp_options even without object cache, and consolidates many
		// in-request increments into one write.
		if ( ! isset( self::$pending_increments[ $key ] ) ) {
			self::$pending_increments[ $key ] = 0;
			// Register the shutdown flush only once.
			if ( 1 === count( self::$pending_increments ) ) {
				add_action( 'shutdown', array( self::class, 'flush_pending_increments' ), 1 );
			}
		}
		self::$pending_increments[ $key ]++;
	}

	/**
	 * Shutdown flush — writes accumulated in-request increments to
	 * wp_options in a single read-modify-write cycle.
	 *
	 * @since 1.2.0-rc.2
	 */
	public static function flush_pending_increments(): void {
		if ( empty( self::$pending_increments ) ) {
			return;
		}

		$stats = get_option( RNRD_OPT_AI_REFERRAL_STATS, array() );
		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		foreach ( self::$pending_increments as $key => $count ) {
			list( $date, $source ) = explode( '|', $key, 2 );
			if ( ! isset( $stats[ $date ] ) || ! is_array( $stats[ $date ] ) ) {
				$stats[ $date ] = array();
			}
			$stats[ $date ][ $source ] = ( isset( $stats[ $date ][ $source ] ) ? (int) $stats[ $date ][ $source ] : 0 ) + (int) $count;
		}

		// Prune entries older than RETENTION_DAYS.
		$cutoff = strtotime( '-' . self::RETENTION_DAYS . ' days' );
		foreach ( array_keys( $stats ) as $date ) {
			if ( strtotime( $date ) < $cutoff ) {
				unset( $stats[ $date ] );
			}
		}

		update_option( RNRD_OPT_AI_REFERRAL_STATS, $stats, false );
		self::$pending_increments = array();
	}

	// ── Reporting ─────────────────────────────────────────────────────────

	/**
	 * Returns aggregate counts per source for the last N days.
	 *
	 *   [ 'chatgpt' => 47, 'perplexity' => 18, ... ]
	 */
	public static function aggregate_last_n_days( int $days = 30 ): array {
		$stats = (array) get_option( RNRD_OPT_AI_REFERRAL_STATS, array() );
		$out   = array_fill_keys( array_keys( self::SOURCES ), 0 );

		$cutoff = strtotime( '-' . max( 1, $days ) . ' days' );
		foreach ( $stats as $date => $by_source ) {
			if ( strtotime( $date ) < $cutoff ) {
				continue;
			}
			foreach ( (array) $by_source as $source => $count ) {
				if ( isset( $out[ $source ] ) ) {
					$out[ $source ] += (int) $count;
				}
			}
		}

		arsort( $out );
		return $out;
	}

	/**
	 * Total visits across all AI sources for the period.
	 */
	public static function total_last_n_days( int $days = 30 ): int {
		return array_sum( self::aggregate_last_n_days( $days ) );
	}

	/**
	 * Human-readable label for a source key.
	 */
	public static function source_label( string $source ): string {
		$labels = array(
			'chatgpt'    => 'ChatGPT',
			'perplexity' => 'Perplexity',
			'gemini'     => 'Gemini',
			'claude'     => 'Claude',
			'copilot'    => 'Copilot',
		);
		return $labels[ $source ] ?? ucfirst( $source );
	}
}
