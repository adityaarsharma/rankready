<?php
/**
 * Markdown endpoint — serves every post as clean .md at its URL.
 *
 * Per llmstxt.org spec: "pages on websites that have information that might be
 * useful for LLMs to read provide a clean markdown version of those pages at
 * the same URL as the original page, but with .md appended."
 *
 * URL format: /post-slug.md (appends .md to the post URL path)
 * Examples:
 *   /hello-world/   -> /hello-world.md
 *   /docs/refunds/  -> /docs/refunds.md
 *   /2024/01/post/  -> /2024/01/post.md
 *
 * Also supports:
 * - Accept: text/markdown content negotiation (like Next.js)
 * - <link rel="alternate" type="text/markdown"> discovery (per Joost de Valk)
 * - Link HTTP header for crawler discovery
 *
 * YAML frontmatter includes title, date, author, excerpt, tags, categories.
 * Aggressively strips Elementor, Divi, WPBakery, Beaver Builder markup.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Markdown {

	public static function init(): void {
		add_action( 'init',              array( self::class, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( self::class, 'handle_request' ) );

		// Content negotiation: serve markdown when Accept: text/markdown is sent.
		add_action( 'template_redirect', array( self::class, 'handle_accept_header' ), 5 );

		// Emit Vary: Accept on all HTML pages so caches store markdown and HTML separately.
		add_action( 'send_headers', array( self::class, 'add_vary_header' ) );

		// Add Link header on HTML pages pointing to .md version.
		add_action( 'wp_head',           array( self::class, 'add_md_link_tag' ) );
		add_action( 'send_headers',      array( self::class, 'add_md_link_header' ) );

		// Prevent WordPress from adding trailing slash to .md URLs.
		add_filter( 'redirect_canonical', array( self::class, 'prevent_md_trailing_slash' ), 10, 2 );

		// Flush rewrite rules when the setting changes.
		add_action( 'update_option_' . RR_OPT_MD_ENABLE, array( self::class, 'flush_rules' ) );

		// Register query vars via named method (not anonymous closure).
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );
	}

	/**
	 * Prevent WordPress from adding trailing slash to .md URLs.
	 */
	public static function prevent_md_trailing_slash( $redirect_url, $requested_url ) {
		if ( preg_match( '/\.md\/?$/i', $requested_url ) ) {
			return false;
		}
		return $redirect_url;
	}

	// ── Query vars (named method so it can be removed) ───────────────────────

	public static function register_query_vars( array $vars ): array {
		$vars[] = 'rr_md_path';
		return $vars;
	}

	// ── Rewrite rules ────────────────────────────────────────────────────────

	public static function add_rewrite_rules(): void {
		if ( 'on' !== get_option( RR_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}

		// IMPORTANT: Exclude wp-admin, wp-content, wp-includes, wp-json paths
		// to prevent hijacking real .md files or admin/API routes.
		// Only match front-end content paths.
		add_rewrite_rule(
			'^(?!wp-admin|wp-content|wp-includes|wp-json)(.+)\.md$',
			'index.php?rr_md_path=$matches[1]',
			'top'
		);
	}

	public static function flush_rules(): void {
		flush_rewrite_rules( false );
	}

	// ── Handle .md URL request ───────────────────────────────────────────────

	public static function handle_request(): void {
		$md_path = get_query_var( 'rr_md_path', '' );

		if ( empty( $md_path ) ) {
			return;
		}

		if ( 'on' !== get_option( RR_OPT_MD_ENABLE, 'off' ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo '# 404 Not Found';
			exit;
		}

		// Resolve the path to a post first so we can pass it to the logger.
		$post = self::resolve_post_from_path( $md_path );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo '# 404 Not Found';
			exit;
		}

		// Check post type is enabled.
		$enabled_types = (array) get_option( RR_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo '# 404 Not Found';
			exit;
		}

		// Log with the resolved post so CPT, title, and ID are captured.
		RR_Crawler_Log::log( 'markdown', $post );

		$cache_key = 'rr_md_' . $post->ID . '_' . strtotime( $post->post_modified );
		$markdown  = get_transient( $cache_key );
		if ( false === $markdown ) {
			$markdown = self::post_to_markdown( $post );
			set_transient( $cache_key, $markdown, 5 * MINUTE_IN_SECONDS );
		}
		self::serve_markdown( $markdown, get_permalink( $post ) );
	}

	// ── Accept header content negotiation ────────────────────────────────────
	// Like Next.js: when a request sends Accept: text/markdown on a normal
	// post URL, serve the markdown version directly.
	//
	// Implements RFC 9110 §12 content negotiation:
	//   - Parses q-values so text/html;q=1.0 beats text/markdown;q=0.5
	//   - Returns 406 when Accept excludes every type we can produce

	public static function handle_accept_header(): void {
		if ( 'on' !== get_option( RR_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}

		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';

		if ( empty( $accept ) ) {
			return;
		}

		$types = self::parse_accept_types( $accept );

		$q_markdown = self::get_type_q( $types, 'text/markdown' );
		$q_html     = self::get_type_q( $types, 'text/html' );
		$q_any      = self::get_type_q( $types, '*/*' );
		$q_text     = self::get_type_q( $types, 'text/*' );

		// Effective HTML q-value — wildcards count for HTML too.
		$q_html_eff = max( $q_html, $q_any, $q_text );

		if ( $q_markdown <= 0.0 ) {
			// text/markdown not in Accept or explicitly excluded (q=0).
			// If the client also can't accept HTML, nothing we serve will satisfy it.
			if ( $q_html_eff <= 0.0 ) {
				status_header( 406 );
				header( 'Content-Type: text/plain; charset=utf-8' );
				header( 'Vary: Accept' );
				echo '406 Not Acceptable'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				exit;
			}
			return;
		}

		// If HTML is strictly more preferred than markdown, let WordPress serve HTML normally.
		if ( $q_html > $q_markdown ) {
			return;
		}

		// text/markdown is preferred (or tied). Serve it.

		// Homepage (static front page OR blog posts index): generate a site overview.
		if ( is_front_page() || is_home() ) {
			RR_Crawler_Log::log( 'home_md' );
			self::serve_homepage_markdown();
			return;
		}

		// Singular post/page views.
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$enabled_types = (array) get_option( RR_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		// Log Accept-header markdown hit with the resolved post (CPT + title captured).
		RR_Crawler_Log::log( 'markdown', $post );

		$cache_key = 'rr_md_' . $post->ID . '_' . strtotime( $post->post_modified );
		$markdown  = get_transient( $cache_key );
		if ( false === $markdown ) {
			$markdown = self::post_to_markdown( $post );
			set_transient( $cache_key, $markdown, 5 * MINUTE_IN_SECONDS );
		}
		self::serve_markdown( $markdown, get_permalink( $post ) );
	}

	/**
	 * Parse an Accept header string into [ 'type/subtype' => q_value ] map.
	 */
	private static function parse_accept_types( string $accept ): array {
		$types = array();
		foreach ( explode( ',', $accept ) as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}
			$segments = explode( ';', $part );
			$type     = strtolower( trim( $segments[0] ) );
			$q        = 1.0;
			foreach ( array_slice( $segments, 1 ) as $param ) {
				$param = trim( $param );
				if ( 0 === strncasecmp( $param, 'q=', 2 ) ) {
					$q = (float) substr( $param, 2 );
					break;
				}
			}
			$types[ $type ] = $q;
		}
		return $types;
	}

	/**
	 * Get the q-value for a media type from a parsed Accept types map.
	 * Returns 0.0 when the type is absent.
	 */
	private static function get_type_q( array $types, string $type ): float {
		return isset( $types[ $type ] ) ? (float) $types[ $type ] : 0.0;
	}

	/**
	 * Serve a markdown overview of the site for homepage requests with Accept: text/markdown.
	 *
	 * Generates a clean markdown document describing the site and listing recent posts.
	 * Cached for 1 hour and bust on post publish/update.
	 */
	private static function serve_homepage_markdown(): void {
		$cache_key = 'rr_md_homepage_' . get_option( 'permalink_structure', '' );
		$markdown  = get_transient( $cache_key );

		if ( false === $markdown ) {
			$site_name = get_bloginfo( 'name' );
			$tagline   = get_bloginfo( 'description' );
			$home_url  = home_url( '/' );

			$lines   = array();
			$lines[] = '# ' . $site_name;
			if ( ! empty( $tagline ) ) {
				$lines[] = '';
				$lines[] = '> ' . $tagline;
			}
			$lines[] = '';
			$lines[] = 'Source: ' . $home_url;
			$lines[] = '';

			// Link to llms.txt if enabled.
			if ( 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' ) ) {
				$lines[] = 'Full site index: [llms.txt](' . home_url( '/llms.txt' ) . ')';
				$lines[] = '';
			}

			// Recent posts.
			$posts = get_posts( array(
				'numberposts'      => 10,
				'post_status'      => 'publish',
				'suppress_filters' => false,
			) );

			if ( ! empty( $posts ) ) {
				$lines[] = '## Recent Posts';
				$lines[] = '';
				foreach ( $posts as $post ) {
					$lines[] = '- [' . esc_html( get_the_title( $post ) ) . '](' . get_permalink( $post ) . ')';
				}
				$lines[] = '';
			}

			$markdown = implode( "\n", $lines );
			set_transient( $cache_key, $markdown, HOUR_IN_SECONDS );
		}

		self::serve_markdown( $markdown, home_url( '/' ) );
	}

	// ── Vary: Accept header ───────────────────────────────────────────────────
	// Tells downstream caches (CDN, reverse proxy) to store separate versions
	// based on the Accept header, enabling correct content negotiation.

	public static function add_vary_header(): void {
		if ( 'on' !== get_option( RR_OPT_MD_ENABLE, 'off' ) ) {
			return;
		}
		if ( is_admin() || defined( 'REST_REQUEST' ) ) {
			return;
		}
		header( 'Vary: Accept', false );

		// Homepage only: fire the full cache-bypass stack so every CDN and page-cache
		// plugin stops serving a stale HTML response when Accept: text/markdown arrives.
		// See RR_Cache::no_cache_headers() for the complete layer-by-layer breakdown.
		if ( is_front_page() || is_home() ) {
			RR_Cache::no_cache_headers();
		}
	}

	// ── Link tag + header to .md version ─────────────────────────────────────
	// Helps crawlers discover the markdown version from the HTML page.

	public static function add_md_link_tag(): void {
		if ( 'on' !== get_option( RR_OPT_MD_ENABLE, 'off' ) || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$enabled_types = (array) get_option( RR_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		$md_url = self::get_md_url( $post );
		echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '" />' . "\n";
	}

	public static function add_md_link_header(): void {
		if ( 'on' !== get_option( RR_OPT_MD_ENABLE, 'off' ) || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$enabled_types = (array) get_option( RR_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $enabled_types, true ) ) {
			return;
		}

		$md_url = self::get_md_url( $post );
		header( 'Link: <' . esc_url( $md_url ) . '>; rel="alternate"; type="text/markdown"', false );
	}

	// ── Serve markdown response ──────────────────────────────────────────────

	private static function serve_markdown( string $markdown, string $canonical_url ): void {
		// Block every cache layer from storing this response. Markdown and HTML
		// are different representations of the same URL — a cache hit would
		// serve the wrong content-type to the next client.
		RR_Cache::no_cache_headers();

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Vary: Accept' );
		header( 'x-markdown-source: accept' );
		header( 'Link: <' . esc_url( $canonical_url ) . '>; rel="canonical"', false );

		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// ── Post resolver ────────────────────────────────────────────────────────

	/**
	 * Resolve a URL path (without .md) back to a WP_Post.
	 *
	 * Handles:
	 * - Pages: /about -> page "about"
	 * - Hierarchical pages: /docs/refunds -> page "refunds" under "docs"
	 * - Posts with /%postname%/: /hello-world -> post "hello-world"
	 * - Posts with date permalinks: /2024/01/15/hello-world -> post "hello-world"
	 * - Custom post types: /product/widget -> CPT "widget"
	 *
	 * @param string $path The URL path captured before .md (no leading/trailing slash).
	 * @return WP_Post|null
	 */
	private static function resolve_post_from_path( string $path ): ?WP_Post {
		// Clean path.
		$path = trim( $path, '/' );

		if ( empty( $path ) ) {
			return null;
		}

		// Strategy 1: Use url_to_postid() — works for most permalink structures.
		$url     = home_url( '/' . $path . '/' );
		$post_id = url_to_postid( $url );

		if ( $post_id > 0 ) {
			return get_post( $post_id );
		}

		// Also try without trailing slash.
		$url     = home_url( '/' . $path );
		$post_id = url_to_postid( $url );

		if ( $post_id > 0 ) {
			return get_post( $post_id );
		}

		// Strategy 2: Try get_page_by_path() for pages and hierarchical types.
		$enabled_types = (array) get_option( RR_OPT_MD_POST_TYPES, array( 'post', 'page' ) );
		$post          = get_page_by_path( $path, OBJECT, $enabled_types );

		if ( $post instanceof WP_Post ) {
			return $post;
		}

		// Strategy 3: For CPTs with rewrite slugs, strip the CPT prefix.
		$segments = explode( '/', $path );
		if ( count( $segments ) >= 2 ) {
			$possible_cpt  = $segments[0];
			$possible_slug = implode( '/', array_slice( $segments, 1 ) );

			foreach ( $enabled_types as $pt ) {
				$type_obj = get_post_type_object( $pt );
				if ( ! $type_obj ) {
					continue;
				}

				$rewrite_slug = $pt;
				if ( ! empty( $type_obj->rewrite['slug'] ) ) {
					$rewrite_slug = $type_obj->rewrite['slug'];
				}

				if ( $possible_cpt === $rewrite_slug ) {
					$found = get_page_by_path( $possible_slug, OBJECT, $pt );
					if ( $found instanceof WP_Post ) {
						return $found;
					}
				}
			}
		}

		// Strategy 4: Last resort — slug lookup.
		$slug  = basename( $path );
		$posts = get_posts( array(
			'name'                   => $slug,
			'post_type'              => $enabled_types,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		) );

		if ( ! empty( $posts ) ) {
			return $posts[0];
		}

		return null;
	}

	// ── Markdown generator ───────────────────────────────────────────────────

	public static function post_to_markdown( WP_Post $post ): string {
		$lines = array();

		// ── YAML frontmatter ─────────────────────────────────────────────
		$include_meta = (bool) get_option( RR_OPT_MD_INCLUDE_META, '1' );

		if ( $include_meta ) {
			$lines[] = '---';
			$lines[] = 'title: "' . self::yaml_escape( get_the_title( $post ) ) . '"';
			$lines[] = 'url: ' . get_permalink( $post );
			$lines[] = 'date: ' . get_post_time( 'Y-m-d', false, $post );
			$lines[] = 'modified: ' . get_post_modified_time( 'Y-m-d', false, $post );
			$lines[] = 'author: "' . self::yaml_escape( get_the_author_meta( 'display_name', $post->post_author ) ) . '"';

			// Excerpt.
			$excerpt = ! empty( $post->post_excerpt )
				? $post->post_excerpt
				: wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '...' );
			$lines[] = 'description: "' . self::yaml_escape( $excerpt ) . '"';

			// Categories.
			$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
			if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
				$lines[] = 'categories:';
				foreach ( $categories as $cat ) {
					$lines[] = '  - "' . self::yaml_escape( $cat ) . '"';
				}
			}

			// Tags.
			$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );
			if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
				$lines[] = 'tags:';
				foreach ( $tags as $tag ) {
					$lines[] = '  - "' . self::yaml_escape( $tag ) . '"';
				}
			}

			// Featured image.
			if ( has_post_thumbnail( $post->ID ) ) {
				$thumb_url = get_the_post_thumbnail_url( $post->ID, 'large' );
				if ( $thumb_url ) {
					$lines[] = 'image: ' . $thumb_url;
				}
			}

			// Word count.
			$plain    = wp_strip_all_tags( $post->post_content );
			$wc       = preg_match_all( '/\S+/', $plain ); // locale-safe word count.
			$lines[]  = 'word_count: ' . ( false !== $wc ? $wc : 0 );

			$lines[] = '---';
			$lines[] = '';
		}

		// ── Title ────────────────────────────────────────────────────────
		$lines[] = '# ' . self::clean_text( get_the_title( $post ) );
		$lines[] = '';

		// ── AI Summary (if available) ────────────────────────────────────
		$summary_raw = (string) get_post_meta( $post->ID, RR_META_SUMMARY, true );
		if ( ! empty( $summary_raw ) ) {
			$summary = RR_Generator::decode_summary( $summary_raw );
			if ( 'bullets' === $summary['type'] && ! empty( $summary['data'] ) ) {
				$lines[] = '## Key Takeaways';
				$lines[] = '';
				foreach ( $summary['data'] as $bullet ) {
					$lines[] = '- ' . self::clean_text( $bullet );
				}
				$lines[] = '';
			}
		}

		// ── Content ──────────────────────────────────────────────────────
		$content = RR_Llms_Txt::post_to_clean_markdown( $post );

		if ( ! empty( $content ) ) {
			$lines[] = $content;
		}

		// ── FAQ section (if available) ───────────────────────────────
		if ( class_exists( 'RR_Faq' ) ) {
			$faq_md = RR_Faq::get_faq_markdown( $post->ID );
			if ( ! empty( $faq_md ) ) {
				$lines[] = '';
				$lines[] = $faq_md;
			}
		}

		return implode( "\n", $lines );
	}

	// ── Get .md URL for a post ───────────────────────────────────────────────

	/**
	 * Get the .md URL for a given post.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 * @return string The .md URL (e.g., https://example.com/hello-world.md).
	 */
	public static function get_md_url( $post ): string {
		$permalink = get_permalink( $post );
		$permalink = untrailingslashit( $permalink );

		return $permalink . '.md';
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private static function clean_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	private static function yaml_escape( string $text ): string {
		$text = self::clean_text( $text );
		$text = str_replace( '"', '\\"', $text );
		return $text;
	}
}
