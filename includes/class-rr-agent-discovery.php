<?php
/**
 * Agent Discovery endpoints — /.well-known/ routes for AI agent standards.
 *
 * Implements two RFC-backed discovery endpoints:
 *
 * - /.well-known/agent-skills/index.json  (Cloudflare Agent Skills Discovery RFC)
 *   Lists the AI-accessible capabilities this site exposes. Auto-built from
 *   enabled RankReady features (llms.txt, markdown endpoints, sitemap, robots.txt).
 *
 * - /.well-known/api-catalog  (RFC 9727)
 *   Linkset (RFC 9264) describing the site's public APIs so agents can discover
 *   what interfaces are available (WP REST API, llms.txt, markdown stream).
 *   Content-Type: application/linkset+json
 *
 * Both endpoints are served via WordPress rewrite rules so they work without
 * creating physical files on disk. Requires that /.well-known/ paths reach PHP —
 * on Nginx/Apache with standard WordPress rewrites this works out of the box.
 * If a Cloudflare WAF rule blocks /.well-known/, add a page rule to skip it for
 * these specific paths.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Agent_Discovery {

	public static function init(): void {
		add_action( 'init',              array( self::class, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( self::class, 'handle_request' ) );

		add_filter( 'query_vars', array( self::class, 'register_query_vars' ) );

		// Prevent trailing-slash redirect on /.well-known/ paths.
		add_filter( 'redirect_canonical', array( self::class, 'prevent_well_known_trailing_slash' ), 10, 2 );

		// Flush rewrite rules when either feature is toggled.
		add_action( 'update_option_' . RR_OPT_AGENT_SKILLS_ENABLE, array( self::class, 'flush_rules' ) );
		add_action( 'update_option_' . RR_OPT_API_CATALOG_ENABLE,  array( self::class, 'flush_rules' ) );
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = 'rr_agent_skills';
		$vars[] = 'rr_api_catalog';
		return $vars;
	}

	public static function add_rewrite_rules(): void {
		if ( 'on' === get_option( RR_OPT_AGENT_SKILLS_ENABLE, 'off' ) ) {
			add_rewrite_rule(
				'^\.well-known/agent-skills/index\.json$',
				'index.php?rr_agent_skills=1',
				'top'
			);
		}

		if ( 'on' === get_option( RR_OPT_API_CATALOG_ENABLE, 'off' ) ) {
			add_rewrite_rule(
				'^\.well-known/api-catalog$',
				'index.php?rr_api_catalog=1',
				'top'
			);
		}
	}

	public static function flush_rules(): void {
		flush_rewrite_rules( false );
	}

	public static function prevent_well_known_trailing_slash( $redirect_url, $requested_url ) {
		if ( preg_match( '/\/\.well-known\//i', $requested_url ) ) {
			return false;
		}
		return $redirect_url;
	}

	// ── Request router ─────────────────────────────────────────────────────────

	public static function handle_request(): void {
		if ( get_query_var( 'rr_agent_skills' ) ) {
			self::serve_agent_skills();
		}

		if ( get_query_var( 'rr_api_catalog' ) ) {
			self::serve_api_catalog();
		}
	}

	// ── Agent Skills index ─────────────────────────────────────────────────────
	// Format: Cloudflare Agent Skills Discovery RFC
	// https://github.com/cloudflare/agent-skills-discovery-rfc

	private static function serve_agent_skills(): void {
		if ( 'on' !== get_option( RR_OPT_AGENT_SKILLS_ENABLE, 'off' ) ) {
			status_header( 404 );
			exit;
		}

		$home    = home_url( '/' );
		$skills  = array();

		// robots.txt — always present on any WordPress site.
		$skills[] = array(
			'id'          => 'robots-txt',
			'name'        => 'robots.txt',
			'description' => 'Crawl permissions and AI bot rules for this site.',
			'url'         => home_url( '/robots.txt' ),
			'type'        => 'access-control',
		);

		// Sitemap.
		$sitemap_url = get_option( 'permalink_structure' ) ? home_url( '/sitemap_index.xml' ) : home_url( '/?sitemap=1' );
		$skills[]    = array(
			'id'          => 'sitemap',
			'name'        => 'XML Sitemap',
			'description' => 'Full list of crawlable URLs on this site.',
			'url'         => $sitemap_url,
			'type'        => 'discoverability',
		);

		// LLMs.txt — if enabled.
		if ( 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' ) ) {
			$skills[] = array(
				'id'          => 'llms-txt',
				'name'        => 'LLMs.txt',
				'description' => 'Machine-readable site index following the llmstxt.org specification.',
				'url'         => home_url( '/llms.txt' ),
				'type'        => 'content',
			);
		}

		// LLMs-full.txt — if enabled.
		if ( 'on' === get_option( RR_OPT_LLMS_FULL_ENABLE, 'off' ) ) {
			$skills[] = array(
				'id'          => 'llms-full-txt',
				'name'        => 'LLMs-full.txt',
				'description' => 'Full site content inlined as clean markdown for AI ingestion.',
				'url'         => home_url( '/llms-full.txt' ),
				'type'        => 'content',
			);
		}

		// Markdown endpoints — if enabled.
		if ( 'on' === get_option( RR_OPT_MD_ENABLE, 'off' ) ) {
			$skills[] = array(
				'id'          => 'markdown-endpoints',
				'name'        => 'Markdown Endpoints',
				'description' => 'Every page is available as clean markdown by appending .md to its URL (e.g. /post-slug.md). Also supports Accept: text/markdown content negotiation.',
				'url'         => home_url( '/' ),
				'type'        => 'content',
				'hint'        => 'Append .md to any page URL, or send Accept: text/markdown.',
			);
		}

		$index = array(
			'$schema'     => 'https://agentskills.io/schema/v1/index.schema.json',
			'version'     => '1.0',
			'site'        => array(
				'name' => get_bloginfo( 'name' ),
				'url'  => $home,
			),
			'skills'      => $skills,
			'generated'   => gmdate( 'c' ),
			'generator'   => 'RankReady/' . RR_VERSION,
		);

		self::serve_json( $index, 'application/json' );
	}

	// ── API Catalog ────────────────────────────────────────────────────────────
	// Format: RFC 9727 (API Catalog) — linkset per RFC 9264
	// Content-Type: application/linkset+json

	private static function serve_api_catalog(): void {
		if ( 'on' !== get_option( RR_OPT_API_CATALOG_ENABLE, 'off' ) ) {
			status_header( 404 );
			exit;
		}

		$home  = home_url( '/' );
		$links = array();

		// WP REST API.
		$links[] = array(
			'href'        => home_url( '/wp-json/' ),
			'type'        => 'application/json',
			'title'       => 'WordPress REST API',
			'description' => 'WordPress core REST API providing access to posts, pages, and site data.',
		);

		// LLMs.txt — if enabled.
		if ( 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' ) ) {
			$links[] = array(
				'href'        => home_url( '/llms.txt' ),
				'type'        => 'text/plain',
				'title'       => 'LLMs.txt Index',
				'description' => 'Site content index in llmstxt.org format for AI models.',
			);
		}

		// Markdown endpoints — if enabled.
		if ( 'on' === get_option( RR_OPT_MD_ENABLE, 'off' ) ) {
			$links[] = array(
				'href'        => $home,
				'type'        => 'text/markdown',
				'title'       => 'Markdown Content Stream',
				'description' => 'Site content accessible as markdown via Accept: text/markdown or *.md URL suffix.',
			);
		}

		// RFC 9727 linkset format.
		$catalog = array(
			'linkset' => array(
				array(
					'anchor'   => $home,
					'item'     => $links,
				),
			),
		);

		self::serve_json( $catalog, 'application/linkset+json' );
	}

	// ── JSON output ───────────────────────────────────────────────────────────

	private static function serve_json( array $data, string $content_type ): void {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $content_type . '; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Access-Control-Allow-Origin: *' );

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}
}
