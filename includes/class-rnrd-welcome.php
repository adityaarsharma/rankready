<?php
/**
 * RankReady — Onboarding Wizard (3-step, progress bar).
 *
 * Step 1 — Brand Identity: site name, one-line summary, about paragraph.
 * Step 2 — AI features:     enable/disable Visibility + Content toggles.
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

	private const MENU_SLUG     = 'rankready-welcome';
	private const SETTINGS_SLUG = 'rankready-ai-llm-seo';
	private const FLAG_OPTION   = 'rnrd_welcome_completed';
	private const REDIRECT_KEY  = 'rnrd_welcome_redirect';

	/**
	 * HostMyBlog CRM incoming-webhook for the RankReady tips list. Contacted ONLY
	 * when the user ticks the opt-in box — see subscribe_email(). Empty by default:
	 * wire the HostMyBlog CRM endpoint here (or via the `rnrd_tips_webhook_url`
	 * filter). While empty, the opt-in is inert and NOTHING is sent anywhere, which
	 * keeps the readme disclosure accurate. Disclosed in readme.txt "External services".
	 */
	private const TIPS_WEBHOOK_URL  = '';
	private const TIPS_FLAG_OPTION  = 'rnrd_tips_optin_sent';

	// ── Lifecycle ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_menu',  array( self::class, 'register_page' ) );
		add_action( 'admin_init',  array( self::class, 'maybe_redirect' ) );
		add_action( 'admin_init',  array( self::class, 'maybe_handle_submit' ) );
	}

	/**
	 * Called from the activation hook in rankready.php.
	 * Sets a one-shot transient that triggers a redirect on the next admin load:
	 * fresh installs → onboarding wizard; re-activations → main settings page.
	 */
	public static function flag_activation(): void {
		$target = get_option( self::FLAG_OPTION ) ? 'settings' : 'welcome';
		set_transient( self::REDIRECT_KEY, $target, 30 );
	}

	/**
	 * Onboarding gate. Runs on admin_init. Three triggers, all one-time and
	 * gated by the FLAG_OPTION so the wizard is shown exactly once per site:
	 *
	 *   1. Activation → immediate redirect (one-shot transient): onboarding
	 *      when the wizard has never been completed/skipped, otherwise the
	 *      main RankReady settings page.
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
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
			exit;
		}

		// 1. Activation — one-shot transient → onboarding or settings page.
		$activation_target = get_transient( self::REDIRECT_KEY );
		if ( $activation_target ) {
			delete_transient( self::REDIRECT_KEY );
			if ( 'settings' === $activation_target ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) );
			} else {
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			}
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1;
		$step = max( 1, min( 3, $step ) );

		// Congratulations is only valid after step 2 wrote FLAG_OPTION.
		// A bookmark or guessed ?step=3 must not claim the site is set up.
		if ( self::MENU_SLUG === $page && 3 === $step && ! get_option( self::FLAG_OPTION ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&step=2' ) );
			exit;
		}

		// Already onboarded — no further automatic redirects.
		if ( get_option( self::FLAG_OPTION ) ) {
			return;
		}

		// 2. Existing/updated user opening RankReady for the first time without
		//    having onboarded — send them through the wizard once. Only when
		//    they land on the main RankReady page (never the wizard page itself,
		//    never any unrelated admin screen, never during the update process).
		if ( self::SETTINGS_SLUG === $page ) {
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
		// Hidden admin page under options.php — not shown in the sidebar
		add_submenu_page(
			'options.php',
			__( 'RankReady Setup', 'rankready-ai-llm-seo' ),
			__( 'RankReady Setup', 'rankready-ai-llm-seo' ),
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
		} elseif ( 2 === $posted_step ) {
			self::handle_step_2();
		}
	}

	private static function handle_step_1(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in maybe_handle_submit() before dispatch.
		$name    = sanitize_text_field( wp_unslash( $_POST['rnrd_llms_site_name'] ?? '' ) );
		$summary = sanitize_textarea_field( wp_unslash( $_POST['rnrd_llms_summary'] ?? '' ) );
		$about   = sanitize_textarea_field( wp_unslash( $_POST['rnrd_llms_about'] ?? '' ) );
		$terms   = sanitize_textarea_field( wp_unslash( $_POST['rnrd_brand_terms'] ?? '' ) );

		// Only write non-empty values so a blank skip doesn't wipe existing data.
		if ( '' !== $name )    update_option( RNRD_OPT_LLMS_SITE_NAME, $name );
		if ( '' !== $summary ) update_option( RNRD_OPT_LLMS_SUMMARY, $summary );
		if ( '' !== $about )   update_option( RNRD_OPT_LLMS_ABOUT, $about );
		if ( '' !== $terms )   update_option( RNRD_OPT_BRAND_TERMS, $terms );

		// Tips opt-in — only fires when the user ticked the box. Explicit consent only.
		self::maybe_subscribe_tips();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&step=2' ) );
		exit;
	}

	private static function handle_step_2(): void {
		self::apply_feature_toggles_from_post();

		self::seed_cpt_defaults_if_unset();
		self::apply_feature_dependents();

		delete_transient( 'rnrd_rewrite_ok' );

		update_option( self::FLAG_OPTION, time() );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&step=3' ) );
		exit;
	}

	/**
	 * Feature toggle definitions for step 2 (Visibility + Content).
	 *
	 * @return array<string, array{label:string, items:array<int, array{option:string, label:string, hint:string, default:bool, nested?:bool, depends?:string}>}>
	 */
	private static function get_feature_groups(): array {
		return array(
			'visibility' => array(
				'label' => __( 'AI Visibility', 'rankready-ai-llm-seo' ),
				'items' => array(
					array(
						'option'  => RNRD_OPT_ROBOTS_ENABLE,
						'label'   => __( 'LLM Crawler Access', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Allow or block named AI crawlers in robots.txt', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_CONTENT_SIGNALS_ENABLE,
						'label'   => __( 'Content Signals', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Tell AI engines how they may use your content for training, search, and input', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_LLMS_ENABLE,
						'label'   => __( 'LLMs.txt Generator', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Publish a site index AI engines can discover at /llms.txt', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_LLMS_FULL_ENABLE,
						'label'   => __( 'LLMs-full.txt (extended index)', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Deeper site corpus at /llms-full.txt — requires LLMs.txt; enable when you want the full index', 'rankready-ai-llm-seo' ),
						'default' => false,
						'nested'  => true,
						'depends' => RNRD_OPT_LLMS_ENABLE,
						'opt_in'  => true,
					),
					array(
						'option'  => RNRD_OPT_MD_ENABLE,
						'label'   => __( 'Markdown Endpoint', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Serve clean Markdown versions of your pages to AI agents', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_MCP_ENABLE,
						'label'   => __( 'WebMCP', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Expose /.well-known/mcp.json for Claude, Cursor, and VS Code — opt in when you are ready', 'rankready-ai-llm-seo' ),
						'default' => false,
						'opt_in'  => true,
					),
					array(
						'option'  => RNRD_OPT_OKF_ENABLE,
						'label'   => __( 'Open Knowledge Format', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Bundle structured knowledge for AI engines at /okf/', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
				),
			),
			'content'    => array(
				'label' => __( 'AI Content', 'rankready-ai-llm-seo' ),
				'items' => array(
					array(
						'option'  => RNRD_OPT_AUTHOR_ENABLE,
						'label'   => __( 'Author Box (E-E-A-T)', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Show author credentials and trust signals on posts', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_SCHEMA_ARTICLE,
						'label'   => __( 'Article schema', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Structured Article markup for AI citation', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_SCHEMA_FAQ,
						'label'   => __( 'FAQ schema', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'FAQPage markup when FAQs are present', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
					array(
						'option'  => RNRD_OPT_SCHEMA_SPEAKABLE,
						'label'   => __( 'Speakable schema', 'rankready-ai-llm-seo' ),
						'hint'    => __( 'Voice-assistant friendly speakable sections', 'rankready-ai-llm-seo' ),
						'default' => true,
					),
				),
			),
		);
	}

	/** @return list<string> */
	private static function get_feature_option_keys(): array {
		$keys = array();
		foreach ( self::get_feature_groups() as $group ) {
			foreach ( $group['items'] as $item ) {
				$keys[] = $item['option'];
			}
		}
		return $keys;
	}

	private static function is_option_on( string $option, bool $default_on = true ): bool {
		$current = get_option( $option, $default_on ? 'on' : 'off' );
		if ( false === $current || '__rnrd_unset__' === $current ) {
			return $default_on;
		}
		return 'on' === (string) $current;
	}

	/** True while the site has not completed or skipped the welcome wizard. */
	private static function is_first_wizard_pass(): bool {
		return ! get_option( self::FLAG_OPTION );
	}

	/**
	 * Step 2 checkbox state: recommended preset on first pass, saved values on re-run.
	 *
	 * @param array{option:string, default?:bool, opt_in?:bool} $item Feature toggle definition.
	 */
	private static function is_feature_checked_for_step2( array $item ): bool {
		if ( self::is_first_wizard_pass() ) {
			return empty( $item['opt_in'] ) && ! empty( $item['default'] );
		}
		return self::is_option_on( $item['option'], ! empty( $item['default'] ) );
	}

	private static function apply_feature_toggles_from_post(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in maybe_handle_submit() before dispatch.
		$posted = isset( $_POST['rnrd_feat'] ) && is_array( $_POST['rnrd_feat'] )
			? wp_unslash( $_POST['rnrd_feat'] )
			: array();

		foreach ( self::get_feature_option_keys() as $option ) {
			$on = ! empty( $posted[ $option ] );
			update_option( $option, $on ? 'on' : 'off' );
		}

		if ( ! self::is_option_on( RNRD_OPT_LLMS_ENABLE, false ) ) {
			update_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' );
		}
	}

	private static function seed_cpt_defaults_if_unset(): void {
		$cpt_defaults = array( 'post', 'page' );
		if ( post_type_exists( 'product' ) ) {
			$cpt_defaults[] = 'product';
		}

		$cpt_option_keys = array(
			RNRD_OPT_LLMS_POST_TYPES,
			RNRD_OPT_MD_POST_TYPES,
			RNRD_OPT_POST_TYPES,
			RNRD_OPT_FAQ_POST_TYPES,
			RNRD_OPT_OKF_POST_TYPES,
			RNRD_OPT_AUTHOR_POST_TYPES,
		);

		foreach ( $cpt_option_keys as $opt ) {
			$current = get_option( $opt, '__rnrd_unset__' );
			if ( '__rnrd_unset__' === $current ) {
				update_option( $opt, $cpt_defaults );
			}
		}
	}

	private static function apply_feature_dependents(): void {
		if ( self::is_option_on( RNRD_OPT_MD_ENABLE, false ) ) {
			update_option( RNRD_OPT_MD_HINT_DIV, 'on' );
			update_option( RNRD_OPT_MD_BOT_AUTO_SERVE, 'on' );
		}

		if ( self::is_option_on( RNRD_OPT_MCP_ENABLE, false ) ) {
			$mcp_safe = array(
				RNRD_OPT_MCP_EXPOSE_POSTS,
				RNRD_OPT_MCP_EXPOSE_PAGES,
				RNRD_OPT_MCP_EXPOSE_AUTHORS,
				RNRD_OPT_MCP_EXPOSE_TAXONOMIES,
				RNRD_OPT_MCP_EXPOSE_SITEMAP,
				RNRD_OPT_MCP_EXPOSE_MENUS,
				RNRD_OPT_MCP_EXPOSE_LLMS_TXT,
				RNRD_OPT_MCP_EXPOSE_RR_AI,
				RNRD_OPT_MCP_EXPOSE_FRESHNESS,
			);
			foreach ( $mcp_safe as $opt ) {
				update_option( $opt, 'on' );
			}
		}
	}

	/**
	 * Labels for the step 3 completion tick list (reflects saved options).
	 *
	 * @return list<string>
	 */
	private static function get_completion_items(): array {
		$items   = array( __( 'Brand Identity saved', 'rankready-ai-llm-seo' ) );
		$wc_active = post_type_exists( 'product' );

		if ( self::is_option_on( RNRD_OPT_LLMS_ENABLE, false ) ) {
			$items[] = __( '/llms.txt site index live', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_LLMS_FULL_ENABLE, false ) ) {
			$items[] = __( '/llms-full.txt extended index live', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_MD_ENABLE, false ) ) {
			$items[] = __( 'Per-post /post-slug.md endpoints live', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_ROBOTS_ENABLE, false ) ) {
			$items[] = __( 'robots.txt allowing 30+ AI crawlers', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_CONTENT_SIGNALS_ENABLE, false ) ) {
			$items[] = __( 'Content Signals in robots.txt', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_MCP_ENABLE, false ) ) {
			$items[] = __( '/.well-known/mcp.json (WebMCP manifest)', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_OKF_ENABLE, false ) ) {
			$items[] = __( '/okf/ Open Knowledge Format bundle', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_AUTHOR_ENABLE, false ) ) {
			$items[] = __( 'Author Box (E-E-A-T) enabled', 'rankready-ai-llm-seo' );
		}

		$schema_on = array();
		if ( self::is_option_on( RNRD_OPT_SCHEMA_ARTICLE, false ) ) {
			$schema_on[] = __( 'Article', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_SCHEMA_FAQ, false ) ) {
			$schema_on[] = __( 'FAQ', 'rankready-ai-llm-seo' );
		}
		if ( self::is_option_on( RNRD_OPT_SCHEMA_SPEAKABLE, false ) ) {
			$schema_on[] = __( 'Speakable', 'rankready-ai-llm-seo' );
		}
		if ( $schema_on ) {
			$items[] = sprintf(
				/* translators: %s: comma-separated schema types */
				__( 'Schema signals: %s', 'rankready-ai-llm-seo' ),
				implode( ', ', $schema_on )
			);
		}

		return $items;
	}

	/**
	 * Subscribe the user to the RankReady AI-SEO-tips list — ONLY when they ticked
	 * the onboarding opt-in box and supplied a valid email. Non-blocking so it never
	 * delays the redirect; guarded by a flag so re-running the wizard never
	 * re-subscribes. This is the single point where the plugin transmits data to an
	 * external service, and only on explicit opt-in. See readme.txt "External services".
	 */
	private static function maybe_subscribe_tips(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in maybe_handle_submit() before dispatch.
		if ( empty( $_POST['rnrd_onboard_tips_optin'] ) ) {
			return;
		}
		$email = sanitize_email( wp_unslash( $_POST['rnrd_onboard_email'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		self::subscribe_email( $email, 'RankReady Plugin Onboarding' );
	}

	/**
	 * Has the site already subscribed to the tips list? Once true, EVERY opt-in
	 * surface (onboarding + dashboard) hides itself — the user is never asked again.
	 * Single source of truth for the "never ask twice" rule.
	 */
	public static function tips_optin_done(): bool {
		return (bool) get_option( self::TIPS_FLAG_OPTION, false );
	}

	/**
	 * Subscribe one email to the RankReady tips list via the HostMyBlog FluentCRM
	 * incoming webhook. The single place the plugin transmits data to an external
	 * service. Non-blocking (never delays the page); one-shot (sets a flag so the
	 * opt-in box disappears everywhere and no email is ever re-POSTed). Returns
	 * false on an invalid email or when already subscribed.
	 *
	 * @param string $email  The address to subscribe (sanitised here).
	 * @param string $source Attribution stored on the FluentCRM contact.
	 */
	public static function subscribe_email( string $email, string $source = 'RankReady' ): bool {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) || self::tips_optin_done() ) {
			return false;
		}

		// No CRM endpoint wired → feature is inert; send nothing, claim nothing.
		$webhook = (string) apply_filters( 'rnrd_tips_webhook_url', self::TIPS_WEBHOOK_URL );
		if ( '' === $webhook ) {
			return false;
		}

		$user  = wp_get_current_user();
		$fname = ( $user && ! empty( $user->first_name ) ) ? $user->first_name : '';

		// Blocking, so we can tell whether the subscription actually happened.
		// This previously ran with 'blocking' => false and then set the permanent
		// one-shot flag regardless — a down webhook, DNS failure or firewalled
		// egress meant the user was told they were subscribed, nothing was sent,
		// and the flag guaranteed it was never retried. 5s on a deliberate opt-in
		// click is an acceptable trade for not silently losing the signup.
		$response = wp_remote_post(
			$webhook,
			array(
				'timeout'     => 5,
				'blocking'    => true,
				'redirection' => 0,
				'body'        => array(
					'email'      => $email,
					'first_name' => $fname,
					'source'     => $source,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			RNRD_Generator::log_error( 'TipsOptIn', 'Subscription request failed: ' . $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			RNRD_Generator::log_error( 'TipsOptIn', 'Subscription endpoint returned HTTP ' . $code . '.' );
			return false;
		}

		// One-shot flag — set only on a CONFIRMED send, so a failure can be retried.
		update_option( self::TIPS_FLAG_OPTION, time() );
		return true;
	}

	// ── Render ─────────────────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'rankready-ai-llm-seo' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 1;
		$step = max( 1, min( 3, $step ) );

		// Defensive: maybe_redirect() already bounces this on admin_init.
		// If we still reach here with no completion flag, do not render Done.
		if ( 3 === $step && ! get_option( self::FLAG_OPTION ) ) {
			$step = 2;
		}

		switch ( $step ) {
			case 3:
				self::render_step_3();
				break;
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
	private static function render_header( int $step, int $total = 3 ): void {
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
			<?php esc_html_e( 'Make your site readable by ChatGPT, Claude, Perplexity, and Google AI in about 2 minutes. Start with brand identity — these four fields feed every AI surface RankReady controls. The panel on the right shows exactly where each one is used.', 'rankready-ai-llm-seo' ); ?>
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

				<?php
				$rnrd_user       = wp_get_current_user();
				$rnrd_prefill_em = ( $rnrd_user && ! empty( $rnrd_user->user_email ) )
					? $rnrd_user->user_email
					: get_bloginfo( 'admin_email' );
				$rnrd_tips_done  = (bool) get_option( self::TIPS_FLAG_OPTION, false );
				?>
				<?php if ( ! $rnrd_tips_done ) : ?>
				<div class="rnrd-onboard__field rnrd-onboard__optin-field">
					<label class="rnrd-onboard__optin">
						<input type="checkbox" name="rnrd_onboard_tips_optin" value="1" class="rnrd-onboard__optin-check" />
						<span class="rnrd-onboard__optin-copy">
							<?php esc_html_e( 'Email me free AI SEO tips and RankReady updates', 'rankready-ai-llm-seo' ); ?>
						</span>
					</label>
					<input
						type="email"
						name="rnrd_onboard_email"
						value="<?php echo esc_attr( $rnrd_prefill_em ); ?>"
						class="rnrd-onboard__input rnrd-onboard__optin-email"
						placeholder="<?php esc_attr_e( 'you@example.com', 'rankready-ai-llm-seo' ); ?>"
						autocomplete="off"
						data-1p-ignore="true"
						data-lpignore="true"
					/>
					<p class="rnrd-onboard__hint rnrd-onboard__optin-hint">
						<?php esc_html_e( 'Practical AI SEO tips and tricks, plus RankReady product updates — straight to your inbox. No spam, unsubscribe anytime.', 'rankready-ai-llm-seo' ); ?>
					</p>
				</div>
				<?php endif; ?>

				<div class="rnrd-onboard__actions">
					<button type="submit" class="rnrd-onboard__btn rnrd-onboard__btn--primary">
						<?php esc_html_e( 'Continue →', 'rankready-ai-llm-seo' ); ?>
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

	// ── Step 2: AI feature toggles ─────────────────────────────────────────────

	private static function render_step_2(): void {
		self::render_header( 2 );
		?>
		<h1 class="rnrd-onboard__title">
			<?php esc_html_e( 'Choose your AI surfaces', 'rankready-ai-llm-seo' ); ?>
		</h1>
		<p class="rnrd-onboard__lede">
			<?php esc_html_e( 'Turn on the endpoints and signals you want live today. Recommended enables the core set — LLMs-full.txt and WebMCP stay off until you opt in.', 'rankready-ai-llm-seo' ); ?>
		</p>

		<form method="post" action="" class="rnrd-onboard__form" id="rnrd-onboard-features">
			<?php wp_nonce_field( 'rnrd_onboard_2', '_rnrd_onboard_nonce' ); ?>
			<input type="hidden" name="rnrd_onboard_step" value="2" />

			<div class="rnrd-onboard__feat-bulk">
				<button type="button" class="rnrd-onboard__feat-bulk-btn" data-rnrd-feat-preset="recommended">
					<?php esc_html_e( 'Enable recommended', 'rankready-ai-llm-seo' ); ?>
				</button>
				<span class="rnrd-onboard__feat-bulk-sep" aria-hidden="true">·</span>
				<button type="button" class="rnrd-onboard__feat-bulk-btn" data-rnrd-feat-preset="all-on">
					<?php esc_html_e( 'Turn all on', 'rankready-ai-llm-seo' ); ?>
				</button>
				<span class="rnrd-onboard__feat-bulk-sep" aria-hidden="true">·</span>
				<button type="button" class="rnrd-onboard__feat-bulk-btn" data-rnrd-feat-preset="all-off">
					<?php esc_html_e( 'Turn all off', 'rankready-ai-llm-seo' ); ?>
				</button>
			</div>

			<?php foreach ( self::get_feature_groups() as $group_key => $group ) : ?>
				<section class="rnrd-onboard__feat-group" aria-labelledby="rnrd-feat-<?php echo esc_attr( $group_key ); ?>">
					<h2 class="rnrd-onboard__feat-group-title" id="rnrd-feat-<?php echo esc_attr( $group_key ); ?>">
						<?php echo esc_html( $group['label'] ); ?>
					</h2>
					<ul class="rnrd-onboard__feat-list">
						<?php
						foreach ( $group['items'] as $item ) :
							$option   = $item['option'];
							$checked  = self::is_feature_checked_for_step2( $item );
							$nested   = ! empty( $item['nested'] );
							$depends  = isset( $item['depends'] ) ? (string) $item['depends'] : '';
							$opt_in   = ! empty( $item['opt_in'] );
							$row_class = 'rnrd-onboard__feat-row';
							if ( $nested ) {
								$row_class .= ' rnrd-onboard__feat-row--nested';
							}
							?>
							<li class="<?php echo esc_attr( $row_class ); ?>"
								<?php if ( $depends ) : ?>
									data-rnrd-feat-depends="<?php echo esc_attr( $depends ); ?>"
								<?php endif; ?>>
								<label class="rnrd-onboard__feat-label">
									<input
										type="checkbox"
										class="rnrd-onboard__feat-check"
										name="rnrd_feat[<?php echo esc_attr( $option ); ?>]"
										value="1"
										<?php checked( $checked ); ?>
										<?php if ( $depends ) : ?>
											data-rnrd-feat-parent="<?php echo esc_attr( $depends ); ?>"
										<?php endif; ?>
										<?php if ( ! empty( $item['default'] ) ) : ?>
											data-rnrd-feat-default="1"
										<?php endif; ?>
										<?php if ( $opt_in ) : ?>
											data-rnrd-feat-optin="1"
										<?php endif; ?>
									/>
									<span class="rnrd-onboard__feat-copy">
										<strong><?php echo esc_html( $item['label'] ); ?></strong>
										<span class="rnrd-onboard__feat-hint"><?php echo esc_html( $item['hint'] ); ?></span>
									</span>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>

			<p class="rnrd-onboard__feat-note">
				<?php esc_html_e( 'AI Summary and AI FAQ Generator need an API provider — set those up anytime under AI Content in RankReady.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<div class="rnrd-onboard__actions">
				<button type="submit" class="rnrd-onboard__btn rnrd-onboard__btn--primary">
					<?php esc_html_e( 'Apply & finish →', 'rankready-ai-llm-seo' ); ?>
				</button>
				<a class="rnrd-onboard__skip" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&step=1' ) ); ?>">
					<?php esc_html_e( '← Back to Brand Identity', 'rankready-ai-llm-seo' ); ?>
				</a>
			</div>
		</form>

		<script>
		(function(){
			var form = document.getElementById('rnrd-onboard-features');
			if (!form) return;
			var checks = form.querySelectorAll('.rnrd-onboard__feat-check');

			function syncDepends(){
				checks.forEach(function(el){
					var parentKey = el.getAttribute('data-rnrd-feat-parent');
					if (!parentKey) return;
					var parent = form.querySelector('[name="rnrd_feat[' + parentKey + ']"]');
					var row = el.closest('[data-rnrd-feat-depends]');
					var enabled = parent && parent.checked;
					el.disabled = !enabled;
					if (!enabled) el.checked = false;
					if (row) row.classList.toggle('rnrd-onboard__feat-row--disabled', !enabled);
				});
			}

			function applyPreset(preset){
				if (preset === 'all-off') {
					checks.forEach(function(el){ el.checked = false; });
					syncDepends();
					return;
				}
				if (preset === 'all-on') {
					checks.forEach(function(el){ el.checked = true; });
					syncDepends();
					return;
				}
				// recommended — idempotent snapshot: default-on items checked, opt-in items forced off
				checks.forEach(function(el){
					var optIn = el.getAttribute('data-rnrd-feat-optin') === '1';
					var defaultOn = el.getAttribute('data-rnrd-feat-default') === '1';
					el.checked = !optIn && defaultOn;
				});
				syncDepends();
			}

			checks.forEach(function(el){ el.addEventListener('change', syncDepends); });
			form.querySelectorAll('[data-rnrd-feat-preset]').forEach(function(btn){
				btn.addEventListener('click', function(){
					applyPreset(btn.getAttribute('data-rnrd-feat-preset'));
				});
			});
			syncDepends();
		})();
		</script>
		<?php
		self::render_footer();
	}

	// ── Step 3: Done (rotating ring → tick reveal) ───────────────────────────

	private static function render_step_3(): void {
		self::render_header( 3 );
		$site_name = (string) get_option( RNRD_OPT_LLMS_SITE_NAME, '' );
		if ( '' === $site_name ) {
			$site_name = get_bloginfo( 'name' );
		}
		$completion = self::get_completion_items();
		?>

		<div class="rnrd-onboard__done">
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
			echo esc_html( sprintf( __( 'Congratulations! %s is set up', 'rankready-ai-llm-seo' ), $site_name ) );
			?>
		</h1>
		<p class="rnrd-onboard__lede">
			<?php
			if ( count( $completion ) > 1 ) {
				esc_html_e( 'Here is what is live based on your choices. You can change any setting anytime in RankReady.', 'rankready-ai-llm-seo' );
			} else {
				esc_html_e( 'Brand identity is saved. Turn on AI surfaces anytime from the RankReady dashboard.', 'rankready-ai-llm-seo' );
			}
			?>
		</p>

		<ul class="rnrd-onboard__ticks">
			<?php
			foreach ( $completion as $i => $label ) :
				$delay_ms = 1800 + ( (int) $i * 140 );
				?>
				<li style="animation-delay:<?php echo esc_attr( (string) $delay_ms ); ?>ms">
					<span class="rnrd-onboard__tick">✓</span>
					<?php echo esc_html( $label ); ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="rnrd-onboard__actions rnrd-onboard__actions--centered">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=rankready-ai-llm-seo' ) ); ?>" class="rnrd-onboard__btn rnrd-onboard__btn--primary">
				<?php esc_html_e( 'Go to Dashboard →', 'rankready-ai-llm-seo' ); ?>
			</a>
		</div>
		</div><!-- /.rnrd-onboard__done -->

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

		/* Step 2 — feature toggles */
		.rnrd-onboard__feat-bulk {
			display: flex;
			align-items: center;
			gap: 8px;
			margin: 0 0 20px;
			font-size: 13px;
		}
		.rnrd-onboard__feat-bulk-btn {
			background: none;
			border: 0;
			padding: 0;
			font: inherit;
			color: var(--rnrd-mint-800, #064E3B);
			cursor: pointer;
			text-decoration: underline;
			text-underline-offset: 2px;
		}
		.rnrd-onboard__feat-bulk-btn:hover {
			color: var(--rnrd-text-primary, #0F1411);
		}
		.rnrd-onboard__feat-bulk-sep {
			color: var(--rnrd-text-tertiary, #6B716D);
		}
		.rnrd-onboard__feat-group {
			margin-bottom: 24px;
		}
		.rnrd-onboard__feat-group-title {
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0.08em;
			text-transform: uppercase;
			color: var(--rnrd-text-tertiary, #6B716D);
			margin: 0 0 10px;
		}
		.rnrd-onboard__feat-list {
			list-style: none;
			margin: 0;
			padding: 0;
			display: flex;
			flex-direction: column;
			gap: 0;
			border: 1px solid var(--rnrd-border-subtle, #ECEDE7);
			border-radius: 12px;
			overflow: hidden;
		}
		.rnrd-onboard__feat-row {
			border-bottom: 1px solid var(--rnrd-border-subtle, #ECEDE7);
		}
		.rnrd-onboard__feat-row:last-child {
			border-bottom: 0;
		}
		.rnrd-onboard__feat-row--nested {
			padding-left: 32px;
		}
		.rnrd-onboard__feat-row--disabled {
			opacity: 0.45;
		}
		.rnrd-onboard__feat-label {
			display: flex;
			align-items: flex-start;
			gap: 12px;
			padding: 8px 16px;
			cursor: pointer;
			margin: 0;
		}
		.rnrd-onboard__feat-label input[type=checkbox],
		.rnrd-onboard__feat-label input[type=radio] {
			margin: 0.25rem 0.25rem 0 0;
		}
		.rnrd-onboard__feat-check {
			flex-shrink: 0;
			width: 18px;
			height: 18px;
			margin: 2px 0 0;
			accent-color: var(--rnrd-mint-600, #10B981);
		}
		.rnrd-onboard__feat-copy {
			display: flex;
			flex-direction: column;
			gap: 2px;
			min-width: 0;
		}
		.rnrd-onboard__feat-copy strong {
			font-size: 14px;
			font-weight: 600;
			color: var(--rnrd-text-primary, #0F1411);
			line-height: 1.35;
		}
		.rnrd-onboard__feat-hint {
			font-size: 12px;
			line-height: 1.5;
			color: var(--rnrd-text-secondary, #3D423F);
			font-weight: 400;
		}
		.rnrd-onboard__feat-note {
			font-size: 12px;
			line-height: 1.55;
			color: var(--rnrd-text-tertiary, #6B716D);
			margin: 0 0 24px;
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

		/* Step 3 — centered completion layout */
		.rnrd-onboard__done {
			max-width: 480px;
			margin: 0 auto;
			text-align: center;
		}
		.rnrd-onboard__done .rnrd-onboard__progress-ring {
			margin-left: auto;
			margin-right: auto;
		}
		.rnrd-onboard__done .rnrd-onboard__title,
		.rnrd-onboard__done .rnrd-onboard__lede {
			text-align: center;
		}
		.rnrd-onboard__done .rnrd-onboard__ticks {
			display: inline-flex;
			flex-direction: column;
			align-items: flex-start;
			text-align: left;
			margin-left: auto;
			margin-right: auto;
		}
		.rnrd-onboard__done .rnrd-onboard__actions {
			justify-content: center;
		}

		/* v1.1.2 — Rotating progress ring → green tick (step 3 hero).
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

		/* ── Tips opt-in — mint-tinted, on-brand friendly card ─────────── */
		.rnrd-onboard__optin-field {
			margin-top: 4px;
			padding: 18px 20px;
			border: 1px solid var(--rnrd-mint-200, #B8F2DC);
			border-radius: 12px;
			background: var(--rnrd-mint-50, #ECFDF6);
		}
		.rnrd-onboard__optin {
			display: flex;
			align-items: flex-start;   /* top-align so multi-line labels don't float the box */
			gap: 12px;
			cursor: pointer;
			font-weight: 600;
			font-size: 14px;
			line-height: 1.45;
			color: var(--rnrd-text-primary, #0F1411);
			-webkit-user-select: none;
			        user-select: none;
		}
		/* Custom mint checkbox — hard-set every property WP admin's checkbox
		 * stylesheet could leak into (appearance, border, background-image,
		 * box-shadow, size), same defence as the step-2 tick. */
		.rnrd-onboard__optin-check {
			-webkit-appearance: none !important;
			        appearance: none !important;
			width: 20px !important;
			height: 20px !important;
			min-width: 20px;
			max-width: 20px;
			flex-shrink: 0;
			margin: 1px 0 0 !important;   /* optical-center against the first label line */
			padding: 0 !important;
			border: 1.5px solid var(--rnrd-border-strong, #B0B3AB) !important;
			border-radius: 6px !important;
			background: var(--rnrd-bg-surface, #fff) !important;
			background-image: none !important;
			box-shadow: none !important;
			cursor: pointer;
			position: relative;
			transition: border-color 0.15s ease, background 0.15s ease;
		}
		.rnrd-onboard__optin-check:hover {
			border-color: var(--rnrd-mint-500, #59F7C2) !important;
		}
		.rnrd-onboard__optin-check:checked {
			background: var(--rnrd-mint-500, #59F7C2) !important;
			border-color: var(--rnrd-mint-500, #59F7C2) !important;
		}
		.rnrd-onboard__optin-check:checked::after {
			content: "";
			position: absolute;
			left: 6px;
			top: 2px;
			width: 5px;
			height: 10px;
			border: solid var(--rnrd-text-on-mint, #0A3D2B);
			border-width: 0 2px 2px 0;
			transform: rotate(45deg);
		}
		/* Kill WP admin's native blue dashicon check (forms.css :checked::before)
		 * so ONLY our custom ::after check renders — prevents the "double checkbox". */
		.rnrd-onboard__optin-check::before {
			content: none !important;
			display: none !important;
			background: none !important;
		}
		.rnrd-onboard__optin-check:focus,
		.rnrd-onboard__optin-check:focus-visible {
			outline: none !important;
			box-shadow: 0 0 0 3px rgba(89, 247, 194, 0.30) !important;
		}
		.rnrd-onboard__optin-email {
			margin-top: 14px;
			background: var(--rnrd-bg-surface, #fff);
		}
		.rnrd-onboard__optin-hint {
			margin-top: 10px !important;
			margin-bottom: 0 !important;
		}
		</style>
		<?php
	}
}
