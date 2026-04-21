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

		// WP-Cron hooks for bulk operations — run even after browser close.
		add_action( RR_CRON_BULK_STARTOVER, array( self::class, 'cron_startover_tick' ) );
		add_action( RR_CRON_BULK_FAQ,       array( self::class, 'cron_faq_tick' ) );
		add_action( RR_CRON_BULK_SUMMARY,   array( self::class, 'cron_summary_tick' ) );
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
					'required'          => false,
					'type'              => 'array',
					'items'             => array( 'type' => 'string' ),
					'default'           => array(),
					'sanitize_callback' => function ( $value ) {
						return is_array( $value ) ? array_map( 'sanitize_key', $value ) : array();
					},
				),
				'resume' => array(
					'type'    => 'boolean',
					'default' => false,
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

		// ── API Key Verification ──────────────────────────────────────────────

		register_rest_route( self::NS, '/verify-key', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'verify_api_key' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
			'args'                => array(
				'key' => array(
					'required' => true,
					'type'     => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		register_rest_route( self::NS, '/verify-dfs', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'verify_dfs_key' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
			'args'                => array(
				'login'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'password' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
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
					'required' => false, 'type' => 'array',
					'items'    => array( 'type' => 'string' ),
					'default'  => array(),
					'sanitize_callback' => function ( $v ) { return is_array( $v ) ? array_map( 'sanitize_key', $v ) : array(); },
				),
				'skip_existing' => array( 'type' => 'boolean', 'default' => true ),
				'resume'        => array( 'type' => 'boolean', 'default' => false ),
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

		// ── FAQ Posts List ────────────────────────────────────────────────────

		register_rest_route( self::NS, '/faq/posts', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'faq_posts_list' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── Error Log ────────────────────────────────────────────────────────

		register_rest_route( self::NS, '/errors', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_errors' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		register_rest_route( self::NS, '/errors/clear', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'clear_errors' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── Token Usage per post ────────────────────────────────────────────
		register_rest_route( self::NS, '/token-usage', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_token_usage' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── Start Over — clear + regenerate both summary and FAQ ────────
		register_rest_route( self::NS, '/start-over/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'start_over' ),
			'permission_callback' => array( self::class, 'can_edit_post' ),
			'args'                => array_merge( self::post_id_arg(), array(
				'keyword' => array( 'type' => 'string', 'default' => '' ),
			) ),
		) );

		// ── Start Over Bulk — clear + regenerate all posts ──────────────
		register_rest_route( self::NS, '/startover-bulk/start', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'startover_bulk_start' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
			'args'                => array(
				'post_types' => array(
					'required' => false, 'type' => 'array',
					'items'    => array( 'type' => 'string' ),
					'default'  => array(),
					'sanitize_callback' => function ( $v ) { return is_array( $v ) ? array_map( 'sanitize_key', $v ) : array(); },
				),
				'resume' => array( 'type' => 'boolean', 'default' => false ),
			),
		) );

		register_rest_route( self::NS, '/startover-bulk/process', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'startover_bulk_process' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		register_rest_route( self::NS, '/startover-bulk/stop', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'startover_bulk_stop' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── Content Freshness Alerts ─────────────────────────────────────────
		register_rest_route( self::NS, '/freshness', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'content_freshness' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
			'args'                => array(
				'days' => array(
					'type'    => 'integer',
					'default' => 90,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		// ── Health Check diagnostic ──────────────────────────────────────────
		register_rest_route( self::NS, '/health-check', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'health_check' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		// ── Schema Scanner endpoints ─────────────────────────────────────────
		register_rest_route( self::NS, '/schema/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'schema_status' ),
			'permission_callback' => array( self::class, 'is_admin_user' ),
		) );

		register_rest_route( self::NS, '/schema/recommendation', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'schema_recommendation' ),
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

	// ── Start Over — clear all data and regenerate both summary + FAQ ────────

	public static function start_over( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$keyword = sanitize_text_field( (string) $request->get_param( 'keyword' ) );

		if ( empty( get_option( RR_OPT_KEY ) ) ) {
			return new WP_Error( 'rr_no_api_key', __( 'OpenAI API key not configured.', 'rankready' ), array( 'status' => 400 ) );
		}

		// Clear existing summary data.
		delete_post_meta( $post_id, RR_META_SUMMARY );
		delete_post_meta( $post_id, RR_META_HASH );
		delete_post_meta( $post_id, RR_META_GENERATED );

		// Clear existing FAQ data.
		delete_post_meta( $post_id, RR_META_FAQ );
		delete_post_meta( $post_id, RR_META_FAQ_HASH );
		delete_post_meta( $post_id, RR_META_FAQ_GENERATED );
		delete_post_meta( $post_id, RR_META_FAQ_KEYWORD );

		$result = array(
			'summary' => null,
			'faq'     => null,
		);

		// Regenerate summary.
		$summary = RR_Generator::force_generate( $post_id );
		if ( false !== $summary ) {
			$result['summary'] = 'generated';
		} else {
			$result['summary'] = 'failed';
		}

		// Regenerate FAQ (if DFS credentials available).
		$has_dfs = ! empty( get_option( RR_OPT_DFS_LOGIN ) ) && ! empty( get_option( RR_OPT_DFS_PASSWORD ) );
		if ( $has_dfs ) {
			$faq = RR_Faq::generate_faq( $post_id, $keyword );
			if ( is_array( $faq ) && ! is_wp_error( $faq ) ) {
				$result['faq'] = 'generated';
				$result['faq_count'] = count( $faq );
			} else {
				$result['faq'] = 'failed';
				$result['faq_error'] = is_wp_error( $faq ) ? $faq->get_error_message() : 'Unknown error';
			}
		} else {
			$result['faq'] = 'skipped_no_dfs';
		}

		return new WP_REST_Response( array( 'success' => true, 'result' => $result ), 200 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// BULK START OVER (clear + regenerate all)
	// ══════════════════════════════════════════════════════════════════════════

	public static function startover_bulk_start( $request ) {
		$resume = (bool) $request->get_param( 'resume' );

		// Resume: pick up existing queue if one exists.
		if ( $resume ) {
			$queue = (array) get_option( RR_SO_QUEUE, array() );
			if ( ! empty( $queue ) ) {
				$done  = (int) get_option( RR_SO_DONE, 0 );
				$total = (int) get_option( RR_SO_TOTAL, 0 );
				update_option( RR_SO_RUNNING, true, false );
				// Re-schedule cron for background processing.
				if ( ! wp_next_scheduled( RR_CRON_BULK_STARTOVER ) ) {
					wp_schedule_event( time() + 10, 'rr_one_minute', RR_CRON_BULK_STARTOVER );
				}
				return new WP_REST_Response( array(
					'total'   => $total,
					'done'    => $done,
					'running' => true,
					'resumed' => true,
				), 200 );
			}
			// No queue to resume — fall through to error or start fresh.
			return new WP_Error( 'rr_nothing_to_resume', __( 'No pending start-over queue found. Start a new operation instead.', 'rankready' ), array( 'status' => 400 ) );
		}

		if ( get_option( RR_SO_RUNNING ) ) {
			return new WP_Error( 'rr_already_running', __( 'A start-over operation is already running. Stop it first.', 'rankready' ), array( 'status' => 409 ) );
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

		update_option( RR_SO_QUEUE,   $ids,   false );
		update_option( RR_SO_TOTAL,   $total, false );
		update_option( RR_SO_DONE,    0,      false );
		update_option( RR_SO_RUNNING, true,   false );

		// Schedule WP-Cron to process queue even if browser closes.
		if ( ! wp_next_scheduled( RR_CRON_BULK_STARTOVER ) ) {
			wp_schedule_event( time() + 10, 'rr_one_minute', RR_CRON_BULK_STARTOVER );
		}

		return new WP_REST_Response( array(
			'total'   => $total,
			'done'    => 0,
			'running' => $total > 0,
		), 200 );
	}

	public static function startover_bulk_process() {
		if ( ! get_option( RR_SO_RUNNING ) ) {
			return new WP_REST_Response( array(
				'total' => (int) get_option( RR_SO_TOTAL, 0 ),
				'done'  => (int) get_option( RR_SO_DONE, 0 ),
				'running' => false,
			), 200 );
		}

		$queue = (array) get_option( RR_SO_QUEUE, array() );
		$done  = (int) get_option( RR_SO_DONE, 0 );
		$total = (int) get_option( RR_SO_TOTAL, 0 );

		if ( empty( $queue ) ) {
			update_option( RR_SO_RUNNING, false );
			return new WP_REST_Response( array( 'total' => $total, 'done' => $done, 'running' => false ), 200 );
		}

		// Process 1 post at a time (both summary + FAQ are heavy).
		$post_id = (int) array_shift( $queue );
		$log     = array();
		$has_dfs = ! empty( get_option( RR_OPT_DFS_LOGIN ) ) && ! empty( get_option( RR_OPT_DFS_PASSWORD ) );

		if ( $post_id > 0 ) {
			// Clear all existing data.
			delete_post_meta( $post_id, RR_META_SUMMARY );
			delete_post_meta( $post_id, RR_META_HASH );
			delete_post_meta( $post_id, RR_META_GENERATED );
			delete_post_meta( $post_id, RR_META_FAQ );
			delete_post_meta( $post_id, RR_META_FAQ_HASH );
			delete_post_meta( $post_id, RR_META_FAQ_GENERATED );
			delete_post_meta( $post_id, RR_META_FAQ_KEYWORD );

			// Regenerate summary.
			$summary_result = RR_Generator::force_generate( $post_id );
			$summary_status = ( false !== $summary_result ) ? 'generated' : 'failed';

			// Regenerate FAQ.
			$faq_status = 'skipped';
			if ( $has_dfs ) {
				$faq = RR_Faq::generate_faq( $post_id );
				$faq_status = ( is_array( $faq ) && ! is_wp_error( $faq ) ) ? 'generated' : 'failed';
			}

			$done++;

			$post  = get_post( $post_id );
			$title = $post ? $post->post_title : '#' . $post_id;

			$log[] = array(
				'id'        => $post_id,
				'title'     => $title,
				'edit_link' => get_edit_post_link( $post_id, 'raw' ),
				'summary'   => $summary_status,
				'faq'       => $faq_status,
			);
		}

		update_option( RR_SO_QUEUE, $queue, false );
		update_option( RR_SO_DONE,  $done,  false );

		if ( empty( $queue ) ) {
			update_option( RR_SO_RUNNING, false );
		}

		return new WP_REST_Response( array(
			'total'   => $total,
			'done'    => $done,
			'running' => ! empty( $queue ),
			'log'     => $log,
		), 200 );
	}

	public static function startover_bulk_stop() {
		$done  = (int) get_option( RR_SO_DONE, 0 );
		$total = (int) get_option( RR_SO_TOTAL, 0 );
		$queue = (array) get_option( RR_SO_QUEUE, array() );
		$queue_remaining = count( $queue );

		update_option( RR_SO_RUNNING, false, false );
		// Keep queue intact so Resume can pick it up.

		// Clear WP-Cron schedule.
		wp_clear_scheduled_hook( RR_CRON_BULK_STARTOVER );

		return new WP_REST_Response( array(
			'stopped'         => true,
			'done'            => $done,
			'total'           => $total,
			'queue_remaining' => $queue_remaining,
		), 200 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// BULK SUMMARY ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	public static function bulk_start( $request ) {
		$resume = (bool) $request->get_param( 'resume' );

		// Resume: pick up existing queue if one exists.
		if ( $resume ) {
			$queue = (array) get_option( RR_BULK_QUEUE, array() );
			if ( ! empty( $queue ) ) {
				$done  = (int) get_option( RR_BULK_DONE, 0 );
				$total = (int) get_option( RR_BULK_TOTAL, 0 );
				update_option( RR_BULK_RUNNING, true, false );
				if ( ! wp_next_scheduled( RR_CRON_BULK_SUMMARY ) ) {
					wp_schedule_event( time() + 10, 'rr_one_minute', RR_CRON_BULK_SUMMARY );
				}
				return new WP_REST_Response( array(
					'total'   => $total,
					'done'    => $done,
					'running' => true,
					'resumed' => true,
				), 200 );
			}
		}

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

		update_option( RR_BULK_QUEUE,      $ids,   false );
		update_option( RR_BULK_TOTAL,      $total, false );
		update_option( RR_BULK_DONE,       0,      false );
		update_option( RR_BULK_RUNNING,    true,   false );
		update_option( 'rr_bulk_skipped',  0,      false );
		update_option( 'rr_bulk_failed',   0,      false );

		// Schedule WP-Cron to process queue even if browser closes.
		if ( ! wp_next_scheduled( RR_CRON_BULK_SUMMARY ) ) {
			wp_schedule_event( time() + 10, 'rr_one_minute', RR_CRON_BULK_SUMMARY );
		}

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

		$queue   = (array) get_option( RR_BULK_QUEUE, array() );
		$done    = (int) get_option( RR_BULK_DONE, 0 );
		$total   = (int) get_option( RR_BULK_TOTAL, 0 );
		$skipped = (int) get_option( 'rr_bulk_skipped', 0 );
		$failed  = (int) get_option( 'rr_bulk_failed', 0 );

		if ( empty( $queue ) ) {
			update_option( RR_BULK_RUNNING, false );
			return new WP_REST_Response( array( 'total' => $total, 'done' => $done, 'skipped' => $skipped, 'failed' => $failed, 'running' => false ), 200 );
		}

		$batch = array_splice( $queue, 0, self::BULK_BATCH );
		$processed = array();

		foreach ( $batch as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id < 1 ) {
				continue;
			}

			$tokens_before = (int) get_post_meta( $post_id, '_rr_tokens_used', true );
			$result        = RR_Generator::force_generate( $post_id, true );
			$tokens_after  = (int) get_post_meta( $post_id, '_rr_tokens_used', true );
			$tokens_used   = $tokens_after - $tokens_before;
			$done++;

			$post  = get_post( $post_id );
			$title = $post ? $post->post_title : '#' . $post_id;
			$link  = get_permalink( $post_id );

			if ( false === $result ) {
				$failed++;
				$processed[] = array(
					'id'     => $post_id,
					'title'  => $title,
					'link'   => $link,
					'status' => 'failed',
					'tokens' => 0,
				);
			} else {
				$existing = (string) get_post_meta( $post_id, RR_META_SUMMARY, true );
				if ( $result === $existing && 0 === $tokens_used ) {
					$skipped++;
					$processed[] = array(
						'id'     => $post_id,
						'title'  => $title,
						'link'   => $link,
						'status' => 'skipped',
						'tokens' => 0,
					);
				} else {
					$processed[] = array(
						'id'     => $post_id,
						'title'  => $title,
						'link'   => $link,
						'status' => 'generated',
						'tokens' => $tokens_used,
					);
				}
			}
		}

		update_option( RR_BULK_QUEUE,     $queue,   false );
		update_option( RR_BULK_DONE,      $done,    false );
		update_option( 'rr_bulk_skipped', $skipped, false );
		update_option( 'rr_bulk_failed',  $failed,  false );

		$still_running = ! empty( $queue );
		if ( ! $still_running ) {
			update_option( RR_BULK_RUNNING, false );
		}

		return new WP_REST_Response( array(
			'total'     => $total,
			'done'      => $done,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'running'   => $still_running,
			'processed' => $processed,
		), 200 );
	}

	public static function bulk_stop() {
		update_option( RR_BULK_RUNNING, false );
		// Keep queue intact for resume — don't clear it.
		wp_clear_scheduled_hook( RR_CRON_BULK_SUMMARY );
		return new WP_REST_Response( array_merge( array( 'stopped' => true ), self::bulk_state() ), 200 );
	}

	public static function bulk_status() {
		return new WP_REST_Response( self::bulk_state(), 200 );
	}

	private static function bulk_state(): array {
		return array(
			'total'   => (int) get_option( RR_BULK_TOTAL, 0 ),
			'done'    => (int) get_option( RR_BULK_DONE, 0 ),
			'skipped' => (int) get_option( 'rr_bulk_skipped', 0 ),
			'failed'  => (int) get_option( 'rr_bulk_failed', 0 ),
			'running' => (bool) get_option( RR_BULK_RUNNING, false ),
			'queue_remaining' => count( (array) get_option( RR_BULK_QUEUE, array() ) ),
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
	// API KEY VERIFICATION
	// ══════════════════════════════════════════════════════════════════════════

	public static function verify_api_key( $request ) {
		$key = $request->get_param( 'key' );

		if ( empty( $key ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => __( 'No API key provided.', 'rankready' ),
			), 200 );
		}

		// If masked key, use stored key.
		if ( false !== strpos( $key, '••••' ) ) {
			$key = (string) get_option( RR_OPT_KEY, '' );
		}

		if ( empty( $key ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => __( 'No API key stored.', 'rankready' ),
			), 200 );
		}

		$response = wp_remote_get( 'https://api.openai.com/v1/models', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => $response->get_error_message(),
			), 200 );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return new WP_REST_Response( array(
				'valid'   => true,
				'message' => __( 'API key is valid and working.', 'rankready' ),
			), 200 );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$err  = isset( $body['error']['message'] ) ? $body['error']['message'] : __( 'Invalid API key.', 'rankready' );

		return new WP_REST_Response( array(
			'valid'   => false,
			'message' => $err,
		), 200 );
	}

	// ── DataForSEO Key Verification ──────────────────────────────────────────

	public static function verify_dfs_key( $request = null ) {
		$login    = (string) get_option( RR_OPT_DFS_LOGIN, '' );
		$password = (string) get_option( RR_OPT_DFS_PASSWORD, '' );

		// Allow passing password from the form for testing before save.
		if ( $request && $request->get_param( 'password' ) ) {
			$password = (string) $request->get_param( 'password' );
		}
		if ( $request && $request->get_param( 'login' ) ) {
			$login = (string) $request->get_param( 'login' );
		}

		if ( empty( $login ) || empty( $password ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => __( 'DataForSEO login or password not configured.', 'rankready' ),
			), 200 );
		}

		// Use a lightweight endpoint to test credentials.
		$response = wp_remote_get( 'https://api.dataforseo.com/v3/appendix/user_data', array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $login . ':' . $password ),
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => $response->get_error_message(),
			), 200 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && isset( $body['tasks'][0]['result'][0]['money'] ) ) {
			$balance = $body['tasks'][0]['result'][0]['money']['balance'];

			// Auto-save verified credentials to DB.
			update_option( RR_OPT_DFS_LOGIN, $login );
			update_option( RR_OPT_DFS_PASSWORD, $password );

			return new WP_REST_Response( array(
				'valid'   => true,
				'message' => sprintf( __( 'Credentials valid and saved. Balance: $%s', 'rankready' ), number_format( (float) $balance, 2 ) ),
			), 200 );
		}

		$err = isset( $body['status_message'] ) ? $body['status_message'] : __( 'Invalid credentials.', 'rankready' );
		return new WP_REST_Response( array(
			'valid'   => false,
			'message' => $err,
		), 200 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// FAQ ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	public static function faq_generate( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$keyword = sanitize_text_field( $request->get_param( 'keyword' ) );
		$count   = (int) $request->get_param( 'count' );

		// Cooldown: prevent rapid-fire FAQ generation for the same post.
		$last  = (int) get_post_meta( $post_id, RR_META_FAQ_GENERATED, true );
		$since = time() - $last;
		if ( $last && $since < self::REGEN_COOLDOWN ) {
			return new WP_Error(
				'rr_rate_limited',
				sprintf( __( 'Please wait %d more seconds before regenerating.', 'rankready' ), self::REGEN_COOLDOWN - $since ),
				array( 'status' => 429 )
			);
		}

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
		$resume = (bool) $request->get_param( 'resume' );

		// Resume: pick up existing queue.
		if ( $resume ) {
			$queue = (array) get_option( RR_FAQ_QUEUE, array() );
			if ( ! empty( $queue ) ) {
				$done  = (int) get_option( RR_FAQ_DONE, 0 );
				$total = (int) get_option( RR_FAQ_TOTAL, 0 );
				update_option( RR_FAQ_RUNNING, true, false );
				if ( ! wp_next_scheduled( RR_CRON_BULK_FAQ ) ) {
					wp_schedule_event( time() + 10, 'rr_one_minute', RR_CRON_BULK_FAQ );
				}
				return new WP_REST_Response( array(
					'running' => true,
					'done'    => $done,
					'total'   => $total,
					'resumed' => true,
				), 200 );
			}
		}

		if ( get_option( RR_FAQ_RUNNING ) ) {
			return new WP_Error( 'rr_faq_running', __( 'FAQ generation already running.', 'rankready' ), array( 'status' => 409 ) );
		}

		$types         = (array) $request->get_param( 'post_types' );
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

		update_option( RR_FAQ_QUEUE,        $ids,          false );
		update_option( RR_FAQ_TOTAL,        count( $ids ), false );
		update_option( RR_FAQ_DONE,         0,             false );
		update_option( RR_FAQ_RUNNING,      true,          false );
		update_option( 'rr_faq_skipped',    0,             false );
		update_option( 'rr_faq_failed',     0,             false );

		// Schedule WP-Cron to process queue even if browser closes.
		if ( ! wp_next_scheduled( RR_CRON_BULK_FAQ ) ) {
			wp_schedule_event( time() + 10, 'rr_one_minute', RR_CRON_BULK_FAQ );
		}

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
				'skipped' => (int) get_option( 'rr_faq_skipped', 0 ),
				'failed'  => (int) get_option( 'rr_faq_failed', 0 ),
			), 200 );
		}

		$queue   = (array) get_option( RR_FAQ_QUEUE, array() );
		$done    = (int) get_option( RR_FAQ_DONE, 0 );
		$total   = (int) get_option( RR_FAQ_TOTAL, 0 );
		$skipped = (int) get_option( 'rr_faq_skipped', 0 );
		$failed  = (int) get_option( 'rr_faq_failed', 0 );

		// Process 1 post per batch (API rate limits).
		$processed = array();

		if ( ! empty( $queue ) ) {
			$post_id = (int) array_shift( $queue );
			update_option( RR_FAQ_QUEUE, $queue, false );

			$post  = get_post( $post_id );
			$title = $post ? $post->post_title : '#' . $post_id;
			$link  = get_permalink( $post_id );

			if ( $post ) {
				$content  = wp_strip_all_tags( do_shortcode( $post->post_content ) );
				$keyword  = RR_Faq::get_focus_keyword( $post_id );
				$count    = (int) get_option( RR_OPT_FAQ_COUNT, 5 );
				$new_hash = md5( $content . $keyword . $count );
				$old_hash = (string) get_post_meta( $post_id, RR_META_FAQ_HASH, true );
				$existing = get_post_meta( $post_id, RR_META_FAQ, true );

				if ( $new_hash === $old_hash && ! empty( $existing ) ) {
					$skipped++;
					$processed[] = array(
						'id'     => $post_id,
						'title'  => $title,
						'link'   => $link,
						'status' => 'skipped',
						'tokens' => 0,
					);
				} else {
					$tokens_before = (int) get_post_meta( $post_id, '_rr_tokens_used', true );
					try {
						$result = RR_Faq::generate_faq( $post_id );
					} catch ( \Throwable $e ) {
						$result = new WP_Error( 'faq_exception', $e->getMessage() );
						if ( class_exists( 'RR_Generator' ) && method_exists( 'RR_Generator', 'log_error' ) ) {
							RR_Generator::log_error( 'FAQ/BulkProcess', $e->getMessage(), $post_id );
						}
					}
					$tokens_after  = (int) get_post_meta( $post_id, '_rr_tokens_used', true );
					$tokens_used   = $tokens_after - $tokens_before;

					if ( is_wp_error( $result ) ) {
						$failed++;
						$failed_ids = (array) get_option( 'rr_faq_failed_ids', array() );
						$failed_ids[] = $post_id;
						if ( count( $failed_ids ) > 100 ) {
							$failed_ids = array_slice( $failed_ids, -100 );
						}
						update_option( 'rr_faq_failed_ids', $failed_ids, false );

						$processed[] = array(
							'id'     => $post_id,
							'title'  => $title,
							'link'   => $link,
							'status' => 'failed',
							'tokens' => 0,
						);
					} else {
						$processed[] = array(
							'id'     => $post_id,
							'title'  => $title,
							'link'   => $link,
							'status' => 'generated',
							'tokens' => $tokens_used,
						);
					}
				}
			} else {
				$failed++;
				$processed[] = array(
					'id'     => $post_id,
					'title'  => $title,
					'link'   => $link,
					'status' => 'failed',
					'tokens' => 0,
				);
			}

			$done++;
			update_option( RR_FAQ_DONE,      $done,    false );
			update_option( 'rr_faq_skipped', $skipped, false );
			update_option( 'rr_faq_failed',  $failed,  false );
		}

		$still_running = ! empty( $queue );
		if ( ! $still_running ) {
			update_option( RR_FAQ_RUNNING, false );
		}

		return new WP_REST_Response( array(
			'running'   => $still_running,
			'done'      => $done,
			'total'     => $total,
			'skipped'   => $skipped,
			'failed'    => $failed,
			'processed' => $processed,
		), 200 );
	}

	public static function faq_bulk_stop() {
		update_option( RR_FAQ_RUNNING, false );
		// Keep queue intact for resume.
		wp_clear_scheduled_hook( RR_CRON_BULK_FAQ );

		return new WP_REST_Response( array(
			'running'         => false,
			'done'            => (int) get_option( RR_FAQ_DONE, 0 ),
			'total'           => (int) get_option( RR_FAQ_TOTAL, 0 ),
			'skipped'         => (int) get_option( 'rr_faq_skipped', 0 ),
			'failed'          => (int) get_option( 'rr_faq_failed', 0 ),
			'queue_remaining' => count( (array) get_option( RR_FAQ_QUEUE, array() ) ),
		), 200 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// FAQ POSTS LIST
	// ══════════════════════════════════════════════════════════════════════════

	public static function faq_posts_list() {
		global $wpdb;

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type, pm2.meta_value AS faq_generated
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value != ''
			 LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = %s
			 WHERE p.post_status = 'publish'
			 ORDER BY pm2.meta_value DESC
			 LIMIT 200",
			RR_META_FAQ,
			RR_META_FAQ_GENERATED
		) );

		$posts = array();
		foreach ( $results as $row ) {
			$generated = ! empty( $row->faq_generated ) ? wp_date( get_option( 'date_format' ), (int) $row->faq_generated ) : '';
			$posts[]   = array(
				'id'        => (int) $row->ID,
				'title'     => $row->post_title,
				'type'      => $row->post_type,
				'generated' => $generated,
				'edit_url'  => get_edit_post_link( $row->ID, 'raw' ),
				'view_url'  => get_permalink( $row->ID ),
			);
		}

		return new WP_REST_Response( array(
			'posts' => $posts,
			'total' => count( $posts ),
		), 200 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// ERROR LOG ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	public static function get_errors() {
		$log = RR_Generator::get_error_log();
		// Reverse so newest first.
		$log = array_reverse( $log );

		// Add human-readable time.
		foreach ( $log as &$entry ) {
			$entry['time_ago'] = human_time_diff( $entry['time'] ) . ' ago';
			$entry['date']     = wp_date( 'Y-m-d H:i:s', $entry['time'] );
		}
		unset( $entry );

		return new WP_REST_Response( array( 'errors' => $log ), 200 );
	}

	public static function clear_errors() {
		RR_Generator::clear_error_log();
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	// ── Token Usage per post ─────────────────────────────────────────────────

	public static function get_token_usage() {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value AS tokens, p.post_title, p.post_type
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s AND pm.meta_value > 0
			 ORDER BY CAST(pm.meta_value AS UNSIGNED) DESC
			 LIMIT 100",
			'_rr_tokens_used'
		) );

		$posts = array();
		foreach ( $rows as $row ) {
			$posts[] = array(
				'id'     => (int) $row->post_id,
				'title'  => $row->post_title,
				'type'   => $row->post_type,
				'tokens' => (int) $row->tokens,
				'link'   => get_permalink( (int) $row->post_id ),
				'edit'   => get_edit_post_link( (int) $row->post_id, 'raw' ),
			);
		}

		$totals = (array) get_option( 'rr_token_usage', array() );

		return new WP_REST_Response( array(
			'posts'          => $posts,
			'summary_tokens' => isset( $totals['summary_tokens'] ) ? (int) $totals['summary_tokens'] : 0,
			'faq_tokens'     => isset( $totals['faq_tokens'] ) ? (int) $totals['faq_tokens'] : 0,
			'total_calls'    => isset( $totals['total_calls'] ) ? (int) $totals['total_calls'] : 0,
		), 200 );
	}

	// ── Content Freshness Alerts ─────────────────────────────────────────────

	public static function content_freshness( $request ) {
		$days        = max( 30, (int) $request->get_param( 'days' ) );
		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// Get post types that have summaries or FAQs enabled.
		$summary_types = (array) get_option( RR_OPT_POST_TYPES, array( 'post' ) );
		$faq_types     = (array) get_option( RR_OPT_FAQ_POST_TYPES, array( 'post' ) );
		$all_types     = array_unique( array_merge( $summary_types, $faq_types ) );

		if ( empty( $all_types ) ) {
			return new WP_REST_Response( array( 'stale' => array(), 'summary' => array() ), 200 );
		}

		$posts = get_posts( array(
			'post_type'      => $all_types,
			'post_status'    => 'publish',
			'date_query'     => array(
				array( 'column' => 'post_modified', 'before' => $cutoff_date ),
			),
			'orderby'        => 'modified',
			'order'          => 'ASC',
			'posts_per_page' => 50,
			'fields'         => 'ids',
		) );

		$stale = array();
		foreach ( $posts as $pid ) {
			$post       = get_post( $pid );
			$modified   = strtotime( $post->post_modified );
			$days_ago   = (int) floor( ( time() - $modified ) / DAY_IN_SECONDS );
			$has_summary = ! empty( get_post_meta( $pid, RR_META_SUMMARY, true ) );
			$has_faq     = ! empty( get_post_meta( $pid, RR_META_FAQ, true ) );

			$urgency = 'moderate';
			if ( $days_ago > 365 ) {
				$urgency = 'critical';
			} elseif ( $days_ago > 180 ) {
				$urgency = 'high';
			}

			$stale[] = array(
				'id'          => $pid,
				'title'       => get_the_title( $pid ),
				'type'        => $post->post_type,
				'modified'    => $post->post_modified,
				'days_ago'    => $days_ago,
				'urgency'     => $urgency,
				'has_summary' => $has_summary,
				'has_faq'     => $has_faq,
				'edit_url'    => get_edit_post_link( $pid, 'raw' ),
				'view_url'    => get_permalink( $pid ),
			);
		}

		// Summary stats. $type_placeholders contains only "%s,%s,%s" tokens
		// generated from array_fill — never user input. Actual slugs are
		// passed as prepare() args. Safe IN() clause pattern.
		global $wpdb;
		$type_placeholders = implode( ',', array_fill( 0, count( $all_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_published = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status = %s",
			array_merge( $all_types, array( 'publish' ) )
		) );
		$total_stale = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status = %s AND post_modified < %s",
			array_merge( $all_types, array( 'publish', $cutoff_date ) )
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return new WP_REST_Response( array(
			'stale'   => $stale,
			'summary' => array(
				'total_published' => $total_published,
				'total_stale'     => $total_stale,
				'threshold_days'  => $days,
				'fresh_pct'       => $total_published > 0 ? round( ( ( $total_published - $total_stale ) / $total_published ) * 100, 1 ) : 100,
			),
		), 200 );
	}

	// ── Health Check Diagnostic ──────────────────────────────────────────────

	public static function health_check() {
		$checks = array();

		// 1. OpenAI API Key.
		$api_key = get_option( RR_OPT_KEY, '' );
		$checks[] = array(
			'label'  => 'OpenAI API Key',
			'status' => ! empty( $api_key ) ? 'pass' : 'fail',
			'detail' => ! empty( $api_key ) ? 'Configured.' : 'Not set — summaries and FAQ answers will not generate.',
		);

		// 2. DataForSEO credentials.
		$dfs_login = get_option( RR_OPT_DFS_LOGIN, '' );
		$dfs_pass  = get_option( RR_OPT_DFS_PASSWORD, '' );
		$dfs_ok    = ! empty( $dfs_login ) && ! empty( $dfs_pass );
		$checks[] = array(
			'label'  => 'DataForSEO Credentials',
			'status' => $dfs_ok ? 'pass' : 'warn',
			'detail' => $dfs_ok ? 'Login and password configured.' : 'Not set — FAQ question discovery will not work.',
		);

		// 3. LLMs.txt enabled.
		$llms_on = 'on' === get_option( RR_OPT_LLMS_ENABLE, 'off' );
		$checks[] = array(
			'label'  => 'LLMs.txt',
			'status' => $llms_on ? 'pass' : 'warn',
			'detail' => $llms_on ? 'Enabled — serving /llms.txt' : 'Disabled.',
		);

		// 4. Markdown endpoints.
		$md_on = 'on' === get_option( RR_OPT_MD_ENABLE, 'off' );
		$checks[] = array(
			'label'  => 'Markdown Endpoints',
			'status' => $md_on ? 'pass' : 'warn',
			'detail' => $md_on ? 'Enabled — .md endpoints active.' : 'Disabled.',
		);

		// 5. Robots.txt LLM crawlers.
		$robots_on = 'on' === get_option( RR_OPT_ROBOTS_ENABLE, 'off' );
		$crawlers  = (array) get_option( RR_OPT_ROBOTS_CRAWLERS, array() );
		$checks[] = array(
			'label'  => 'LLM Crawler Access (robots.txt)',
			'status' => $robots_on ? 'pass' : 'warn',
			'detail' => $robots_on ? count( $crawlers ) . ' crawlers allowed.' : 'Disabled — no crawler rules in robots.txt.',
		);

		// 6. SEO plugin detected.
		$seo_plugin = 'None detected';
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$seo_plugin = 'Rank Math ' . RANK_MATH_VERSION;
		} elseif ( defined( 'WPSEO_VERSION' ) ) {
			$seo_plugin = 'Yoast SEO ' . WPSEO_VERSION;
		} elseif ( defined( 'AIOSEO_VERSION' ) ) {
			$seo_plugin = 'All in One SEO ' . AIOSEO_VERSION;
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$seo_plugin = 'SEOPress ' . SEOPRESS_VERSION;
		} elseif ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$seo_plugin = 'The SEO Framework ' . THE_SEO_FRAMEWORK_VERSION;
		}
		$checks[] = array(
			'label'  => 'SEO Plugin',
			'status' => 'None detected' !== $seo_plugin ? 'pass' : 'info',
			'detail' => $seo_plugin . ( 'None detected' !== $seo_plugin ? ' — schema merging active.' : ' — RankReady will output standalone Article schema.' ),
		);

		// 7. Auto-generate setting.
		$auto_gen = 'on' === get_option( RR_OPT_AUTO_GENERATE, 'off' );
		$checks[] = array(
			'label'  => 'Auto-Generate on Publish',
			'status' => $auto_gen ? 'pass' : 'info',
			'detail' => $auto_gen ? 'Enabled — summaries auto-generate on publish/update.' : 'Disabled (default) — use manual Regenerate or Bulk.',
		);

		// 8. Post coverage stats.
		global $wpdb;
		$public_types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$public_types = array_diff( $public_types, array( 'attachment' ) );

		if ( ! empty( $public_types ) ) {
			// $placeholders contains only "%s,%s,%s" tokens from array_fill,
			// never user input. Safe IN() clause pattern.
			$placeholders = implode( ',', array_fill( 0, count( $public_types ), '%s' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_posts = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
					...$public_types
				)
			);

			$with_summary = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish' AND p.post_type IN ({$placeholders})
					 WHERE pm.meta_key = %s AND pm.meta_value != ''",
					...array_merge( $public_types, array( RR_META_SUMMARY ) )
				)
			);

			$with_faq = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish' AND p.post_type IN ({$placeholders})
					 WHERE pm.meta_key = %s AND pm.meta_value != '' AND pm.meta_value != 'a:0:{}'",
					...array_merge( $public_types, array( RR_META_FAQ ) )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$total_posts  = 0;
			$with_summary = 0;
			$with_faq     = 0;
		}

		$summary_pct = $total_posts > 0 ? round( ( $with_summary / $total_posts ) * 100 ) : 0;
		$faq_pct     = $total_posts > 0 ? round( ( $with_faq / $total_posts ) * 100 ) : 0;

		$auto_summary_on = 'on' === get_option( RR_OPT_AUTO_GENERATE, 'off' );
		$auto_faq_on     = 'on' === get_option( RR_OPT_FAQ_AUTO_GENERATE, 'off' );

		// Only penalize coverage when the feature is intentionally enabled.
		// If auto-generate is off, report as info so it doesn't drag down the health score.
		if ( $auto_summary_on || $with_summary > 0 ) {
			$checks[] = array(
				'label'  => 'Key Takeaways Coverage',
				'status' => $summary_pct >= 80 ? 'pass' : ( $summary_pct >= 30 ? 'warn' : 'fail' ),
				'detail' => $with_summary . ' / ' . $total_posts . ' posts (' . $summary_pct . '%)',
			);
		} else {
			$checks[] = array(
				'label'  => 'Key Takeaways Coverage',
				'status' => 'info',
				'detail' => 'Auto-generate is off — generate summaries manually or enable auto-generate on publish.',
			);
		}

		if ( $auto_faq_on || $with_faq > 0 ) {
			$checks[] = array(
				'label'  => 'FAQ Coverage',
				'status' => $faq_pct >= 80 ? 'pass' : ( $faq_pct >= 30 ? 'warn' : ( $dfs_ok ? 'fail' : 'info' ) ),
				'detail' => $with_faq . ' / ' . $total_posts . ' posts (' . $faq_pct . '%)',
			);
		} else {
			$checks[] = array(
				'label'  => 'FAQ Coverage',
				'status' => 'info',
				'detail' => 'FAQ auto-generate is off — generate FAQs manually or enable auto-generate on publish.',
			);
		}

		// 9. Auto-display settings.
		$summary_display = 'on' === get_option( RR_OPT_AUTO_DISPLAY, 'off' );
		$faq_display     = 'on' === get_option( RR_OPT_FAQ_AUTO_DISPLAY, 'off' );
		$checks[] = array(
			'label'  => 'Auto-Display (Key Takeaways)',
			'status' => $summary_display ? 'pass' : 'info',
			'detail' => $summary_display ? 'Enabled — position: ' . get_option( RR_OPT_DISPLAY_POSITION, 'before' ) . ' content.' : 'Disabled — use block or widget.',
		);
		$checks[] = array(
			'label'  => 'Auto-Display (FAQ)',
			'status' => $faq_display ? 'pass' : 'info',
			'detail' => $faq_display ? 'Enabled — position: ' . get_option( RR_OPT_FAQ_POSITION, 'after' ) . ' content.' : 'Disabled — use block or widget.',
		);

		// 10. Rewrite rules check.
		$rules = get_option( 'rewrite_rules', array() );
		$has_md_rule   = false;
		$has_llms_rule = false;
		if ( is_array( $rules ) ) {
			foreach ( $rules as $pattern => $query ) {
				if ( false !== strpos( $pattern, '.md' ) ) {
					$has_md_rule = true;
				}
				if ( false !== strpos( $query, 'llms_txt' ) || false !== strpos( $pattern, 'llms' ) ) {
					$has_llms_rule = true;
				}
			}
		}
		if ( $md_on || $llms_on ) {
			$rewrite_ok = ( ! $md_on || $has_md_rule )
				&& ( ! $llms_on || $has_llms_rule );
			$checks[] = array(
				'label'  => 'Rewrite Rules',
				'status' => $rewrite_ok ? 'pass' : 'fail',
				'detail' => $rewrite_ok ? 'All required rewrite rules found.' : 'Missing rewrite rules — try deactivating and reactivating the plugin, or visit Settings > Permalinks.',
			);
		}

		// 11. Error log count.
		$errors = (array) get_option( 'rr_error_log', array() );
		$recent_errors = 0;
		$one_day_ago   = time() - DAY_IN_SECONDS;
		foreach ( $errors as $err ) {
			if ( isset( $err['time'] ) && $err['time'] > $one_day_ago ) {
				$recent_errors++;
			}
		}
		$checks[] = array(
			'label'  => 'Recent Errors (24h)',
			'status' => 0 === $recent_errors ? 'pass' : ( $recent_errors <= 5 ? 'warn' : 'fail' ),
			'detail' => $recent_errors . ' error' . ( 1 !== $recent_errors ? 's' : '' ) . ' in the last 24 hours.' . ( $recent_errors > 0 ? ' Check Error Log for details.' : '' ),
		);

		// 12. DataForSEO usage.
		$dfs_usage = (array) get_option( 'rr_dfs_usage', array() );
		$dfs_calls = isset( $dfs_usage['total_calls'] ) ? (int) $dfs_usage['total_calls'] : 0;
		if ( $dfs_ok ) {
			$checks[] = array(
				'label'  => 'DataForSEO API Calls',
				'status' => 'info',
				'detail' => $dfs_calls . ' total API calls tracked.',
			);
		}

		return new WP_REST_Response( array( 'checks' => $checks ), 200 );
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

	// ══════════════════════════════════════════════════════════════════════════
	// SCHEMA SCANNER ENDPOINTS
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Get schema scanner status — progress, scanned/total counts.
	 */
	public static function schema_status() {
		$rec = RR_Block::get_server_recommendation();
		return new WP_REST_Response( array(
			'success'         => true,
			'total_posts'     => $rec['total_posts'],
			'scanned_posts'   => $rec['scanned_posts'],
			'unscanned_posts' => $rec['unscanned_posts'],
			'current_batch'   => $rec['current_batch'],
			'est_minutes'     => $rec['est_minutes'],
			'cron_next_run'   => $rec['cron_next_run'],
		), 200 );
	}

	/**
	 * Get server recommendation for batch size.
	 */
	public static function schema_recommendation() {
		return new WP_REST_Response( array(
			'success' => true,
			'data'    => RR_Block::get_server_recommendation(),
		), 200 );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// WP-CRON BULK TICKS — process queue items even after browser close
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * WP-Cron tick for Start Over bulk — processes 1 post per tick.
	 * Runs every minute. Clears itself when queue is empty or stopped.
	 */
	public static function cron_startover_tick(): void {
		if ( ! get_option( RR_SO_RUNNING ) ) {
			wp_clear_scheduled_hook( RR_CRON_BULK_STARTOVER );
			return;
		}

		$queue = (array) get_option( RR_SO_QUEUE, array() );
		if ( empty( $queue ) ) {
			update_option( RR_SO_RUNNING, false, false );
			wp_clear_scheduled_hook( RR_CRON_BULK_STARTOVER );
			return;
		}

		$done    = (int) get_option( RR_SO_DONE, 0 );
		$has_dfs = ! empty( get_option( RR_OPT_DFS_LOGIN ) ) && ! empty( get_option( RR_OPT_DFS_PASSWORD ) );

		// Process 1 post per cron tick (summary + FAQ are heavy API calls).
		$post_id = (int) array_shift( $queue );

		if ( $post_id > 0 ) {
			// Clear existing data.
			delete_post_meta( $post_id, RR_META_SUMMARY );
			delete_post_meta( $post_id, RR_META_HASH );
			delete_post_meta( $post_id, RR_META_GENERATED );
			delete_post_meta( $post_id, RR_META_FAQ );
			delete_post_meta( $post_id, RR_META_FAQ_HASH );
			delete_post_meta( $post_id, RR_META_FAQ_GENERATED );
			delete_post_meta( $post_id, RR_META_FAQ_KEYWORD );

			// Regenerate summary.
			RR_Generator::force_generate( $post_id );

			// Regenerate FAQ.
			if ( $has_dfs ) {
				RR_Faq::generate_faq( $post_id );
			}

			$done++;
		}

		update_option( RR_SO_QUEUE, $queue, false );
		update_option( RR_SO_DONE,  $done,  false );

		if ( empty( $queue ) ) {
			update_option( RR_SO_RUNNING, false, false );
			wp_clear_scheduled_hook( RR_CRON_BULK_STARTOVER );
		}
	}

	/**
	 * WP-Cron tick for FAQ bulk generation.
	 *
	 * Safe batch processor with the following guarantees:
	 * - Dequeues BEFORE calling generate_faq() — a fatal error or timeout cannot stick the queue.
	 * - Wraps generate_faq() in try/catch to prevent uncaught exceptions from killing the tick.
	 * - Processes multiple posts per tick within a time budget (fits 30s max_execution_time).
	 * - Tracks failed post IDs in a separate list for debugging.
	 * - Watchdog: records last-tick timestamp for stuck-state detection.
	 */
	public static function cron_faq_tick(): void {
		if ( ! get_option( RR_FAQ_RUNNING ) ) {
			wp_clear_scheduled_hook( RR_CRON_BULK_FAQ );
			return;
		}

		$queue = (array) get_option( RR_FAQ_QUEUE, array() );
		if ( empty( $queue ) ) {
			update_option( RR_FAQ_RUNNING, false, false );
			wp_clear_scheduled_hook( RR_CRON_BULK_FAQ );
			return;
		}

		// Watchdog heartbeat — used by admin UI to detect stuck state.
		update_option( 'rr_faq_cron_last_tick', time(), false );

		// Time budget — stop processing 5s before max_execution_time to allow clean finish.
		$max_exec  = (int) ini_get( 'max_execution_time' );
		if ( $max_exec <= 0 ) {
			$max_exec = 30;
		}
		$deadline  = microtime( true ) + max( 10, $max_exec - 5 );
		$max_batch = 5; // Hard cap per tick to avoid runaway.

		$done    = (int) get_option( RR_FAQ_DONE, 0 );
		$failed  = (int) get_option( 'rr_faq_failed', 0 );
		$failed_ids = (array) get_option( 'rr_faq_failed_ids', array() );

		$processed_count = 0;

		while ( ! empty( $queue ) && $processed_count < $max_batch && microtime( true ) < $deadline ) {
			// CRITICAL: dequeue and persist BEFORE generation.
			// If generate_faq() fatals, the post is already removed from the queue
			// and will not be retried in an infinite loop.
			$post_id = (int) array_shift( $queue );
			update_option( RR_FAQ_QUEUE, $queue, false );

			if ( $post_id <= 0 ) {
				continue;
			}

			$post_exists = (bool) get_post( $post_id );
			if ( ! $post_exists ) {
				$done++;
				$failed++;
				$failed_ids[] = $post_id;
				continue;
			}

			// Wrap in try/catch — a fatal Throwable (PHP 7+) is not caught,
			// but Exception/Error are. We minimize surface by persisting dequeue first.
			try {
				$result = RR_Faq::generate_faq( $post_id );

				if ( is_wp_error( $result ) ) {
					$failed++;
					$failed_ids[] = $post_id;
				}
			} catch ( \Throwable $e ) {
				$failed++;
				$failed_ids[] = $post_id;
				if ( class_exists( 'RR_Generator' ) && method_exists( 'RR_Generator', 'log_error' ) ) {
					RR_Generator::log_error( 'FAQ/Cron', $e->getMessage(), $post_id );
				}
			}

			$done++;
			$processed_count++;

			// Persist progress after every post so a later fatal cannot roll back.
			update_option( RR_FAQ_DONE, $done, false );
			update_option( 'rr_faq_failed', $failed, false );

			// Keep the failed_ids list bounded to last 100 entries.
			if ( count( $failed_ids ) > 100 ) {
				$failed_ids = array_slice( $failed_ids, -100 );
			}
			update_option( 'rr_faq_failed_ids', $failed_ids, false );
		}

		// Final state.
		if ( empty( $queue ) ) {
			update_option( RR_FAQ_RUNNING, false, false );
			wp_clear_scheduled_hook( RR_CRON_BULK_FAQ );
		}
	}

	/**
	 * WP-Cron tick for summary bulk generation.
	 *
	 * Same safety guarantees as cron_faq_tick(): dequeue-before-generate, try/catch,
	 * time budget, and per-post progress persistence.
	 */
	public static function cron_summary_tick(): void {
		if ( ! get_option( RR_BULK_RUNNING ) ) {
			wp_clear_scheduled_hook( RR_CRON_BULK_SUMMARY );
			return;
		}

		$queue = (array) get_option( RR_BULK_QUEUE, array() );
		if ( empty( $queue ) ) {
			update_option( RR_BULK_RUNNING, false, false );
			wp_clear_scheduled_hook( RR_CRON_BULK_SUMMARY );
			return;
		}

		// Watchdog heartbeat.
		update_option( 'rr_bulk_cron_last_tick', time(), false );

		// Time budget — stop 5s before max_execution_time.
		$max_exec = (int) ini_get( 'max_execution_time' );
		if ( $max_exec <= 0 ) {
			$max_exec = 30;
		}
		$deadline  = microtime( true ) + max( 10, $max_exec - 5 );
		$max_batch = (int) self::BULK_BATCH;

		$done   = (int) get_option( RR_BULK_DONE, 0 );
		$failed = (int) get_option( 'rr_bulk_failed', 0 );

		$processed_count = 0;

		while ( ! empty( $queue ) && $processed_count < $max_batch && microtime( true ) < $deadline ) {
			$post_id = (int) array_shift( $queue );
			update_option( RR_BULK_QUEUE, $queue, false );

			if ( $post_id <= 0 || ! get_post( $post_id ) ) {
				$done++;
				$failed++;
				continue;
			}

			try {
				$result = RR_Generator::force_generate( $post_id );
				if ( false === $result ) {
					$failed++;
				}
			} catch ( \Throwable $e ) {
				$failed++;
				if ( class_exists( 'RR_Generator' ) && method_exists( 'RR_Generator', 'log_error' ) ) {
					RR_Generator::log_error( 'Summary/Cron', $e->getMessage(), $post_id );
				}
			}

			$done++;
			$processed_count++;

			update_option( RR_BULK_DONE, $done, false );
			update_option( 'rr_bulk_failed', $failed, false );
		}

		if ( empty( $queue ) ) {
			update_option( RR_BULK_RUNNING, false, false );
			wp_clear_scheduled_hook( RR_CRON_BULK_SUMMARY );
		}
	}
}
