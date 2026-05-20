<?php
/**
 * RankReady — Welcome flow (Apple-style 1-question onboarding).
 *
 * Replaces the "land on the settings page with 40 options" experience.
 * After activation, the user sees a single screen:
 *
 *   1. Plain English promise — "Get your WordPress site cited by ChatGPT,
 *      Perplexity, Claude & Google AI."
 *   2. One question — "What's your brand name?" (textarea, one per line)
 *   3. One button — "Make my site Agent Ready"
 *
 * On submit, RankReady:
 *   - Saves brand terms to RR_OPT_BRAND_TERMS (wires to llms.txt, robots,
 *     FAQ prompt, summary prompt automatically).
 *   - Auto-enables llms.txt, .md routes, and the AI crawler allowlist.
 *   - Flushes rewrite rules so endpoints go live immediately.
 *   - Redirects to the WP Dashboard so the Agent Visibility widget is the
 *     first thing the user sees.
 *
 * The welcome screen is only shown once per install (tracked in
 * `rr_welcome_completed` option). Power users can re-open it from the
 * settings page Info tab.
 *
 * @package RankReady
 * @since   1.2.0-beta.2
 */

defined( 'ABSPATH' ) || exit;

class RR_Welcome {

	private const MENU_SLUG    = 'rankready-welcome';
	private const FLAG_OPTION  = 'rr_welcome_completed';
	private const REDIRECT_KEY = 'rr_welcome_redirect';

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_page' ) );
		add_action( 'admin_init', array( self::class, 'maybe_redirect' ) );
		add_action( 'admin_init', array( self::class, 'maybe_handle_submit' ) );
	}

	/**
	 * Called from the activation hook in rankready.php. Sets a one-shot
	 * transient that triggers the redirect on the next admin page load.
	 */
	public static function flag_activation(): void {
		if ( get_option( self::FLAG_OPTION ) ) {
			return; // Already completed; never reshow on re-activation.
		}
		set_transient( self::REDIRECT_KEY, 1, 30 );
	}

	public static function maybe_redirect(): void {
		if ( ! get_transient( self::REDIRECT_KEY ) ) {
			return;
		}
		delete_transient( self::REDIRECT_KEY );

		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['activate-multi'] ) ) {
			return; // Don't hijack bulk-activation.
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public static function register_page(): void {
		// Hidden from menu (parent = null) but reachable via URL.
		add_submenu_page(
			null,
			__( 'Welcome to RankReady', 'rankready' ),
			__( 'Welcome', 'rankready' ),
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render_page' )
		);
	}

	public static function maybe_handle_submit(): void {
		if ( ! isset( $_POST['rr_welcome_submit'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['rr_welcome_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rr_welcome_nonce'] ) ), 'rr_welcome' ) ) {
			return;
		}

		// 1. Save brand terms (single source — wires to llms.txt, robots, FAQ, summary).
		$brand_raw = isset( $_POST['rr_brand_terms'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rr_brand_terms'] ) ) : '';
		update_option( RR_OPT_BRAND_TERMS, $brand_raw );

		// 2. Auto-enable the three agent-visibility features that are safe
		//    defaults: llms.txt index, .md per-post routes, AI crawler allowlist.
		//    These cost nothing at runtime and ship the discovery surface.
		update_option( RR_OPT_LLMS_ENABLE,   'on' );
		update_option( RR_OPT_MD_ENABLE,     'on' );
		update_option( RR_OPT_ROBOTS_ENABLE, 'on' );

		// 3. Flush rewrite rules so /llms.txt + .md routes resolve immediately.
		if ( class_exists( 'RR_Llms_Txt' ) ) {
			RR_Llms_Txt::add_rewrite_rules();
		}
		if ( class_exists( 'RR_Markdown' ) ) {
			RR_Markdown::add_rewrite_rules();
		}
		if ( class_exists( 'RR_MCP' ) ) {
			RR_MCP::add_manifest_rewrite();
		}
		flush_rewrite_rules( false );

		// 4. Mark completed so we don't redirect on subsequent activations.
		update_option( self::FLAG_OPTION, time() );

		// 5. Send them to the Dashboard so they see the Agent Visibility widget
		//    populated with the just-enabled signals.
		wp_safe_redirect( admin_url( 'index.php?rr_welcomed=1' ) );
		exit;
	}

	public static function render_page(): void {
		$site_name        = get_bloginfo( 'name' );
		$existing_brand   = (string) get_option( RR_OPT_BRAND_TERMS, '' );
		$default_brand    = '' !== $existing_brand ? $existing_brand : $site_name;
		?>
		<div class="rr-welcome">
			<style>
				.rr-welcome {
					max-width: 640px;
					margin: 48px auto 80px;
					padding: 0 24px;
					font-size: var(--rr-text-md, 13px);
					color: var(--rr-color-ink, #1d2327);
				}
				.rr-welcome__brand {
					display: flex; align-items: center; gap: 10px;
					margin-bottom: 24px;
					font-size: var(--rr-text-sm, 12px);
					color: var(--rr-color-text-muted, #646970);
					letter-spacing: 0.08em;
					text-transform: uppercase;
				}
				.rr-welcome__brand-dot {
					width: 10px; height: 10px; border-radius: 50%;
					background: var(--rr-color-brand, #2271b1);
				}
				.rr-welcome__title {
					font-size: var(--rr-text-3xl, 28px);
					line-height: var(--rr-leading-tight, 1.2);
					font-weight: var(--rr-weight-bold, 700);
					margin: 0 0 12px;
					letter-spacing: -0.01em;
				}
				.rr-welcome__lede {
					font-size: var(--rr-text-lg, 14px);
					line-height: var(--rr-leading-relaxed, 1.6);
					color: var(--rr-color-ink-soft, #3c434a);
					margin: 0 0 32px;
				}
				.rr-welcome__card {
					background: var(--rr-color-surface, #fff);
					border: 1px solid var(--rr-color-border, #c3c4c7);
					border-radius: var(--rr-radius-lg, 10px);
					padding: 28px 28px 24px;
					box-shadow: var(--rr-shadow-md, 0 2px 4px rgba(0,0,0,0.06));
				}
				.rr-welcome__label {
					display: block;
					font-size: var(--rr-text-md, 13px);
					font-weight: var(--rr-weight-semibold, 600);
					margin-bottom: 4px;
				}
				.rr-welcome__hint {
					font-size: var(--rr-text-sm, 12px);
					color: var(--rr-color-text-muted, #646970);
					margin: 0 0 12px;
					line-height: var(--rr-leading-normal, 1.5);
				}
				.rr-welcome textarea {
					width: 100%;
					min-height: 84px;
					padding: 10px 12px;
					font-size: var(--rr-text-md, 13px);
					font-family: inherit;
					border: 1px solid var(--rr-color-border, #c3c4c7);
					border-radius: var(--rr-radius-md, 6px);
					resize: vertical;
				}
				.rr-welcome textarea:focus {
					outline: 2px solid var(--rr-color-brand, #2271b1);
					outline-offset: -1px;
				}
				.rr-welcome__actions {
					margin-top: 22px;
					display: flex; align-items: center; gap: 14px;
				}
				.rr-welcome__primary {
					display: inline-block;
					padding: 10px 22px;
					background: var(--rr-color-brand, #2271b1);
					color: #fff;
					border: 0;
					border-radius: var(--rr-radius-md, 6px);
					font-size: var(--rr-text-md, 13px);
					font-weight: var(--rr-weight-semibold, 600);
					cursor: pointer;
					transition: background var(--rr-motion-fast, 120ms);
				}
				.rr-welcome__primary:hover { background: var(--rr-color-brand-hover, #135e96); }
				.rr-welcome__skip {
					color: var(--rr-color-text-muted, #646970);
					text-decoration: none;
					font-size: var(--rr-text-sm, 12px);
				}
				.rr-welcome__skip:hover { text-decoration: underline; }
				.rr-welcome__includes {
					margin: 28px 0 0;
					padding: 16px 18px;
					background: var(--rr-color-brand-soft, #f0f6fc);
					border-radius: var(--rr-radius-md, 6px);
					font-size: var(--rr-text-sm, 12px);
					color: var(--rr-color-info-text, #135e96);
					line-height: var(--rr-leading-relaxed, 1.6);
				}
				.rr-welcome__includes strong {
					display: block;
					margin-bottom: 4px;
					color: var(--rr-color-ink, #1d2327);
					font-size: var(--rr-text-md, 13px);
				}
			</style>

			<div class="rr-welcome__brand">
				<span class="rr-welcome__brand-dot" aria-hidden="true"></span>
				<span>RankReady</span>
			</div>

			<h1 class="rr-welcome__title">
				<?php esc_html_e( "Let's get your site cited by ChatGPT & Perplexity.", 'rankready' ); ?>
			</h1>

			<p class="rr-welcome__lede">
				<?php esc_html_e( '40–55% of AI citations go to fewer than 1,000 domains. One quick step and your site joins the discovery surface that the rest of the web has to chase.', 'rankready' ); ?>
			</p>

			<div class="rr-welcome__card">
				<form method="post" action="">
					<?php wp_nonce_field( 'rr_welcome', 'rr_welcome_nonce' ); ?>

					<label class="rr-welcome__label" for="rr_brand_terms">
						<?php esc_html_e( "What's your brand name?", 'rankready' ); ?>
					</label>
					<p class="rr-welcome__hint">
						<?php esc_html_e( 'One canonical name per line. This single input wires into llms.txt, robots.txt, AI summary prompts and FAQ prompts — every AI engine learns to recognise your brand consistently.', 'rankready' ); ?>
					</p>

					<textarea
						id="rr_brand_terms"
						name="rr_brand_terms"
						placeholder="<?php echo esc_attr( $site_name ); ?>"
						autofocus
					><?php echo esc_textarea( $default_brand ); ?></textarea>

					<div class="rr-welcome__actions">
						<button type="submit" name="rr_welcome_submit" value="1" class="rr-welcome__primary">
							<?php esc_html_e( 'Make my site Agent Ready →', 'rankready' ); ?>
						</button>
						<a class="rr-welcome__skip" href="<?php echo esc_url( admin_url( 'admin.php?page=rankready' ) ); ?>">
							<?php esc_html_e( 'Skip — go straight to settings', 'rankready' ); ?>
						</a>
					</div>
				</form>

				<div class="rr-welcome__includes">
					<strong><?php esc_html_e( 'This will automatically enable', 'rankready' ); ?></strong>
					<?php esc_html_e( 'llms.txt at /llms.txt — Markdown routes on every post (/post-slug.md) — AI crawler allowlist for GPTBot, ClaudeBot, PerplexityBot, OAI-SearchBot, Google-Extended + 13 others — WebMCP manifest at /.well-known/mcp.json.', 'rankready' ); ?>
				</div>
			</div>
		</div>
		<?php
	}
}
