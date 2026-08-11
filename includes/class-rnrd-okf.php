<?php
/**
 * RNRD_OKF — Open Knowledge Format (OKF) bundle generator.
 *
 * Serves a Google OKF v0.1 bundle at /okf/ and offers a downloadable .zip of it.
 * Spec: https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md
 *
 * Bundle layout (all served live, regenerated on request; the .md URLs always
 * return markdown so they are safe to cache publicly):
 *
 *   /okf/            → /okf/index.md (manifest; the ONLY index with frontmatter,
 *                       and only the single allowed key `okf_version`)
 *   /okf/index.md    → manifest: sections of `* [Title](/okf/<slug>.md) - description`
 *   /okf/log.md      → update history, ISO date headings, newest first
 *   /okf/<slug>.md   → one concept document per published post/page, with required
 *                       `type` frontmatter + recommended title/description/resource/tags/timestamp
 *
 * Conformance (SPEC §9): every non-reserved .md has parseable YAML frontmatter with a
 * non-empty `type`; index.md/log.md follow their reserved structures. We generate the
 * bundle entirely from local content — RankReady never contacts Google or any external
 * service to produce or validate OKF.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_OKF {

	/** OKF spec version this generator targets. */
	const OKF_VERSION = '0.1';

	/** Transient keys for the (content-busted) manifest + log so the origin never
	 *  rebuilds the full O(N) bundle on a normal request — only after content changes. */
	const CACHE_INDEX = 'rnrd_okf_index';
	const CACHE_LOG   = 'rnrd_okf_log';

	/** admin-post action for the bundle .zip download. */
	const EXPORT_ACTION = 'rnrd_okf_export';

	public static function init(): void {
		// Register rewrite rules BEFORE RNRD_Markdown (init priority 10). Both use the
		// `top` bucket, which WordPress matches in INSERTION order, and markdown's
		// `^(?!wp-admin|…)(.+)\.md$` rule would otherwise capture /okf/<slug>.md first.
		// Registering at priority 9 inserts our more-specific `^okf/…` rules ahead of it.
		add_action( 'init', array( self::class, 'add_rewrite_rules' ), 9 );
		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );

		// Serve at priority 0 — ahead of the markdown handlers (priority 1/2) so an /okf/
		// request is answered here and never falls through to the .md resolver, regardless
		// of class init order.
		add_action( 'template_redirect', array( self::class, 'handle_request' ), 0 );

		// Bundle .zip download (admin-triggered).
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( self::class, 'handle_export' ) );

		// Bundle freshness: drop the cached manifest + log whenever content or the
		// included-types setting changes, so the bundle genuinely refreshes on publish/
		// edit/delete and the origin only does the O(N) rebuild after a real change.
		add_action( 'save_post', array( self::class, 'flush_cache' ) );
		add_action( 'deleted_post', array( self::class, 'flush_cache' ) );
		add_action( 'transition_post_status', array( self::class, 'flush_cache' ) );
		add_action( 'update_option_' . RNRD_OPT_OKF_POST_TYPES, array( self::class, 'flush_cache' ) );
		add_action( 'update_option_' . RNRD_OPT_OKF_ENABLE, array( self::class, 'flush_cache' ) );
	}

	/** Drop the cached manifest + log (called on any content/setting change). */
	public static function flush_cache(): void {
		delete_transient( self::CACHE_INDEX );
		delete_transient( self::CACHE_LOG );
	}

	/** Is the OKF bundle enabled? */
	public static function enabled(): bool {
		return 'on' === get_option( RNRD_OPT_OKF_ENABLE, 'off' );
	}

	/** Post types included in the bundle. */
	public static function post_types(): array {
		$types = get_option( RNRD_OPT_OKF_POST_TYPES, array( 'post', 'page' ) );
		$types = is_array( $types ) ? array_values( array_filter( array_map( 'sanitize_key', $types ) ) ) : array();
		return ! empty( $types ) ? $types : array( 'post', 'page' );
	}

	// ── Routing ────────────────────────────────────────────────────────────

	public static function register_query_vars( array $vars ): array {
		$vars[] = 'rnrd_okf';
		$vars[] = 'rnrd_okf_slug';
		return $vars;
	}

	public static function add_rewrite_rules(): void {
		if ( ! self::enabled() ) {
			return;
		}
		// Bundle root + manifest.
		add_rewrite_rule( '^okf/?$', 'index.php?rnrd_okf=index', 'top' );
		add_rewrite_rule( '^okf/index\.md$', 'index.php?rnrd_okf=index', 'top' );
		// Reserved log file.
		add_rewrite_rule( '^okf/log\.md$', 'index.php?rnrd_okf=log', 'top' );
		// Concept documents — anything else ending in .md directly under /okf/.
		add_rewrite_rule( '^okf/([^/]+)\.md$', 'index.php?rnrd_okf=concept&rnrd_okf_slug=$matches[1]', 'top' );
	}

	/** Normalised current request path (no query string / surrounding slashes, subdirectory-aware). */
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

	public static function handle_request(): void {
		$what = (string) get_query_var( 'rnrd_okf', '' );
		$slug = sanitize_title( (string) get_query_var( 'rnrd_okf_slug', '' ) );

		// Fallback: match the raw /okf/ request path if WP didn't surface our query
		// var (SEO-plugin early router, rewrite ordering, or a query_vars strip).
		if ( '' === $what && self::enabled() ) {
			$rnrd_path = self::request_path();
			if ( 'okf' === $rnrd_path || 'okf/index.md' === $rnrd_path ) {
				$what = 'index';
			} elseif ( 'okf/log.md' === $rnrd_path ) {
				$what = 'log';
			} elseif ( preg_match( '#^okf/([^/]+)\\.md$#', $rnrd_path, $rnrd_m ) ) {
				$what = 'concept';
				$slug = sanitize_title( $rnrd_m[1] );
			}
		}

		if ( '' === $what ) {
			return;
		}
		if ( ! self::enabled() ) {
			return; // Let WordPress 404 naturally.
		}

		switch ( $what ) {
			case 'index':
				self::serve( self::build_index_markdown(), false );
				break;
			case 'log':
				self::serve( self::build_log_markdown(), false );
				break;
			case 'concept':
				$post = self::resolve_slug( $slug );
				// 404 for missing OR excluded posts (noindex / per-post opt-out) so a
				// hidden post can't be reached via its direct /okf/<slug>.md URL either.
				if ( ! $post instanceof WP_Post || ! self::is_includable( $post ) ) {
					status_header( 404 );
					return; // Consumers MUST tolerate broken links (SPEC §5.3); a 404 is fine.
				}
				self::serve( self::build_concept_markdown( $post ), false );
				break;
		}
	}

	// ── Bundle generation ────────────────────────────────────────────────────

	/**
	 * The bundle-root index.md. Per SPEC §6 it carries NO frontmatter — the single
	 * exception (§11) is the `okf_version` declaration, which is the only frontmatter
	 * permitted in a root index.md. Body is sections of `* [Title](url) - description`.
	 */
	public static function build_index_markdown(): string {
		$cached = get_transient( self::CACHE_INDEX );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$lines   = array();
		$lines[] = '---';
		$lines[] = 'okf_version: "' . self::OKF_VERSION . '"';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = '# ' . self::clean( get_bloginfo( 'name' ) );
		$lines[] = '';

		$tagline = self::clean( get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$lines[] = $tagline;
			$lines[] = '';
		}

		// Group concepts by post type, each its own section (SPEC §6 "grouped entries").
		foreach ( self::post_types() as $pt ) {
			$posts = self::query_posts( array( $pt ) );
			if ( empty( $posts ) ) {
				continue;
			}
			$obj    = get_post_type_object( $pt );
			$label  = ( $obj && ! empty( $obj->labels->name ) ) ? $obj->labels->name : ucfirst( $pt );
			$lines[] = '## ' . self::clean( $label );
			foreach ( $posts as $post ) {
				$lines[] = '* [' . self::clean( get_the_title( $post ) ) . '](/okf/' . self::concept_slug( $post ) . '.md) - ' . self::clean( self::excerpt( $post ) );
			}
			$lines[] = '';
		}

		$out = rtrim( implode( "\n", $lines ) ) . "\n";
		set_transient( self::CACHE_INDEX, $out, DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * The reserved log.md (SPEC §7): flat list of date-grouped entries, newest first,
	 * date headings in YYYY-MM-DD. Built from recent post modifications. No frontmatter.
	 */
	public static function build_log_markdown(): string {
		$cached = get_transient( self::CACHE_LOG );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$posts = self::query_posts( self::post_types(), 100, 'modified' );

		$by_date = array();
		foreach ( $posts as $post ) {
			$date = get_post_modified_time( 'Y-m-d', true, $post );
			$by_date[ $date ][] = $post;
		}
		// query_posts already orders by modified DESC, so keys arrive newest-first.

		$lines   = array();
		$lines[] = '# Update Log';
		$lines[] = '';
		foreach ( $by_date as $date => $group ) {
			$lines[] = '## ' . $date;
			foreach ( $group as $post ) {
				$lines[] = '* Updated [' . self::clean( get_the_title( $post ) ) . '](/okf/' . self::concept_slug( $post ) . '.md)';
			}
			$lines[] = '';
		}

		$out = rtrim( implode( "\n", $lines ) ) . "\n";
		set_transient( self::CACHE_LOG, $out, DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * One OKF concept document for a post. OKF frontmatter (required `type` +
	 * recommended fields) followed by the post body. The body is reused verbatim from
	 * RNRD_Markdown::post_to_markdown() — we strip its own frontmatter block and prepend
	 * the OKF one, so there is a single source of truth for body rendering.
	 */
	public static function build_concept_markdown( WP_Post $post ): string {
		$body = RNRD_Markdown::post_to_markdown( $post );
		// Drop the markdown class's own leading YAML frontmatter, if present.
		$body = preg_replace( '/\A---\R.*?\R---\R+/s', '', $body );

		return self::okf_frontmatter( $post ) . "\n" . ltrim( (string) $body );
	}

	/** Build the OKF-compliant YAML frontmatter block for a post. */
	private static function okf_frontmatter( WP_Post $post ): string {
		$lines   = array();
		$lines[] = '---';
		// REQUIRED, non-empty (SPEC §4.1 / §9).
		$lines[] = 'type: ' . self::map_type( $post );
		$lines[] = 'title: "' . self::yaml_escape( get_the_title( $post ) ) . '"';
		$lines[] = 'description: "' . self::yaml_escape( self::excerpt( $post ) ) . '"';
		// `resource`: the canonical URI uniquely identifying the underlying asset.
		$lines[] = 'resource: ' . get_permalink( $post );

		$tags = self::tags_for( $post );
		if ( ! empty( $tags ) ) {
			$lines[] = 'tags:';
			foreach ( $tags as $tag ) {
				$lines[] = '  - "' . self::yaml_escape( $tag ) . '"';
			}
		}

		// ISO 8601 datetime of last meaningful change.
		$lines[] = 'timestamp: ' . get_post_modified_time( 'c', true, $post );
		$lines[] = '---';

		return implode( "\n", $lines );
	}

	/**
	 * Map a WordPress post type to an OKF `type` string. Always non-empty (conformance).
	 * "Article"/"Page" are the natural schema.org-aligned names for the core types;
	 * other types use their registered singular label.
	 */
	private static function map_type( WP_Post $post ): string {
		switch ( $post->post_type ) {
			case 'post':
				return 'Article';
			case 'page':
				return 'Page';
			default:
				$obj = get_post_type_object( $post->post_type );
				$label = ( $obj && ! empty( $obj->labels->singular_name ) ) ? $obj->labels->singular_name : ucfirst( $post->post_type );
				return self::clean( $label ) !== '' ? self::clean( $label ) : 'Document';
		}
	}

	/** Category + tag names as OKF tags. */
	private static function tags_for( WP_Post $post ): array {
		$out = array();
		foreach ( array( 'category', 'post_tag' ) as $tax ) {
			$terms = get_the_terms( $post->ID, $tax );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$out[] = $term->name;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function excerpt( WP_Post $post ): string {
		if ( ! empty( $post->post_excerpt ) ) {
			return $post->post_excerpt;
		}
		return wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '…' );
	}

	// ── Post lookup ────────────────────────────────────────────────────────

	/**
	 * Published posts for the bundle.
	 *
	 * @param array  $types    Post types.
	 * @param int    $limit    Max posts (-1 = all).
	 * @param string $orderby  'modified' or 'date'.
	 * @return WP_Post[]
	 */
	private static function query_posts( array $types, int $limit = -1, string $orderby = 'date' ): array {
		if ( empty( $types ) ) {
			return array();
		}
		$posts = get_posts( array(
			'post_type'        => $types,
			'post_status'      => 'publish',
			'has_password'     => false, // TC-SEC-01: exclude password-protected posts.
			'numberposts'      => $limit,
			'orderby'          => 'modified' === $orderby ? 'modified' : 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
			'no_found_rows'    => true,
		) );

		// v1.1.5 — respect the SAME exclusion rules as llms.txt: the per-post
		// "Exclude from llms.txt" toggle and SEO-plugin noindex (Yoast / Rank Math /
		// AIOSEO / SEOPress). A post a user has hidden from AI crawlers must not leak
		// into the OKF bundle either. Single source of truth: RNRD_Llms_Txt.
		if ( class_exists( 'RNRD_Llms_Txt' ) ) {
			$posts = array_values( array_filter( $posts, static function ( $p ) {
				return $p instanceof WP_Post && ! RNRD_Llms_Txt::should_exclude_from_llms( $p );
			} ) );
		}

		return $posts;
	}

	/** Whether a single post may appear in the bundle (mirrors llms.txt exclusion). */
	private static function is_includable( WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( ! empty( $post->post_password ) ) { // TC-SEC-01: never expose password-protected content in the OKF bundle.
			return false;
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return false;
		}
		if ( class_exists( 'RNRD_Llms_Txt' ) && RNRD_Llms_Txt::should_exclude_from_llms( $post ) ) {
			return false;
		}
		return true;
	}

	/** /okf/<slug>.md uses the post slug. */
	private static function concept_slug( WP_Post $post ): string {
		return $post->post_name;
	}

	/** Resolve an /okf/<slug>.md request back to a published post in an enabled type. */
	private static function resolve_slug( string $slug ): ?WP_Post {
		if ( '' === $slug ) {
			return null;
		}
		$posts = get_posts( array(
			'name'             => $slug,
			'post_type'        => self::post_types(),
			'post_status'      => 'publish',
			'has_password'     => false, // TC-SEC-01: exclude password-protected posts.
			'numberposts'      => 1,
			'suppress_filters' => false,
			'no_found_rows'    => true,
		) );
		return ! empty( $posts ) ? $posts[0] : null;
	}

	// ── Serving ────────────────────────────────────────────────────────────

	/**
	 * Emit a markdown document with the same cache/CORS/ETag policy RankReady uses for
	 * its distinct .md endpoints. /okf/*.md always returns markdown regardless of
	 * request headers, so it is safe to cache publicly. Strong ETag → 304 on revalidation.
	 *
	 * @param string $markdown    The bundle document.
	 * @param bool   $shared_url  Always false here (distinct URLs); kept for parity.
	 */
	private static function serve( string $markdown, bool $shared_url = false ): void {
		$etag = '"' . md5( $markdown ) . '"';

		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) )
			: '';
		if ( '' !== $inm && $inm === $etag ) {
			header( 'ETag: ' . $etag );
			status_header( 304 );
			exit;
		}

		$ttl = (int) apply_filters( 'rankready_okf_cache_max_age', HOUR_IN_SECONDS );
		if ( $ttl > 0 ) {
			header( 'Cache-Control: public, max-age=' . $ttl . ', s-maxage=' . $ttl . ', stale-while-revalidate=86400' );
		} elseif ( class_exists( 'RNRD_Cache' ) ) {
			RNRD_Cache::no_cache_headers();
		}

		// Assert 200 explicitly — see the note in RNRD_Llms_Txt::serve_llms_txt().
		// OKF hooks template_redirect at priority 0, still after the main query.
		status_header( 200 );

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Vary: Accept-Encoding' );
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'ETag: ' . $etag );

		// CORS — AI agents fetch /okf/ cross-origin from their browser runtime.
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, HEAD, OPTIONS' );
		header( 'Access-Control-Expose-Headers: Content-Type, ETag, Last-Modified' );

		header( 'X-RankReady-Source: okf' );

		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text markdown body, not HTML.
		exit;
	}

	// ── Bundle .zip export ─────────────────────────────────────────────────

	/** Build the bundle as an array of relative-path => contents. */
	public static function build_bundle_files(): array {
		$files = array(
			'index.md' => self::build_index_markdown(),
			'log.md'   => self::build_log_markdown(),
		);
		foreach ( self::query_posts( self::post_types() ) as $post ) {
			$files[ self::concept_slug( $post ) . '.md' ] = self::build_concept_markdown( $post );
		}
		return $files;
	}

	/** admin-post handler: stream the bundle as a .zip download. */
	public static function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export the OKF bundle.', 'rankready-ai-llm-seo' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );

		if ( ! self::enabled() ) {
			wp_die( esc_html__( 'The OKF bundle is not enabled.', 'rankready-ai-llm-seo' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'The PHP Zip extension is required to export the OKF bundle.', 'rankready-ai-llm-seo' ) );
		}

		$files = self::build_bundle_files();

		$tmp = wp_tempnam( 'rnrd-okf' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not create the OKF bundle archive.', 'rankready-ai-llm-seo' ) );
		}
		$zip->addEmptyDir( 'okf' );
		foreach ( $files as $name => $contents ) {
			$zip->addFromString( 'okf/' . $name, $contents );
		}
		$zip->close();

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="okf-bundle.zip"' );
		header( 'Content-Length: ' . (int) filesize( $tmp ) );
		// Stream the archive straight from disk rather than buffering the whole zip into a
		// PHP string — keeps peak memory flat regardless of bundle size on large sites.
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a local temp file to the browser.
		wp_delete_file( $tmp );
		exit;
	}

	/** URL of the bundle .zip download (admin). */
	public static function export_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION ), self::EXPORT_ACTION );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private static function clean( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( (string) $text );
	}

	private static function yaml_escape( string $text ): string {
		return str_replace( '"', '\\"', self::clean( $text ) );
	}
}
