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
}
