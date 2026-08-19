<?php
/**
 * Robots — manages the RankReady block in robots.txt (virtual & physical).
 *
 * Owns:
 *   - generate_robots_block()    builds the RankReady BEGIN/END section
 *   - add_to_robots_txt()        hooks into the WordPress `robots_txt` filter
 *   - sync_physical_robots_txt() keeps a physical robots.txt in sync
 *   - strip_rankready_robots_block() removes our markers (deactivation, re-sync)
 *   - detect_robots_txt_interceptor() finds other plugins claiming /robots.txt
 *   - parse_wildcard_group_rules()   extracts `*` rules for mirror group
 *   - purge_robots_cache()       clears cache layers after a write
 *
 * Delegates crawler-policy decisions to RNRD_Crawler_Access and
 * content-signal directives to RNRD_Content_Signals. Brand terms are read
 * via RNRD_Brand_Identity.
 *
 * @package RankReady
 * @since   1.2.2
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Robots {

	public static function init(): void {
		// Append RankReady block to WordPress's virtual robots.txt.
		// PHP_INT_MAX so we run after SEOPress / Yoast / Rank Math.
		add_filter( 'robots_txt', array( self::class, 'add_to_robots_txt' ), PHP_INT_MAX, 2 );

		// Sync to physical robots.txt whenever any option that affects the
		// robots.txt output changes. Both add_option_ and update_option_ are
		// required: the very first save on a fresh install fires add_option_,
		// subsequent saves fire update_option_.
		$sync = array( self::class, 'sync_physical_robots_txt' );
		foreach ( array(
			RNRD_OPT_ROBOTS_ENABLE,
			RNRD_OPT_ROBOTS_CRAWLERS,
			RNRD_OPT_ROBOTS_BLOCKED,
			RNRD_OPT_LLMS_ENABLE,
			RNRD_OPT_LLMS_FULL_ENABLE,
			RNRD_OPT_MD_ENABLE,
			RNRD_OPT_CONTENT_SIGNALS_ENABLE,
			RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN,
			RNRD_OPT_CONTENT_SIGNALS_SEARCH,
			RNRD_OPT_CONTENT_SIGNALS_AI_INPUT,
		) as $opt ) {
			add_action( 'update_option_' . $opt, $sync );
			add_action( 'add_option_' . $opt,    $sync, 10, 0 );
		}

		// v1.2.1 — Keep the mirrored search/social group fresh.
		//
		// That group restates the site's `User-agent: *` rules, so it goes STALE
		// if those rules change outside RankReady — a Rank Math / Yoast robots
		// editor save, or a hand-edited physical file. Re-check hourly.
		// sync_physical_robots_txt() diffs before writing so a no-change sync
		// costs only one file read.
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

		// CDN / page-cache purge for robots.txt on option changes.
		// (llms.txt transient + CDN purge is handled by RNRD_Llms_Txt::init().)
		$cdn_opts = array(
			RNRD_OPT_ROBOTS_ENABLE,           RNRD_OPT_ROBOTS_CRAWLERS,
			RNRD_OPT_ROBOTS_BLOCKED,
			RNRD_OPT_MD_ENABLE,
			RNRD_OPT_CONTENT_SIGNALS_ENABLE,  RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN,
			RNRD_OPT_CONTENT_SIGNALS_SEARCH,  RNRD_OPT_CONTENT_SIGNALS_AI_INPUT,
		);
		foreach ( $cdn_opts as $opt ) {
			add_action( 'update_option_' . $opt, array( 'RNRD_Cache', 'purge_all_endpoints' ) );
		}
	}

	// ── robots_txt filter ──────────────────────────────────────────────────────

	/**
	 * Append the RankReady block to WordPress's virtual robots.txt output.
	 *
	 * Runs at PHP_INT_MAX priority so RankReady appends to whatever earlier
	 * filters (SEOPress, Yoast, Rank Math) have already written.
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

	// ── Block builder ──────────────────────────────────────────────────────────

	/**
	 * Generate the RankReady robots.txt block as a standalone string.
	 *
	 * Used by the `robots_txt` filter (virtual) and physical file sync.
	 *
	 * @param string|null $surrounding_robots Existing robots body with our block
	 *                                        already stripped, used to build the
	 *                                        search/social mirror group.
	 */
	public static function generate_robots_block( ?string $surrounding_robots = null ): string {
		$llms_on    = 'on' === get_option( RNRD_OPT_LLMS_ENABLE, 'off' );
		$full_on    = 'on' === get_option( RNRD_OPT_LLMS_FULL_ENABLE, 'off' );
		$md_on      = 'on' === get_option( RNRD_OPT_MD_ENABLE, 'off' );
		$robots_on  = 'on' === get_option( RNRD_OPT_ROBOTS_ENABLE, 'on' );
		$signals_on = RNRD_Content_Signals::is_enabled();

		if ( ! $robots_on && ! $signals_on ) {
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

		// Use BEGIN/END functional markers (like `# BEGIN WordPress`) so
		// sync_physical_robots_txt() can reliably locate + replace our block.
		$block = "\n# BEGIN RankReady\n";

		if ( $robots_on ) {
			$block .= "# LLM & AI Crawler Rules\n";
		}

		// Brand Terms — canonical names as a comment. Some AI crawlers
		// (notably PerplexityBot and SearchBot variants) ingest robots.txt
		// comments alongside directives for entity recognition.
		$brand_terms = RNRD_Brand_Identity::get_brand_terms_list();
		if ( ( $robots_on || $signals_on ) && ! empty( $brand_terms ) ) {
			$block .= '# Brand: ' . implode( ', ', $brand_terms ) . "\n";
		}

		// Stack all User-agent lines in one block — per robots.txt spec,
		// grouped User-agent lines share the same Allow/Disallow rules.
		if ( $robots_on ) {
			// Read stored crawler list without eagerly evaluating the default —
			// PHP evaluates default args eagerly, and loading RNRD_Admin on every
			// public robots.txt / llms.txt request wastes 288KB autoloading.
			// The option is seeded on activation so the fallback effectively never runs.
			$enabled_crawlers = get_option( RNRD_OPT_ROBOTS_CRAWLERS, null );
			if ( null === $enabled_crawlers ) {
				$enabled_crawlers = array_keys( RNRD_Crawler_Access::get_llm_crawlers() );
			}
			$enabled_crawlers = (array) $enabled_crawlers;

			// Hard-block list (Disallow). A crawler in BOTH lists is blocked (block wins).
			$blocked_crawlers = (array) get_option( RNRD_OPT_ROBOTS_BLOCKED, array() );
			$enabled_crawlers = array_values( array_diff( $enabled_crawlers, $blocked_crawlers ) );

			// Blocked group first — its own User-agent group with Disallow: /.
			if ( ! empty( $blocked_crawlers ) ) {
				foreach ( $blocked_crawlers as $crawler ) {
					$block .= 'User-agent: ' . sanitize_text_field( $crawler ) . "\n";
				}
				$block .= "Disallow: /\n\n";
			}

			// Allowed group — Allow: / plus the RankReady endpoint paths.
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

			// Search / social crawlers — named explicitly but NOT widened.
			//
			// Googlebot and FacebookExternalHit are not AI crawlers, so they must
			// never join the `Allow: /` group above — a crawler obeys only its
			// most specific matching group, so that would detach them from the
			// site's own `User-agent: *` rules and expose /checkout/, /wp-admin/.
			// Instead we give them their own group that RESTATES the site's
			// existing `*` rules verbatim. Net permissions: unchanged. What
			// changes is that they are now explicitly named, which readiness
			// scanners check for.
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

				foreach ( array_keys( RNRD_Crawler_Access::get_search_social_crawlers() ) as $crawler ) {
					$block .= 'User-agent: ' . sanitize_text_field( $crawler ) . "\n";
				}
				if ( empty( $rules ) ) {
					// Site places no restrictions on `*` — say so explicitly.
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

		// Content Signals — delegate to RNRD_Content_Signals.
		$block .= RNRD_Content_Signals::get_robots_fragment();

		// Close functional marker.
		$block .= "# END RankReady\n";

		return $block;
	}

	// ── Physical file sync ─────────────────────────────────────────────────────

	/**
	 * Sync RankReady rules to a physical robots.txt file.
	 *
	 * When a physical robots.txt exists (e.g. manually created or by a plugin),
	 * WordPress's `robots_txt` filter never fires. This method detects the
	 * physical file and appends/updates the RankReady block directly.
	 *
	 * Safe: only touches the RankReady-marked block, never modifies other rules.
	 */
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
			self::log_sync_failure( 'WordPress could not get filesystem access (FS_METHOD is not "direct"). robots.txt was not updated.' );
			return;
		}

		// Write a physical robots.txt when one doesn't exist AND another plugin
		// is intercepting the URL via custom rewrite. Without a physical file,
		// plugins like SEOPress can register their own /robots.txt rewrite that
		// bypasses both WP's virtual robots_txt filter AND our PHP_INT_MAX-priority
		// append. A physical file wins at the webserver level before WP routing runs.
		//
		// Only create when robots toggle is ON and we have a non-empty block —
		// otherwise we'd leave an empty file that could surprise users.
		if ( ! $wp_filesystem->exists( $file ) ) {
			$robots_on = 'on' === get_option( RNRD_OPT_ROBOTS_ENABLE, 'on' );
			$block     = self::generate_robots_block();
			if ( ! $robots_on || empty( trim( $block ) ) ) {
				return;
			}
			$intercepting = self::detect_robots_txt_interceptor();
			if ( ! $intercepting ) {
				return;
			}
			if ( ! $wp_filesystem->put_contents( $file, ltrim( $block ) . "\n", FS_CHMOD_FILE ) ) {
				self::log_sync_failure( 'Could not create a physical robots.txt at ' . $file . '. Another plugin is intercepting /robots.txt, so the AI crawler rules are NOT live.' );
				return;
			}
			self::purge_robots_cache();
			return;
		}

		if ( ! $wp_filesystem->is_writable( $file ) ) {
			self::log_sync_failure( 'robots.txt exists at ' . $file . ' but is not writable, so the AI crawler rules were not applied. Fix the file permissions or edit it manually.' );
			return;
		}

		$contents = $wp_filesystem->get_contents( $file );
		if ( false === $contents ) {
			return;
		}

		// Remove any current or legacy RankReady-managed block before re-rendering.
		$new_contents = self::strip_rankready_robots_block( $contents );

		// Remove orphaned Content Signals blocks.
		//
		// Builds before v1.2.1 closed `# END RankReady` *before* the Content
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

		// Diff before writing — settings saves that don't change the rendered
		// block were touching the file and purging every cache layer needlessly.
		if ( $new_contents === $contents ) {
			return;
		}

		if ( ! $wp_filesystem->put_contents( $file, $new_contents, FS_CHMOD_FILE ) ) {
			self::log_sync_failure( 'Writing robots.txt at ' . $file . ' failed, so the AI crawler rules were not applied.' );
			return;
		}

		self::purge_robots_cache();
	}

	/**
	 * Purge robots.txt from every active cache layer.
	 */
	public static function purge_robots_cache(): void {
		RNRD_Cache::purge_url( home_url( '/robots.txt' ) );
	}

	// ── Strip / parse helpers ──────────────────────────────────────────────────

	/**
	 * Strip any RankReady-managed robots.txt block variants from a robots body.
	 *
	 * Supports the current BEGIN/END marker format plus legacy pre-marker
	 * comment styles so upgrades and deactivation cleanup stay in sync.
	 *
	 * @param string $contents Raw robots.txt body.
	 * @return string
	 */
	public static function strip_rankready_robots_block( string $contents ): string {
		$contents = preg_replace( '/\n?# BEGIN RankReady\n.*?# END RankReady\n?/s', '', $contents );
		$contents = preg_replace( '/\n?# -+ LLM.*?\(RankReady\).*?\n.*?(?=\n#[^-]|\n?$)/s', '', $contents );
		$contents = preg_replace( '/\n?#[^\n]*LLM[^\n]*RankReady[^\n]*\n.*?(?=\n#[^-]|\n?$)/s', '', $contents );
		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * Extract Allow/Disallow rules from every `User-agent: *` group in a robots.txt body.
	 *
	 * Used to mirror the site's own rules into the search/social group. Callers
	 * MUST pass content with the RankReady block already stripped, otherwise the
	 * mirrored rules would be re-ingested on every sync and compound.
	 *
	 * @param string $robots Raw robots.txt body, RankReady block removed.
	 * @return array{allow:string[],disallow:string[]}
	 */
	public static function parse_wildcard_group_rules( string $robots ): array {
		$allow      = array();
		$disallow   = array();
		$in_group   = false;
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

	// ── Interceptor detection ──────────────────────────────────────────────────

	/**
	 * Detect another plugin actively serving /robots.txt via custom rewrite.
	 *
	 * If detected, the `robots_txt` filter NEVER fires (interceptor exits
	 * before WP's template router reaches the virtual robots.txt). We
	 * detect this so sync_physical_robots_txt() knows to create a physical
	 * file that wins at the webserver level.
	 *
	 * @return string Plugin name if detected, empty string otherwise.
	 */
	public static function detect_robots_txt_interceptor(): string {
		// SEOPress Pro has its own /robots.txt rewrite when the feature is on.
		if ( defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ) ) {
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

	// ── Internal ───────────────────────────────────────────────────────────────

	/**
	 * Record a robots.txt sync failure so Diagnostics and the error log can surface it.
	 */
	private static function log_sync_failure( string $message ): void {
		if ( class_exists( 'RNRD_Generator' ) ) {
			RNRD_Generator::log_error( 'robots.txt', $message );
		}
	}
}
