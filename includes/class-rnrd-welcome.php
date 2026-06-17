<?php
/**
 * RankReady — Onboarding Wizard (3-step, progress bar).
 *
 * Step 1 — Brand Identity: site name, one-line summary, about paragraph.
 * Step 2 — Auto-tune:      one-click recommended-settings application.
 * Step 3 — Done:           confirmation + redirect to Dashboard.
 *
 * Triggers once on first activation via a transient flag. Power users
 * can re-run it from the Dashboard via the "Re-run the setup wizard" link.
 *
 * @package RankReady
 * @since   1.0.3
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Welcome {

	private const MENU_SLUG    = 'rankready-welcome';
	private const FLAG_OPTION  = 'rnrd_welcome_completed';
	private const REDIRECT_KEY = 'rnrd_welcome_redirect';

	// ── Lifecycle ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_menu',  array( self::class, 'register_page' ) );
		add_action( 'admin_init',  array( self::class, 'maybe_redirect' ) );
		add_action( 'admin_init',  array( self::class, 'maybe_handle_submit' ) );
	}

	/**
	 * Called from the activation hook in rankready.php.
	 * Sets a one-shot transient that triggers the redirect on next admin load.
	 * No-op when the wizard has already been completed.
	 */
	public static function flag_activation(): void {
		if ( get_option( self::FLAG_OPTION ) ) {
			return;
		}
		set_transient( self::REDIRECT_KEY, 1, 30 );
	}

	/**
	 * Onboarding gate. Runs on admin_init. Three triggers, all one-time and
	 * gated by the FLAG_OPTION so the wizard is shown exactly once per site:
	 *
	 *   1. Fresh activation  → immediate redirect (one-shot transient).
	 *   2. Existing/updated users who never onboarded → redirected the FIRST
	 *      time they OPEN a RankReady admin page (page=rankready-ai-llm-seo).
	 *      NOT forced on the update itself or on any other admin screen.
	 *   3. "Skip" action → marks onboarding seen (sets the flag) so it never
	 *      reappears, then lands the user on the Dashboard.
	 *
	 * Once FLAG_OPTION is in the DB (completed OR skipped) the wizard never
	 * shows again. Mirrors the setup-wizard pattern of Yoast / Rank Math.
	 */
	public static function maybe_redirect(): void {
		if ( ! self::can_redirect() ) {
			// Consume a lingering activation transient even when we can't act,
			// so it never fires later in the wrong context.
			delete_transient( self::REDIRECT_KEY );
			return;
		}

		// 3. Skip — mark seen, then continue to the Dashboard. Nonce-protected.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['rnrd_onboard_skip'], $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'rnrd_onboard_skip' ) ) {
			if ( ! get_option( self::FLAG_OPTION ) ) {
				update_option( self::FLAG_OPTION, time(), false );
			}
			delete_transient( self::REDIRECT_KEY );
			wp_safe_redirect( admin_url( 'admin.php?page=rankready-ai-llm-seo' ) );
			exit;
		}

		// Already onboarded — never redirect again.
		if ( get_option( self::FLAG_OPTION ) ) {
			delete_transient( self::REDIRECT_KEY );
			return;
		}

		// 1. Fresh activation — one-shot transient → immediate redirect.
		if ( get_transient( self::REDIRECT_KEY ) ) {
			delete_transient( self::REDIRECT_KEY );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}

		// 2. Existing/updated user opening RankReady for the first time without
		//    having onboarded — send them through the wizard once. Only when
		//    they land on the main RankReady page (never the wizard page itself,
		//    never any unrelated admin screen, never during the update process).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'rankready-ai-llm-seo' === $page ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}
	}

	/**
	 * Shared guard — never redirect during AJAX/cron/REST, in network admin,
	 * for users who can't manage options, or during bulk plugin activation.
	 */
	private static function can_redirect(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) ) {
			return false; // Don't hijack bulk-activation.
		}
		return true;
	}

	public static function register_page(): void {
		add_submenu_page(
			null,
			__( 'RankReady Setup', 'rankready-ai-llm-seo' ),
			__( 'Setup', 'rankready-ai-llm-seo' ),
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render_page' )
		);
	}

	// ── Form handler (admin_init, before any output) ───────────────────────────

	public static function maybe_handle_submit(): void {
		if ( empty( $_POST['rnrd_onboard_step'] ) ) {
			return;
		}
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$posted_step = absint( wp_unslash( $_POST['rnrd_onboard_step'] ) );
		$nonce_raw   = isset( $_POST['_rnrd_onboard_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_rnrd_onboard_nonce'] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce_raw, 'rnrd_onboard_' . $posted_step ) ) {
			return;
		}

		if ( 1 === $posted_step ) {
			self::handle_step_1();
		}
		// v1.1.2 — Wizard is now 2 steps. Step 2 is a server-rendered "Site is
		// AI-ready" screen with the spinner/tick animation; it has no form submit.
	}

	private static function handle_step_1(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in maybe_handle_submit() before dispatch.
		$name    = sanitize_text_field( wp_unslash( $_POST['rnrd_llms_site_name'] ?? '' ) );
		$summary = sanitize_textarea_field( wp_unslash( $_POST['rnrd_llms_summary'] ?? '' ) );
		$about   = sanitize_textarea_field( wp_unslash( $_POST['rnrd_llms_about'] ?? '' ) );
		$terms   = sanitize_textarea_field( wp_unslash( $_POST['rnrd_brand_terms'] ?? '' ) );

		// Only write non-empty values so a blank skip doesn't wipe existing data.
		if ( '' !== $name )    update_option( 'rnrd_llms_site_name', $name );
		if ( '' !== $summary ) update_option( 'rnrd_llms_summary',   $summary );
		if ( '' !== $about )   update_option( 'rnrd_llms_about',     $about );
		if ( '' !== $terms )   update_option( 'rnrd_brand_terms',    $terms );

		// v1.1.2 — Auto-tune happens IMMEDIATELY after brand save (no manual button).
		// Step 2 then just displays the result with the spinner→tick reveal.
		//
		// Free-tier options only (Pro-only flags rnrd_auto_generate / rnrd_faq_auto_generate
		// are NOT touched — they belong to the Pro plugin which manages its own defaults).
		//
		// Backward-compat: respects explicit "off" preferences from prior versions —
		// only writes when the option was never persisted.
		//
		// v1.1.1 — The per-option "was it ever persisted?" check below is the ONLY
		// condition we gate on. A previous version ALSO force-wrote whenever the
		// welcome flag was missing ($is_fresh_install). That clobbered an existing
		// user's saved settings the instant they passed through the wizard after an
		// update — e.g. AI Summary / llms.txt / Markdown / FAQ post-type selections
		// reverting to post+page, and explicitly-disabled toggles flipping back on.
		// Any site that installed before the wizard existed (or skipped it) has no
		// flag, so it was wrongly treated as "fresh". Removed — see the per-option
		// guards in both loops. Defaults now seed ONLY for options never saved.
		$bool_defaults_to_on = array(
			'rnrd_llms_enable',
			// v1.1.21 — llms-full.txt and AI Referral tracking were the last
			// two scorecard signals not being written by onboarding. Without
			// them, the dashboard "agent signals active" card capped at 8/10
			// even after a clean install. Both are user-positive features:
			// llms-full.txt is the deeper site index AI agents prefer when
			// available, and AI Referral tracking only fires on referrers
			// from AI bots (no broad pageview surveillance). Completing the
			// onboarding wizard is the consent moment for both.
			'rnrd_llms_full_enable',
			'rnrd_md_enable',
			// v1.1.19 — Without these two, the dashboard agent-signal scorecard
			// flagged "AI hint in body" and "AI bot auto-serve" as unchecked
			// even after a clean onboarding run, capping the score at 8/10.
			// register_setting() defaults to 'on' but never writes to the DB —
			// so the first time the user saves any other LLMS_GROUP form,
			// these unposted checkboxes get sanitised to 'off'. Writing them
			// explicitly during onboarding closes both holes.
			'rnrd_md_hint_div',
			'rnrd_md_bot_auto_serve',
			'rnrd_robots_enable',
			'rnrd_content_signals_enable',
			'rnrd_mcp_enable',
			'rnrd_ai_referral_enable',
			// v1.2.0 — Open Knowledge Format bundle on by default for new installs (same
			// consent moment as the other AI-readability endpoints). The '__rnrd_unset__'
			// guard below only writes when the option was never saved, so an existing user
			// who turned OKF off is never re-enabled on update.
			'rnrd_okf_enable',
			// v1.2.0 — free AEO schema signals AI engines read for citation. They default
			// 'on' in register_setting; seeded explicitly so a fresh install is 100% AI-ready
			// and the values survive the first Authority-tab save. (HowTo/ItemList are Pro —
			// intentionally NOT seeded in Free.)
			'rnrd_schema_article',
			'rnrd_schema_faq',
			'rnrd_schema_speakable',
		);
		foreach ( $bool_defaults_to_on as $opt ) {
			$current = get_option( $opt, '__rnrd_unset__' );
			if ( '__rnrd_unset__' === $current ) {
				update_option( $opt, 'on' );
			}
		}

		// CPT defaults — post + page always. WooCommerce product added when WC is active.
		// Free supports these three CPTs by design; full CPT picker is Coming Soon.
		$cpt_defaults = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$cpt_defaults[] = 'product';
		}
		$cpt_option_keys = array(
			'rnrd_llms_post_types',
			'rnrd_md_post_types',
			'rnrd_post_types',
			'rnrd_faq_post_types',
		);
		foreach ( $cpt_option_keys as $opt ) {
			$current = get_option( $opt, '__rnrd_unset__' );
			if ( '__rnrd_unset__' === $current ) {
				update_option( $opt, $cpt_defaults );
			}
		}

		// Flush rewrites so /llms.txt + .md routes resolve immediately.
		if ( class_exists( 'RNRD_Llms_Txt' ) ) {
			RNRD_Llms_Txt::add_rewrite_rules();
		}
		if ( class_exists( 'RNRD_Markdown' ) ) {
			RNRD_Markdown::add_rewrite_rules();
		}
		flush_rewrite_rules( false );

		// Mark wizard completed so re-activation never relaunches it.
		update_option( self::FLAG_OPTION, time() );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&step=2' ) );
		exit;
	}

	// ── Render ─────────────────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'rankready-ai-llm-seo' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1;
		// v1.1.2 — Wizard is now 2 steps (was 3). Legacy ?step=3 URLs gracefully
		// fall through to step 2 so bookmarked completion links keep working.
		$step = max( 1, min( 2, $step ) );

		switch ( $step ) {
			case 2:
				self::render_step_2();
				break;
			default:
				self::render_step_1();
				break;
		}
	}

	// ── Shared chrome ─────────────────────────────────────────────────────────

	/**
	 * Opens the wizard wrapper and prints the progress bar.
	 *
	 * @param int $step      Current step (1–3).
	 * @param int $total     Total steps.
	 */
	private static function render_header( int $step, int $total = 2 ): void {
		$pct = (int) round( ( $step / $total ) * 100 );
		?>
		<div class="rnrd-onboard">
		<?php self::render_styles(); ?>

		<!-- Logo + step counter -->
		<div class="rnrd-onboard__brand">
			<span class="rnrd-onboard__dot" aria-hidden="true"></span>
			<span>RankReady</span>
			<span class="rnrd-onboard__step-count">
				<?php
				/* translators: 1: current step number, 2: total steps */
				printf( esc_html__( 'Step %1$d of %2$d', 'rankready-ai-llm-seo' ), absint( $step ), absint( $total ) );
				?>
			</span>
		</div>

		<!-- Progress bar -->
		<div class="rnrd-onboard__progress-track" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100">
			<div class="rnrd-onboard__progress-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
		</div>

		<div class="rnrd-onboard__card">
		<?php
	}

	/** Closes the card and wrapper divs. */
	private static function render_footer(): void {
		?>
		</div><!-- /.rnrd-onboard__card -->
		</div><!-- /.rnrd-onboard -->
		<?php
	}

	// ── Step 1: Brand Identity ─────────────────────────────────────────────────

	private static function render_step_1(): void {
		$site_name = get_bloginfo( 'name' );
		$cur_name  = (string) get_option( 'rnrd_llms_site_name', '' );
		$cur_sum   = (string) get_option( 'rnrd_llms_summary',   '' );
		$cur_about = (string) get_option( 'rnrd_llms_about',     '' );
		$cur_terms = (string) get_option( 'rnrd_brand_terms',    '' );

		self::render_header( 1 );
		?>

		<h1 class="rnrd-onboard__title">
			<?php esc_html_e( 'Welcome to RankReady', 'rankready-ai-llm-seo' ); ?>
		</h1>
		<p class="rnrd-onboard__lede">
			<?php esc_html_e( 'Get cited by ChatGPT, Claude, Perplexity, and Google AI in about 2 minutes. Start with brand identity — these four fields feed every AI surface RankReady controls. The panel on the right shows exactly where each one is used.', 'rankready-ai-llm-seo' ); ?>
		</p>

		<div class="rnrd-onboard__split">
			<form method="post" action="" class="rnrd-onboard__form">
				<?php wp_nonce_field( 'rnrd_onboard_1', '_rnrd_onboard_nonce' ); ?>
				<input type="hidden" name="rnrd_onboard_step" value="1" />

				<div class="rnrd-onboard__field">
					<label class="rnrd-onboard__label" for="rnrd_llms_site_name">
						<?php esc_html_e( 'Site / brand name', 'rankready-ai-llm-seo' ); ?>
					</label>
					<p class="rnrd-onboard__hint">
						<?php esc_html_e( 'The canonical brand name. ChatGPT quotes it, Claude cites it, Google AI Overviews uses it as the entity name. Leave blank to fall back to the WordPress site title.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<input
						type="text"
						id="rnrd_llms_site_name"
						name="rnrd_llms_site_name"
						value="<?php echo esc_attr( $cur_name ); ?>"
						placeholder="<?php echo esc_attr( $site_name ); ?>"
						class="rnrd-onboard__input"
						data-preview-key="name"
					/>
				</div>

				<div class="rnrd-onboard__field">
					<label class="rnrd-onboard__label" for="rnrd_llms_summary">
						<?php esc_html_e( 'One-line summary', 'rankready-ai-llm-seo' ); ?>
					</label>
					<p class="rnrd-onboard__hint">
						<?php esc_html_e( 'A single sentence an AI engine can quote. Shows up under the brand name in /llms.txt and gets repeated verbatim in AI summaries. Max 160 characters.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<textarea
						id="rnrd_llms_summary"
						name="rnrd_llms_summary"
						class="rnrd-onboard__textarea rnrd-onboard__textarea--short"
						maxlength="160"
						data-preview-key="summary"
						placeholder="<?php esc_attr_e( 'Example: A 5-minute Pomodoro timer for Mac that hides your distracting apps automatically.', 'rankready-ai-llm-seo' ); ?>"
					><?php echo esc_textarea( $cur_sum ); ?></textarea>
				</div>

				<div class="rnrd-onboard__field">
					<label class="rnrd-onboard__label" for="rnrd_llms_about">
						<?php esc_html_e( 'About (optional)', 'rankready-ai-llm-seo' ); ?>
					</label>
					<p class="rnrd-onboard__hint">
						<?php esc_html_e( 'Detailed context for AI engines to recommend the site confidently — audience, differentiators, proof. Markdown supported. ≤ 500 characters.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<textarea
						id="rnrd_llms_about"
						name="rnrd_llms_about"
						class="rnrd-onboard__textarea"
						maxlength="500"
						data-preview-key="about"
						placeholder="<?php esc_attr_e( 'Example: We help solo Mac users stay focused without forcing them into a calendar. Built since 2022, used by 12,000+ freelancers and indie devs. Featured in The Verge and Lifehacker.', 'rankready-ai-llm-seo' ); ?>"
					><?php echo esc_textarea( $cur_about ); ?></textarea>
				</div>

				<div class="rnrd-onboard__field">
					<label class="rnrd-onboard__label" for="rnrd_brand_terms">
						<?php esc_html_e( 'Canonical brand terms', 'rankready-ai-llm-seo' ); ?>
					</label>
					<p class="rnrd-onboard__hint">
						<?php esc_html_e( 'One canonical name per line — exact capitalisation and spacing. Include product names, frequent misspellings, abbreviations. AI engines unify every variant into a single recognised entity.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<textarea
						id="rnrd_brand_terms"
						name="rnrd_brand_terms"
						class="rnrd-onboard__textarea"
						rows="4"
						data-preview-key="terms"
						placeholder="<?php esc_attr_e( "Acme Studio\nAcme\nAcmeStudio (one word)", 'rankready-ai-llm-seo' ); ?>"
					><?php echo esc_textarea( $cur_terms ); ?></textarea>
				</div>

				<div class="rnrd-onboard__actions">
					<button type="submit" class="rnrd-onboard__btn rnrd-onboard__btn--primary">
						<?php esc_html_e( 'Make site AI-ready →', 'rankready-ai-llm-seo' ); ?>
					</button>
					<a class="rnrd-onboard__skip" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=rankready-ai-llm-seo&rnrd_onboard_skip=1' ), 'rnrd_onboard_skip' ) ); ?>">
						<?php esc_html_e( 'Skip for now', 'rankready-ai-llm-seo' ); ?>
					</a>
				</div>
			</form>

			<aside class="rnrd-onboard__sidebar" aria-label="<?php esc_attr_e( 'Where these fields appear', 'rankready-ai-llm-seo' ); ?>">
				<div class="rnrd-onboard__sidebar-inner">
					<h2 class="rnrd-onboard__sidebar-title"><?php esc_html_e( 'Where this goes', 'rankready-ai-llm-seo' ); ?></h2>
					<p class="rnrd-onboard__sidebar-lede">
						<?php esc_html_e( 'These exact fields are read by every AI surface RankReady controls. One place to write, six places to be cited.', 'rankready-ai-llm-seo' ); ?>
					</p>

					<ul class="rnrd-onboard__sidebar-list">
						<li>
							<code>/llms.txt</code>
							<span><?php esc_html_e( 'Brand name = H1. One-liner = blockquote. About = body. Canonical terms = Brand line.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<code>/llms-full.txt</code>
							<span><?php esc_html_e( 'Same header block at the top of every page below. AI engines crawl the full corpus.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<code>/robots.txt</code>
							<span><?php esc_html_e( 'Brand name appears in the comment block above the RankReady AI-crawler rules.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<?php esc_html_e( 'AI Summary prompt', 'rankready-ai-llm-seo' ); ?>
							<span><?php esc_html_e( 'Brand name is forced into every per-post summary so ChatGPT cites you by name, not "this site".', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<?php esc_html_e( 'FAQ generation prompt', 'rankready-ai-llm-seo' ); ?>
							<span><?php esc_html_e( 'Brand context anchors every FAQ answer — deduped against per-FAQ legacy fields.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<code>/.well-known/mcp.json</code>
							<span><?php esc_html_e( 'WebMCP ability "rankready/get-site-info" returns brand name + canonical terms to Claude Desktop, Cursor, VS Code.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
						<li>
							<?php esc_html_e( 'Homepage Markdown', 'rankready-ai-llm-seo' ); ?>
							<span><?php esc_html_e( 'Sent when an agent requests text/markdown for /. Uses brand name + one-liner as the header.', 'rankready-ai-llm-seo' ); ?></span>
						</li>
					</ul>
				</div>
			</aside>
		</div>

		<?php
		self::render_footer();
	}

	// ── Step 2: Configuring → AI-ready (rotating ring → tick reveal) ─────────

	private static function render_step_2(): void {
		self::render_header( 2 );
		// v1.1.2 — Auto-tune already ran in handle_step_1(). This screen is
		// pure visual feedback: a rotating mint ring spins for ~1.6s (CSS
		// animation, no JS), morphs into a green checkmark, then the
		// 7-item tick list staggers in 140ms apart for the "tick tick tick"
		// feel. Final CTA: Go to Dashboard.
		$wc_active = post_type_exists( 'product' );
		$site_name = (string) get_option( 'rnrd_llms_site_name', '' );
		if ( '' === $site_name ) {
			$site_name = get_bloginfo( 'name' );
		}
		?>

		<div class="rnrd-onboard__progress-ring" aria-hidden="true">
			<svg class="rnrd-onboard__ring-svg" viewBox="0 0 56 56">
				<circle class="rnrd-onboard__ring-track"  cx="28" cy="28" r="24"></circle>
				<circle class="rnrd-onboard__ring-stroke" cx="28" cy="28" r="24"></circle>
			</svg>
			<span class="rnrd-onboard__ring-tick">✓</span>
		</div>

		<h1 class="rnrd-onboard__title">
			<?php
			/* translators: %s: site name */
			/* translators: %s: site name */
			echo esc_html( sprintf( __( 'Congratulations! %s is now AI-ready', 'rankready-ai-llm-seo' ), $site_name ) );
			?>
		</h1>
		<p class="rnrd-onboard__lede">
			<?php esc_html_e( 'Every surface below is live. AI crawlers can now discover, read, and cite your content.', 'rankready-ai-llm-seo' ); ?>
		</p>

		<ul class="rnrd-onboard__ticks">
			<li><span class="rnrd-onboard__tick">✓</span> <?php esc_html_e( 'Brand Identity saved', 'rankready-ai-llm-seo' ); ?></li>
			<li><span class="rnrd-onboard__tick">✓</span> <?php esc_html_e( '/llms.txt site index live', 'rankready-ai-llm-seo' ); ?></li>
			<li><span class="rnrd-onboard__tick">✓</span> <?php esc_html_e( 'Per-post /post-slug.md endpoints live', 'rankready-ai-llm-seo' ); ?></li>
			<li><span class="rnrd-onboard__tick">✓</span> <?php esc_html_e( 'robots.txt allowing 30+ AI crawlers', 'rankready-ai-llm-seo' ); ?></li>
			<li><span class="rnrd-onboard__tick">✓</span> <?php esc_html_e( 'Content Signals in <head>', 'rankready-ai-llm-seo' ); ?></li>
			<li><span class="rnrd-onboard__tick">✓</span> <?php esc_html_e( '/.well-known/mcp.json (WebMCP manifest)', 'rankready-ai-llm-seo' ); ?></li>
			<li><span class="rnrd-onboard__tick">✓</span>
				<?php
				echo esc_html(
					$wc_active
						? __( 'Default post types enabled: Post, Page, WooCommerce Product', 'rankready-ai-llm-seo' )
						: __( 'Default post types enabled: Post, Page', 'rankready-ai-llm-seo' )
				);
				?>
			</li>
		</ul>

		<div class="rnrd-onboard__actions rnrd-onboard__actions--centered">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo' ) ); ?>" class="rnrd-onboard__btn rnrd-onboard__btn--primary">
				<?php esc_html_e( 'Go to Dashboard →', 'rankready-ai-llm-seo' ); ?>
			</a>
		</div>

		<?php
		self::render_footer();
	}

	// ── Styles ────────────────────────────────────────────────────────────────

	/**
	 * Inline styles scoped to the onboarding wizard.
	 * Kept inline (not a separate stylesheet) so the wizard works even if the
	 * admin CSS fails to load, and to avoid an extra HTTP round-trip on a
	 * screen the user sees once.
	 *
	 * WP.org Rule #3 exemption: wp_kses_post() is used on all echoed HTML;
	 * this <style> block is server-generated, not user-editable, and is the
	 * accepted pattern for admin-only wizard screens (see WC, GF, etc.).
	 */
	private static function render_styles(): void {
		?>
		<style>
		/* ── Onboarding wizard layout ──────────────────────────────────── */
		.rnrd-onboard {
			max-width: 1080px;
			margin: 48px auto 80px;
			padding: 0 24px;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			color: var(--rnrd-text-primary, #0F1411);
		}

		/* Brand row */
		.rnrd-onboard__brand {
			display: flex;
			align-items: center;
			gap: 8px;
			margin-bottom: 20px;
			font-size: 12px;
			font-weight: 600;
			color: var(--rnrd-text-tertiary, #6B716D);
			letter-spacing: 0.06em;
			text-transform: uppercase;
		}
		.rnrd-onboard__dot {
			width: 8px;
			height: 8px;
			border-radius: 50%;
			background: var(--rnrd-mint-500, #59F7C2);
			flex-shrink: 0;
		}
		.rnrd-onboard__step-count {
			margin-left: auto;
			font-weight: 400;
			letter-spacing: 0;
			text-transform: none;
		}

		/* Progress bar */
		.rnrd-onboard__progress-track {
			height: 6px;
			background: var(--rnrd-bg-surface-2, #F4F5F0);
			border-radius: 99px;
			overflow: hidden;
			margin-bottom: 32px;
		}
		.rnrd-onboard__progress-fill {
			height: 100%;
			background: var(--rnrd-mint-500, #59F7C2);
			border-radius: 99px;
			transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
		}

		/* Card */
		.rnrd-onboard__card {
			background: var(--rnrd-bg-surface, #FFFFFF);
			border: 1px solid var(--rnrd-border-subtle, #ECEDE7);
			border-radius: 16px;
			padding: 36px 40px 32px;
			box-shadow: 0 2px 8px rgba(15, 20, 17, 0.06);
		}

		/* v1.1.8 — Kill EVERY WP-admin form-table style leak inside the wizard.
		 * The wizard renders under /wp-admin/ so common.css cascades. We apply
		 * a single border/box-shadow/outline reset on the UNFOCUSED defaults,
		 * then let the more-specific `.rnrd-onboard__input:focus` rules below
		 * paint the mint focus ring. Order matters: this block has to come
		 * BEFORE the focus rules so source-order tiebreaker doesn't
		 * overwrite the mint glow. */
		.rnrd-onboard,
		.rnrd-onboard * {
			box-sizing: border-box;
		}
		.rnrd-onboard input[type="text"],
		.rnrd-onboard textarea {
			-webkit-appearance: none !important;
			        appearance: none !important;
			background-image: none !important;
			box-shadow: none;
			border: 1px solid var(--rnrd-border-default, #D9DAD5);
		}
		/* v1.1.10 — WP-admin button + anchor base override. Covers both
		 * <button>-style submits (step 1 Make site AI-ready) AND <a>-style
		 * primary CTAs (step 2 Go to Dashboard, which inherits WP-admin's
		 * blue focus outline by default). Mouse-click focus = no halo,
		 * keyboard-tab focus = mint ring via .rnrd-onboard__btn--primary rule. */
		.rnrd-onboard button:focus:not(:focus-visible),
		.rnrd-onboard a:focus:not(:focus-visible),
		.rnrd-onboard__btn:focus:not(:focus-visible) {
			outline: none !important;
			box-shadow: none !important;
		}
		.rnrd-onboard a.rnrd-onboard__btn:focus,
		.rnrd-onboard a.rnrd-onboard__btn:focus-visible {
			outline: none !important;
		}

		/* Typography */
		.rnrd-onboard__title {
			font-size: 24px;
			font-weight: 700;
			line-height: 1.2;
			letter-spacing: -0.015em;
			margin: 0 0 12px;
			color: var(--rnrd-text-primary, #0F1411);
		}
		.rnrd-onboard__lede {
			font-size: 14px;
			line-height: 1.6;
			color: var(--rnrd-text-secondary, #3D423F);
			margin: 0 0 28px;
		}

		/* v1.1.6 — 2-column split for Step 1: form on the left, sticky
		 * "Where this goes" sidebar on the right. Stacks on narrow screens. */
		.rnrd-onboard__split {
			display: grid;
			grid-template-columns: minmax(0, 1fr) 320px;
			gap: 28px;
			align-items: start;
		}
		@media (max-width: 900px) {
			.rnrd-onboard__split {
				grid-template-columns: 1fr;
			}
		}
		.rnrd-onboard__form {
			min-width: 0;
		}
		.rnrd-onboard__sidebar {
			min-width: 0;
			position: sticky;
			top: 64px;
		}
		.rnrd-onboard__sidebar-inner {
			padding: 22px 24px;
			background: var(--rnrd-mint-50, #ECFDF6);
			border: 1px solid var(--rnrd-mint-200, #B8F2DC);
			border-radius: 14px;
		}
		.rnrd-onboard__sidebar-title {
			font-size: 13px;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: var(--rnrd-mint-800, #064E3B);
			margin: 0 0 8px;
		}
		.rnrd-onboard__sidebar-lede {
			font-size: 12px;
			line-height: 1.55;
			color: var(--rnrd-text-secondary, #3D423F);
			margin: 0 0 14px;
		}
		.rnrd-onboard__sidebar-list {
			list-style: none;
			margin: 0;
			padding: 0;
			display: flex;
			flex-direction: column;
			gap: 10px;
		}
		.rnrd-onboard__sidebar-list li {
			font-size: 12px;
			color: var(--rnrd-text-primary, #0F1411);
			line-height: 1.5;
			padding-left: 14px;
			position: relative;
			font-weight: 600;
		}
		.rnrd-onboard__sidebar-list li::before {
			content: "";
			position: absolute;
			left: 0;
			top: 7px;
			width: 5px;
			height: 5px;
			border-radius: 50%;
			background: var(--rnrd-mint-500, #59F7C2);
		}
		.rnrd-onboard__sidebar-list li code {
			font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
			background: transparent;
			padding: 0;
			color: var(--rnrd-mint-800, #064E3B);
			font-size: 12px;
		}
		.rnrd-onboard__sidebar-list li span {
			display: block;
			margin-top: 2px;
			font-weight: 400;
			color: var(--rnrd-text-tertiary, #6B716D);
		}

		/* v1.1.7 — One spacing/typography token across every field.
		 * Hard-overrides WP-admin's input:focus blue ring via !important
		 * (we're in /wp-admin so WP core CSS specificity always loads).
		 *
		 * v1.1.20 — Bumped field gap (24 → 28) and hint-to-input gap
		 * (10 → 12) so the bigger 46px input + 10px-radius chrome reads
		 * with the same breathing room as the textareas below. */
		.rnrd-onboard__field {
			margin: 0 0 28px;
		}
		.rnrd-onboard__field:last-of-type {
			margin-bottom: 0;
		}
		.rnrd-onboard__label {
			display: block;
			font-size: 14px;
			font-weight: 600;
			line-height: 1.4;
			color: var(--rnrd-text-primary, #0F1411);
			margin: 0 0 6px;
			letter-spacing: -0.005em;
		}
		.rnrd-onboard__hint {
			font-size: 12px;
			line-height: 1.55;
			color: var(--rnrd-text-tertiary, #6B716D);
			margin: 0 0 12px;
			max-width: 640px;
		}

		/* Inputs — strip WP-admin styles, lock to RankReady design tokens.
		 * v1.1.20 — Bumped padding (12px 14px) and radius (10px) so the
		 * single-line input matches the textarea's visible curve. Aditya's
		 * screenshot showed the text input looking squarer than the textarea
		 * below it; both classes now share identical chrome, the only
		 * difference is height (input is a single line, textarea has
		 * min-height). */
		.rnrd-onboard__input,
		.rnrd-onboard__textarea {
			display: block;
			width: 100%;
			max-width: 100%;
			padding: 12px 14px;
			font-size: 14px;
			font-family: inherit;
			line-height: 1.5;
			color: var(--rnrd-text-primary, #0F1411);
			background: var(--rnrd-bg-surface, #fff);
			background-image: none;
			border: 1px solid var(--rnrd-border-default, #D9DAD5);
			border-radius: 10px;
			box-shadow: none;
			box-sizing: border-box;
			margin: 0;
			-webkit-appearance: none;
			        appearance: none;
			transition: border-color 0.15s ease, box-shadow 0.15s ease;
		}
		/* Single-line input — fixed height locks it to the same visual weight
		 * as the textarea, just shorter. Without this the input shrunk to
		 * line-height + padding and looked thinner than the textarea below. */
		.rnrd-onboard__input {
			height: 46px;
		}
		.rnrd-onboard__textarea {
			min-height: 96px;
			resize: vertical;
		}
		.rnrd-onboard__textarea--short {
			min-height: 72px;
		}
		/* Hover — subtle indication of interactivity, mint-tinted */
		.rnrd-onboard__input:hover,
		.rnrd-onboard__textarea:hover {
			border-color: var(--rnrd-border-strong, #B0B3AB);
		}
		/* Focus — kill WP-admin's blue ring with !important. Mint glow only. */
		.rnrd-onboard__input:focus,
		.rnrd-onboard__textarea:focus,
		.rnrd-onboard__input:focus-visible,
		.rnrd-onboard__textarea:focus-visible {
			border-color: var(--rnrd-mint-500, #59F7C2) !important;
			box-shadow: 0 0 0 3px rgba(89, 247, 194, 0.22) !important;
			outline: none !important;
		}
		/* Placeholder — consistent muted ink */
		.rnrd-onboard__input::placeholder,
		.rnrd-onboard__textarea::placeholder {
			color: var(--rnrd-text-muted, #A8ACA3);
			opacity: 1;
		}
		/* Selection — mint-tinted (matches brand) */
		.rnrd-onboard__input::selection,
		.rnrd-onboard__textarea::selection {
			background: var(--rnrd-mint-200, #B8F2DC);
			color: var(--rnrd-text-primary, #0F1411);
		}

		/* Buttons */
		.rnrd-onboard__actions {
			display: flex;
			align-items: center;
			gap: 16px;
			margin-top: 28px;
			flex-wrap: wrap;
		}
		.rnrd-onboard__actions--centered {
			justify-content: center;
		}
		/* v1.1.5 — The "Go to Dashboard" final-step button stretches to the
		 * width of the card content so it doesn't float small in the centre. */
		.rnrd-onboard__actions--centered .rnrd-onboard__btn--primary {
			width: 100%;
			max-width: 360px;
			justify-content: center;
			padding-top: 14px;
			padding-bottom: 14px;
		}
		.rnrd-onboard__btn {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 12px 24px;
			font-size: 14px;
			font-weight: 600;
			font-family: inherit;
			border-radius: 8px;
			border: none;
			cursor: pointer;
			text-decoration: none;
			transition: background 0.15s ease, transform 0.1s ease;
			line-height: 1.4;
			box-sizing: border-box;
		}
		.rnrd-onboard__btn--primary {
			background: var(--rnrd-mint-500, #59F7C2);
			color: var(--rnrd-text-on-mint, #0A3D2B);
			border: 1px solid var(--rnrd-mint-500, #59F7C2);
		}
		.rnrd-onboard__btn--primary:hover {
			background: var(--rnrd-mint-400, #7CF9CE);
			color: var(--rnrd-text-on-mint, #0A3D2B);
			border-color: var(--rnrd-mint-400, #7CF9CE);
		}
		.rnrd-onboard__btn--primary:focus-visible {
			outline: none !important;
			box-shadow: 0 0 0 3px rgba(89, 247, 194, 0.40) !important;
		}
		.rnrd-onboard__btn--primary:active {
			transform: scale(0.97);
		}
		.rnrd-onboard__skip {
			font-size: 13px;
			color: var(--rnrd-text-tertiary, #6B716D);
			text-decoration: none;
		}
		.rnrd-onboard__skip:hover {
			color: var(--rnrd-text-primary, #0F1411);
			text-decoration: underline;
		}
		.rnrd-onboard__back {
			display: inline-block;
			margin-top: 16px;
			font-size: 12px;
			color: var(--rnrd-text-tertiary, #6B716D);
			text-decoration: none;
		}
		.rnrd-onboard__back:hover {
			color: var(--rnrd-text-primary, #0F1411);
			text-decoration: underline;
		}

		/* Checklist (step 2) */
		.rnrd-onboard__checklist {
			list-style: none;
			margin: 0 0 24px;
			padding: 0;
			display: flex;
			flex-direction: column;
			gap: 12px;
		}
		.rnrd-onboard__check-item {
			display: flex;
			align-items: flex-start;
			gap: 10px;
			font-size: 13px;
			color: var(--rnrd-text-secondary, #3D423F);
			line-height: 1.5;
		}
		.rnrd-onboard__check-icon {
			flex-shrink: 0;
			width: 20px;
			height: 20px;
			border-radius: 50%;
			background: var(--rnrd-mint-50, #ECFDF6);
			color: var(--rnrd-mint-700, #047857);
			font-size: 11px;
			font-weight: 700;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			margin-top: 1px;
		}
		.rnrd-onboard__check-item strong {
			color: var(--rnrd-text-primary, #0F1411);
		}

		/* v1.1.0 — Plan list (step 2) — clean bulleted preview of what step 2 will turn on */
		.rnrd-onboard__plan {
			list-style: none;
			margin: 0 0 28px;
			padding: 18px 20px;
			background: var(--rnrd-bg-surface-2, #F4F5F0);
			border-radius: 12px;
			display: flex;
			flex-direction: column;
			gap: 8px;
		}
		.rnrd-onboard__plan li {
			font-size: 13px;
			color: var(--rnrd-text-secondary, #3D423F);
			line-height: 1.5;
			padding-left: 20px;
			position: relative;
		}
		.rnrd-onboard__plan li::before {
			content: "";
			position: absolute;
			left: 4px;
			top: 8px;
			width: 6px;
			height: 6px;
			border-radius: 50%;
			background: var(--rnrd-mint-500, #59F7C2);
		}

		/* v1.1.0 — Tick list (step 3) — animated reveal, "tick tick tick" feel */
		.rnrd-onboard__ticks {
			list-style: none;
			margin: 0 0 28px;
			padding: 0;
			display: flex;
			flex-direction: column;
			gap: 10px;
		}
		.rnrd-onboard__ticks li {
			display: flex;
			align-items: center;
			gap: 12px;
			font-size: 14px;
			color: var(--rnrd-text-primary, #0F1411);
			line-height: 1.5;
			min-height: 28px; /* same row height for every tick — no jagged baseline */
			opacity: 0;
			transform: translateY(4px);
			animation: rnrd-onboard-tick-in 0.32s cubic-bezier(0.2, 0, 0, 1) forwards;
		}
		/* v1.1.2 — Ticks start after the spinner ring fills (1700ms baseline). */
		.rnrd-onboard__ticks li:nth-child(1) { animation-delay: 1800ms; }
		.rnrd-onboard__ticks li:nth-child(2) { animation-delay: 1940ms; }
		.rnrd-onboard__ticks li:nth-child(3) { animation-delay: 2080ms; }
		.rnrd-onboard__ticks li:nth-child(4) { animation-delay: 2220ms; }
		.rnrd-onboard__ticks li:nth-child(5) { animation-delay: 2360ms; }
		.rnrd-onboard__ticks li:nth-child(6) { animation-delay: 2500ms; }
		.rnrd-onboard__ticks li:nth-child(7) { animation-delay: 2640ms; }
		@keyframes rnrd-onboard-tick-in {
			to { opacity: 1; transform: translateY(0); }
		}
		/* v1.1.5 — Tick is a SOLID mint circle with a WHITE check.
		 * Hard-set every property that WP admin's checkbox stylesheet could
		 * leak into (border, box-shadow, background-image, appearance) so the
		 * circle never picks up the native WP-checkbox look. Same size for
		 * every tick — width === height === 24px exactly. */
		.rnrd-onboard__tick {
			flex-shrink: 0;
			width: 24px;
			height: 24px;
			min-width: 24px;
			min-height: 24px;
			max-width: 24px;
			max-height: 24px;
			border: 0;
			border-radius: 50%;
			background: var(--rnrd-mint-500, #10B981);
			background-image: none;
			color: #ffffff;
			font-size: 14px;
			font-weight: 700;
			line-height: 1;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			margin: 0;
			padding: 0;
			box-shadow: none;
			-webkit-appearance: none;
			        appearance: none;
			text-align: center;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;
		}

		/* v1.1.2 — Rotating progress ring → green tick (step 2 hero).
		 * 1500ms: ring sweeps clockwise from empty → full (CSS stroke-dashoffset)
		 * 1500ms→1700ms: ring fades out
		 * 1700ms→2000ms: green tick scales in
		 * Pure CSS, no JS. Respects prefers-reduced-motion below. */
		.rnrd-onboard__progress-ring {
			position: relative;
			width: 72px;
			height: 72px;
			margin: 0 0 24px;
		}
		.rnrd-onboard__ring-svg {
			width: 100%;
			height: 100%;
			transform: rotate(-90deg);
		}
		.rnrd-onboard__ring-track {
			fill: none;
			stroke: var(--rnrd-mint-50, #ECFDF6);
			stroke-width: 4;
		}
		.rnrd-onboard__ring-stroke {
			fill: none;
			stroke: var(--rnrd-mint-500, #59F7C2);
			stroke-width: 4;
			stroke-linecap: round;
			stroke-dasharray: 151;   /* 2π × 24 ≈ 150.8 */
			stroke-dashoffset: 151;
			animation: rnrd-ring-fill 1500ms cubic-bezier(0.4, 0, 0.2, 1) forwards,
			           rnrd-ring-fade 300ms ease 1500ms forwards;
		}
		@keyframes rnrd-ring-fill {
			to { stroke-dashoffset: 0; }
		}
		@keyframes rnrd-ring-fade {
			to { opacity: 0; }
		}
		.rnrd-onboard__ring-tick {
			position: absolute;
			inset: 0;
			display: flex;
			align-items: center;
			justify-content: center;
			width: 100%;
			height: 100%;
			font-size: 32px;
			font-weight: 700;
			color: var(--rnrd-mint-600, #059669);
			background: var(--rnrd-mint-50, #ECFDF6);
			border-radius: 50%;
			opacity: 0;
			transform: scale(0.4);
			animation: rnrd-ring-tick-in 320ms cubic-bezier(0.2, 0, 0, 1) 1700ms forwards;
		}
		@keyframes rnrd-ring-tick-in {
			to { opacity: 1; transform: scale(1); }
		}
		@media (prefers-reduced-motion: reduce) {
			.rnrd-onboard__ring-stroke,
			.rnrd-onboard__ring-tick,
			.rnrd-onboard__ticks li {
				animation-duration: 0.001ms !important;
				animation-delay: 0ms !important;
			}
			.rnrd-onboard__ring-stroke { stroke-dashoffset: 0; opacity: 0; }
			.rnrd-onboard__ring-tick   { opacity: 1; transform: scale(1); }
			.rnrd-onboard__ticks li    { opacity: 1; transform: none; }
		}

		/* Done icon — legacy (preserved for any pre-1.1.2 deep-link to ?step=3
		   that the router may still redirect; kept for layout-safety). */
		.rnrd-onboard__done-icon {
			width: 56px;
			height: 56px;
			border-radius: 50%;
			background: var(--rnrd-mint-50, #ECFDF6);
			color: var(--rnrd-mint-600, #059669);
			font-size: 24px;
			font-weight: 700;
			display: flex;
			align-items: center;
			justify-content: center;
			margin: 0 0 24px;
		}
		</style>
		<?php
	}
}
