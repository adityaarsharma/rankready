<?php
/**
 * RankReady — Unified "Agent Visibility" dashboard widget.
 *
 * One WP dashboard widget that combines:
 *   - AI Referral Traffic (last 30 days, 5 sources)
 *   - Content Freshness (3-tab bucket counts)
 *
 * Replaces the two separate widgets shipped in v1.2.0-beta.1.
 *
 * Why one widget: WP admin dashboards are already crowded. Two RankReady
 * widgets fight for screen real estate and split the user's attention. One
 * widget = one mental model: "Agent Visibility — is my site reaching AI?".
 *
 * The widget delegates rendering to the per-feature classes (RNRD_AI_Referral,
 * RNRD_Freshness) so the data, REST endpoints, and copy stay in those classes
 * — this orchestrator only owns the layout shell.
 *
 * @package RankReady
 * @since   1.2.0-beta.2
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Agent_Dashboard {

	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'register_widget' ) );
	}

	public static function register_widget(): void {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'rnrd_agent_dashboard',
			__( 'RankReady — Agent Visibility', 'rankready-ai-llm-seo' ),
			array( self::class, 'render' )
		);
	}

	/**
	 * Compact Insights summary — one key number per Insights sub-tab, each
	 * linking to its full breakdown. Deliberately a SUMMARY (single headline
	 * figure each), not the detailed tables — those live on the Insights tab.
	 */
	public static function render(): void {
		$stale = 0;
		if ( class_exists( 'RNRD_Freshness' ) && method_exists( 'RNRD_Freshness', 'bucket_counts' ) ) {
			$buckets = RNRD_Freshness::bucket_counts();
			$stale   = (int) ( $buckets['stale'] ?? 0 );
		}

		$base = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=insights' );

		$stats = array(
			array(
				'label' => __( 'Training bots', 'rankready-ai-llm-seo' ),
				'value' => self::sum_bot_intent( 'training' ),
				'sub'   => __( 'crawling you for AI training (30d)', 'rankready-ai-llm-seo' ),
				'url'   => $base . '&sub=bot-activity',
			),
			array(
				'label' => __( 'Citation bots', 'rankready-ai-llm-seo' ),
				'value' => self::sum_bot_intent( 'citation' ),
				'sub'   => __( 'reading you live to answer questions (30d)', 'rankready-ai-llm-seo' ),
				'url'   => $base . '&sub=citation',
			),
			array(
				'label' => __( 'Real AI referrals', 'rankready-ai-llm-seo' ),
				'value' => class_exists( 'RNRD_AI_Referral' ) ? RNRD_AI_Referral::total_last_n_days( 30 ) : 0,
				'sub'   => __( 'visitors from ChatGPT, Perplexity, Claude (30d)', 'rankready-ai-llm-seo' ),
				'url'   => $base . '&sub=referral',
			),
			array(
				'label' => __( 'Content going stale', 'rankready-ai-llm-seo' ),
				'value' => $stale,
				'sub'   => __( 'posts 60+ days old, losing AI citations', 'rankready-ai-llm-seo' ),
				'url'   => $base . '&sub=freshness',
			),
		);

		// Real Agent Visibility coverage — the SAME 10 signals as the Agent
		// Visibility card on the Dashboard tab, so the two always match. Never a
		// hardcoded 100%. RNRD_Admin is already loaded in admin context.
		$score   = ( class_exists( 'RNRD_Admin' ) && method_exists( 'RNRD_Admin', 'agent_visibility_score' ) )
			? RNRD_Admin::agent_visibility_score()
			: array( 'active' => 0, 'total' => 0, 'pct' => 0 );
		$pct     = (int) $score['pct'];
		$is_full = ( $pct >= 100 );
		// "View details" leads to the AI Crawlers tab — that's where the 10 agent
		// visibility signals (llms.txt, .md routes, robots AI rules, WebMCP, etc.)
		// are actually toggled, so the user lands where they can act on the score.
		$dash    = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=crawlers' );
		?>
		<div class="rnrd-agent-dashboard">
			<a href="<?php echo esc_url( $dash ); ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;padding:12px 14px;border-radius:8px;margin:0 0 12px;background:<?php echo $is_full ? '#e6f8f0' : '#f0f6fc'; ?>;border:1px solid <?php echo $is_full ? '#9be3c6' : '#c8def5'; ?>;">
				<span style="flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:50%;background:<?php echo $is_full ? '#0F9C70' : '#2271b1'; ?>;color:#fff;font-weight:700;font-size:<?php echo $is_full ? '22px' : '13px'; ?>;line-height:1;">
					<?php echo $is_full ? '&#10003;' : esc_html( $pct . '%' ); ?>
				</span>
				<span style="min-width:0;">
					<span style="display:block;font-size:14px;font-weight:600;color:#1d2327;line-height:1.3;">
						<?php
						/* translators: %d: agent-optimisation percentage */
						echo esc_html( sprintf( __( 'Your site is %d%% agent-optimised', 'rankready-ai-llm-seo' ), $pct ) );
						?>
					</span>
					<span style="display:block;font-size:12px;color:#646970;line-height:1.4;">
						<?php
						echo esc_html( sprintf(
							/* translators: 1: active signal count, 2: total signal count */
							__( '%1$d of %2$d agent signals active — view details →', 'rankready-ai-llm-seo' ),
							(int) $score['active'],
							(int) $score['total']
						) );
						?>
					</span>
				</span>
			</a>
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
				<?php foreach ( $stats as $s ) : ?>
					<a href="<?php echo esc_url( $s['url'] ); ?>" style="display:block;text-decoration:none;color:inherit;background:#fff;border:1px solid #e2e4e7;border-radius:8px;padding:12px 14px;">
						<span style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#646970;"><?php echo esc_html( $s['label'] ); ?></span>
						<span style="display:block;font-size:26px;font-weight:700;line-height:1.15;color:#1d2327;margin:3px 0 2px;"><?php echo esc_html( number_format_i18n( (int) $s['value'] ) ); ?></span>
						<span style="display:block;font-size:11px;color:#787c82;line-height:1.4;"><?php echo esc_html( $s['sub'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<p style="margin:12px 0 0;font-size:13px;">
				<a href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'View full Insights →', 'rankready-ai-llm-seo' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Sum the last-30-day hit totals for every logged bot whose intent matches
	 * $intent ('training' | 'citation'). Returns 0 when the crawler log is
	 * unavailable.
	 */
	private static function sum_bot_intent( string $intent ): int {
		if ( ! class_exists( 'RNRD_Crawler_Log' ) ) {
			return 0;
		}
		$sum = 0;
		foreach ( (array) RNRD_Crawler_Log::get_bot_stats( 30 ) as $row ) {
			$bot = isset( $row['bot_name'] ) ? (string) $row['bot_name'] : '';
			if ( '' !== $bot && RNRD_Crawler_Log::bot_intent( $bot ) === $intent ) {
				$sum += isset( $row['total'] ) ? (int) $row['total'] : 0;
			}
		}
		return $sum;
	}
}
