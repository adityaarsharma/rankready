<?php
/**
 * Headless WordPress support — enterprise public read-only REST API.
 *
 * Exposes FAQ, summary, and JSON-LD schema data for headless frontends
 * (Next.js, Nuxt, Astro, SvelteKit, Gatsby, Faust.js, Atlas, etc.) where
 * the WordPress backend domain is separate from the frontend rendering layer.
 *
 * Endpoints (all public, read-only, opt-in via settings):
 *   GET  /rankready/v1/public/faq/{id}             FAQ items for a post
 *   GET  /rankready/v1/public/summary/{id}         AI summary for a post
 *   GET  /rankready/v1/public/schema/{id}          Ready-to-inject JSON-LD
 *   GET  /rankready/v1/public/post/{id}            Combined (FAQ + summary + schema)
 *   GET  /rankready/v1/public/post-by-slug/{slug}  Lookup by slug (+ post_type)
 *   GET  /rankready/v1/public/list                 Paginated list (for ISR build)
 *   POST /rankready/v1/public/revalidate           Manual revalidation trigger
 *
 * Enterprise features:
 *   - HTTP caching: ETag, Last-Modified, Cache-Control s-maxage, SWR, 304 Not Modified
 *   - CORS allowlist with Vary: Origin and Access-Control-Expose-Headers
 *   - Rate limiting (transient-based, Cloudflare/proxy aware real IP detection)
 *   - Next.js / Nuxt On-Demand Revalidation webhook (fire-and-forget)
 *   - WPGraphQL conditional field registration (when WPGraphQL plugin active)
 *   - Multilingual support: WPML + Polylang (lang, translations metadata)
 *   - RFC 7807 Problem Details error format (application/problem+json)
 *   - Security hardening: per_page cap, hash_equals, published-only guard
 *   - Observability: diagnostic response headers (X-RR-*) for debugging
 *
 * Security model:
 *   - Master toggle (RR_OPT_HEADLESS_ENABLE) — off by default
 *   - Only published posts of public post types
 *   - No secrets, no admin data, no user PII exposed
 *   - CORS restricted to configured frontend origins (wildcard if empty)
 *   - Rate limited per real IP to prevent abuse
 *
 * @package RankReady
 * @since   1.5.4
 */

defined( 'ABSPATH' ) || exit;

class RR_Headless {

	private const NS             = 'rankready/v1';
	private const ROUTE_PREFIX   = '/rankready/v1/public/';
	private const DEFAULT_TTL    = 300;      // 5 min s-maxage default.
	private const SWR_WINDOW     = 86400;    // 1 day stale-while-revalidate.
	private const DEFAULT_RATE   = 120;      // Requests per minute per IP.
	private const MAX_PER_PAGE   = 100;      // Hard cap for list pagination.

	/**
	 * Bootstrap — wire everything into WordPress.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( self::class, 'register_rest_meta' ) );

		// CORS headers for public endpoints.
		add_filter( 'rest_pre_serve_request', array( self::class, 'add_cors_headers' ), 10, 4 );

		// Transform WP_Error responses into RFC 7807 Problem Details on our routes.
		add_filter( 'rest_post_dispatch', array( self::class, 'transform_error_response' ), 10, 3 );

		// Revalidation webhook — fire-and-forget when FAQ / summary updated.
		add_action( 'updated_post_meta', array( self::class, 'maybe_fire_revalidate' ), 10, 4 );
		add_action( 'added_post_meta', array( self::class, 'maybe_fire_revalidate' ), 10, 4 );
		add_action( 'save_post', array( self::class, 'fire_revalidate_on_save' ), 20, 3 );

		// WPGraphQL fields — only when WPGraphQL plugin is loaded.
		add_action( 'graphql_register_types', array( self::class, 'register_graphql_fields' ) );
	}

	/**
	 * Master enable check.
	 */
	public static function is_enabled(): bool {
		return 'on' === get_option( RR_OPT_HEADLESS_ENABLE, 'off' );
	}

	// ── Route registration ────────────────────────────────────────────────────

	/**
	 * Register all public REST routes. Only runs when headless mode is enabled.
	 */
	public static function register_routes(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$id_arg = array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => array( self::class, 'validate_id_arg' ),
			),
		);

		// GET /public/faq/{id}.
		register_rest_route( self::NS, '/public/faq/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_public_faq' ),
			'permission_callback' => array( self::class, 'public_permission' ),
			'args'                => $id_arg,
		) );

		// GET /public/summary/{id}.
		register_rest_route( self::NS, '/public/summary/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_public_summary' ),
			'permission_callback' => array( self::class, 'public_permission' ),
			'args'                => $id_arg,
		) );

		// GET /public/schema/{id}.
		register_rest_route( self::NS, '/public/schema/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_public_schema' ),
			'permission_callback' => array( self::class, 'public_permission' ),
			'args'                => $id_arg,
		) );

		// GET /public/post/{id} — combined payload.
		register_rest_route( self::NS, '/public/post/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_public_post' ),
			'permission_callback' => array( self::class, 'public_permission' ),
			'args'                => $id_arg,
		) );

		// GET /public/post-by-slug/{slug}?post_type=post.
		register_rest_route( self::NS, '/public/post-by-slug/(?P<slug>[a-zA-Z0-9\-_]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_public_post_by_slug' ),
			'permission_callback' => array( self::class, 'public_permission' ),
			'args'                => array(
				'slug'      => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_title',
				),
				'post_type' => array(
					'type'              => 'string',
					'default'           => 'post',
					'sanitize_callback' => 'sanitize_key',
				),
				'lang'      => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
			),
		) );

		// GET /public/list — paginated list for headless build steps (SSG / ISR).
		register_rest_route( self::NS, '/public/list', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_public_list' ),
			'permission_callback' => array( self::class, 'public_permission' ),
			'args'                => array(
				'post_type' => array(
					'type'              => 'string',
					'default'           => 'post',
					'sanitize_callback' => 'sanitize_key',
				),
				'per_page'  => array(
					'type'              => 'integer',
					'default'           => 20,
					'sanitize_callback' => 'absint',
				),
				'page'      => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
				'since'     => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'lang'      => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				),
			),
		) );

		// POST /public/revalidate — manual trigger for Next.js / Nuxt webhook.
		register_rest_route( self::NS, '/public/revalidate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'trigger_revalidate' ),
			'permission_callback' => array( self::class, 'revalidate_permission' ),
			'args'                => array(
				'post_id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		) );
	}

	/**
	 * Public permission gate with rate limiting.
	 *
	 * Returns WP_Error on rate limit hit, true otherwise. Bypasses rate
	 * limiting for authenticated editors and admins.
	 *
	 * @return true|WP_Error
	 */
	public static function public_permission() {
		// Authenticated editors + admins bypass rate limit.
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		$limit = (int) get_option( RR_OPT_HEADLESS_RATE_LIMIT, self::DEFAULT_RATE );
		if ( $limit <= 0 ) {
			return true; // Rate limiting disabled.
		}

		$ip  = self::get_real_ip();
		$key = 'rr_rl_' . md5( $ip );

		$hits = (int) get_transient( $key );
		if ( $hits >= $limit ) {
			return new WP_Error(
				'rr_rate_limited',
				__( 'Too many requests. Please slow down.', 'rankready' ),
				array(
					'status'      => 429,
					'retry_after' => 60,
				)
			);
		}

		set_transient( $key, $hits + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Permission check for the revalidate webhook.
	 * Requires shared secret via X-RR-Secret header OR valid admin auth.
	 */
	public static function revalidate_permission( WP_REST_Request $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$provided = (string) $request->get_header( 'x_rr_secret' );
		$expected = (string) get_option( RR_OPT_HEADLESS_REVALIDATE_SEC, '' );

		if ( empty( $expected ) ) {
			return new WP_Error( 'rr_no_secret', __( 'Revalidate secret not configured.', 'rankready' ), array( 'status' => 503 ) );
		}

		if ( ! empty( $provided ) && hash_equals( $expected, $provided ) ) {
			return true;
		}

		return new WP_Error( 'rr_forbidden', __( 'Invalid credentials.', 'rankready' ), array( 'status' => 403 ) );
	}

	/**
	 * Validate ID arg — must be a positive integer below a sane cap.
	 */
	public static function validate_id_arg( $value ) {
		$id = absint( $value );
		return $id > 0 && $id < PHP_INT_MAX;
	}

	// ── REST meta registration (core /wp/v2/posts/{id}) ──────────────────────

	/**
	 * Expose _rr_faq and _rr_summary under core post responses.
	 */
	public static function register_rest_meta(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( 'on' !== get_option( RR_OPT_HEADLESS_EXPOSE_META, 'on' ) ) {
			return;
		}

		$post_types = get_post_types( array( 'public' => true ), 'names' );

		foreach ( $post_types as $post_type ) {
			register_rest_field( $post_type, 'rankready_faq', array(
				'get_callback' => function ( $post_arr ) {
					$post_id = isset( $post_arr['id'] ) ? (int) $post_arr['id'] : 0;
					if ( $post_id <= 0 ) {
						return array();
					}
					return RR_Faq::get_faq_data( $post_id );
				},
				'schema'       => array(
					'description' => __( 'RankReady FAQ items (question/answer pairs).', 'rankready' ),
					'type'        => 'array',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			) );

			register_rest_field( $post_type, 'rankready_summary', array(
				'get_callback' => function ( $post_arr ) {
					$post_id = isset( $post_arr['id'] ) ? (int) $post_arr['id'] : 0;
					if ( $post_id <= 0 ) {
						return '';
					}
					return (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
				},
				'schema'       => array(
					'description' => __( 'RankReady AI-generated summary.', 'rankready' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			) );

			register_rest_field( $post_type, 'rankready_schema', array(
				'get_callback' => function ( $post_arr ) {
					$post_id = isset( $post_arr['id'] ) ? (int) $post_arr['id'] : 0;
					if ( $post_id <= 0 ) {
						return null;
					}
					$schema = RR_Faq::build_faq_schema_array( $post_id );
					return empty( $schema ) ? null : $schema;
				},
				'schema'       => array(
					'description' => __( 'RankReady FAQPage JSON-LD schema.', 'rankready' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			) );
		}
	}

	// ── CORS ──────────────────────────────────────────────────────────────────

	/**
	 * Add CORS headers for public RankReady endpoints only.
	 *
	 * Sets proper Vary: Origin and Access-Control-Expose-Headers so that
	 * CDNs cache per-origin and frontends can read ETag/X-RR-* headers.
	 */
	public static function add_cors_headers( $served, $result, $request, $server ) {
		if ( ! self::is_enabled() ) {
			return $served;
		}

		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, self::ROUTE_PREFIX ) ) {
			return $served;
		}

		$origin          = get_http_origin();
		$allowed_origins = self::get_allowed_origins();

		// Always send Vary: Origin so CDN + browser caches key per origin.
		header( 'Vary: Origin', false );

		if ( empty( $allowed_origins ) ) {
			// Wildcard — endpoints are read-only so this is safe.
			header( 'Access-Control-Allow-Origin: *' );
		} elseif ( ! empty( $origin ) && in_array( rtrim( $origin, '/' ), $allowed_origins, true ) ) {
			header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
			header( 'Access-Control-Allow-Credentials: false' );
		} else {
			// Origin not allowed — do not set Allow-Origin, browser will block.
			return $served;
		}

		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, If-None-Match, If-Modified-Since, X-RR-Secret' );
		header( 'Access-Control-Expose-Headers: ETag, Last-Modified, Cache-Control, X-RR-Cache, X-RR-Version, X-RR-Rate-Limit-Remaining, X-RR-Request-Id' );
		header( 'Access-Control-Max-Age: 3600' );

		return $served;
	}

	/**
	 * Parse configured CORS origins.
	 */
	private static function get_allowed_origins(): array {
		$raw = (string) get_option( RR_OPT_HEADLESS_CORS_ORIGINS, '' );
		if ( '' === $raw ) {
			return array();
		}

		$origins = array_map( 'trim', explode( ',', $raw ) );
		$origins = array_filter( $origins, function ( $o ) {
			return (bool) filter_var( $o, FILTER_VALIDATE_URL );
		} );
		$origins = array_map( function ( $o ) {
			return rtrim( $o, '/' );
		}, $origins );

		return array_values( $origins );
	}

	// ── Endpoint callbacks ────────────────────────────────────────────────────

	public static function get_public_faq( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = self::validate_public_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$faq_data  = RR_Faq::get_faq_data( $post_id );
		$generated = (int) get_post_meta( $post_id, RR_META_FAQ_GENERATED, true );
		$keyword   = (string) get_post_meta( $post_id, RR_META_FAQ_KEYWORD, true );

		$payload = array(
			'post_id'       => $post_id,
			'slug'          => $post->post_name,
			'faq'           => $faq_data,
			'count'         => count( $faq_data ),
			'focus_keyword' => $keyword,
			'generated_at'  => $generated > 0 ? gmdate( 'c', $generated ) : null,
			'has_data'      => ! empty( $faq_data ),
			'lang'          => self::get_post_language( $post_id ),
		);

		return self::respond_with_cache( $request, $payload, $post );
	}

	public static function get_public_summary( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = self::validate_public_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$summary   = (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
		$generated = (int) get_post_meta( $post_id, RR_META_GENERATED, true );

		$payload = array(
			'post_id'      => $post_id,
			'slug'         => $post->post_name,
			'summary'      => $summary,
			'generated_at' => $generated > 0 ? gmdate( 'c', $generated ) : null,
			'has_data'     => ! empty( $summary ),
			'lang'         => self::get_post_language( $post_id ),
		);

		return self::respond_with_cache( $request, $payload, $post );
	}

	public static function get_public_schema( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = self::validate_public_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$schemas    = array();
		$faq_schema = RR_Faq::build_faq_schema_array( $post_id );
		if ( ! empty( $faq_schema ) ) {
			$schemas['faq_page'] = $faq_schema;
		}

		$schema_type = get_post_meta( $post_id, RR_META_SCHEMA_TYPE, true );
		$schema_data = get_post_meta( $post_id, RR_META_SCHEMA_DATA, true );
		if ( ! empty( $schema_type ) && ! empty( $schema_data ) ) {
			$decoded = is_string( $schema_data ) ? json_decode( $schema_data, true ) : $schema_data;
			if ( is_array( $decoded ) ) {
				$schemas[ (string) $schema_type ] = $decoded;
			}
		}

		$payload = array(
			'post_id'  => $post_id,
			'slug'     => $post->post_name,
			'schemas'  => (object) $schemas,
			'has_data' => ! empty( $schemas ),
		);

		return self::respond_with_cache( $request, $payload, $post );
	}

	public static function get_public_post( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post    = self::validate_public_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return self::respond_with_cache( $request, self::build_post_payload( $post ), $post );
	}

	public static function get_public_post_by_slug( WP_REST_Request $request ) {
		$slug      = sanitize_title( (string) $request->get_param( 'slug' ) );
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$lang      = sanitize_key( (string) $request->get_param( 'lang' ) );

		if ( empty( $post_type ) ) {
			$post_type = 'post';
		}

		$public_types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! in_array( $post_type, $public_types, true ) ) {
			return new WP_Error( 'rr_invalid_post_type', __( 'Invalid post type.', 'rankready' ), array( 'status' => 400 ) );
		}

		// Polylang: set language filter if provided and plugin active.
		if ( ! empty( $lang ) && function_exists( 'pll_languages_list' ) ) {
			add_filter( 'pll_filter_query_excluded_query_vars', '__return_empty_array' );
		}

		$query_args = array(
			'name'             => $slug,
			'post_type'        => $post_type,
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'suppress_filters' => false,
		);

		if ( ! empty( $lang ) ) {
			$query_args['lang'] = $lang;
		}

		$posts = get_posts( $query_args );

		if ( empty( $posts ) ) {
			return new WP_Error( 'rr_not_found', __( 'Post not found.', 'rankready' ), array( 'status' => 404 ) );
		}

		return self::respond_with_cache( $request, self::build_post_payload( $posts[0] ), $posts[0] );
	}

	/**
	 * GET /public/list — paginated for SSG / ISR build steps.
	 */
	public static function get_public_list( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$per_page  = (int) $request->get_param( 'per_page' );
		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$since     = (string) $request->get_param( 'since' );
		$lang      = sanitize_key( (string) $request->get_param( 'lang' ) );

		if ( empty( $post_type ) ) {
			$post_type = 'post';
		}

		$public_types = get_post_types( array( 'public' => true ), 'names' );
		if ( ! in_array( $post_type, $public_types, true ) ) {
			return new WP_Error( 'rr_invalid_post_type', __( 'Invalid post type.', 'rankready' ), array( 'status' => 400 ) );
		}

		// Clamp per_page to the hard cap.
		if ( $per_page <= 0 ) {
			$per_page = 20;
		}
		$per_page = min( $per_page, self::MAX_PER_PAGE );

		$query_args = array(
			'post_type'        => $post_type,
			'post_status'      => 'publish',
			'posts_per_page'   => $per_page,
			'paged'            => $page,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => false,
			'no_found_rows'    => false,
		);

		if ( ! empty( $since ) ) {
			$ts = strtotime( $since );
			if ( $ts ) {
				$query_args['date_query'] = array(
					array(
						'column' => 'post_modified_gmt',
						'after'  => gmdate( 'Y-m-d H:i:s', $ts ),
					),
				);
			}
		}

		if ( ! empty( $lang ) ) {
			$query_args['lang'] = $lang;
		}

		$query = new WP_Query( $query_args );

		$items = array();
		foreach ( $query->posts as $p ) {
			$items[] = array(
				'id'        => (int) $p->ID,
				'slug'      => $p->post_name,
				'post_type' => $p->post_type,
				'title'     => get_the_title( $p ),
				'modified'  => gmdate( 'c', (int) strtotime( $p->post_modified_gmt ) ),
				'lang'      => self::get_post_language( (int) $p->ID ),
				'has_faq'   => (bool) get_post_meta( (int) $p->ID, RR_META_FAQ, true ),
				'has_summary' => (bool) get_post_meta( (int) $p->ID, RR_META_SUMMARY, true ),
			);
		}

		$payload = array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);

		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'X-WP-Total', (string) (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) (int) $query->max_num_pages );

		// Short cache for lists — they update more often than individual posts.
		$ttl = max( 30, (int) ( self::get_cache_ttl() / 5 ) );
		$response->header( 'Cache-Control', 'public, s-maxage=' . $ttl . ', stale-while-revalidate=' . self::SWR_WINDOW );
		$response->header( 'X-RR-Version', RR_VERSION );

		return $response;
	}

	/**
	 * POST /public/revalidate — trigger Next.js/Nuxt webhook manually.
	 */
	public static function trigger_revalidate( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = self::validate_public_post( $post_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$sent = self::send_revalidate_webhook( $post_id, $post->post_name, 'manual' );

		return new WP_REST_Response( array(
			'success' => $sent,
			'post_id' => $post_id,
			'slug'    => $post->post_name,
		), $sent ? 202 : 503 );
	}

	// ── Cache / ETag / 304 ────────────────────────────────────────────────────

	/**
	 * Build a response with proper HTTP caching headers and 304 handling.
	 *
	 * Adds: ETag, Last-Modified, Cache-Control s-maxage + SWR, X-RR-* diag.
	 * Returns 304 Not Modified if If-None-Match / If-Modified-Since match.
	 *
	 * @param WP_REST_Request $request
	 * @param array           $payload
	 * @param WP_Post         $post    Used to derive Last-Modified timestamp.
	 */
	private static function respond_with_cache( WP_REST_Request $request, array $payload, WP_Post $post ) {
		// Last-Modified comes from the post modified time (GMT).
		$last_modified_ts = (int) strtotime( $post->post_modified_gmt );
		if ( $last_modified_ts <= 0 ) {
			$last_modified_ts = time();
		}

		$last_modified_header = gmdate( 'D, d M Y H:i:s', $last_modified_ts ) . ' GMT';

		// ETag: hash of payload + plugin version (busts on plugin upgrade).
		$etag = 'W/"' . md5( wp_json_encode( $payload ) . '|' . RR_VERSION ) . '"';

		// Handle conditional GET.
		$if_none_match     = trim( (string) $request->get_header( 'if_none_match' ) );
		$if_modified_since = trim( (string) $request->get_header( 'if_modified_since' ) );

		$etag_matches         = ( '' !== $if_none_match && hash_equals( $etag, $if_none_match ) );
		$not_modified_matches = ( '' !== $if_modified_since && strtotime( $if_modified_since ) >= $last_modified_ts );

		if ( $etag_matches || $not_modified_matches ) {
			$response = new WP_REST_Response( null, 304 );
			self::attach_cache_headers( $response, $etag, $last_modified_header );
			return $response;
		}

		$response = new WP_REST_Response( $payload, 200 );
		self::attach_cache_headers( $response, $etag, $last_modified_header );
		return $response;
	}

	/**
	 * Attach cache + observability headers to a response.
	 */
	private static function attach_cache_headers( WP_REST_Response $response, string $etag, string $last_modified ): void {
		$ttl = self::get_cache_ttl();

		$response->header( 'ETag', $etag );
		$response->header( 'Last-Modified', $last_modified );
		$response->header( 'Cache-Control', sprintf(
			'public, s-maxage=%d, stale-while-revalidate=%d',
			$ttl,
			self::SWR_WINDOW
		) );
		$response->header( 'X-RR-Version', RR_VERSION );
		$response->header( 'X-RR-Cache', 'MISS' );
		$response->header( 'X-RR-Request-Id', wp_generate_uuid4() );
	}

	private static function get_cache_ttl(): int {
		$ttl = (int) get_option( RR_OPT_HEADLESS_CACHE_TTL, self::DEFAULT_TTL );
		if ( $ttl <= 0 ) {
			$ttl = self::DEFAULT_TTL;
		}
		return min( $ttl, 31536000 ); // Cap at 1 year.
	}

	// ── RFC 7807 Problem Details ──────────────────────────────────────────────

	/**
	 * Transform WP_Error responses into RFC 7807 Problem Details format.
	 * Only applies to /rankready/v1/public/ routes.
	 */
	public static function transform_error_response( $response, $server, $request ) {
		if ( ! ( $response instanceof WP_REST_Response ) ) {
			return $response;
		}

		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, self::ROUTE_PREFIX ) ) {
			return $response;
		}

		$status = (int) $response->get_status();
		if ( $status < 400 ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['code'] ) ) {
			return $response;
		}

		$problem = array(
			'type'     => 'https://rankready.dev/errors/' . $data['code'],
			'title'    => isset( $data['message'] ) ? $data['message'] : __( 'Error', 'rankready' ),
			'status'   => $status,
			'detail'   => isset( $data['message'] ) ? $data['message'] : '',
			'instance' => $route,
		);

		if ( isset( $data['data']['retry_after'] ) ) {
			$problem['retry_after'] = (int) $data['data']['retry_after'];
			$response->header( 'Retry-After', (string) (int) $data['data']['retry_after'] );
		}

		$response->set_data( $problem );
		$response->header( 'Content-Type', 'application/problem+json; charset=' . get_option( 'blog_charset' ) );

		return $response;
	}

	// ── Revalidation webhook (Next.js / Nuxt on-demand ISR) ──────────────────

	/**
	 * Fire webhook when FAQ / summary meta updated.
	 */
	public static function maybe_fire_revalidate( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		$watched = array( RR_META_FAQ, RR_META_SUMMARY, RR_META_SCHEMA_DATA );
		if ( ! in_array( $meta_key, $watched, true ) ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		self::send_revalidate_webhook( (int) $post_id, $post->post_name, 'meta:' . $meta_key );
	}

	/**
	 * Fire webhook when a published post is saved.
	 */
	public static function fire_revalidate_on_save( $post_id, $post, $update ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return;
		}
		self::send_revalidate_webhook( (int) $post_id, $post->post_name, 'save_post' );
	}

	/**
	 * Fire-and-forget webhook call to the configured revalidate URL.
	 *
	 * Uses blocking=>false + tiny timeout so the editor flow never waits
	 * on the frontend's revalidation work.
	 */
	public static function send_revalidate_webhook( int $post_id, string $slug, string $reason ): bool {
		$url    = (string) get_option( RR_OPT_HEADLESS_REVALIDATE_URL, '' );
		$secret = (string) get_option( RR_OPT_HEADLESS_REVALIDATE_SEC, '' );
		if ( empty( $url ) || empty( $secret ) ) {
			return false;
		}
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$body = array(
			'post_id' => $post_id,
			'slug'    => $slug,
			'reason'  => $reason,
			'ts'      => time(),
			'site'    => home_url(),
		);

		$args = array(
			'method'      => 'POST',
			'timeout'     => 0.01,
			'blocking'    => false,
			'redirection' => 0,
			'headers'     => array(
				'Content-Type' => 'application/json',
				'X-RR-Secret'  => $secret,
				'User-Agent'   => 'RankReady/' . RR_VERSION,
			),
			'body'        => wp_json_encode( $body ),
			'sslverify'   => true,
		);

		$response = wp_safe_remote_post( $url, $args );
		return ! is_wp_error( $response );
	}

	// ── WPGraphQL ─────────────────────────────────────────────────────────────

	/**
	 * Conditionally register rankReadyFaq, rankReadySummary, and rankReadySchema
	 * fields on every GraphQL post-type type. Only fires if WPGraphQL is active
	 * and headless mode + GraphQL flag are both enabled.
	 */
	public static function register_graphql_fields(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( 'on' !== get_option( RR_OPT_HEADLESS_GRAPHQL, 'off' ) ) {
			return;
		}
		if ( ! function_exists( 'register_graphql_field' ) ) {
			return;
		}

		$post_types = get_post_types( array( 'public' => true, 'show_in_graphql' => true ), 'objects' );

		foreach ( $post_types as $post_type ) {
			if ( empty( $post_type->graphql_single_name ) ) {
				continue;
			}
			$gql_type = ucfirst( $post_type->graphql_single_name );

			register_graphql_field( $gql_type, 'rankReadyFaq', array(
				'type'        => array( 'list_of' => 'String' ),
				'description' => __( 'RankReady FAQ items (JSON-encoded).', 'rankready' ),
				'resolve'     => function ( $post ) {
					$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
					if ( $post_id <= 0 ) {
						return array();
					}
					$items = RR_Faq::get_faq_data( $post_id );
					return array_map( 'wp_json_encode', $items );
				},
			) );

			register_graphql_field( $gql_type, 'rankReadySummary', array(
				'type'        => 'String',
				'description' => __( 'RankReady AI-generated summary.', 'rankready' ),
				'resolve'     => function ( $post ) {
					$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
					if ( $post_id <= 0 ) {
						return '';
					}
					return (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
				},
			) );

			register_graphql_field( $gql_type, 'rankReadySchema', array(
				'type'        => 'String',
				'description' => __( 'RankReady FAQPage JSON-LD schema (JSON-encoded).', 'rankready' ),
				'resolve'     => function ( $post ) {
					$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
					if ( $post_id <= 0 ) {
						return '';
					}
					$schema = RR_Faq::build_faq_schema_array( $post_id );
					return empty( $schema ) ? '' : (string) wp_json_encode( $schema );
				},
			) );
		}
	}

	// ── Multilingual helpers ──────────────────────────────────────────────────

	/**
	 * Get post language using Polylang or WPML if available.
	 */
	private static function get_post_language( int $post_id ): ?string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post_id, 'slug' );
			return $lang ? (string) $lang : null;
		}
		if ( function_exists( 'apply_filters' ) && defined( 'ICL_LANGUAGE_CODE' ) ) {
			$lang = apply_filters( 'wpml_post_language_details', null, $post_id );
			if ( is_array( $lang ) && ! empty( $lang['language_code'] ) ) {
				return (string) $lang['language_code'];
			}
		}
		return null;
	}

	/**
	 * Get translations map for a post (lang_code => post_id).
	 */
	private static function get_post_translations( int $post_id ): array {
		$out = array();

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$out = (array) pll_get_post_translations( $post_id );
		} elseif ( function_exists( 'apply_filters' ) && defined( 'ICL_LANGUAGE_CODE' ) ) {
			$trid         = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . get_post_type( $post_id ) );
			$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . get_post_type( $post_id ) );
			if ( is_array( $translations ) ) {
				foreach ( $translations as $lang_code => $t ) {
					if ( isset( $t->element_id ) ) {
						$out[ $lang_code ] = (int) $t->element_id;
					}
				}
			}
		}

		return $out;
	}

	// ── Security helpers ──────────────────────────────────────────────────────

	/**
	 * Get the real client IP, honoring Cloudflare + reverse proxy headers.
	 *
	 * Every $_SERVER header is unslashed, sanitized, and validated via
	 * filter_var( FILTER_VALIDATE_IP ) before use. Invalid inputs fall
	 * through to REMOTE_ADDR. Final fallback is 0.0.0.0 so rate-limit
	 * keys are never empty and never polluted by garbage header values.
	 */
	private static function get_real_ip(): string {
		$candidates = array();

		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xff          = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ips          = explode( ',', $xff );
			$candidates[] = trim( (string) $ips[0] );
		}

		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
		}

		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		foreach ( $candidates as $candidate ) {
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				return $candidate;
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Validate that a post ID corresponds to a published, public post.
	 *
	 * @return WP_Post|WP_Error
	 */
	private static function validate_public_post( int $post_id ) {
		if ( $post_id <= 0 ) {
			return new WP_Error( 'rr_invalid_id', __( 'Invalid post ID.', 'rankready' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'rr_not_found', __( 'Post not found.', 'rankready' ), array( 'status' => 404 ) );
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'rr_not_public', __( 'Post is not published.', 'rankready' ), array( 'status' => 404 ) );
		}

		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return new WP_Error( 'rr_not_public', __( 'Post type is not public.', 'rankready' ), array( 'status' => 404 ) );
		}

		// Require explicit password-protected flag to be empty (no unlocked content leaks).
		if ( ! empty( $post->post_password ) ) {
			return new WP_Error( 'rr_password_protected', __( 'Post is password-protected.', 'rankready' ), array( 'status' => 403 ) );
		}

		return $post;
	}

	/**
	 * Build the combined post payload (FAQ + summary + schemas + translations).
	 */
	private static function build_post_payload( WP_Post $post ): array {
		$post_id = (int) $post->ID;

		$faq_data      = RR_Faq::get_faq_data( $post_id );
		$faq_generated = (int) get_post_meta( $post_id, RR_META_FAQ_GENERATED, true );
		$faq_keyword   = (string) get_post_meta( $post_id, RR_META_FAQ_KEYWORD, true );

		$summary       = (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
		$sum_generated = (int) get_post_meta( $post_id, RR_META_GENERATED, true );

		$schemas    = array();
		$faq_schema = RR_Faq::build_faq_schema_array( $post_id );
		if ( ! empty( $faq_schema ) ) {
			$schemas['faq_page'] = $faq_schema;
		}

		$schema_type = get_post_meta( $post_id, RR_META_SCHEMA_TYPE, true );
		$schema_data = get_post_meta( $post_id, RR_META_SCHEMA_DATA, true );
		if ( ! empty( $schema_type ) && ! empty( $schema_data ) ) {
			$decoded = is_string( $schema_data ) ? json_decode( $schema_data, true ) : $schema_data;
			if ( is_array( $decoded ) ) {
				$schemas[ (string) $schema_type ] = $decoded;
			}
		}

		return array(
			'post_id'      => $post_id,
			'slug'         => $post->post_name,
			'post_type'    => $post->post_type,
			'title'        => get_the_title( $post ),
			'permalink'    => get_permalink( $post ),
			'modified'     => gmdate( 'c', (int) strtotime( $post->post_modified_gmt ) ),
			'lang'         => self::get_post_language( $post_id ),
			'translations' => self::get_post_translations( $post_id ),
			'summary'      => array(
				'text'         => $summary,
				'generated_at' => $sum_generated > 0 ? gmdate( 'c', $sum_generated ) : null,
				'has_data'     => ! empty( $summary ),
			),
			'faq'          => array(
				'items'         => $faq_data,
				'count'         => count( $faq_data ),
				'focus_keyword' => $faq_keyword,
				'generated_at'  => $faq_generated > 0 ? gmdate( 'c', $faq_generated ) : null,
				'has_data'      => ! empty( $faq_data ),
			),
			'schemas'      => (object) $schemas,
		);
	}
}
