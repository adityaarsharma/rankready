<?php
/**
 * REST API endpoints — authenticated, rate-limited.
 *
 * Endpoints:
 *   GET  /rankready/v1/summary/{id}
 *   POST /rankready/v1/regenerate/{id}
 *   POST /rankready/v1/bulk/start
 *   POST /rankready/v1/bulk/process
 *   POST /rankready/v1/bulk/stop
 *   GET  /rankready/v1/bulk/status
 *   POST /rankready/v1/author/preview
 *   POST /rankready/v1/author/execute
 *   POST /rankready/v1/author/process
 *   POST /rankready/v1/author/stop
 *   POST /rankready/v1/llms/flush-cache
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RR_Rest {

	private const NS             = 'rankready/v1';
	private const REGEN_COOLDOWN = 60;
	private const BULK_BATCH     = 5;
	private const AUTHOR_BATCH   = 20;

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {

		// ── Summary endpoints ─────────────────────────────────────────────────

		register_rest_route( self::NS, '/summary/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_summary' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => self::post_id_arg(),
		) );

		register_rest_route( self::NS, '/regenerate/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'regenerate_summary' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => self::post_id_arg(),
		) );

		// ── Bulk summary endpoints ────────────────────────────────────────────

		register_rest_route( self::NS, '/bulk/start', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'bulk_start' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
			'args'                => array(
				'post_types' => array(
					'required'          => true,
					'type'              => 'array',
					'items'             => array( 'type' => 'string' ),
					'sanitize_callback' => function ( $value ) {
						return is_array( $value ) ? array_map( 'sanitize_key', $value ) : array();
					},
				),
			),
		) );

		register_rest_route( self::NS, '/bulk/process', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'bulk_process' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		register_rest_route( self::NS, '/bulk/stop', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'bulk_stop' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		register_rest_route( self::NS, '/bulk/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'bulk_status' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── Bulk Author Changer endpoints ─────────────────────────────────────

		register_rest_route( self::NS, '/author/preview', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'author_preview' ),
			'permission_callback' => array( self::class, 'can_edit_others' ),
			'args'                => self::author_args(),
		) );

		register_rest_route( self::NS, '/author/execute', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'author_execute' ),
			'permission_callback' => array( self::class, 'can_edit_others' ),
			'args'                => self::author_args(),
		) );

		register_rest_route( self::NS, '/author/process', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'author_process' ),
			'permission_callback' => array( self::class, 'can_edit_others' ),
		) );

		register_rest_route( self::NS, '/author/stop', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'author_stop' ),
			'permission_callback' => array( self::class, 'can_edit_others' ),
		) );

		// ── LLMs.txt cache flush ──────────────────────────────────────────────

		register_rest_route( self::NS, '/llms/flush-cache', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'llms_flush_cache' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── FAQ endpoints ─────────────────────────────────────────────────────

		register_rest_route( self::NS, '/faq/generate/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'faq_generate' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => array_merge( self::post_id_arg(), array(
				'keyword' => array( 'type' => 'string', 'default' => '' ),
				'count'   => array( 'type' => 'integer', 'default' => 0 ),
			) ),
		) );

		register_rest_route( self::NS, '/faq/get/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'faq_get' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => self::post_id_arg(),
		) );

		register_rest_route( self::NS, '/faq/save/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'faq_save' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => self::post_id_arg(),
		) );

		register_rest_route( self::NS, '/faq-bulk/start', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'faq_bulk_start' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
			'args'                => array(
				'post_types' => array(
					'required' => true, 'type' => 'array',
					'items'    => array( 'type' => 'string' ),
					'sanitize_callback' => function ( $v ) { return is_array( $v ) ? array_map( 'sanitize_key', $v ) : array(); },
				),
				'skip_existing' => array( 'type' => 'boolean', 'default' => true ),
			),
		) );

		register_rest_route( self::NS, '/faq-bulk/process', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'faq_bulk_process' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		register_rest_route( self::NS, '/faq-bulk/stop', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'faq_bulk_stop' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );
	}

	// ── Permission callbacks ──────────────────────────────────────────────────

	public static function can_edit_post( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'rankready' ), array( 'status' => 401 ) );
		}
		$post_id = (int) $request->get_param( 'id' );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to edit this post.', 'rankready' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function is_admin_user() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'rankready' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Admin access required.', 'rankready' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function can_edit_others() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'rankready' ), array( 'status' => 401 ) );
		}
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Insufficient permissions.', 'rankready' ), array( 'status' => 403 ) );
		}
		return true;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SUMMARY ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	public static function get_summary( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		return new WP_REST_Response( array(
			'success' => true,
			'summary' => (string) get_post_meta( $post_id, RR_META_SUMMARY, true ),
			'has_key' => ! empty( get_option( RR_OPT_KEY ) ),
		), 200 );
	}

	public static function regenerate_summary( $request ) {
		$post_id = (int) $request->get_param( 'id' );

		if ( empty( get_option( RR_OPT_KEY ) ) ) {
			return new WP_Error( 'rr_no_api_key', __( 'OpenAI API key not configured.', 'rankready' ), array( 'status' => 400 ) );
		}

		$last  = (int) get_post_meta( $post_id, RR_META_GENERATED, true );
		$since = time() - $last;
		if ( $last > 0 && $since < self::REGEN_COOLDOWN ) {
			return new WP_Error(
				'rr_rate_limited',
				sprintf( __( 'Please wait %d more seconds before regenerating.', 'rankready' ), self::REGEN_COOLDOWN - $since ),
				array( 'status' => 429 )
			);
		}

		$summary = RR_Generator::force_generate( $post_id );
		if ( false === $summary ) {
			return new WP_Error( 'rr_generation_failed', __( 'Failed to generate summary.', 'rankready' ), array( 'status' => 502 ) );
		}

		return new WP_REST_Response( array( 'success' => true, 'summary' => $summary ), 200 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// BULK SUMMARY ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	public static function bulk_start( $request ) {
		if ( get_option( RR_BULK_RUNNING ) ) {
			return new WP_Error( 'rr_already_running', __( 'A bulk operation is already in progress. Stop it first.', 'rankready' ), array( 'status' => 409 ) );
		}

		if ( empty( get_option( RR_OPT_KEY ) ) ) {
			return new WP_Error( 'rr_no_api_key', __( 'OpenAI API key not configured.', 'rankready' ), array( 'status' => 400 ) );
		}

		$raw_types  = (array) $request->get_param( 'post_types' );
		$allowed    = array_keys( RR_Admin::get_allowed_post_types() );
		$post_types = array_values( array_intersect( $raw_types, $allowed ) );

		if ( empty( $post_types ) ) {
			return new WP_Error( 'rr_invalid_types', __( 'No valid post types selected.', 'rankready' ), array( 'status' => 400 ) );
		}

		$ids = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 2000,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		$total = count( $ids );

		update_option( RR_BULK_QUEUE,   $ids,   false );
		update_option( RR_BULK_TOTAL,   $total, false );
		update_option( RR_BULK_DONE,    0,      false );
		update_option( RR_BULK_RUNNING, true,   false );

		return new WP_REST_Response( array(
			'total'   => $total,
			'done'    => 0,
			'running' => $total > 0,
		), 200 );
	}

	public static function bulk_process() {
		if ( ! get_option( RR_BULK_RUNNING ) ) {
			return new WP_REST_Response( self::bulk_state(), 200 );
		}

		$queue = (array) get_option( RR_BULK_QUEUE, array() );
		$done  = (int) get_option( RR_BULK_DONE, 0 );
		$total = (int) get_option( RR_BULK_TOTAL, 0 );

		if ( empty( $queue ) ) {
			update_option( RR_BULK_RUNNING, false );
			return new WP_REST_Response( array( 'total' => $total, 'done' => $done, 'running' => false ), 200 );
		}

		$batch = array_splice( $queue, 0, self::BULK_BATCH );

		foreach ( $batch as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id < 1 ) {
				continue;
			}
			RR_Generator::force_generate( $post_id );
			$done++;
		}

		update_option( RR_BULK_QUEUE, $queue, false );
		update_option( RR_BULK_DONE,  $done,  false );

		$still_running = ! empty( $queue );
		if ( ! $still_running ) {
			update_option( RR_BULK_RUNNING, false );
		}

		return new WP_REST_Response( array( 'total' => $total, 'done' => $done, 'running' => $still_running ), 200 );
	}

	public static function bulk_stop() {
		update_option( RR_BULK_RUNNING, false );
		return new WP_REST_Response( array_merge( array( 'stopped' => true ), self::bulk_state() ), 200 );
	}

	public static function bulk_status() {
		return new WP_REST_Response( self::bulk_state(), 200 );
	}

	private static function bulk_state(): array {
		return array(
			'total'   => (int) get_option( RR_BULK_TOTAL, 0 ),
			'done'    => (int) get_option( RR_BULK_DONE, 0 ),
			'running' => (bool) get_option( RR_BULK_RUNNING, false ),
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// BULK AUTHOR CHANGER ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	public static function author_preview( $request ) {
		$params = self::extract_author_params( $request );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$ids   = self::get_author_matching_ids( $params );
		$count = count( $ids );

		$to_user = get_userdata( $params['to_author'] );

		return new WP_REST_Response( array(
			'count'   => $count,
			'message' => sprintf(
				_n(
					'%1$d post will be reassigned to %2$s.',
					'%1$d posts will be reassigned to %2$s.',
					$count,
					'rankready'
				),
				$count,
				$to_user ? $to_user->display_name : '?'
			),
		), 200 );
	}

	public static function author_execute( $request ) {
		if ( get_option( RR_BAC_RUNNING ) ) {
			return new WP_Error( 'rr_already_running', __( 'An author change is already in progress. Stop it first.', 'rankready' ), array( 'status' => 409 ) );
		}

		$params = self::extract_author_params( $request );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$ids   = self::get_author_matching_ids( $params );
		$total = count( $ids );

		if ( 0 === $total ) {
			return new WP_REST_Response( array(
				'total' => 0, 'done' => 0, 'running' => false,
				'message' => __( 'No matching posts found.', 'rankready' ),
			), 200 );
		}

		update_option( RR_BAC_QUEUE,   $ids,                  false );
		update_option( RR_BAC_TOTAL,   $total,                false );
		update_option( RR_BAC_DONE,    0,                     false );
		update_option( RR_BAC_RUNNING, true,                  false );
		update_option( RR_BAC_TO,      $params['to_author'],  false );

		return new WP_REST_Response( array( 'total' => $total, 'done' => 0, 'running' => true ), 200 );
	}

	public static function author_process() {
		if ( ! get_option( RR_BAC_RUNNING ) ) {
			return new WP_REST_Response( self::author_state(), 200 );
		}

		$queue     = (array) get_option( RR_BAC_QUEUE, array() );
		$done      = (int)   get_option( RR_BAC_DONE,  0 );
		$total     = (int)   get_option( RR_BAC_TOTAL, 0 );
		$to_author = (int)   get_option( RR_BAC_TO,    0 );

		if ( empty( $queue ) || ! $to_author ) {
			update_option( RR_BAC_RUNNING, false );
			return new WP_REST_Response( array( 'total' => $total, 'done' => $done, 'running' => false ), 200 );
		}

		$batch = array_splice( $queue, 0, self::AUTHOR_BATCH );

		// Temporarily unhook summary generation to prevent cascading API calls
		// during bulk author changes (author change doesn't change content).
		remove_action( 'wp_after_insert_post', array( 'RR_Generator', 'schedule_generation' ), 10 );

		foreach ( $batch as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id < 1 ) {
				continue;
			}
			wp_update_post( array(
				'ID'          => $post_id,
				'post_author' => $to_author,
			) );
			$done++;
		}

		// Re-hook summary generation.
		add_action( 'wp_after_insert_post', array( 'RR_Generator', 'schedule_generation' ), 10, 4 );

		update_option( RR_BAC_QUEUE, $queue, false );
		update_option( RR_BAC_DONE,  $done,  false );

		$still_running = ! empty( $queue );
		if ( ! $still_running ) {
			update_option( RR_BAC_RUNNING, false );
		}

		return new WP_REST_Response( array( 'total' => $total, 'done' => $done, 'running' => $still_running ), 200 );
	}

	public static function author_stop() {
		update_option( RR_BAC_RUNNING, false );
		update_option( RR_BAC_QUEUE,   array() );
		return new WP_REST_Response( array_merge( array( 'stopped' => true ), self::author_state() ), 200 );
	}

	private static function author_state(): array {
		return array(
			'total'   => (int)  get_option( RR_BAC_TOTAL,   0 ),
			'done'    => (int)  get_option( RR_BAC_DONE,    0 ),
			'running' => (bool) get_option( RR_BAC_RUNNING, false ),
		);
	}

	private static function extract_author_params( $request ) {
		$raw_types   = (array) $request->get_param( 'post_types' );
		$to_author   = (int)   $request->get_param( 'to_author' );
		$from_author = (int)   $request->get_param( 'from_author' );
		$date_from   = sanitize_text_field( (string) $request->get_param( 'date_from' ) );
		$date_to     = sanitize_text_field( (string) $request->get_param( 'date_to' ) );

		$allowed    = array_keys( RR_Admin::get_author_post_types() );
		$post_types = array_values( array_intersect( array_map( 'sanitize_key', $raw_types ), $allowed ) );

		if ( empty( $post_types ) ) {
			return new WP_Error( 'rr_no_post_types', __( 'Select at least one post type.', 'rankready' ), array( 'status' => 400 ) );
		}

		if ( ! $to_author || ! get_userdata( $to_author ) ) {
			return new WP_Error( 'rr_invalid_author', __( 'Invalid target author.', 'rankready' ), array( 'status' => 400 ) );
		}

		if ( $from_author && ! get_userdata( $from_author ) ) {
			return new WP_Error( 'rr_invalid_from_author', __( 'Invalid source author.', 'rankready' ), array( 'status' => 400 ) );
		}

		if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = '';
		}
		if ( $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = '';
		}

		return compact( 'post_types', 'to_author', 'from_author', 'date_from', 'date_to' );
	}

	private static function get_author_matching_ids( array $params ): array {
		$query_args = array(
			'post_type'              => $params['post_types'],
			'post_status'            => 'any',
			'posts_per_page'         => 10000, // Cap to prevent memory exhaustion on large sites.
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		);

		if ( ! empty( $params['from_author'] ) ) {
			$query_args['author'] = $params['from_author'];
		}

		if ( ! empty( $params['date_from'] ) || ! empty( $params['date_to'] ) ) {
			$date_query = array( 'inclusive' => true );
			if ( ! empty( $params['date_from'] ) ) {
				$date_query['after'] = $params['date_from'];
			}
			if ( ! empty( $params['date_to'] ) ) {
				$date_query['before'] = $params['date_to'];
			}
			$query_args['date_query'] = array( $date_query );
		}

		return (array) get_posts( $query_args );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// LLMS CACHE FLUSH
	// ══════════════════════════════════════════════════════════════════════════

	public static function llms_flush_cache() {
		delete_transient( RR_LLMS_CACHE_KEY );
		delete_transient( RR_LLMS_FULL_CACHE_KEY );
		return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Cache cleared.', 'rankready' ) ), 200 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// FAQ ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	public static function faq_generate( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$keyword = sanitize_text_field( $request->get_param( 'keyword' ) );
		$count   = (int) $request->get_param( 'count' );

		$result = RR_Faq::generate_faq( $post_id, $keyword, $count );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => $result->get_error_message(),
			), 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'faq'     => $result,
			'count'   => count( $result ),
		), 200 );
	}

	public static function faq_get( $request ) {
		$post_id  = (int) $request->get_param( 'id' );
		$faq_data = RR_Faq::get_faq_data( $post_id );

		$generated_ts  = (int) get_post_meta( $post_id, RR_META_FAQ_GENERATED, true );
		$generated_str = '';
		if ( $generated_ts > 0 ) {
			$generated_str = wp_date( get_option( 'date_format' ), $generated_ts );
		}

		return new WP_REST_Response( array(
			'success'   => true,
			'faq'       => $faq_data,
			'keyword'   => (string) get_post_meta( $post_id, RR_META_FAQ_KEYWORD, true ),
			'generated' => $generated_str,
			'disabled'  => (bool) get_post_meta( $post_id, RR_META_FAQ_DISABLE, true ),
		), 200 );
	}

	public static function faq_save( $request ) {
		$post_id  = (int) $request->get_param( 'id' );
		$faq_data = $request->get_json_params();

		if ( ! isset( $faq_data['faq'] ) || ! is_array( $faq_data['faq'] ) ) {
			return new WP_Error( 'rr_invalid_faq', __( 'Invalid FAQ data.', 'rankready' ), array( 'status' => 400 ) );
		}

		$clean = array();
		foreach ( $faq_data['faq'] as $item ) {
			if ( ! empty( $item['question'] ) && ! empty( $item['answer'] ) ) {
				$clean[] = array(
					'question' => sanitize_text_field( $item['question'] ),
					'answer'   => wp_kses_post( $item['answer'] ),
				);
			}
		}

		update_post_meta( $post_id, RR_META_FAQ, wp_json_encode( $clean ) );
		update_post_meta( $post_id, RR_META_FAQ_GENERATED, time() );

		return new WP_REST_Response( array( 'success' => true, 'count' => count( $clean ) ), 200 );
	}

	// ── FAQ Bulk ──────────────────────────────────────────────────────────────

	public static function faq_bulk_start( $request ) {
		if ( get_option( RR_FAQ_RUNNING ) ) {
			return new WP_Error( 'rr_faq_running', __( 'FAQ generation already running.', 'rankready' ), array( 'status' => 409 ) );
		}

		$types         = $request->get_param( 'post_types' );
		$skip_existing = (bool) $request->get_param( 'skip_existing' );
		$allowed       = array_keys( RR_Admin::get_allowed_post_types() );
		$types         = array_values( array_intersect( $types, $allowed ) );

		if ( empty( $types ) ) {
			return new WP_Error( 'rr_no_types', __( 'Select at least one post type.', 'rankready' ), array( 'status' => 400 ) );
		}

		$args = array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 10000,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		// Skip posts that already have FAQ.
		if ( $skip_existing ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => RR_META_FAQ, 'compare' => 'NOT EXISTS' ),
				array( 'key' => RR_META_FAQ, 'value' => '', 'compare' => '=' ),
			);
		}

		$ids = get_posts( $args );

		if ( empty( $ids ) ) {
			return new WP_REST_Response( array(
				'running' => false,
				'done'    => 0,
				'total'   => 0,
			), 200 );
		}

		update_option( RR_FAQ_QUEUE, $ids );
		update_option( RR_FAQ_TOTAL, count( $ids ) );
		update_option( RR_FAQ_DONE, 0 );
		update_option( RR_FAQ_RUNNING, true );

		return new WP_REST_Response( array(
			'running' => true,
			'done'    => 0,
			'total'   => count( $ids ),
		), 200 );
	}

	public static function faq_bulk_process() {
		if ( ! get_option( RR_FAQ_RUNNING ) ) {
			return new WP_REST_Response( array(
				'running' => false,
				'done'    => (int) get_option( RR_FAQ_DONE, 0 ),
				'total'   => (int) get_option( RR_FAQ_TOTAL, 0 ),
			), 200 );
		}

		$queue = (array) get_option( RR_FAQ_QUEUE, array() );
		$done  = (int) get_option( RR_FAQ_DONE, 0 );
		$total = (int) get_option( RR_FAQ_TOTAL, 0 );

		// Process 1 post per batch (API rate limits).
		if ( ! empty( $queue ) ) {
			$post_id = array_shift( $queue );
			update_option( RR_FAQ_QUEUE, $queue );

			$result = RR_Faq::generate_faq( (int) $post_id );

			$done++;
			update_option( RR_FAQ_DONE, $done );
		}

		$still_running = ! empty( $queue );
		if ( ! $still_running ) {
			update_option( RR_FAQ_RUNNING, false );
		}

		return new WP_REST_Response( array(
			'running' => $still_running,
			'done'    => $done,
			'total'   => $total,
		), 200 );
	}

	public static function faq_bulk_stop() {
		update_option( RR_FAQ_RUNNING, false );
		update_option( RR_FAQ_QUEUE, array() );

		return new WP_REST_Response( array(
			'running' => false,
			'done'    => (int) get_option( RR_FAQ_DONE, 0 ),
			'total'   => (int) get_option( RR_FAQ_TOTAL, 0 ),
		), 200 );
	}

	// ── Common arg schemas ────────────────────────────────────────────────────

	private static function post_id_arg(): array {
		return array(
			'id' => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
		);
	}

	private static function author_args(): array {
		return array(
			'post_types' => array(
				'required'          => true,
				'type'              => 'array',
				'items'             => array( 'type' => 'string' ),
				'sanitize_callback' => function ( $v ) { return array_map( 'sanitize_key', (array) $v ); },
			),
			'to_author' => array(
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'from_author' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'date_from' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_to' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
