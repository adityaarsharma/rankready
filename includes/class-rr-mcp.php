<?php
/**
 * RankReady — WebMCP via WordPress Abilities API.
 *
 * Registers RankReady's read-only content surfaces as typed WordPress
 * Abilities (WP 6.9+ Abilities API). Any AI agent that speaks Model Context
 * Protocol (Claude Desktop, Cursor, VS Code, custom OpenAI-Functions agents)
 * can then discover and call these abilities — turning the WordPress site
 * into a first-class agentic tool, not just an HTML source to scrape.
 *
 * Registered abilities (namespace `rankready/`):
 *   - get-site-info       → name, description, URL, brand terms, languages
 *   - get-brand-terms     → canonical brand names array
 *   - search-posts        → keyword search across published content
 *   - get-post-summary    → AI summary bullets for a post
 *   - get-post-faq        → FAQ Q&A array for a post
 *   - list-recent-posts   → paginated list with titles, URLs, modified dates
 *
 * Discovery:
 *   GET /.well-known/mcp.json    → MCP-style manifest listing abilities
 *   GET /wp-json/wp/v2/abilities → core WP route (when Abilities API plugin is active)
 *
 * Security:
 *   - Read-only; no write abilities exposed.
 *   - All abilities filter to published, public-status posts.
 *   - Permission callback returns true for all reads (matches public REST
 *     headless endpoints) — host site can add auth via standard WP filters.
 *
 * Graceful degradation:
 *   - If the Abilities API plugin is not active, this class no-ops silently.
 *     RankReady stays fully functional; only the MCP layer is dormant.
 *
 * @package RankReady
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

class RR_MCP {

	private const NS = 'rankready';

	public static function init(): void {
		// Abilities API registers on its own init hook ('abilities_api_init')
		// when the plugin is active. We hook there if the API is loaded; else
		// no-op cleanly. The handler itself bails when the master toggle is off.
		add_action( 'abilities_api_init', array( self::class, 'register_abilities' ) );

		// /.well-known/mcp.json manifest — works whether or not Abilities API
		// is active, so agents can still discover the site even on older WP.
		// The handler bails on 404 when the master toggle is off.
		add_action( 'init',              array( self::class, 'add_manifest_rewrite' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_serve_manifest' ) );
		add_filter( 'query_vars',        array( self::class, 'register_query_vars' ) );
	}

	/**
	 * Master toggle check. Defaults to enabled — agent visibility is the
	 * core value RankReady ships, opt-out rather than opt-in.
	 */
	public static function is_enabled(): bool {
		return 'on' === get_option( RR_OPT_MCP_ENABLE, 'on' );
	}

	// ── Manifest endpoint ─────────────────────────────────────────────────

	public static function register_query_vars( array $vars ): array {
		$vars[] = 'rr_mcp_manifest';
		return $vars;
	}

	public static function add_manifest_rewrite(): void {
		add_rewrite_rule( '^\.well-known/mcp\.json$', 'index.php?rr_mcp_manifest=1', 'top' );
	}

	public static function maybe_serve_manifest(): void {
		if ( ! get_query_var( 'rr_mcp_manifest' ) ) {
			return;
		}
		if ( ! self::is_enabled() ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo '404 Not Found';
			exit;
		}
		self::serve_manifest();
	}

	private static function serve_manifest(): void {
		$brand_terms = class_exists( 'RR_Llms_Txt' ) ? RR_Llms_Txt::get_brand_terms_list() : array();

		$manifest = array(
			'mcpVersion' => '2024-11-05',
			'name'       => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'website'    => home_url( '/' ),
			'brand'      => $brand_terms,
			'tools'      => self::tool_descriptors(),
			'discovery'  => array(
				'llms_txt'         => home_url( '/llms.txt' ),
				'llms_full_txt'    => home_url( '/llms-full.txt' ),
				'sitemap'          => home_url( '/sitemap.xml' ),
				'abilities_api'    => function_exists( 'wp_register_ability' ) ? rest_url( 'wp/v2/abilities' ) : null,
				'public_rest_base' => rest_url( 'rankready/v1/public' ),
			),
			'generator'  => 'RankReady ' . RR_VERSION,
		);

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=300' );
		echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		exit;
	}

	private static function tool_descriptors(): array {
		return array(
			array(
				'name'        => 'rankready/get-site-info',
				'description' => 'Returns site identity: name, description, URL, brand terms, language.',
				'method'      => 'GET',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/get-site-info' ),
			),
			array(
				'name'        => 'rankready/get-brand-terms',
				'description' => 'Returns canonical brand names for entity consistency.',
				'method'      => 'GET',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/get-brand-terms' ),
			),
			array(
				'name'        => 'rankready/search-posts',
				'description' => 'Keyword search across published posts. Returns title, URL, excerpt, modified date.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/search-posts' ),
				'inputs'      => array( 'query' => 'string', 'limit' => 'integer (1-20)' ),
			),
			array(
				'name'        => 'rankready/get-post-summary',
				'description' => 'Returns RankReady-generated AI summary (key takeaways) for a post.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/get-post-summary' ),
				'inputs'      => array( 'post_id' => 'integer' ),
			),
			array(
				'name'        => 'rankready/get-post-faq',
				'description' => 'Returns RankReady-generated FAQ question/answer pairs for a post.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/get-post-faq' ),
				'inputs'      => array( 'post_id' => 'integer' ),
			),
			array(
				'name'        => 'rankready/list-recent-posts',
				'description' => 'Paginated list of recently modified posts.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/list-recent-posts' ),
				'inputs'      => array( 'limit' => 'integer (1-50)', 'offset' => 'integer' ),
			),
			// v1.2.0-beta.5 — Expanded surface (10 new abilities).
			array(
				'name'        => 'rankready/get-post',
				'description' => 'Full post content (markdown) + title + URL + author + summary + FAQ + schema in one call.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/get-post' ),
				'inputs'      => array( 'post_id' => 'integer' ),
			),
			array(
				'name'        => 'rankready/get-post-by-url',
				'description' => 'Resolve any URL (incl. .md, /category/x/, /tag/y/) to a post or term.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/get-post-by-url' ),
				'inputs'      => array( 'url' => 'string (URL)' ),
			),
			array(
				'name'        => 'rankready/list-pages',
				'description' => 'Static pages (About / Pricing / Docs). Returns parent_id for hierarchy.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/list-pages' ),
				'inputs'      => array( 'limit' => 'integer (1-100)', 'offset' => 'integer' ),
			),
			array(
				'name'        => 'rankready/list-content-types',
				'description' => 'Every public post type this site exposes + published count + archive URL.',
				'method'      => 'GET',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/list-content-types' ),
			),
			array(
				'name'        => 'rankready/list-categories',
				'description' => 'Categories ordered by post count. Name, slug, parent, count, URL.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/list-categories' ),
				'inputs'      => array( 'limit' => 'integer (1-200)' ),
			),
			array(
				'name'        => 'rankready/list-tags',
				'description' => 'Tags ordered by post count. Name, slug, count, URL.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/list-tags' ),
				'inputs'      => array( 'limit' => 'integer (1-200)' ),
			),
			array(
				'name'        => 'rankready/get-llms-txt',
				'description' => 'Returns the rendered llms.txt (or llms-full.txt) content inline.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/get-llms-txt' ),
				'inputs'      => array( 'full' => 'boolean' ),
			),
			array(
				'name'        => 'rankready/get-author',
				'description' => 'EEAT Person fields: bio, job title, credentials, education, awards, socials.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/get-author' ),
				'inputs'      => array( 'author_id' => 'integer' ),
			),
			array(
				'name'        => 'rankready/get-sitemap',
				'description' => 'Parsed sitemap — URLs + lastmod for every published post + page.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/get-sitemap' ),
				'inputs'      => array( 'limit' => 'integer (1-2000)' ),
			),
			array(
				'name'        => 'rankready/get-fresh-content',
				'description' => 'Posts/pages modified in last N days. AI engines prioritise fresh content.',
				'method'      => 'POST',
				'endpoint'    => rest_url( 'wp/v2/abilities/' . self::NS . '/get-fresh-content' ),
				'inputs'      => array( 'days' => 'integer (1-365)', 'limit' => 'integer (1-100)' ),
			),
		);
	}

	// ── Abilities API registration ────────────────────────────────────────

	/**
	 * Permission callback shared by every RankReady MCP ability.
	 *
	 * Read-only public — same trust level as `/.well-known/mcp.json` and
	 * the headless REST surface — BUT gated by the master toggle so turning
	 * MCP off in admin actually shuts off the abilities (audit beta.3 #3).
	 *
	 * @return true|WP_Error true when allowed, WP_Error 503 otherwise.
	 */
	public static function ability_permission() {
		if ( ! self::is_enabled() ) {
			return new WP_Error(
				'rr_mcp_disabled',
				__( 'WebMCP is disabled on this site.', 'rankready' ),
				array( 'status' => 503 )
			);
		}
		return true;
	}

	public static function register_abilities(): void {
		if ( ! self::is_enabled() ) {
			return; // Master toggle off — skip Abilities registration entirely.
		}
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // Abilities API plugin not active — graceful no-op.
		}

		wp_register_ability( self::NS . '/get-site-info', array(
			'label'               => __( 'Get site info', 'rankready' ),
			'description'         => __( 'Returns site identity: name, description, URL, brand terms, language.', 'rankready' ),
			'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'name'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'url'         => array( 'type' => 'string', 'format' => 'uri' ),
					'language'    => array( 'type' => 'string' ),
					'brand_terms' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
			),
			'execute_callback'    => array( self::class, 'ability_get_site_info' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		wp_register_ability( self::NS . '/get-brand-terms', array(
			'label'               => __( 'Get brand terms', 'rankready' ),
			'description'         => __( 'Returns canonical brand names for entity consistency.', 'rankready' ),
			'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array( 'brand_terms' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
			),
			'execute_callback'    => array( self::class, 'ability_get_brand_terms' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		wp_register_ability( self::NS . '/search-posts', array(
			'label'               => __( 'Search posts', 'rankready' ),
			'description'         => __( 'Keyword search across published posts. Returns title, URL, excerpt, modified date.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'query' => array( 'type' => 'string', 'minLength' => 1, 'description' => 'Search keyword(s).' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10 ),
				),
				'required'             => array( 'query' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'results' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'title'    => array( 'type' => 'string' ),
								'url'      => array( 'type' => 'string', 'format' => 'uri' ),
								'excerpt'  => array( 'type' => 'string' ),
								'modified' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( self::class, 'ability_search_posts' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		wp_register_ability( self::NS . '/get-post-summary', array(
			'label'               => __( 'Get post AI summary', 'rankready' ),
			'description'         => __( 'Returns RankReady-generated AI summary (key takeaways) for a post.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'required'   => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'title'    => array( 'type' => 'string' ),
					'url'      => array( 'type' => 'string', 'format' => 'uri' ),
					'bullets'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
			),
			'execute_callback'    => array( self::class, 'ability_get_post_summary' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		wp_register_ability( self::NS . '/get-post-faq', array(
			'label'               => __( 'Get post FAQ', 'rankready' ),
			'description'         => __( 'Returns RankReady-generated FAQ question/answer pairs for a post.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'required'   => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'title' => array( 'type' => 'string' ),
					'url'   => array( 'type' => 'string', 'format' => 'uri' ),
					'faq'   => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'question' => array( 'type' => 'string' ),
								'answer'   => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( self::class, 'ability_get_post_faq' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		wp_register_ability( self::NS . '/list-recent-posts', array(
			'label'               => __( 'List recent posts', 'rankready' ),
			'description'         => __( 'Paginated list of recently modified posts.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10 ),
					'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'posts' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'title'    => array( 'type' => 'string' ),
								'url'      => array( 'type' => 'string', 'format' => 'uri' ),
								'modified' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'execute_callback'    => array( self::class, 'ability_list_recent_posts' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// ── v1.2.0-beta.5 — Expanded surface (10 new abilities) ─────────

		// 7. get-post — the "view document" primitive. Returns full markdown +
		//    metadata + summary + FAQ + schema in one call.
		wp_register_ability( self::NS . '/get-post', array(
			'label'               => __( 'Get post (full content)', 'rankready' ),
			'description'         => __( 'Returns full post content as clean Markdown plus title, URL, modified date, author, AI summary bullets, FAQ Q&A, and JSON-LD schema. The agent\'s primary content retrieval primitive.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				),
				'required'   => array( 'post_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'        => array( 'type' => 'integer' ),
					'title'     => array( 'type' => 'string' ),
					'url'       => array( 'type' => 'string', 'format' => 'uri' ),
					'md_url'    => array( 'type' => 'string', 'format' => 'uri' ),
					'post_type' => array( 'type' => 'string' ),
					'modified'  => array( 'type' => 'string' ),
					'author'    => array( 'type' => 'string' ),
					'excerpt'   => array( 'type' => 'string' ),
					'markdown'  => array( 'type' => 'string' ),
					'summary'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'faq'       => array( 'type' => 'array' ),
					'categories' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
			),
			'execute_callback'    => array( self::class, 'ability_get_post' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 8. get-post-by-url — resolve a URL (including /post-slug.md or
		//    /category/x/) → post payload. Lets an agent follow internal links.
		wp_register_ability( self::NS . '/get-post-by-url', array(
			'label'               => __( 'Get post by URL', 'rankready' ),
			'description'         => __( 'Resolve any site URL (incl. .md, category, tag URLs) to a post. Returns the same shape as get-post. Returns 404-like empty payload if the URL does not map to content.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'url' => array( 'type' => 'string', 'format' => 'uri' ),
				),
				'required'   => array( 'url' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),  // same shape as get-post
			'execute_callback'    => array( self::class, 'ability_get_post_by_url' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 9. list-pages — static pages separately from posts.
		wp_register_ability( self::NS . '/list-pages', array(
			'label'               => __( 'List pages', 'rankready' ),
			'description'         => __( 'List static pages (About, Pricing, Docs, etc.). Hierarchical — returns parent_id so an agent can rebuild the page tree.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
					'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_list_pages' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 10. list-content-types — what post types this site exposes.
		wp_register_ability( self::NS . '/list-content-types', array(
			'label'               => __( 'List content types', 'rankready' ),
			'description'         => __( 'Returns every public post type the site exposes (Posts, Pages, Products, Docs, etc.) so the agent can target queries to the right type.', 'rankready' ),
			'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_list_content_types' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 11. list-categories — taxonomy discovery.
		wp_register_ability( self::NS . '/list-categories', array(
			'label'               => __( 'List categories', 'rankready' ),
			'description'         => __( 'Browse the site\'s topical hierarchy. Returns category name, slug, parent, post count, and archive URL.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_list_categories' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 12. list-tags — same shape, different taxonomy.
		wp_register_ability( self::NS . '/list-tags', array(
			'label'               => __( 'List tags', 'rankready' ),
			'description'         => __( 'List tags ordered by post count. Returns name, slug, post count, archive URL.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_list_tags' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 13. get-llms-txt — return the rendered llms.txt inline.
		wp_register_ability( self::NS . '/get-llms-txt', array(
			'label'               => __( 'Get llms.txt', 'rankready' ),
			'description'         => __( 'Returns the rendered /llms.txt content inline so the agent does not have to make a separate HTTP fetch. Same content as the file endpoint.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'full' => array( 'type' => 'boolean', 'default' => false, 'description' => 'When true, returns llms-full.txt content (every page inlined).' ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_get_llms_txt' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 14. get-author — EEAT Person schema fields for an author.
		wp_register_ability( self::NS . '/get-author', array(
			'label'               => __( 'Get author (EEAT Person schema)', 'rankready' ),
			'description'         => __( 'Returns full EEAT Person schema for a WordPress author: name, bio, job title, employer, credentials, education, awards, social profiles. Drives AI trust signals.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'author_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				),
				'required'   => array( 'author_id' ),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_get_author' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 15. get-sitemap — parsed sitemap index for cold crawls.
		wp_register_ability( self::NS . '/get-sitemap', array(
			'label'               => __( 'Get sitemap', 'rankready' ),
			'description'         => __( 'Returns a parsed sitemap (URL + lastmod) of every published post + page. Agent\'s first call when discovering a site cold.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'default' => 500 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_get_sitemap' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );

		// 16. get-fresh-content — posts modified in last N days.
		wp_register_ability( self::NS . '/get-fresh-content', array(
			'label'               => __( 'Get fresh content', 'rankready' ),
			'description'         => __( 'Posts and pages modified within the last N days. AI engines prioritise fresh content — this ability surfaces what to read first when context is limited.', 'rankready' ),
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'days'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30 ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 ),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => array( self::class, 'ability_get_fresh_content' ),
			'permission_callback' => array( self::class, 'ability_permission' ),
		) );
	}

	// ── Ability implementations ───────────────────────────────────────────

	public static function ability_get_site_info(): array {
		// v1.2.0-beta.4 — return the unified Brand Identity so agents see
		// the same canonical name + summary + about + terms that humans see.
		$brand = class_exists( 'RR_Llms_Txt' )
			? RR_Llms_Txt::get_brand_identity()
			: array(
				'name'    => (string) get_bloginfo( 'name' ),
				'summary' => (string) get_bloginfo( 'description' ),
				'about'   => '',
				'terms'   => array(),
			);

		return array(
			'name'        => $brand['name'],
			'description' => $brand['summary'],
			'about'       => $brand['about'],
			'url'         => home_url( '/' ),
			'language'    => (string) get_bloginfo( 'language' ),
			'brand_terms' => $brand['terms'],
		);
	}

	public static function ability_get_brand_terms(): array {
		return array(
			'brand_terms' => class_exists( 'RR_Llms_Txt' ) ? RR_Llms_Txt::get_brand_terms_list() : array(),
		);
	}

	public static function ability_search_posts( array $input ): array {
		$query = isset( $input['query'] ) ? (string) $input['query'] : '';
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$limit = max( 1, min( 20, $limit ) );

		$post_types = (array) get_option( RR_OPT_POST_TYPES, array( 'post' ) );
		if ( ! in_array( 'page', $post_types, true ) ) {
			$post_types[] = 'page';
		}

		$posts = get_posts( array(
			's'              => $query,
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'relevance',
		) );

		$results = array();
		foreach ( $posts as $p ) {
			$results[] = array(
				'id'       => (int) $p->ID,
				'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'      => get_permalink( $p ),
				'excerpt'  => wp_strip_all_tags( ! empty( $p->post_excerpt ) ? $p->post_excerpt : wp_trim_words( $p->post_content, 40 ) ),
				'modified' => mysql2date( 'c', $p->post_modified_gmt ),
			);
		}

		return array( 'results' => $results );
	}

	public static function ability_get_post_summary( array $input ): array {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return array( 'title' => '', 'url' => '', 'bullets' => array() );
		}

		$bullets = array();
		$raw     = (string) get_post_meta( $post->ID, RR_META_SUMMARY, true );
		if ( '' !== $raw && class_exists( 'RR_Generator' ) ) {
			$decoded = RR_Generator::decode_summary( $raw );
			if ( 'bullets' === $decoded['type'] ) {
				$bullets = array_values( (array) $decoded['data'] );
			}
		}

		return array(
			'title'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'     => get_permalink( $post ),
			'bullets' => $bullets,
		);
	}

	public static function ability_get_post_faq( array $input ): array {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return array( 'title' => '', 'url' => '', 'faq' => array() );
		}

		$faq = class_exists( 'RR_Faq' ) ? RR_Faq::get_faq_data( $post->ID ) : array();

		return array(
			'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'   => get_permalink( $post ),
			'faq'   => array_values( $faq ),
		);
	}

	public static function ability_list_recent_posts( array $input ): array {
		$limit  = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
		$limit  = max( 1, min( 50, $limit ) );
		$offset = max( 0, $offset );

		$post_types = (array) get_option( RR_OPT_POST_TYPES, array( 'post' ) );
		if ( ! in_array( 'page', $post_types, true ) ) {
			$post_types[] = 'page';
		}

		$posts = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'       => (int) $p->ID,
				'title'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'      => get_permalink( $p ),
				'modified' => mysql2date( 'c', $p->post_modified_gmt ),
			);
		}

		return array( 'posts' => $out );
	}

	// ── v1.2.0-beta.5 — Expanded ability implementations ─────────────────

	/**
	 * Resolve a WP_Post to the canonical "post payload" the agent sees from
	 * get-post and get-post-by-url. Shared helper so both abilities return
	 * the same shape.
	 */
	private static function post_payload( WP_Post $post ): array {
		$post_id   = (int) $post->ID;
		$summary   = array();
		$summary_raw = (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
		if ( '' !== $summary_raw && class_exists( 'RR_Generator' ) ) {
			$decoded = RR_Generator::decode_summary( $summary_raw );
			if ( 'bullets' === $decoded['type'] ) {
				$summary = array_values( (array) $decoded['data'] );
			}
		}

		$faq = class_exists( 'RR_Faq' ) ? RR_Faq::get_faq_data( $post_id ) : array();

		$markdown = class_exists( 'RR_Markdown' ) ? RR_Markdown::post_to_markdown( $post ) : '';
		$md_url   = class_exists( 'RR_Markdown' ) ? RR_Markdown::get_md_url( $post ) : '';

		$author_name = (string) get_the_author_meta( 'display_name', $post->post_author );

		$cats = wp_get_post_categories( $post_id, array( 'fields' => 'names' ) );
		$tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );

		return array(
			'id'         => $post_id,
			'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'        => get_permalink( $post ),
			'md_url'     => $md_url,
			'post_type'  => $post->post_type,
			'modified'   => mysql2date( 'c', $post->post_modified_gmt ),
			'author'     => $author_name,
			'author_id'  => (int) $post->post_author,
			'excerpt'    => wp_strip_all_tags( ! empty( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( $post->post_content, 50 ) ),
			'markdown'   => $markdown,
			'summary'    => $summary,
			'faq'        => array_values( $faq ),
			'categories' => is_array( $cats ) && ! is_wp_error( $cats ) ? array_values( $cats ) : array(),
			'tags'       => is_array( $tags ) && ! is_wp_error( $tags ) ? array_values( $tags ) : array(),
		);
	}

	public static function ability_get_post( array $input ): array {
		$post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return array( 'id' => 0, 'title' => '', 'markdown' => '' );
		}
		return self::post_payload( $post );
	}

	public static function ability_get_post_by_url( array $input ): array {
		$url = isset( $input['url'] ) ? esc_url_raw( (string) $input['url'] ) : '';
		if ( '' === $url ) {
			return array( 'id' => 0 );
		}

		// Strip trailing .md so the resolver can also be passed a .md URL.
		$probe = preg_replace( '/\.md(\/?)?$/', '$1', $url );

		$post_id = url_to_postid( $probe );
		if ( $post_id <= 0 ) {
			// Try category / tag archive URLs — return empty content + the term info.
			$term_url = trim( wp_parse_url( $probe, PHP_URL_PATH ) ?? '', '/' );
			if ( '' !== $term_url ) {
				$segments = explode( '/', $term_url );
				if ( count( $segments ) >= 2 && in_array( $segments[0], array( 'category', 'tag' ), true ) ) {
					$tax  = 'category' === $segments[0] ? 'category' : 'post_tag';
					$slug = end( $segments );
					$term = get_term_by( 'slug', $slug, $tax );
					if ( $term && ! is_wp_error( $term ) ) {
						return array(
							'id'         => 0,
							'type'       => 'term',
							'taxonomy'   => $tax,
							'term'       => array(
								'name'  => $term->name,
								'slug'  => $term->slug,
								'count' => (int) $term->count,
								'url'   => get_term_link( $term ),
							),
						);
					}
				}
			}
			return array( 'id' => 0 );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return array( 'id' => 0 );
		}
		return self::post_payload( $post );
	}

	public static function ability_list_pages( array $input ): array {
		$limit  = isset( $input['limit'] )  ? (int) $input['limit']  : 50;
		$offset = isset( $input['offset'] ) ? (int) $input['offset'] : 0;
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		) );

		$out = array();
		foreach ( $pages as $p ) {
			$out[] = array(
				'id'        => (int) $p->ID,
				'title'     => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'       => get_permalink( $p ),
				'parent_id' => (int) $p->post_parent,
				'modified'  => mysql2date( 'c', $p->post_modified_gmt ),
			);
		}
		return array( 'pages' => $out );
	}

	public static function ability_list_content_types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$out   = array();
		foreach ( $types as $slug => $obj ) {
			if ( 'attachment' === $slug ) {
				continue;
			}
			// Live post counts so the agent knows what's worth querying.
			$counts = wp_count_posts( $slug );
			$out[] = array(
				'slug'         => $slug,
				'label'        => $obj->labels->name,
				'singular'     => $obj->labels->singular_name,
				'hierarchical' => (bool) $obj->hierarchical,
				'published'    => isset( $counts->publish ) ? (int) $counts->publish : 0,
				'archive_url'  => get_post_type_archive_link( $slug ) ?: '',
			);
		}
		return array( 'content_types' => $out );
	}

	public static function ability_list_categories( array $input ): array {
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
		$limit = max( 1, min( 200, $limit ) );

		$cats = get_categories( array(
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => $limit,
			'hide_empty' => false,
		) );

		$out = array();
		foreach ( $cats as $c ) {
			$out[] = array(
				'id'        => (int) $c->term_id,
				'name'      => $c->name,
				'slug'      => $c->slug,
				'parent_id' => (int) $c->parent,
				'count'     => (int) $c->count,
				'url'       => get_category_link( $c->term_id ),
			);
		}
		return array( 'categories' => $out );
	}

	public static function ability_list_tags( array $input ): array {
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
		$limit = max( 1, min( 200, $limit ) );

		$tags = get_tags( array(
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => $limit,
			'hide_empty' => false,
		) );

		$out = array();
		foreach ( $tags as $t ) {
			$out[] = array(
				'id'    => (int) $t->term_id,
				'name'  => $t->name,
				'slug'  => $t->slug,
				'count' => (int) $t->count,
				'url'   => get_tag_link( $t->term_id ),
			);
		}
		return array( 'tags' => $out );
	}

	public static function ability_get_llms_txt( array $input ): array {
		$full = ! empty( $input['full'] );
		if ( ! class_exists( 'RR_Llms_Txt' ) ) {
			return array( 'content' => '', 'enabled' => false );
		}

		// Respect the master toggles — don't return content the file endpoint
		// itself would 404 on.
		$llms_on = 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' );
		$full_on = 'on' === get_option( RR_OPT_LLMS_FULL_ENABLE, 'off' );

		if ( ! $llms_on ) {
			return array( 'content' => '', 'enabled' => false, 'note' => 'llms.txt is disabled on this site.' );
		}
		if ( $full && ! $full_on ) {
			return array( 'content' => '', 'enabled' => false, 'note' => 'llms-full.txt is disabled on this site.' );
		}

		$content = $full ? RR_Llms_Txt::generate_full() : RR_Llms_Txt::generate();
		return array(
			'content' => $content,
			'enabled' => true,
			'url'     => home_url( $full ? '/llms-full.txt' : '/llms.txt' ),
		);
	}

	public static function ability_get_author( array $input ): array {
		$author_id = isset( $input['author_id'] ) ? (int) $input['author_id'] : 0;
		$user      = $author_id ? get_userdata( $author_id ) : null;
		if ( ! $user ) {
			return array( 'id' => 0 );
		}

		// Return the standard set of EEAT fields RankReady's Author Box records.
		$fields = array(
			'job_title', 'employer', 'employer_url', 'bio', 'headshot', 'headshot_alt',
			'started_year', 'expertise', 'credentials_suffix', 'education', 'certifications',
			'memberships', 'awards', 'wikidata', 'wikipedia', 'orcid', 'scholar',
			'linkedin', 'github', 'youtube', 'twitter', 'website', 'contact_url',
		);

		$eeat = array();
		foreach ( $fields as $key ) {
			$val = get_user_meta( $user->ID, 'rr_author_' . $key, true );
			if ( '' !== (string) $val ) {
				$eeat[ $key ] = is_array( $val ) ? $val : (string) $val;
			}
		}

		return array(
			'id'          => (int) $user->ID,
			'name'        => $user->display_name,
			'url'         => get_author_posts_url( $user->ID ),
			'description' => $user->description,
			'eeat'        => $eeat,
		);
	}

	public static function ability_get_sitemap( array $input ): array {
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 500;
		$limit = max( 1, min( 2000, $limit ) );

		$entries = get_posts( array(
			'post_type'              => array( 'post', 'page' ),
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		$urls = array();
		foreach ( $entries as $p ) {
			$urls[] = array(
				'url'       => get_permalink( $p ),
				'lastmod'   => mysql2date( 'c', $p->post_modified_gmt ),
				'type'      => $p->post_type,
			);
		}

		return array(
			'count'         => count( $urls ),
			'urls'          => $urls,
			'sitemap_xml'   => home_url( '/sitemap.xml' ),
			'wp_sitemap'    => home_url( '/wp-sitemap.xml' ),
		);
	}

	public static function ability_get_fresh_content( array $input ): array {
		$days  = isset( $input['days'] )  ? (int) $input['days']  : 30;
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 25;
		$days  = max( 1, min( 365, $days ) );
		$limit = max( 1, min( 100, $limit ) );

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$posts = get_posts( array(
			'post_type'              => array( 'post', 'page' ),
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'date_query'             => array(
				array( 'column' => 'post_modified_gmt', 'after' => $cutoff, 'inclusive' => true ),
			),
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'        => (int) $p->ID,
				'title'     => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'       => get_permalink( $p ),
				'post_type' => $p->post_type,
				'modified'  => mysql2date( 'c', $p->post_modified_gmt ),
				'age_days'  => (int) max( 0, floor( ( time() - strtotime( $p->post_modified_gmt ) ) / DAY_IN_SECONDS ) ),
			);
		}

		return array(
			'days_window' => $days,
			'count'       => count( $out ),
			'items'       => $out,
		);
	}
}
