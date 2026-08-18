<?php
/**
 * LLMs.txt generator — full spec compliance per llmstxt.org.
 *
 * Generates /llms.txt and optionally /llms-full.txt.
 * Uses WordPress rewrite rules + transient caching.
 *
 * Spec requirements:
 * - H1 with site name (required)
 * - Blockquote summary (recommended)
 * - Markdown body with site info
 * - H2-delimited sections with file lists: - [title](url): description
 * - Optional section for secondary content
 *
 * llms-full.txt format (per real-world implementations like Lovable/Mintlify):
 * - Each page starts with: # Page Title\nSource: URL
 * - Full content inlined as clean markdown below
 * - No XML wrappers, just concatenated markdown pages
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Llms_Txt {

	/**
	 * Canonical list of AI/LLM crawler user-agents — single source of truth used
	 * by both the robots.txt block (public frontend) and the AI Crawlers admin UI.
	 * It lives in this frontend-loaded class so the public robots.txt / llms.txt
	 * fallback never has to autoload the 288KB RNRD_Admin class. RNRD_Admin's
	 * get_llm_crawlers() delegates here for backward compatibility.
	 *
	 * @return array<string,array{0:string,1:string}> user-agent => [vendor, purpose]
	 */
	public static function get_llm_crawlers(): array {
		return array(
			// ── OpenAI ────────────────────────────────────────────────────────
			'GPTBot'              => array( 'OpenAI', 'GPT model training data' ),
			'ChatGPT-User'        => array( 'OpenAI', 'ChatGPT browse mode (user-initiated)' ),
			'OAI-SearchBot'       => array( 'OpenAI', 'ChatGPT search results' ),
			// ── Anthropic ─────────────────────────────────────────────────────
			'ClaudeBot'           => array( 'Anthropic', 'Claude AI retrieval + training' ),
			'anthropic-ai'        => array( 'Anthropic', 'Anthropic training data collection' ),
			'Claude-Web'          => array( 'Anthropic', 'Claude AI (legacy identifier)' ),
			// ── Google ────────────────────────────────────────────────────────
			'Google-Extended'     => array( 'Google', 'Gemini AI training (does NOT affect search ranking)' ),
			'GoogleOther'         => array( 'Google', 'Google R&D crawling (non-search)' ),
			// ── Apple ─────────────────────────────────────────────────────────
			'Applebot-Extended'   => array( 'Apple', 'Apple Intelligence / Siri AI features' ),
			// ── Microsoft ─────────────────────────────────────────────────────
			'Bingbot'             => array( 'Microsoft', 'Bing Search + Copilot (shared UA)' ),
			// ── Perplexity ────────────────────────────────────────────────────
			'PerplexityBot'       => array( 'Perplexity', 'Perplexity AI answer engine' ),
			// ── Meta ──────────────────────────────────────────────────────────
			'Meta-ExternalAgent'  => array( 'Meta', 'Meta AI / Llama training' ),
			'Meta-ExternalFetcher' => array( 'Meta', 'Meta AI real-time retrieval' ),
			'FacebookBot'         => array( 'Meta', 'Facebook/Meta content crawling' ),
			// ── Mistral ───────────────────────────────────────────────────────
			'MistralAI-User'      => array( 'Mistral', 'Le Chat real-time browsing' ),
			// ── ByteDance ─────────────────────────────────────────────────────
			'Bytespider'          => array( 'ByteDance', 'TikTok / ByteDance AI' ),
			// ── Amazon ────────────────────────────────────────────────────────
			'Amazonbot'           => array( 'Amazon', 'Alexa AI / Amazon' ),
			// ── Cohere ────────────────────────────────────────────────────────
			'cohere-ai'           => array( 'Cohere', 'Cohere AI RAG & enterprise' ),
			// ── AI Search Engines ─────────────────────────────────────────────
			'DuckAssistBot'       => array( 'DuckDuckGo', 'DuckDuckGo AI Assist' ),
			'YouBot'              => array( 'You.com', 'You.com AI search' ),
			'PhindBot'            => array( 'Phind', 'Phind AI search for developers' ),
			// ── Training / Dataset Crawlers ────────────────────────────────────
			'CCBot'               => array( 'Common Crawl', 'Open dataset used by many LLMs' ),
			'AI2Bot'              => array( 'Allen Institute', 'AI2 research crawler' ),
			'Diffbot'             => array( 'Diffbot', 'Diffbot AI extraction' ),
			'Omgilibot'           => array( 'Webz.io', 'AI content aggregation' ),
			'PetalBot'            => array( 'Huawei', 'Huawei search & AI data' ),
			'Brightbot'           => array( 'BrightEdge', 'AI SEO data crawling' ),
			'magpie-crawler'      => array( 'Magpie', 'AI data collection' ),
			'DataForSeoBot'       => array( 'DataForSEO', 'SEO data with AI uses' ),
		);
	}

	public static function init(): void {
		add_action( 'init',             array( self::class, 'add_rewrite_rules' ) );
		// v1.2.0-rc.2 — priority 1 so page builders (Bricks, Elementor Pro
		// templates) can't intercept /llms.txt + /llms-full.txt before we
		// respond. Our handler exit()s when matched.
		add_action( 'template_redirect', array( self::class, 'handle_request' ), 1 );

		// v1.2.0-rc.2 — tell every WP page-cache plugin to never cache the
		// llms.txt endpoints. RankReady already caches the response in a
		// 1-hour transient and sets Cache-Control: public, max-age=3600.
		// Layered page-cache would stomp the dynamic header.
		add_action( 'init', array( self::class, 'register_cache_exclusions' ), 11 );

		// Prevent WordPress from adding trailing slash to .txt URLs.
		add_filter( 'redirect_canonical', array( self::class, 'prevent_txt_trailing_slash' ), 10, 2 );

		// Rewrite flush: pre_update_option_* busts rnrd_rewrite_ok; admin_init self-heal flushes.

		// Bust cache when posts are published/updated/deleted.
		add_action( 'transition_post_status', array( self::class, 'bust_cache_on_status_change' ), 10, 3 );
		add_action( 'deleted_post',           array( self::class, 'bust_cache' ) );

		// rc.16 audit fix C1 — bust transient + purge CDN/page-cache on EVERY
		// option that mutates llms.txt output. Without this, brand identity
		// edits stay invisible for up to 1 hour (default TTL) and CDN/page-cache
		// layers serve the prior version even longer. (slift.co user report.)
		$busters = array(
			RNRD_OPT_LLMS_ENABLE,           RNRD_OPT_LLMS_FULL_ENABLE,
			RNRD_OPT_LLMS_SITE_NAME,        RNRD_OPT_LLMS_SUMMARY,
			RNRD_OPT_LLMS_ABOUT,            RNRD_OPT_BRAND_TERMS,
			RNRD_OPT_LLMS_POST_TYPES,       RNRD_OPT_LLMS_MAX_POSTS,
			RNRD_OPT_LLMS_EXCLUDE_CATS,     RNRD_OPT_LLMS_EXCLUDE_TAGS,
			RNRD_OPT_LLMS_SHOW_CATEGORIES,  RNRD_OPT_LLMS_CACHE_TTL,
		);
		foreach ( $busters as $opt ) {
			add_action( 'update_option_' . $opt, array( self::class, 'bust_cache_and_purge_cdn' ) );
		}

		// Add llms.txt reference to robots.txt so AI crawlers discover it.
		// rc.8 fix: bump priority to PHP_INT_MAX so RankReady's block
		// survives SEOPress / Yoast / RankMath overwriting the entire
		// robots_txt output at high priority. We APPEND to whatever
		// the earlier filter left in $output — we don't replace.
		add_filter( 'robots_txt', array( self::class, 'add_to_robots_txt' ), PHP_INT_MAX, 2 );

		// Emit Link: headers and <link> tags for AI discovery on every front-end page.
		add_action( 'send_headers', array( self::class, 'add_discovery_link_headers' ) );
		add_action( 'wp_head',      array( self::class, 'add_discovery_link_tags' ) );

		// Sync to physical robots.txt when settings change.
		add_action( 'update_option_' . RNRD_OPT_ROBOTS_ENABLE,             array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RNRD_OPT_ROBOTS_CRAWLERS,           array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RNRD_OPT_ROBOTS_BLOCKED,            array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RNRD_OPT_LLMS_ENABLE,               array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RNRD_OPT_LLMS_FULL_ENABLE,          array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RNRD_OPT_MD_ENABLE,                 array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RNRD_OPT_CONTENT_SIGNALS_ENABLE,   array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN, array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RNRD_OPT_CONTENT_SIGNALS_SEARCH,   array( self::class, 'sync_physical_robots_txt' ) );
		add_action( 'update_option_' . RNRD_OPT_CONTENT_SIGNALS_AI_INPUT, array( self::class, 'sync_physical_robots_txt' ) );

		// v1.2.1 — Derive the two legacy crawler arrays whenever the Allow /
		// Default / Block map changes. Registered here rather than in RNRD_Admin
		// so it fires for ANY writer — admin form post, WP-CLI, REST — not only
		// an admin request. Priority 5 so the arrays land before anything at the
		// default priority reads them. Both hooks are required: the first save
		// on an existing install ADDS the option, later saves UPDATE it, and the
		// two fire with different argument signatures.
		add_action(
			'add_option_' . RNRD_OPT_ROBOTS_MODE,
			static function ( $option, $value ): void {
				self::apply_robots_mode( (array) $value );
			},
			5,
			2
		);
		add_action(
			'update_option_' . RNRD_OPT_ROBOTS_MODE,
			static function ( $old_value, $value ): void {
				self::apply_robots_mode( (array) $value );
			},
			5,
			2
		);

		// v1.2.1 — Keep the mirrored search/social group fresh.
		//
		// That group restates the site's `User-agent: *` rules, so it goes STALE
		// if those rules change outside RankReady — a Rank Math / Yoast robots
		// editor save, or a hand-edited physical file. None of the update_option_
		// hooks above fire for that, and the version-bump re-sync in rankready.php
		// only runs on upgrade. A stale mirror means Googlebot would keep obeying
		// the OLD rules, so a newly added Disallow would not reach it.
		//
		// Re-check hourly. sync_physical_robots_txt() diffs the rendered output
		// before writing, so when nothing has changed this costs one file read and
		// performs no write, no cache purge. admin_init is admin-only, so this
		// never touches a frontend request.
		add_action(
			'admin_init',
			static function (): void {
				if ( false !== get_transient( 'rnrd_robots_mirror_check' ) ) {
					return;
				}
				set_transient( 'rnrd_robots_mirror_check', 1, HOUR_IN_SECONDS );
				self::sync_physical_robots_txt();
			},
			20
		);

		// v1.0.1 — robots.txt + mcp.json + .md endpoints also flush every cache
		// layer on the option changes that affect them. Without this, CDNs serve
		// stale robots.txt / mcp.json for hours after the user changes settings.
		// Previously only /llms.txt + /llms-full.txt were CDN-purged; we now
		// purge the full set whenever ANY agent-affecting option changes.
		$cdn_purge_triggers = array(
			RNRD_OPT_ROBOTS_ENABLE,           RNRD_OPT_ROBOTS_CRAWLERS,
			RNRD_OPT_ROBOTS_BLOCKED,
			RNRD_OPT_MD_ENABLE,
			RNRD_OPT_CONTENT_SIGNALS_ENABLE,  RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN,
			RNRD_OPT_CONTENT_SIGNALS_SEARCH,  RNRD_OPT_CONTENT_SIGNALS_AI_INPUT,
		);
		foreach ( $cdn_purge_triggers as $opt ) {
			add_action( 'update_option_' . $opt, array( self::class, 'bust_cache_and_purge_cdn' ) );
		}
	}

	/**
	 * Prevent WordPress from adding a trailing slash to /llms.txt and /llms-full.txt.
	 *
	 * WordPress canonical redirect turns /llms.txt into /llms.txt/ by default,
	 * causing a 301 loop. This filter stops that.
	 */
	public static function prevent_txt_trailing_slash( $redirect_url, $requested_url ) {
		if ( preg_match( '/\/llms(?:-full)?\.txt\/?$/i', $requested_url ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Append llms.txt and llms-full.txt references to WordPress robots.txt.
	 *
	 * This tells AI crawlers where to find structured content about the site.
	 * Similar to how Sitemap is referenced in robots.txt.
	 */
	public static function add_to_robots_txt( string $output, bool $public ): string {
		// Note: do NOT bail out when ! $public.
		//
		// When the WP "Discourage search engines" toggle is on, $public is
		// false and WordPress emits `Disallow: /`. We previously bailed out
		// here, which silently dropped Content Signals output too. Content
		// Signals (ai-train / search / ai-input) is an INDEPENDENT
		// declaration about AI training, not about search-engine indexing —
		// users may legitimately want SEO blocked but AI allowed (or
		// vice-versa). The block below only emits content the user has
		// explicitly enabled, so always running it is safe.

		// Don't duplicate if RankReady block already present in $output
		// (defends against double-filtering edge cases).
		if ( false !== stripos( $output, 'RankReady' ) ) {
			return $output;
		}

		// $output is WP's generated robots.txt including other plugins' additions
		// (we run at PHP_INT_MAX, so this is the final text) and does not yet
		// contain our block — safe to mirror the `*` group from.
		$block = self::generate_robots_block( $output );
		if ( empty( trim( $block ) ) ) {
			return $output;
		}

		return $output . $block;
	}

	/**
	 * Emit Link: HTTP response headers for AI agent discovery on all front-end pages.
	 *
	 * These are checked by isitagentready.com and similar agent-readiness scanners
	 * to verify the site exposes its LLM-readable endpoints via standard headers.
	 */
	public static function add_discovery_link_headers(): void {
		if ( is_admin() || defined( 'REST_REQUEST' ) ) {
			return;
		}

		if ( 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			header( 'Link: <' . esc_url( home_url( '/llms.txt' ) ) . '>; rel="llms-txt"', false );
		}

		if ( 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			header( 'Link: <' . esc_url( home_url( '/llms-full.txt' ) ) . '>; rel="llms-full-txt"', false );
		}

		// Archives / search / other listings. Front and Posts-page indexes emit
		// their own alternates from RNRD_Markdown. Emitting /index.md here would
		// duplicate the front and mis-label the blog index.
		if ( 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) && 'on' === get_option( RNRD_OPT_MD_HOME_ENABLE, 'on' ) && ! is_singular() && ! is_front_page() && ! is_home() ) {
			header( 'Link: <' . esc_url( home_url( '/index.md' ) ) . '>; rel="alternate"; type="text/markdown"', false );
		}

		// rc.16 audit — sitemap Link header removed. Sitemap discovery belongs
		// in robots.txt per Google Search Central docs, NOT in HTTP Link
		// headers for LLM/agent discovery.

		// rc.16 — emit hreflang alternate Link headers for each detected
		// multilingual plugin so agents can discover language variants.
		// Per-language /es/llms.txt generation ships in v1.3 Pro.
		$ml = self::detect_multilingual();
		if ( ! empty( $ml ) && 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			$emitted = array();
			foreach ( $ml as $set ) {
				foreach ( (array) $set['langs'] as $code ) {
					$code = strtolower( (string) $code );
					if ( '' === $code || isset( $emitted[ $code ] ) ) {
						continue;
					}
					$emitted[ $code ] = true;
					$lang_url         = home_url( '/' . $code . '/llms.txt' );
					header( 'Link: <' . esc_url( $lang_url ) . '>; rel="alternate"; hreflang="' . esc_attr( $code ) . '"', false );
				}
			}
		}
	}

	/**
	 * Emit <link> tags in <head> for AI agent discovery.
	 *
	 * Mirrors the Link: headers as HTML meta-equivalents so HTML parsers
	 * (and tools that don't inspect response headers) can also discover endpoints.
	 */
	public static function add_discovery_link_tags(): void {
		if ( 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			echo '<link rel="llms-txt" type="text/plain" href="' . esc_url( home_url( '/llms.txt' ) ) . '" />' . "\n";
		}

		if ( 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			echo '<link rel="llms-full-txt" type="text/plain" href="' . esc_url( home_url( '/llms-full.txt' ) ) . '" />' . "\n";
		}
	}

	/**
	 * Generate the RankReady robots.txt block as a standalone string.
	 *
	 * Used both by the `robots_txt` filter (virtual) and physical file sync.
	 */
	/**
	 * Current Allow / Default / Block state for every known crawler.
	 *
	 * Derived from the two legacy arrays, so installs that have never saved the
	 * radio render correctly with no migration step and no DB write. Block wins
	 * when a crawler somehow appears in both, matching the precedence
	 * generate_robots_block() already applies.
	 *
	 * Lives here, not in RNRD_Admin, because the mapping is domain logic rather
	 * than UI: this class is always loaded, so the derive hooks below fire for
	 * WP-CLI and REST writes too, not only for an admin form post.
	 *
	 * @since 1.2.1
	 * @return array<string,string> user-agent => 'allow'|'block'|'default'
	 */
	public static function get_robots_mode(): array {
		$crawlers = array_keys( self::get_llm_crawlers() );
		$allow    = (array) get_option( RNRD_OPT_ROBOTS_CRAWLERS, $crawlers );
		$blocked  = (array) get_option( RNRD_OPT_ROBOTS_BLOCKED, array() );

		$mode = array();
		foreach ( $crawlers as $ua ) {
			if ( in_array( $ua, $blocked, true ) ) {
				$mode[ $ua ] = 'block';
			} elseif ( in_array( $ua, $allow, true ) ) {
				$mode[ $ua ] = 'allow';
			} else {
				$mode[ $ua ] = 'default';
			}
		}
		return $mode;
	}

	/**
	 * Write the two legacy arrays from a mode map.
	 *
	 * RNRD_OPT_ROBOTS_CRAWLERS and RNRD_OPT_ROBOTS_BLOCKED stay the source of
	 * truth for robots.txt output, so nothing downstream changed. Writing them
	 * here also fires their existing update_option_ hooks, which re-sync the
	 * physical robots.txt exactly as before.
	 *
	 * @since 1.2.1
	 * @param array $mode user-agent => 'allow'|'block'|'default'
	 */
	public static function apply_robots_mode( array $mode ): void {
		$allow   = array();
		$blocked = array();
		foreach ( array_keys( self::get_llm_crawlers() ) as $ua ) {
			$state = isset( $mode[ $ua ] ) ? (string) $mode[ $ua ] : 'default';
			if ( 'allow' === $state ) {
				$allow[] = $ua;
			} elseif ( 'block' === $state ) {
				$blocked[] = $ua;
			}
			// 'default' — in neither list, so RankReady writes nothing for it
			// and the crawler follows the site's existing robots.txt rules.
		}
		update_option( RNRD_OPT_ROBOTS_CRAWLERS, $allow );
		update_option( RNRD_OPT_ROBOTS_BLOCKED, $blocked );
	}

	/**
	 * Search / social crawlers that are NOT AI crawlers.
	 *
	 * These deliberately live outside get_llm_crawlers(): they must never join
	 * the permissive `Allow: /` group, because a crawler obeys only the most
	 * specific matching group (RFC 9309 §2.2.1) — putting Googlebot there would
	 * detach it from the site's own `User-agent: *` rules and let it crawl
	 * /checkout/, /wp-admin/ and search pages.
	 *
	 * Instead they get their own group that MIRRORS the site's `*` rules, so
	 * they are explicitly named (which readiness scanners look for) while their
	 * effective permissions stay exactly what the site already granted them.
	 *
	 * @since 1.2.1
	 */
	public static function get_search_social_crawlers(): array {
		return array(
			'Googlebot'           => array( 'Google', 'Google Search + AI Overviews' ),
			'FacebookExternalHit' => array( 'Meta', 'Facebook / WhatsApp link previews' ),
		);
	}

	/**
	 * Extract Allow/Disallow rules from every `User-agent: *` group in a robots.txt body.
	 *
	 * Used to mirror the site's own rules into the search/social group. Callers
	 * MUST pass content with the RankReady block already stripped, otherwise the
	 * mirrored rules would be re-ingested on every sync and compound.
	 *
	 * @since 1.2.1
	 * @param string $robots Raw robots.txt body, RankReady block removed.
	 * @return array{allow:string[],disallow:string[]}
	 */
	public static function parse_wildcard_group_rules( string $robots ): array {
		$allow    = array();
		$disallow = array();
		$in_group = false;
		$reading_ua = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $robots ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || ! preg_match( '/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m ) ) {
				continue;
			}
			$field = strtolower( $m[1] );
			$value = trim( $m[2] );

			if ( 'user-agent' === $field ) {
				// A User-agent line following rule lines opens a NEW group.
				if ( ! $reading_ua ) {
					$in_group = false;
				}
				$reading_ua = true;
				if ( '*' === $value ) {
					$in_group = true;
				}
				continue;
			}
			$reading_ua = false;
			if ( ! $in_group || '' === $value ) {
				continue;
			}
			if ( 'disallow' === $field ) {
				// Never mirror a site-wide block. WordPress emits `Disallow: /`
				// when "Discourage search engines" is on, and that is a
				// search-indexing decision we must not silently widen.
				if ( '/' === $value ) {
					continue;
				}
				$disallow[ $value ] = true;
			} elseif ( 'allow' === $field ) {
				$allow[ $value ] = true;
			}
		}

		return array(
			'allow'    => array_keys( $allow ),
			'disallow' => array_keys( $disallow ),
		);
	}

	public static function generate_robots_block( ?string $surrounding_robots = null ): string {
		$llms_on    = 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$full_on    = 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' );
		$md_on      = 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' );
		$robots_on  = 'on' === get_option( RNRD_OPT_ROBOTS_ENABLE, 'on' );
		$signals_on = 'on' === get_option( RNRD_OPT_CONTENT_SIGNALS_ENABLE, 'off' );

		if ( ! $llms_on && ! $md_on && ! $robots_on && ! $signals_on ) {
			return '';
		}

		$allow_paths = array();
		if ( $llms_on ) {
			$allow_paths[] = '/llms.txt';
		}
		if ( $llms_on && $full_on ) {
			$allow_paths[] = '/llms-full.txt';
		}
		if ( $md_on ) {
			$allow_paths[] = '/*.md$';
		}

		// rc.11 — Use BEGIN/END functional markers (like `# BEGIN WordPress`)
		// instead of branded header. Visible header is unbranded; the BEGIN
		// marker is a technical identifier so sync_physical_robots_txt() can
		// reliably locate + replace our block on re-saves.
		$block  = "\n# BEGIN RankReady\n";
		$block .= "# LLM & AI Crawler Rules\n";

		// Brand Terms — canonical names as a comment. Some AI crawlers
		// (notably PerplexityBot and SearchBot variants) ingest robots.txt
		// comments alongside directives for entity recognition.
		$brand_terms = self::get_brand_terms_list();
		if ( ! empty( $brand_terms ) ) {
			$block .= '# Brand: ' . implode( ', ', $brand_terms ) . "\n";
		}

		// Stack all User-agent lines in one block — per robots.txt spec,
		// grouped User-agent lines share the same Allow/Disallow rules.
		if ( $robots_on ) {
			// Read the stored crawler list WITHOUT eagerly evaluating the default —
			// PHP evaluates default args eagerly, so referencing RNRD_Admin here
			// would autoload the 288KB admin class on every public robots.txt /
			// llms.txt request (the AI-crawler-heavy URLs). The option is seeded on
			// activation, so the RNRD_Admin fallback effectively never runs here.
			$enabled_crawlers = get_option( RNRD_OPT_ROBOTS_CRAWLERS, null );
			if ( null === $enabled_crawlers ) {
				$enabled_crawlers = array_keys( self::get_llm_crawlers() );
			}
			$enabled_crawlers = (array) $enabled_crawlers;

			// v1.2.1 - hard-block list (Disallow). Default empty so existing installs
			// are byte-identical; a crawler in BOTH lists is blocked (block wins).
			$blocked_crawlers = (array) get_option( RNRD_OPT_ROBOTS_BLOCKED, array() );
			$enabled_crawlers = array_values( array_diff( $enabled_crawlers, $blocked_crawlers ) );

			// Blocked group first - its own User-agent group with Disallow: /.
			if ( ! empty( $blocked_crawlers ) ) {
				foreach ( $blocked_crawlers as $crawler ) {
					$block .= 'User-agent: ' . sanitize_text_field( $crawler ) . "\n";
				}
				$block .= "Disallow: /\n\n";
			}

			// Allowed group - Allow: / plus the RankReady endpoint paths.
			if ( ! empty( $enabled_crawlers ) ) {
				foreach ( $enabled_crawlers as $crawler ) {
					$block .= 'User-agent: ' . sanitize_text_field( $crawler ) . "\n";
				}
				$block .= "Allow: /\n";
				foreach ( $allow_paths as $path ) {
					$block .= "Allow: {$path}\n";
				}
				$block .= "\n";
			}

			// v1.2.1 — Search / social crawlers, named explicitly but NOT widened.
			//
			// Googlebot and FacebookExternalHit are not AI crawlers, so they must
			// never join the `Allow: /` group above — a crawler obeys only its
			// most specific matching group, so that would detach them from the
			// site's own `User-agent: *` rules and expose /checkout/, /wp-admin/
			// and search pages. Instead we give them their own group that RESTATES
			// the site's existing `*` rules verbatim. Net permissions: unchanged.
			// What changes is that they are now explicitly named, which is what
			// readiness scanners check for.
			//
			// $surrounding_robots must already have the RankReady block stripped
			// (both callers do this) or we would re-ingest our own mirror.
			if ( null !== $surrounding_robots && '' !== trim( $surrounding_robots ) ) {
				$mirror = self::parse_wildcard_group_rules( $surrounding_robots );

				$rules = array();
				foreach ( $mirror['disallow'] as $path ) {
					$rules[] = array( 'Disallow', $path );
				}
				foreach ( $mirror['allow'] as $path ) {
					$rules[] = array( 'Allow', $path );
				}

				// Most-specific-first. RFC 9309 §2.2.2 mandates longest-match, but
				// order-based parsers exist in the wild; sorting by descending path
				// length (Allow winning ties) is correct under both.
				usort(
					$rules,
					static function ( $a, $b ) {
						$diff = strlen( $b[1] ) - strlen( $a[1] );
						if ( 0 !== $diff ) {
							return $diff;
						}
						return ( 'Allow' === $a[0] ? 0 : 1 ) - ( 'Allow' === $b[0] ? 0 : 1 );
					}
				);

				foreach ( array_keys( self::get_search_social_crawlers() ) as $crawler ) {
					$block .= 'User-agent: ' . sanitize_text_field( $crawler ) . "\n";
				}
				if ( empty( $rules ) ) {
					// Site places no restrictions on `*` — say so explicitly rather
					// than emitting a User-agent with no rules under it.
					$block .= "Allow: /\n";
				} else {
					foreach ( $rules as $rule ) {
						$block .= $rule[0] . ': ' . $rule[1] . "\n";
					}
				}
				foreach ( $allow_paths as $path ) {
					$block .= "Allow: {$path}\n";
				}
				$block .= "\n";
			}
		}

		// Content Signals — single Content-Signal directive (contentsignals.org).
		// Format per isitagentready.com check: Content-Signal: ai-train=yes, search=yes, ai-input=yes
		// Options stored as allow/deny internally; mapped to yes/no for output.
		if ( $signals_on ) {
			$ai_train = 'allow' === get_option( RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN, 'allow' ) ? 'yes' : 'no';
			$search   = 'allow' === get_option( RNRD_OPT_CONTENT_SIGNALS_SEARCH, 'allow' ) ? 'yes' : 'no';
			$ai_input = 'allow' === get_option( RNRD_OPT_CONTENT_SIGNALS_AI_INPUT, 'allow' ) ? 'yes' : 'no';

			$block .= "# Content Signals (contentsignals.org)\n";
			$block .= "Content-Signal: ai-train={$ai_train}, search={$search}, ai-input={$ai_input}\n";
			$block .= "\n";
		}

		// rc.11 — Close functional marker (paired with `# BEGIN RankReady` opener).
		$block .= "# END RankReady\n";

		return $block;
	}

	/**
	 * Whether the "Generated from RankReady" credit line should be hidden.
	 *
	 * WP.org Free build: always returns false (credit shows). The branding
	 * toggle is a Coming Soon placeholder.
	 *
	 * @since 1.2.0-rc.11
	 */
	public static function should_hide_branding(): bool {
		return ( function_exists( 'rnrd_is_pro' ) && rnrd_is_pro() )
			&& 'on' === get_option( RNRD_OPT_HIDE_BRANDING, 'off' );
	}

	/**
	 * Sync RankReady rules to a physical robots.txt file.
	 *
	 * When a physical robots.txt exists (e.g. manually created or by a plugin),
	 * WordPress's `robots_txt` filter never fires. This method detects the
	 * physical file and appends/updates the RankReady block directly.
	 *
	 * Safe: only touches the RankReady-marked block, never modifies other rules.
	 */
	/**
	 * Record a robots.txt sync failure.
	 *
	 * Every failure path in sync_physical_robots_txt() used to `return` silently,
	 * so a user who blocked a crawler saw "Settings saved" while robots.txt was
	 * never touched. The option persisted, the file did not — reported state and
	 * real state diverged with nothing to diagnose it. Diagnostics already detects
	 * the drift; this makes the save path itself speak up.
	 *
	 * @param string $message Human-readable cause.
	 */
	private static function log_robots_sync_failure( string $message ): void {
		if ( class_exists( 'RNRD_Generator' ) ) {
			RNRD_Generator::log_error( 'robots.txt', $message );
		}
	}

	public static function sync_physical_robots_txt(): void {
		// Skip physical robots.txt on multisite — subsites share ABSPATH.
		if ( is_multisite() ) {
			return;
		}

		$file = ABSPATH . 'robots.txt';

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() ) {
			// Returns false whenever FS_METHOD is not 'direct' (host wants FTP/SSH
			// credentials). Previously silent: the user saw "Settings saved" while
			// robots.txt kept the old rules and the crawler they just blocked kept
			// crawling. Log it so Diagnostics and the error log can surface it.
			self::log_robots_sync_failure( 'WordPress could not get filesystem access (FS_METHOD is not "direct"). robots.txt was not updated.' );
			return;
		}

		// v1.2.0-rc.9 — Write a physical robots.txt when one doesn't exist
		// AND another plugin is intercepting the URL via custom rewrite.
		// Without a physical file, plugins like SEOPress can register their
		// own /robots.txt rewrite that bypasses both WP's virtual robots_txt
		// filter AND our PHP_INT_MAX-priority append. A physical file wins
		// at the webserver level (nginx/Apache) before WP routing runs.
		//
		// We only create the file when robots toggle is ON and we have a
		// non-empty block to write — otherwise we'd leave an empty file
		// around that could surprise users.
		if ( ! $wp_filesystem->exists( $file ) ) {
			// (bool) 'off' is true — this guard was inert, so a physical robots.txt
			// could be written even with the toggle explicitly OFF.
			$robots_on = 'on' === get_option( RNRD_OPT_ROBOTS_ENABLE, 'on' );
			$block     = self::generate_robots_block();
			if ( ! $robots_on || empty( trim( $block ) ) ) {
				// Filter handles it — no need for physical file.
				return;
			}
			// Detect another plugin actively claiming /robots.txt.
			$intercepting = self::detect_robots_txt_interceptor();
			if ( ! $intercepting ) {
				// WP serves virtual robots.txt — our filter at PHP_INT_MAX wins.
				return;
			}
			// Write a fresh physical robots.txt — block-only is fine; it
			// reads as a normal robots.txt with comments + directives.
			if ( ! $wp_filesystem->put_contents( $file, ltrim( $block ) . "\n", FS_CHMOD_FILE ) ) {
				self::log_robots_sync_failure( 'Could not create a physical robots.txt at ' . $file . '. Another plugin is intercepting /robots.txt, so the AI crawler rules are NOT live.' );
				return;
			}
			self::purge_robots_cache();
			return;
		}

		if ( ! $wp_filesystem->is_writable( $file ) ) {
			self::log_robots_sync_failure( 'robots.txt exists at ' . $file . ' but is not writable, so the AI crawler rules were not applied. Fix the file permissions or edit it manually.' );
			return;
		}

		$contents = $wp_filesystem->get_contents( $file );
		if ( false === $contents ) {
			return;
		}

		// Remove any existing RankReady block.
		// rc.11 — new format uses `# BEGIN RankReady` ... `# END RankReady` markers.
		// Older formats (rc.10 and earlier) used `# -- LLM ... (RankReady) --` style.
		// Match all 3 patterns so upgrades cleanly replace old blocks.
		$new_contents = preg_replace( '/\n?# BEGIN RankReady\n.*?# END RankReady\n?/s', '', $contents );
		$new_contents = preg_replace( '/\n?# -+ LLM.*?\(RankReady\).*?\n.*?(?=\n#[^-]|\n?$)/s', '', $new_contents );
		$new_contents = preg_replace( '/\n?#[^\n]*LLM[^\n]*RankReady[^\n]*\n.*?(?=\n#[^-]|\n?$)/s', '', $new_contents );

		// v1.2.1 — Remove orphaned Content Signals blocks.
		//
		// Builds before this one closed `# END RankReady` *before* the Content
		// Signals section, so the marker strip above removed the crawler rules
		// but left the Content-Signal pair behind. Every subsequent sync then
		// appended a fresh copy, accumulating one orphan per settings save
		// (a production site had 14 stranded copies outside the markers).
		//
		// This runs *after* the marker strip, so the current managed block is
		// already gone — anything still carrying our own comment signature is
		// by definition an orphan. A Content-Signal line the user wrote by hand
		// has no such comment above it and is left untouched.
		$new_contents = preg_replace(
			'/\n?# Content Signals \(contentsignals\.org\)\n(?:Content-Signal:[^\n]*\n)+/',
			'',
			$new_contents
		);

		// Trim trailing whitespace.
		$new_contents = rtrim( $new_contents ) . "\n";

		// Generate and append new block. $new_contents has our block already
		// stripped above, so mirroring the `*` group from it cannot re-ingest
		// our own mirrored rules.
		$block = self::generate_robots_block( $new_contents );

		if ( ! empty( trim( $block ) ) ) {
			$new_contents .= $block;
		}

		// v1.2.0-rc.1 — diff before writing. Settings saves that don't
		// actually change the rendered robots block were touching the file
		// (slow on managed hosts) and purging every cache layer for nothing.
		// (Audit beta.3 #14.)
		if ( $new_contents === $contents ) {
			return;
		}

		if ( ! $wp_filesystem->put_contents( $file, $new_contents, FS_CHMOD_FILE ) ) {
			self::log_robots_sync_failure( 'Writing robots.txt at ' . $file . ' failed, so the AI crawler rules were not applied.' );
			return;
		}

		// Purge robots.txt from all common page caches so changes are live immediately.
		self::purge_robots_cache();
	}

	/**
	 * Purge robots.txt from every active cache layer.
	 *
	 * Delegates to RNRD_Cache::purge_url() which covers all major WordPress cache
	 * plugins and CDN layers — no user configuration needed, safe no-op when
	 * a plugin is not active. See class-rnrd-cache.php for the full layer list.
	 */
	public static function purge_robots_cache(): void {
		RNRD_Cache::purge_url( home_url( '/robots.txt' ) );
	}

	/**
	 * Detect another plugin actively serving /robots.txt via custom rewrite.
	 *
	 * If detected, the `robots_txt` filter NEVER fires (interceptor exits
	 * before WP's template router reaches the virtual robots.txt). We
	 * detect this so sync_physical_robots_txt() knows to create a physical
	 * file that wins at the webserver level.
	 *
	 * @since 1.2.0-rc.9
	 * @return string Plugin name if detected, empty string otherwise.
	 */
	public static function detect_robots_txt_interceptor(): string {
		// SEOPress Pro has its own /robots.txt rewrite when the feature is on.
		if ( ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) ) ) {
			$seopress = get_option( 'seopress_pro_option_name', array() );
			if ( is_array( $seopress ) && ! empty( $seopress['seopress_pro_robots'] ) ) {
				return 'SEOPress (robots module on)';
			}
		}

		// Rank Math: their robots editor sets a transient when active.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_general = (array) get_option( 'rank-math-options-general', array() );
			if ( ! empty( $rm_general['robots_txt_content'] ) ) {
				return 'Rank Math (custom robots.txt set)';
			}
		}

		// Yoast: editor stored in option `wpseo_robots`.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$yoast_robots = get_option( 'wpseo_robots' );
			if ( ! empty( $yoast_robots ) ) {
				return 'Yoast SEO (robots editor used)';
			}
		}

		return '';
	}

	// ── Rewrite rules ─────────────────────────────────────────────────────────

	public static function add_rewrite_rules(): void {
		if ( 'on' !== get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			return;
		}

		// Skip llms.txt if a major SEO plugin already generates it.
		// RankReady still registers llms-full.txt since no SEO plugin does that.
		if ( ! self::another_plugin_handles_llms_txt() ) {
			add_rewrite_rule( '^llms\.txt$', 'index.php?rnrd_llms_txt=1', 'top' );
		}

		if ( 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			add_rewrite_rule( '^llms-full\.txt$', 'index.php?rnrd_llms_full_txt=1', 'top' );
		}

		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
	}

	/**
	 * Check if another plugin already handles /llms.txt generation.
	 *
	 * Detects Rank Math, Yoast, AIOSEO, SEOPress, and standalone llms.txt plugins.
	 * Returns true if RankReady should NOT register its own llms.txt route.
	 */
	// v1.1.5 (#10) — made public so the rewrite self-heal in rankready.php reuses this
	// single source of truth (RM + Yoast + AIOSEO + SEOPress + force filter) instead of
	// its own RM/Yoast-only inline check.
	public static function another_plugin_handles_llms_txt(): bool {
		// Allow users to force RankReady's llms.txt via filter.
		if ( apply_filters( 'rankready_force_llms_txt', false ) ) {
			return false;
		}

		// Rank Math llms.txt (has its own module).
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_modules = (array) get_option( 'rank_math_modules', array() );
			if ( in_array( 'llms-txt', $rm_modules, true ) ) {
				return true;
			}
		}

		// Yoast SEO llms.txt.
		if ( defined( 'WPSEO_VERSION' ) ) {
			$yoast_features = get_option( 'wpseo', array() );
			if ( ! empty( $yoast_features['enable_llms_txt'] ) ) {
				return true;
			}
		}

		// AIOSEO llms.txt (v1.2.0-rc.8 fix: was unconditionally true, broke
		// sites where AIOSEO is installed but llms.txt feature is off).
		// AIOSEO stores its toggles under `aioseo_options` JSON; check the
		// llms.txt key explicitly. If we can't verify it's ON, we serve.
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$aio = get_option( 'aioseo_options', '' );
			if ( is_string( $aio ) && $aio ) {
				$decoded = json_decode( $aio, true );
				if ( is_array( $decoded ) && ! empty( $decoded['llmsTxt']['enable'] ) ) {
					return true;
				}
			}
			// AIOSEO present but feature not verified on → RankReady serves.
		}

		// SEOPress llms.txt (v1.2.0-rc.8 fix: was unconditionally true for
		// 9.5+, broke slift.co where SEOPress 9.8.5 is installed but the
		// llms.txt feature wasn't enabled in SEOPress settings).
		// Check the SEOPress option explicitly. If we can't verify it's on,
		// we serve our own /llms.txt — RankReady's rewrite rule uses 'top'
		// priority so it wins anyway if both register.
		// rc.12 — read whichever constant is defined (Pro can be active without Free).
		$_seopress_v = defined( 'SEOPRESS_VERSION' ) ? SEOPRESS_VERSION : ( defined( 'SEOPRESS_PRO_VERSION' ) ? SEOPRESS_PRO_VERSION : '0' );
		if ( '0' !== $_seopress_v && version_compare( $_seopress_v, '9.5', '>=' ) ) {
			// SEOPress Pro stores llms.txt config under
			// `seopress_pro_option_name` array, key `seopress_pro_llms_txt`.
			$seopress = get_option( 'seopress_pro_option_name', array() );
			if ( is_array( $seopress ) && ! empty( $seopress['seopress_pro_llms_txt'] ) ) {
				return true;
			}
			// SEOPress present but llms.txt not verified on → RankReady serves.
		}

		return false;
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = 'rnrd_llms_txt';
		$vars[] = 'rnrd_llms_full_txt';
		return $vars;
	}

	// ── Request handler ───────────────────────────────────────────────────────

	public static function handle_request(): void {
		// Primary path: WordPress resolved our rewrite rule into a query var.
		// Fallback path: match the raw request URI directly. On some stacks (e.g. an
		// SEO plugin's early template_redirect router, aggressive rewrite ordering, or
		// a query_vars strip) WP never surfaces our query var even though the rule
		// matched — the raw-path check keeps the endpoint working. (Support: barisdayak.com.)
		$path     = self::request_path();
		$llms_on  = 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$full_on  = 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' );
		$other    = self::another_plugin_handles_llms_txt();

		if ( $llms_on && ! $other
			&& ( get_query_var( 'rnrd_llms_txt' ) || 'llms.txt' === $path ) ) {
			RNRD_Crawler_Log::log( 'llms_txt' );
			self::serve_llms_txt( false );
		}

		if ( $llms_on && $full_on
			&& ( get_query_var( 'rnrd_llms_full_txt' ) || 'llms-full.txt' === $path ) ) {
			RNRD_Crawler_Log::log( 'llms_full' );
			self::serve_llms_txt( true );
		}
	}

	/** Normalised current request path: no query string, no surrounding slashes, subdirectory-aware. */
	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$req = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home ) {
			if ( 0 === strpos( $req, $home . '/' ) ) {
				$req = trim( substr( $req, strlen( $home ) ), '/' );
			} elseif ( $req === $home ) {
				$req = '';
			}
		}
		return $req;
	}

	// ── Serve ─────────────────────────────────────────────────────────────────

	private static function serve_llms_txt( bool $full = false ): void {
		if ( 'on' !== get_option( RNRD_OPT_LLMS_ENABLE, 'off' ) ) {
			status_header( 404 );
			exit;
		}

		if ( $full && 'on' !== get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			status_header( 404 );
			exit;
		}

		$cache_key = $full ? RNRD_LLMS_FULL_CACHE_KEY : RNRD_LLMS_CACHE_KEY;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			/**
			 * rc.16 — programmatic override hook for the rendered llms.txt body.
			 *
			 * Chosen over a UI placeholder template editor (which would have
			 * required ~15 placeholders, preview UI, and would generate broken
			 * llmstxt.org-spec output from 99% of users who don't read the doc).
			 *
			 * @param string $content Final llms.txt body about to be served.
			 * @param array  $context ['full' => bool] full or index variant.
			 */
			$cached = (string) apply_filters( 'rankready_llms_txt_content', $cached, array( 'full' => $full ) );
			self::output_txt( $cached );
			return; // output_txt calls exit, but guard against refactoring.
		}

		$content = $full ? self::generate_full() : self::generate();

		$ttl = (int) get_option( RNRD_OPT_LLMS_CACHE_TTL, 3600 );
		if ( $ttl < 60 ) {
			$ttl = 3600;
		}
		set_transient( $cache_key, $content, $ttl );

		// rc.16 — same filter as above, applied on the fresh-generation path.
		$content = (string) apply_filters( 'rankready_llms_txt_content', $content, array( 'full' => $full ) );

		self::output_txt( $content );
	}

	private static function output_txt( string $content ): void {
		// v1.2.0-rc.2 — bypass WP page-cache plugins (LiteSpeed Cache, WP Rocket,
		// W3TC, etc.) so they don't layer their own cache on top.
		// RankReady manages its own caching via wp_transient.
		if ( class_exists( 'RNRD_Cache' ) ) {
			RNRD_Cache::bypass_page_cache_plugins_only();
		}

		// v1.0.1 — ETag-based revalidation. The strong ETag is the SHA-1 of
		// the response body. If the client sends If-None-Match matching this,
		// reply 304 Not Modified with no body — 60–90% bandwidth savings on
		// re-fetches, no origin work to regenerate content. Standard enterprise
		// pattern used by Cloudflare docs, Stripe docs, Vercel docs.
		$etag          = '"' . sha1( $content ) . '"';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- If-None-Match header used for ETag string comparison; wp_unslash applied, no echo/store.
		$client_etag   = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
		if ( '' !== $client_etag && $client_etag === $etag ) {
			status_header( 304 );
			header( 'ETag: ' . $etag );
			header( 'Cache-Control: public, max-age=60, s-maxage=600, stale-while-revalidate=3600' );
			exit;
		}

		// Assert 200 explicitly. We run on template_redirect, which fires AFTER
		// the main query — if another plugin intercepted the rewrite rule, WP has
		// already resolved this request as a 404 and sent that status. Serving the
		// correct body under a 404 makes agents and scanners discard it. Mirrors
		// RNRD_MCP::handle_request(), which has always done this.
		status_header( 200 );

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: text/plain; charset=utf-8' );

		// v1.2.0 — Keep llms.txt / llms-full.txt CRAWLABLE (AI agents fetch it,
		// and Google must be able to read this directive) but OUT of Google's
		// search results. Google ignores llms.txt for ranking, and it's a
		// machine-readable file, not a user-facing page — the recommended
		// practice for such files is "allow crawling, then X-Robots-Tag: noindex".
		// This response already bypasses page caches, so the header survives.
		header( 'X-Robots-Tag: noindex, follow' );

		// Browser-vs-edge TTL split (Mark Nottingham's caching tutorial §6.2).
		// max-age=60 keeps end-user browsers re-checking every minute (cheap
		// with ETag → 304). s-maxage=600 lets shared CDN edges cache 10x longer
		// so origin sees ~one request per 10 minutes per edge POP regardless
		// of how many readers hit each edge. stale-while-revalidate=3600 lets
		// edges serve a slightly-stale response for up to an hour while
		// fetching a fresh one in the background — zero user-facing latency
		// for the refresh.
		header( 'Cache-Control: public, max-age=60, s-maxage=600, stale-while-revalidate=3600' );
		header( 'CDN-Cache-Control: public, max-age=600, stale-while-revalidate=3600' );
		header( 'Cloudflare-CDN-Cache-Control: public, max-age=600' );
		header( 'Surrogate-Control: max-age=600' );
		header( 'ETag: ' . $etag );

		// Vary on Accept-Encoding so a gzip-compressed body is never served to
		// a non-gzip client. The plain-text endpoint doesn't content-negotiate
		// on Accept, so that's not in the Vary list.
		header( 'Vary: Accept-Encoding' );

		// CORS — AI agents fetch llms.txt cross-origin from their runtime.
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, HEAD, OPTIONS' );
		header( 'Access-Control-Expose-Headers: Content-Type, ETag, Last-Modified' );

		header( 'X-RankReady-Source: llms-txt' );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Register URL patterns with WP page-cache plugins so they don't cache
	 * the endpoints RankReady manages itself.
	 *
	 * Hooked at init priority 11 so cache plugins have already registered
	 * their filters when we add ours.
	 *
	 * @since 1.2.0-rc.2
	 */
	public static function register_cache_exclusions(): void {
		if ( ! class_exists( 'RNRD_Cache' ) ) {
			return;
		}
		$patterns = array( '/llms.txt', '/llms-full.txt' );
		// Add per-post .md when markdown is on (RNRD_Markdown registers its own
		// exclusions; we list here so a misconfigured cache plugin still
		// honours at least one filter).
		if ( 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			$patterns[] = '.md';
		}
		RNRD_Cache::exclude_url_patterns( $patterns );
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// GENERATE: /llms.txt (index with links only)
	// ═══════════════════════════════════════════════════════════════════════════

	public static function generate(): string {
		$lines = array();

		// v1.2.0-beta.4 — read every brand field from the unified getter.
		// Single source of truth: see get_brand_identity().
		$brand = self::get_brand_identity();

		// ── H1: Site name (REQUIRED per spec) ─────────────────────────────
		$lines[] = '# ' . self::clean_text( $brand['name'] );
		$lines[] = '';

		// ── Blockquote: Brief summary (RECOMMENDED per spec) ──────────────
		if ( '' !== $brand['summary'] ) {
			$lines[] = '> ' . self::clean_text( $brand['summary'] );
			$lines[] = '';
		}

		// ── About section (detailed info) ─────────────────────────────────
		if ( '' !== $brand['about'] ) {
			$lines[] = self::clean_text( $brand['about'] );
			$lines[] = '';
		}

		// ── Site metadata ─────────────────────────────────────────────────
		$lines[] = '- URL: ' . home_url( '/' );

		// Brand Terms — canonical names for entity consistency. Helps AI engines
		// recognise the same site/brand across variant spellings.
		$brand_terms = self::get_brand_terms_list();
		if ( ! empty( $brand_terms ) ) {
			$lines[] = '- Brand: ' . implode( ', ', $brand_terms );
		}

		$feed_url = get_bloginfo( 'rss2_url' );
		if ( ! empty( $feed_url ) ) {
			$lines[] = '- RSS Feed: ' . $feed_url;
		}

		// rc.16 audit — sitemap intentionally NOT listed in llms.txt body.
		// llmstxt.org spec does not require it; Google requires Sitemap: in
		// robots.txt (where RankReady already emits it). Anthropic / OpenAI /
		// Perplexity have published no docs requiring it in llms.txt. Real-
		// world llms.txt files (Anthropic docs, Lovable, Mintlify) omit it.

		// Link to llms-full.txt if enabled.
		if ( 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			$lines[] = '- Full version: ' . home_url( '/llms-full.txt' );
		}

		// Tell crawlers that markdown is available per page.
		if ( 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' ) ) {
			$lines[] = '- Markdown: Append .md to any page URL for clean markdown (e.g., /page-slug.md)';
			$lines[] = '- Content negotiation: Send `Accept: text/markdown` header on any page URL';
		}

		$lines[] = '';

		// ── Post type sections (H2-delimited file lists) ──────────────────
		$post_types = (array) get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) );
		$max_posts  = (int) get_option( RNRD_OPT_LLMS_MAX_POSTS, 100 );

		if ( $max_posts < 1 ) {
			$max_posts = 100;
		}

		// Get taxonomy exclusions from settings.
		$exclude_cats = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_CATS, array() );
		$exclude_tags = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_TAGS, array() );

		foreach ( $post_types as $pt ) {
			$type_obj = get_post_type_object( $pt );
			if ( ! $type_obj ) {
				continue;
			}

			$query_args = self::build_llms_query( $pt, $max_posts, $exclude_cats, $exclude_tags );
			$posts      = get_posts( $query_args );

			if ( empty( $posts ) ) {
				continue;
			}

			// Filter out posts flagged noindex by SEO plugins.
			$filtered = array();
			foreach ( $posts as $post ) {
				if ( self::should_exclude_from_llms( $post ) ) {
					continue;
				}
				$filtered[] = $post;
			}

			if ( empty( $filtered ) ) {
				continue;
			}

			// H2 section header.
			$section_title = $type_obj->labels->name;
			$lines[]       = '## ' . self::clean_text( $section_title );

			foreach ( $filtered as $post ) {
				$title    = self::clean_text( get_the_title( $post ) );
				$url      = get_permalink( $post );
				$excerpt  = self::get_post_description( $post );
				$lastmod  = get_post_modified_time( 'Y-m-d', false, $post );

				$lines[] = '- [' . $title . '](' . $url . '): ' . $excerpt . ' (updated: ' . $lastmod . ')';
			}

			$lines[] = '';
		}

		// ── Optional section (per spec: secondary/skippable content) ──────
		// Controlled by admin setting — user can toggle it off entirely.
		if ( 'on' === get_option( RNRD_OPT_LLMS_SHOW_CATEGORIES, 'on' ) ) {
			$cat_args = array(
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 20,
				'hide_empty' => true,
			);

			// Respect excluded categories.
			if ( ! empty( $exclude_cats ) ) {
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Exclude list is the user-curated category exclusion; bounded, intentional.
				$cat_args['exclude'] = $exclude_cats;
			}

			$categories = get_categories( $cat_args );

			if ( ! empty( $categories ) ) {
				$lines[] = '## Optional';

				foreach ( $categories as $cat ) {
					$lines[] = '- [' . self::clean_text( $cat->name ) . '](' . get_category_link( $cat->term_id ) . '): '
						. sprintf( '%d posts', $cat->count );
				}

				$lines[] = '';
			}
		}

		// ── Footer ────────────────────────────────────────────────────────
		$lines[] = '---';
		// rc.11 — Unbranded credit line. Hide entirely when Pro toggle on.
		if ( ! self::should_hide_branding() ) {
			$lines[] = 'Generated from RankReady';
		}

		return implode( "\n", $lines );
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// GENERATE: /llms-full.txt (full content inlined per page)
	//
	// Format follows real-world implementations (Lovable/Mintlify):
	//   # Page Title
	//   Source: https://example.com/page-url
	//
	//   [full page content as clean markdown]
	//
	// ═══════════════════════════════════════════════════════════════════════════

	public static function generate_full(): string {
		$lines = array();

		// v1.2.0-beta.4 — unified getter, same brand truth as generate().
		$brand = self::get_brand_identity();

		// ── Header (same as llms.txt) ─────────────────────────────────────
		$lines[] = '# ' . self::clean_text( $brand['name'] );
		$lines[] = '';

		if ( '' !== $brand['summary'] ) {
			$lines[] = '> ' . self::clean_text( $brand['summary'] );
			$lines[] = '';
		}

		if ( '' !== $brand['about'] ) {
			$lines[] = self::clean_text( $brand['about'] );
			$lines[] = '';
		}

		// Brand Terms — canonical names for entity consistency.
		$brand_terms = self::get_brand_terms_list();
		if ( ! empty( $brand_terms ) ) {
			$lines[] = '- Brand: ' . implode( ', ', $brand_terms );
			$lines[] = '';
		}

		$lines[] = '---';
		$lines[] = '';

		// ── Inline each page as clean markdown ────────────────────────────
		$post_types = (array) get_option( RNRD_OPT_LLMS_POST_TYPES, array( 'post', 'page' ) );
		$max_posts  = (int) get_option( RNRD_OPT_LLMS_MAX_POSTS, 100 );

		if ( $max_posts < 1 ) {
			$max_posts = 100;
		}

		// Get taxonomy exclusions from settings.
		$exclude_cats = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_CATS, array() );
		$exclude_tags = (array) get_option( RNRD_OPT_LLMS_EXCLUDE_TAGS, array() );

		foreach ( $post_types as $pt ) {
			$type_obj = get_post_type_object( $pt );
			if ( ! $type_obj ) {
				continue;
			}

			$query_args = self::build_llms_query( $pt, $max_posts, $exclude_cats, $exclude_tags );
			$posts      = get_posts( $query_args );

			if ( empty( $posts ) ) {
				continue;
			}

			foreach ( $posts as $post ) {
				// Skip noindex posts.
				if ( self::should_exclude_from_llms( $post ) ) {
					continue;
				}

				$title   = self::clean_text( get_the_title( $post ) );
				$url     = get_permalink( $post );
				$content = self::post_to_clean_markdown( $post, false );

				// Per-page separator: # Title + Source URL
				$lines[] = '# ' . $title;
				$lines[] = 'Source: ' . $url;
				$lines[] = '';

				if ( ! empty( $content ) ) {
					$lines[] = $content;
				}

				$lines[] = '';
				$lines[] = '---';
				$lines[] = '';
			}
		}

		// rc.11 — Unbranded credit line. Hide entirely when Pro toggle on.
		if ( ! self::should_hide_branding() ) {
			$lines[] = 'Generated from RankReady';
		}

		return implode( "\n", $lines );
	}

	// ── Cache busting ─────────────────────────────────────────────────────────

	public static function bust_cache_on_status_change( $new_status, $old_status, $post ): void {
		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			self::bust_cache();
		}
	}

	public static function bust_cache(): void {
		delete_transient( RNRD_LLMS_CACHE_KEY );
		delete_transient( RNRD_LLMS_FULL_CACHE_KEY );
	}

	/**
	 * Bust transient AND purge upstream CDN / page-cache layers.
	 *
	 * Called from update_option hooks for every setting that mutates llms.txt
	 * output. Without the CDN/page-cache purge, edits stay invisible at the
	 * edge even after we delete the transient.
	 *
	 * @since 1.2.0-rc.16 (audit C1 fix)
	 */
	public static function bust_cache_and_purge_cdn(): void {
		self::bust_cache();
		if ( ! class_exists( 'RNRD_Cache' ) ) {
			return;
		}

		// v1.0.1 — Purge every RankReady-managed endpoint, not just llms.txt,
		// so any setting change that affects AI discovery / agent readiness
		// produces fresh responses for crawlers across every cache layer.
		//
		// Each call fires the full purge chain inside RNRD_Cache::purge_url():
		//   - litespeed_purge_url        (LiteSpeed Cache plugin + LSWS)
		//   - cloudflare_purge_by_url    (official Cloudflare WP plugin)
		//   - rt_nginx_helper_purge_url  (Nginx Helper)
		//   - wphb_clear_cache_url       (Hummingbird)
		//   - nitropack_purge_individual_url (NitroPack)
		//   - rocket_clean_files         (WP Rocket — registered via filter elsewhere)
		// If the user has any of the major cache plugins active, the purge
		// reaches the CDN. If they have none, the purge_url() call is a no-op.
		$urls_to_purge = array(
			home_url( '/llms.txt' ),
			home_url( '/llms-full.txt' ),
			home_url( '/robots.txt' ),
			home_url( '/.well-known/mcp.json' ),
			home_url( '/index.md' ),
		);

		if ( 'on' === get_option( RNRD_OPT_MD_HOME_ENABLE, 'on' ) && class_exists( 'RNRD_Markdown' ) && 'page' === get_option( 'show_on_front' ) ) {
			$posts_page_id = (int) get_option( 'page_for_posts', 0 );
			if ( $posts_page_id > 0 ) {
				$posts_page = get_post( $posts_page_id );
				if ( $posts_page instanceof WP_Post ) {
					$urls_to_purge[] = RNRD_Markdown::get_md_url( $posts_page );
				}
			}
		}

		// Allow third parties + the RankReady Pro addon to extend the purge list.
		$urls_to_purge = (array) apply_filters( 'rankready_purge_urls', $urls_to_purge );

		foreach ( $urls_to_purge as $url ) {
			RNRD_Cache::purge_url( $url );
		}
	}

	// ── Multilingual detection ────────────────────────────────────────────────

	/**
	 * Detect active multilingual plugins so the admin can be warned that
	 * /llms.txt currently serves only the default language.
	 *
	 * Per-language generation (/es/llms.txt etc.) is planned for a future
	 * release. Today the plugin emits hreflang `<link rel="alternate">`
	 * discovery tags for each detected language pointing to language-prefixed URLs.
	 *
	 * @since 1.2.0-rc.16
	 * @return array<int, array{plugin:string,langs:string[],default:string}>
	 */
	public static function detect_multilingual(): array {
		$found = array();

		// WPML — most common, ships its own API.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML filter; must use its published name to integrate.
			$langs   = (array) apply_filters( 'wpml_active_languages', null );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML filter; must use its published name to integrate.
			$default = (string) apply_filters( 'wpml_default_language', 'en' );
			$found[] = array(
				'plugin'  => 'WPML ' . ICL_SITEPRESS_VERSION,
				'langs'   => array_keys( $langs ),
				'default' => $default,
			);
		}

		// Polylang.
		if ( function_exists( 'pll_languages_list' ) ) {
			$langs   = (array) pll_languages_list();
			$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';
			$found[] = array(
				'plugin'  => 'Polylang' . ( defined( 'POLYLANG_VERSION' ) ? ' ' . POLYLANG_VERSION : '' ),
				'langs'   => $langs,
				'default' => $default,
			);
		}

		// TranslatePress.
		if ( defined( 'TRP_PLUGIN_VERSION' ) ) {
			$settings = (array) get_option( 'trp_settings', array() );
			$langs    = (array) ( $settings['translation-languages'] ?? array() );
			$default  = (string) ( $settings['default-language'] ?? '' );
			$found[]  = array(
				'plugin'  => 'TranslatePress ' . TRP_PLUGIN_VERSION,
				'langs'   => $langs,
				'default' => $default,
			);
		}

		// Weglot.
		if ( class_exists( 'Weglot\\Util\\Helper_Util_Weglot' ) || defined( 'WEGLOT_VERSION' ) ) {
			$weglot   = (array) get_option( 'weglot_options', array() );
			$langs    = (array) ( $weglot['destination_language'] ?? array() );
			$default  = (string) ( $weglot['original_language'] ?? '' );
			$found[]  = array(
				'plugin'  => 'Weglot' . ( defined( 'WEGLOT_VERSION' ) ? ' ' . WEGLOT_VERSION : '' ),
				'langs'   => $langs,
				'default' => $default,
			);
		}

		// GTranslate — minimal data exposed via options.
		if ( defined( 'GTRANSLATE_VERSION' ) || function_exists( 'gtranslate' ) ) {
			$opts    = (array) get_option( 'GTranslate', array() );
			$langs   = ! empty( $opts['flag_codes'] ) ? explode( ',', (string) $opts['flag_codes'] ) : array();
			$found[] = array(
				'plugin'  => 'GTranslate',
				'langs'   => array_filter( array_map( 'trim', $langs ) ),
				'default' => '',
			);
		}

		return $found;
	}

	// ═══════════════════════════════════════════════════════════════════════════
	// HTML-TO-MARKDOWN: Aggressive page-builder-safe converter
	//
	// Strips ALL Elementor, Beaver Builder, Divi, WPBakery, and generic
	// page builder wrapper divs/sections/spans. Preserves only semantic
	// content: headings, paragraphs, lists, links, images, blockquotes, code.
	// ═══════════════════════════════════════════════════════════════════════════

	public static function post_to_clean_markdown( $post, bool $run_shortcodes = true ): string {
		$html = $post->post_content;

		if ( empty( $html ) ) {
			return '';
		}

		// ── Step 1: Strip Gutenberg block comments ────────────────────────
		$html = preg_replace( '/<!--\s*\/?wp:[^\>]+-->/s', '', $html );

		// ── Step 2: Shortcodes — run them for individual requests, strip for bulk ──
		$html = $run_shortcodes ? do_shortcode( $html ) : strip_shortcodes( $html );

		// ── Step 3: Strip ALL page builder wrapper elements ───────────────
		// Elementor: div.elementor-*, section.elementor-*, div.e-*, etc.
		// Divi: div.et_pb_*, div.et_*, etc.
		// WPBakery: div.vc_*, div.wpb_*, etc.
		// Beaver Builder: div.fl-*, etc.
		// Generic: section, article, aside, main, figure wrappers
		//
		// Strategy: Remove open/close tags for non-semantic containers,
		// keeping their inner content.

		// Strip Elementor widget/section/column wrappers (keep inner HTML).
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:elementor-|e-con|e-child)[^"]*"[^>]*>/si', '', $html );
		$html = preg_replace( '/<section[^>]*class="[^"]*elementor-[^"]*"[^>]*>/si', '', $html );

		// Strip Divi wrappers.
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:et_pb_|et_builder_)[^"]*"[^>]*>/si', '', $html );

		// Strip WPBakery wrappers.
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:vc_|wpb_)[^"]*"[^>]*>/si', '', $html );

		// Strip Beaver Builder wrappers.
		$html = preg_replace( '/<div[^>]*class="[^"]*fl-[^"]*"[^>]*>/si', '', $html );

		// Strip generic layout wrappers (div with only class/id/style attrs).
		$html = preg_replace( '/<div[^>]*class="[^"]*(?:wp-block-|entry-|post-|content-|container|wrapper|row|col-|grid)[^"]*"[^>]*>/si', '', $html );

		// Remove stray closing divs and sections.
		$html = preg_replace( '/<\/(?:div|section|article|aside|main|header|footer|nav|figure|figcaption)>/si', '', $html );

		// Strip inline styles and data attributes from remaining elements.
		$html = preg_replace( '/\s+style="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+data-[a-z0-9_-]+="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+class="[^"]*"/si', '', $html );
		$html = preg_replace( '/\s+id="[^"]*"/si', '', $html );

		// ── Step 4: Convert semantic HTML to markdown ─────────────────────

		// Headings (callback for dynamic #).
		$html = preg_replace_callback( '/<h([1-6])[^>]*>(.*?)<\/h\1>/si', function ( $m ) {
			return "\n" . str_repeat( '#', (int) $m[1] ) . ' ' . wp_strip_all_tags( $m[2] ) . "\n";
		}, $html );

		// Bold and italic (before stripping tags).
		$html = preg_replace( '/<(strong|b)>(.*?)<\/\1>/si', '**$2**', $html );
		$html = preg_replace( '/<(em|i)>(.*?)<\/\1>/si', '*$2*', $html );

		// Links — strip anchor-only hrefs (#section) since they're meaningless outside the page.
		$html = preg_replace( '/<a\s[^>]*href=["\']#[^"\']*["\'][^>]*>(.*?)<\/a>/si', '$1', $html );
		$html = preg_replace( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', '[$2]($1)', $html );

		// Images — extract src and alt only.
		$html = preg_replace_callback( '/<img[^>]*>/si', function ( $m ) {
			$tag = $m[0];
			$src = '';
			$alt = '';
			if ( preg_match( '/src=["\']([^"\']+)["\']/i', $tag, $sm ) ) {
				$src = $sm[1];
			}
			if ( preg_match( '/alt=["\']([^"\']*)["\']/', $tag, $am ) ) {
				$alt = $am[1];
			}
			if ( empty( $src ) ) {
				return '';
			}
			return '![' . $alt . '](' . $src . ')';
		}, $html );

		// Lists.
		$html = preg_replace( '/<li[^>]*>(.*?)<\/li>/si', '- $1', $html );
		$html = preg_replace( '/<\/?[ou]l[^>]*>/si', '', $html );

		// Paragraphs and br.
		$html = preg_replace( '/<p[^>]*>(.*?)<\/p>/si', "$1\n\n", $html );
		$html = preg_replace( '/<br\s*\/?>/si', "\n", $html );

		// Blockquotes.
		$html = preg_replace_callback( '/<blockquote[^>]*>(.*?)<\/blockquote>/si', function ( $m ) {
			$inner = wp_strip_all_tags( trim( $m[1] ) );
			$bq_lines = explode( "\n", $inner );
			return implode( "\n", array_map( function ( $l ) { return '> ' . trim( $l ); }, $bq_lines ) );
		}, $html );

		// Code blocks.
		$html = preg_replace( '/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/si', "\n```\n$1\n```\n", $html );
		$html = preg_replace( '/<code[^>]*>(.*?)<\/code>/si', '`$1`', $html );

		// Tables (basic).
		$html = preg_replace_callback( '/<table[^>]*>(.*?)<\/table>/si', function ( $m ) {
			return self::table_to_markdown( $m[1] );
		}, $html );

		// Horizontal rules.
		$html = preg_replace( '/<hr[^>]*\/?>/si', "\n---\n", $html );

		// ── Step 5: Strip ALL remaining HTML tags ─────────────────────────
		$html = wp_strip_all_tags( $html );

		// ── Step 6: Decode entities and clean whitespace ──────────────────
		$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
		$html = preg_replace( '/\n{3,}/', "\n\n", $html );
		$html = preg_replace( '/[ \t]+/', ' ', $html );

		// Clean up lines — remove lines that are just whitespace.
		$final_lines = array();
		foreach ( explode( "\n", $html ) as $line ) {
			$trimmed = trim( $line );
			if ( '' !== $trimmed || ( ! empty( $final_lines ) && '' !== end( $final_lines ) ) ) {
				$final_lines[] = $trimmed;
			}
		}

		return trim( implode( "\n", $final_lines ) );
	}

	/**
	 * Basic HTML table to markdown table.
	 */
	private static function table_to_markdown( string $table_html ): string {
		$rows = array();
		preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/si', $table_html, $row_matches );

		if ( empty( $row_matches[1] ) ) {
			return wp_strip_all_tags( $table_html );
		}

		$is_header = true;
		foreach ( $row_matches[1] as $row_html ) {
			preg_match_all( '/<t[hd][^>]*>(.*?)<\/t[hd]>/si', $row_html, $cell_matches );
			if ( empty( $cell_matches[1] ) ) {
				continue;
			}

			$cells  = array_map( function ( $c ) { return trim( wp_strip_all_tags( $c ) ); }, $cell_matches[1] );
			$rows[] = '| ' . implode( ' | ', $cells ) . ' |';

			if ( $is_header ) {
				$separator = array_map( function ( $c ) { return str_repeat( '-', max( 3, strlen( $c ) ) ); }, $cells );
				$rows[]    = '| ' . implode( ' | ', $separator ) . ' |';
				$is_header = false;
			}
		}

		return "\n" . implode( "\n", $rows ) . "\n";
	}

	// ── Query builder ─────────────────────────────────────────────────────────

	/**
	 * Build WP_Query args for llms.txt post retrieval.
	 *
	 * Applies:
	 * - Post type and publish status filter
	 * - Rank Math noindex meta_query exclusion (query-level)
	 * - Taxonomy exclusions from admin settings (category, tag)
	 *
	 * @param string $post_type    Post type slug.
	 * @param int    $max_posts    Max posts to retrieve.
	 * @param array  $exclude_cats Category term IDs to exclude.
	 * @param array  $exclude_tags Tag term IDs to exclude.
	 * @return array WP_Query compatible args.
	 */
	private static function build_llms_query( string $post_type, int $max_posts, array $exclude_cats, array $exclude_tags ): array {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => $max_posts,
			// Freshest content first: order by last-modified, not publish date.
			// Each list line shows "(updated: <modified>)", so sorting by modified
			// keeps the displayed freshness signal consistent with the ordering — a
			// recently refreshed post surfaces at the top for AI crawlers, instead of
			// being buried just because it was first published long ago.
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		// Exclude Rank Math noindex posts at query level.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Filtering by AI-readiness meta; intentional and bounded by post_type.
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'     => 'rank_math_robots',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'rank_math_robots',
					'value'   => 'noindex',
					'compare' => 'NOT LIKE',
				),
			);
		}

		// Taxonomy exclusions from admin settings.
		$tax_query = array();

		if ( ! empty( $exclude_cats ) ) {
			$tax_query[] = array(
				'taxonomy' => 'category',
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $exclude_cats ),
				'operator' => 'NOT IN',
			);
		}

		if ( ! empty( $exclude_tags ) ) {
			$tax_query[] = array(
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => array_map( 'absint', $exclude_tags ),
				'operator' => 'NOT IN',
			);
		}

		if ( ! empty( $tax_query ) ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Filtering by category exclusion; bounded by post_type + cached internally.
			$args['tax_query'] = $tax_query;
		}

		return $args;
	}

	// ── Post exclusion logic ──────────────────────────────────────────────────

	/**
	 * Check if a post should be excluded from llms.txt output.
	 *
	 * Only excludes posts marked noindex by SEO plugins. Everything else
	 * is controlled via taxonomy settings in the admin (Exclude Categories,
	 * Exclude Tags) and post type selection.
	 *
	 * @param WP_Post $post The post to check.
	 * @return bool True if the post should be excluded.
	 */
	// v1.1.5 — made public so RNRD_OKF reuses the same exclusion rules (per-post
	// "Exclude this post from AI surfaces" toggle + Yoast/Rank Math/AIOSEO/SEOPress noindex) as the
	// single source of truth for what belongs on an AI-readable surface.
	public static function should_exclude_from_llms( WP_Post $post ): bool {
		$post_id = $post->ID;

		// ── Per-post RankReady opt-out (v1.2.0) ──────────────────────────
		// Editors can tick "Exclude this post from AI surfaces" in the meta box.
		if ( '1' === (string) get_post_meta( $post_id, RNRD_META_LLMS_EXCLUDE, true ) ) {
			return true;
		}

		// ── Yoast noindex ────────────────────────────────────────────────
		if ( defined( 'WPSEO_VERSION' ) ) {
			$yoast_noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
			if ( '1' === $yoast_noindex ) {
				return true;
			}
		}

		// ── AIOSEO noindex ───────────────────────────────────────────────
		if ( defined( 'AIOSEO_VERSION' ) ) {
			// v1.1.5 (#8) — AIOSEO v4 stores per-post robots in its own
			// {prefix}_aioseo_posts table (robots_default + robots_noindex), NOT postmeta.
			// The old _aioseo_noindex postmeta check never fired on v4, so AIOSEO-noindexed
			// posts leaked into llms.txt / llms-full.txt. A post counts as noindex only when
			// it overrides the global default (robots_default = 0) AND robots_noindex = 1.
			// (Global-default noindex is intentionally not resolved here — that mirrors the
			// SEO plugin's own per-post override semantics; the explicit toggle is what users set.)
			global $wpdb;
			$aioseo_robots = $wpdb->get_row( $wpdb->prepare( "SELECT robots_default, robots_noindex FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- AIOSEO keeps no postmeta mirror; called during the (transient-cached) llms.txt build
			if ( $aioseo_robots && ! (int) $aioseo_robots->robots_default && (int) $aioseo_robots->robots_noindex ) {
				return true;
			}

			// Back-compat: AIOSEO v3 (and pre-migration installs) used postmeta.
			if ( '1' === (string) get_post_meta( $post_id, '_aioseo_noindex', true ) ) {
				return true;
			}
		}

		// ── SEOPress noindex ─────────────────────────────────────────────
		if ( ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) ) ) {
			$sp_noindex = get_post_meta( $post_id, '_seopress_robots_index', true );
			if ( 'yes' === $sp_noindex ) {
				return true;
			}
		}

		// ── Rank Math noindex (secondary check — primary is in meta_query) ──
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rm_robots = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) ) {
				return true;
			}
		}

		/**
		 * Filter to exclude specific posts from llms.txt.
		 *
		 * @param bool    $exclude Whether to exclude the post (default false).
		 * @param WP_Post $post    The post being checked.
		 */
		return (bool) apply_filters( 'rankready_exclude_from_llms', false, $post );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Parse the Brand Terms textarea (one canonical name per line) into an
	 * array of cleaned, deduplicated terms. Used by:
	 *   - generate()             llms.txt header metadata
	 *   - generate_full()        llms-full.txt header metadata
	 *   - generate_robots_block() robots.txt comment line
	 *   - RNRD_Faq::generate_faq()  augments FAQ prompt brand context
	 *   - RNRD_Generator           augments summary system prompt
	 *
	 * Single source of truth: RNRD_OPT_BRAND_TERMS option (AI Crawlers tab).
	 */
	public static function get_brand_terms_list(): array {
		$raw = (string) get_option( RNRD_OPT_BRAND_TERMS, '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			// v1.2.0-rc.1 — strip any embedded line separator that survived
			// sanitize_textarea_field. A \r /   /   inside a term
			// would split the robots.txt # Brand: line across records and
			// confuse parsers. (Audit beta.3 #16.)
			$line = preg_replace( '/[\r\n\x{2028}\x{2029}]+/u', ' ', $line );
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Returns brand terms as a comma-separated string suitable for prompt
	 * injection. Empty string when nothing is configured.
	 */
	public static function get_brand_terms_string(): string {
		$list = self::get_brand_terms_list();
		return empty( $list ) ? '' : implode( ', ', $list );
	}

	/**
	 * Unified Brand Identity getter (v1.2.0-beta.4).
	 *
	 * Replaces five fragmented inputs (RNRD_OPT_LLMS_SITE_NAME,
	 * RNRD_OPT_LLMS_SUMMARY, RNRD_OPT_LLMS_ABOUT, RNRD_OPT_BRAND_TERMS,
	 * RNRD_OPT_FAQ_BRAND_TERMS) with one consistent record so every consumer
	 * — llms.txt, llms-full.txt, robots.txt, FAQ prompt, summary prompt,
	 * MCP ability, homepage .md — sees the same brand truth.
	 *
	 * Resolution order (per field):
	 *   1. The new unified options if present
	 *   2. The legacy per-field options (back-compat)
	 *   3. WordPress core fallbacks (bloginfo)
	 *
	 * Returns an associative array shaped:
	 *   [
	 *     'name'    => string (site / brand name),
	 *     'summary' => string (one-line, ≤ 160 chars),
	 *     'about'   => string (longer description, ≤ 500 chars),
	 *     'terms'   => string[] (canonical brand names list),
	 *   ]
	 *
	 * @since 1.2.0-beta.4
	 */
	public static function get_brand_identity(): array {
		// Name: explicit > legacy > WP site title.
		$name = (string) get_option( RNRD_OPT_LLMS_SITE_NAME, '' );
		if ( '' === trim( $name ) ) {
			$name = (string) get_bloginfo( 'name' );
		}

		// Summary: legacy LLMS summary > WP tagline.
		$summary = (string) get_option( RNRD_OPT_LLMS_SUMMARY, '' );
		if ( '' === trim( $summary ) ) {
			$summary = (string) get_bloginfo( 'description' );
		}

		// About: only the dedicated LLMS_ABOUT option.
		$about = (string) get_option( RNRD_OPT_LLMS_ABOUT, '' );

		// Terms: the canonical Brand Terms list (already a getter).
		$terms = self::get_brand_terms_list();

		return array(
			'name'    => trim( $name ),
			'summary' => trim( $summary ),
			'about'   => trim( $about ),
			'terms'   => $terms,
		);
	}

	/**
	 * Brand identity sub-getters — convenience wrappers so callers don't have
	 * to unpack the array. Returns empty string / array on miss, never null.
	 *
	 * @since 1.2.0-beta.4
	 */
	public static function get_brand_name(): string    { return (string) self::get_brand_identity()['name']; }
	public static function get_brand_summary(): string { return (string) self::get_brand_identity()['summary']; }
	public static function get_brand_about(): string   { return (string) self::get_brand_identity()['about']; }

	private static function clean_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		// Collapse runs of spaces/tabs within each line, but preserve newlines.
		$text = preg_replace( '/[^\S\n]+/', ' ', $text );
		// Collapse 3+ consecutive newlines to 2.
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Flatten a value that will be interpolated into a SINGLE Markdown list line.
	 *
	 * clean_text() deliberately preserves newlines (llms.txt has multi-line
	 * sections), but a description is spliced into one `- [title](url): desc`
	 * line. An Author-level user could put newlines in an excerpt or a Yoast /
	 * Rank Math meta description and forge extra `## Section` headings and
	 * `- [anything](https://attacker.example)` entries in the public file that
	 * AI crawlers treat as the site's authoritative guidance.
	 *
	 * @param string $text Cleaned text that may contain newlines.
	 * @return string Single-line, length-capped text.
	 */
	private static function flatten_for_list_line( string $text ): string {
		$text = preg_replace( '/\s*\R\s*/u', ' ', $text );
		$text = trim( preg_replace( '/\s{2,}/u', ' ', (string) $text ) );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text, 'UTF-8' ) > 300 ) {
			$text = rtrim( mb_substr( $text, 0, 300, 'UTF-8' ) ) . '…';
		}

		return $text;
	}

	private static function get_post_description( $post ): string {
		// Try Yoast.
		$yoast = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		if ( ! empty( $yoast ) ) {
			return self::flatten_for_list_line( self::clean_text( $yoast ) );
		}

		// Try Rank Math.
		$rankmath = get_post_meta( $post->ID, 'rank_math_description', true );
		if ( ! empty( $rankmath ) ) {
			return self::flatten_for_list_line( self::clean_text( $rankmath ) );
		}

		// Try AIOSEO.
		$aioseo = get_post_meta( $post->ID, '_aioseo_description', true );
		if ( ! empty( $aioseo ) ) {
			return self::flatten_for_list_line( self::clean_text( $aioseo ) );
		}

		// Excerpt.
		if ( ! empty( $post->post_excerpt ) ) {
			return self::flatten_for_list_line( self::clean_text( $post->post_excerpt ) );
		}

		// Auto excerpt.
		$content = wp_strip_all_tags( do_shortcode( $post->post_content ) );
		return self::flatten_for_list_line( self::clean_text( wp_trim_words( $content, 30, '...' ) ) );
	}
}
