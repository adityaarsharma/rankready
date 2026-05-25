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

	public static function render(): void {
		?>
		<div class="rnrd-agent-dashboard">
			<style>
				.rnrd-agent-dashboard { font-size: var(--rnrd-text-md); }
				.rnrd-agent-dashboard__grid {
					display: grid;
					grid-template-columns: 1fr;
					gap: var(--rnrd-space-lg);
				}
				@media (min-width: 760px) {
					.rnrd-agent-dashboard__grid { grid-template-columns: 1fr 1fr; }
				}
				.rnrd-agent-dashboard__panel {
					min-width: 0;
				}
				.rnrd-agent-dashboard__panel + .rnrd-agent-dashboard__panel {
					border-top: 1px solid var(--rnrd-color-border-soft);
					padding-top: var(--rnrd-space-lg);
				}
				@media (min-width: 760px) {
					.rnrd-agent-dashboard__panel + .rnrd-agent-dashboard__panel {
						border-top: 0;
						border-left: 1px solid var(--rnrd-color-border-soft);
						padding-top: 0;
						padding-left: var(--rnrd-space-lg);
					}
				}
				.rnrd-agent-dashboard__panel-title {
					margin: 0 0 var(--rnrd-space-md);
					font-size: var(--rnrd-text-xs);
					font-weight: var(--rnrd-weight-semibold);
					text-transform: uppercase;
					letter-spacing: 0.04em;
					color: var(--rnrd-color-text-muted);
				}
			</style>

			<div class="rnrd-agent-dashboard__grid">
				<div class="rnrd-agent-dashboard__panel">
					<h3 class="rnrd-agent-dashboard__panel-title"><?php esc_html_e( 'AI Referral Traffic — last 30 days', 'rankready-ai-llm-seo' ); ?></h3>
					<?php
					if ( class_exists( 'RNRD_AI_Referral' ) ) {
						RNRD_AI_Referral::render_widget();
					}
					?>
				</div>

				<div class="rnrd-agent-dashboard__panel">
					<h3 class="rnrd-agent-dashboard__panel-title"><?php esc_html_e( 'Content Freshness', 'rankready-ai-llm-seo' ); ?></h3>
					<?php
					if ( class_exists( 'RNRD_Freshness' ) ) {
						RNRD_Freshness::render_widget();
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}
}
