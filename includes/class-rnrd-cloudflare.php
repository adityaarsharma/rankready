<?php
/**
 * RankReady — Cloudflare auto-fix integration.
 *
 * Cloudflare APO famously ignores `Vary: Accept` on HTML responses: when an
 * AI agent requests a normal page URL with `Accept: text/markdown`, APO serves
 * the cached HTML (same URL = same cache key) instead of markdown. This class
 * lets the user paste a scoped Cloudflare API token, and we POST a single Cache
 * Rule via the Rulesets API that bypasses cache ONLY for requests carrying the
 * `Accept: text/markdown` header. Distinct `.md` URLs are a separate, unique
 * cache key with no collision, so they stay fully cacheable (fast for crawlers).
 *
 * No Worker, no edge compute, no recurring cost. One Cache Rule. Free
 * Cloudflare tier supports up to 10 such rules; the user almost always
 * has room.
 *
 * Threat model: API token is stored encrypted at rest via RNRD_Crypto
 * (added to SECRET_OPTIONS). Scoped to Zone:Cache Rules:Edit only —
 * can't escalate to other Cloudflare resources.
 *
 * @package RankReady
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Cloudflare {

	private const OPT_TOKEN   = 'rnrd_cf_api_token';
	private const OPT_EMAIL   = 'rnrd_cf_email';
	private const OPT_ZONE    = 'rnrd_cf_zone_id';
	private const OPT_RULE_ID = 'rnrd_cf_rule_id';
	private const OPT_GLOBAL_KEY = 'rnrd_cf_global_key';
	private const OPT_AUTH_MODE  = 'rnrd_cf_auth_mode';

	private const RULE_DESCRIPTION = 'RankReady — bypass cache for AI markdown requests';

	private const REST_NAMESPACE = 'rankready/v1';

	// ── Lifecycle ──────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	public static function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/cloudflare/connect',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'permission_check' ),
				'args'                => array(
					'mode'  => array( 'type' => 'string' ),
					'token' => array( 'type' => 'string' ),
					'email' => array( 'type' => 'string' ),
					'key'   => array( 'type' => 'string' ),
				),
				'callback' => array( self::class, 'rest_connect' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/cloudflare/disconnect',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'permission_check' ),
				'callback'            => array( self::class, 'rest_disconnect' ),
			)
		);
	}

	public static function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	// ── Detection ──────────────────────────────────────────────────────────

	/**
	 * Best-effort Cloudflare detection. Cached for 1 hour so we don't
	 * burn an HTTP request on every Settings-page render.
	 *
	 * Order of evidence:
	 *   1. If the user has already connected (token + zone stored) → yes
	 *   2. Hit the site's own home URL via wp_remote_head and look at headers
	 *
	 * @return array{detected:bool, ray:?string, connected:bool}
	 */
	public static function detect(): array {
		$connected = self::has_stored_creds()
			&& '' !== (string) get_option( self::OPT_ZONE, '' );

		$cache_key = 'rnrd_cf_detect_v1';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$cached['connected'] = $connected;
			return $cached;
		}

		$response = wp_remote_head(
			home_url( '/' ),
			array(
				'timeout'     => 4,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'rnrd_sslverify', true ),
			)
		);

		$detected = false;
		$ray      = null;

		if ( ! is_wp_error( $response ) ) {
			$headers = wp_remote_retrieve_headers( $response );
			if ( $headers && method_exists( $headers, 'offsetExists' ) ) {
				foreach ( array( 'cf-ray', 'server', 'cf-cache-status' ) as $h ) {
					if ( $headers->offsetExists( $h ) ) {
						$val = (string) $headers->offsetGet( $h );
						if ( 'cf-ray' === $h ) {
							$detected = true;
							$ray      = $val;
							break;
						}
						if ( false !== stripos( $val, 'cloudflare' ) ) {
							$detected = true;
							break;
						}
					}
				}
			}
		}

		$result = array(
			'detected'  => $detected,
			'ray'       => $ray,
			'connected' => $connected,
		);

		set_transient( $cache_key, $result, HOUR_IN_SECONDS );

		return $result;
	}

	// ── REST endpoints ─────────────────────────────────────────────────────

	public static function rest_connect( WP_REST_Request $request ): WP_REST_Response {
		// v1.2.1 — scoped API tokens only. The Global API Key grants account-wide
		// access, so it is no longer offered for NEW connections. Installs that
		// already connected that way keep working (see stored_creds/auth_headers,
		// which still read the legacy global-key options).
		$token = trim( (string) $request->get_param( 'token' ) );

		if ( '' === $token ) {
			return new WP_REST_Response(
				array( 'success' => false, 'error' => __( 'A Cloudflare API token is required.', 'rankready-ai-llm-seo' ) ),
				400
			);
		}

		$mode  = 'token';
		$creds = array( 'mode' => 'token', 'token' => $token, 'email' => '', 'key' => '' );

		// v1.1.10 — Auto-detect zone ID from the site host. User no longer
		// pastes the zone ID manually. We query GET /zones?name={host} with
		// the scoped token; the API returns the zone IDs the token can access.
		$host = self::get_site_host();
		if ( '' === $host ) {
			return new WP_REST_Response(
				array( 'success' => false, 'error' => __( 'Could not determine the site host name.', 'rankready-ai-llm-seo' ) ),
				400
			);
		}

		// Find the zone. Exact name match first (fast path); if that returns
		// nothing, list every zone the token can see and match by apex — this
		// also covers subdomain hosts whose Cloudflare zone is the root domain,
		// and lets us return a precise error instead of a generic "no zone".
		$zone_id = '';
		$lookup  = self::api_request( $creds, 'GET', '/zones?name=' . rawurlencode( $host ) );
		if ( ! is_wp_error( $lookup ) && ! empty( $lookup['result'][0]['id'] ) ) {
			$zone_id = (string) $lookup['result'][0]['id'];
		}

		if ( '' === $zone_id ) {
			$all = self::api_request( $creds, 'GET', '/zones?per_page=50' );
			if ( is_wp_error( $all ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						// phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Instructional link to the user's own Cloudflare dashboard, not an offloaded asset.
						/* translators: %s: Cloudflare API error message */
						'error'   => sprintf( __( 'Cloudflare rejected the request: %s. Create a Custom Token at dash.cloudflare.com/profile/api-tokens with two permissions — "Zone : Zone : Read" and "Zone : Cache Rules : Edit" — and scope it to this site\'s zone (or all zones).', 'rankready-ai-llm-seo' ), $all->get_error_message() ),
						// phpcs:enable PluginCheck.CodeAnalysis.Offloading.OffloadedContent
					),
					400
				);
			}
			$seen = array();
			if ( ! empty( $all['result'] ) && is_array( $all['result'] ) ) {
				foreach ( $all['result'] as $z ) {
					$name = isset( $z['name'] ) ? strtolower( (string) $z['name'] ) : '';
					if ( '' === $name ) {
						continue;
					}
					$seen[] = $name;
					$is_apex = strlen( $host ) > strlen( $name ) && substr( $host, -strlen( '.' . $name ) ) === '.' . $name;
					if ( $name === $host || $is_apex ) {
						$zone_id = isset( $z['id'] ) ? (string) $z['id'] : '';
						break;
					}
				}
			}
			if ( '' === $zone_id ) {
				$msg = empty( $seen )
					? __( 'This Cloudflare token cannot see any zones. Add "Zone : Zone : Read" permission and scope the token to this site\'s zone (or all zones).', 'rankready-ai-llm-seo' )
					: sprintf(
						/* translators: 1: site host, 2: comma-separated zone names the token can access */
						__( 'The token can see these zones: %2$s — but none match %1$s. Confirm %1$s is on this Cloudflare account and the token is scoped to it.', 'rankready-ai-llm-seo' ),
						$host,
						implode( ', ', array_slice( $seen, 0, 10 ) )
					);
				return new WP_REST_Response( array( 'success' => false, 'error' => $msg ), 400 );
			}
		}

		// If we already had a stored rule_id, delete it first (re-connect path).
		$existing_rule_id = (string) get_option( self::OPT_RULE_ID, '' );
		if ( '' !== $existing_rule_id ) {
			self::delete_rule( $creds, $zone_id, $existing_rule_id );
		}

		$rule_id = self::create_rule( $creds, $zone_id );
		if ( is_wp_error( $rule_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: Cloudflare API error message */
						__( 'Found the zone, but creating the cache rule failed: %s. The token needs "Zone : Cache Rules : Edit" — make sure that row is set to Edit (not Read), alongside "Zone : Zone : Read".', 'rankready-ai-llm-seo' ),
						$rule_id->get_error_message()
					),
				),
				400
			);
		}

		// v1.2.1 — token-only. Connecting also clears any legacy Global API Key
		// credentials left over from an earlier connection on this site.
		update_option( self::OPT_AUTH_MODE, $mode );
		update_option( self::OPT_TOKEN, $token );
		delete_option( self::OPT_EMAIL );
		delete_option( self::OPT_GLOBAL_KEY );

		update_option( self::OPT_ZONE,    $zone_id );
		update_option( self::OPT_RULE_ID, $rule_id );
		delete_transient( 'rnrd_cf_detect_v1' );

		return new WP_REST_Response(
			array(
				'success' => true,
				'rule_id' => $rule_id,
				'zone'    => $zone_id,
				'host'    => $host,
			)
		);
	}

	/**
	 * Get the bare host name from home_url() — strips port + path.
	 * Used as the Cloudflare zone-lookup key.
	 */
	private static function get_site_host(): string {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return '';
		}
		// Strip leading "www." so wildcard zones (root domain on CF) match.
		return preg_replace( '/^www\./i', '', strtolower( $host ) );
	}

	public static function rest_disconnect( WP_REST_Request $request ): WP_REST_Response {
		$creds   = self::stored_creds();
		$zone_id = (string) get_option( self::OPT_ZONE, '' );
		$rule_id = (string) get_option( self::OPT_RULE_ID, '' );

		if ( self::has_stored_creds() && '' !== $zone_id && '' !== $rule_id ) {
			self::delete_rule( $creds, $zone_id, $rule_id );
		}

		delete_option( self::OPT_TOKEN );
		delete_option( self::OPT_EMAIL );
		delete_option( self::OPT_GLOBAL_KEY );
		delete_option( self::OPT_AUTH_MODE );
		delete_option( self::OPT_ZONE );
		delete_option( self::OPT_RULE_ID );
		delete_transient( 'rnrd_cf_detect_v1' );

		return new WP_REST_Response( array( 'success' => true ) );
	}

	// ── Cloudflare API ─────────────────────────────────────────────────────

	/**
	 * Assemble stored Cloudflare credentials. Global key can also come from a
	 * RNRD_CF_GLOBAL_KEY wp-config constant so it never has to live in the DB.
	 *
	 * @return array{mode:string,token:string,email:string,key:string}
	 */
	private static function stored_creds(): array {
		$mode = 'global' === get_option( self::OPT_AUTH_MODE, 'token' ) ? 'global' : 'token';
		$key  = ( defined( 'RNRD_CF_GLOBAL_KEY' ) && RNRD_CF_GLOBAL_KEY )
			? (string) RNRD_CF_GLOBAL_KEY
			: (string) get_option( self::OPT_GLOBAL_KEY, '' );
		return array(
			'mode'  => $mode,
			'token' => (string) get_option( self::OPT_TOKEN, '' ),
			'email' => (string) get_option( self::OPT_EMAIL, '' ),
			'key'   => $key,
		);
	}

	private static function has_stored_creds(): bool {
		$c = self::stored_creds();
		return 'global' === $c['mode']
			? ( '' !== $c['email'] && '' !== $c['key'] )
			: ( '' !== $c['token'] );
	}

	/**
	 * HTTP auth headers for a Cloudflare request. Scoped token -> Bearer;
	 * Global API Key -> X-Auth-Email + X-Auth-Key (the WP Rocket / official
	 * Cloudflare-plugin method). Accepts a creds array or a bare token string.
	 *
	 * @param array|string $creds Credentials.
	 * @return array HTTP headers.
	 */
	private static function auth_headers( $creds ): array {
		if ( is_array( $creds ) && 'global' === ( $creds['mode'] ?? '' ) ) {
			return array(
				'X-Auth-Email' => (string) ( $creds['email'] ?? '' ),
				'X-Auth-Key'   => (string) ( $creds['key'] ?? '' ),
				'Content-Type' => 'application/json',
			);
		}
		$token = is_array( $creds ) ? (string) ( $creds['token'] ?? '' ) : (string) $creds;
		return array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		);
	}

	private static function api_request( $creds, string $method, string $path, array $body = array() ) {
		$url  = 'https://api.cloudflare.com/client/v4' . $path;
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => self::auth_headers( $creds ),
		);

		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( $code >= 400 || ! is_array( $json ) || empty( $json['success'] ) ) {
			$message = __( 'Cloudflare API rejected the request.', 'rankready-ai-llm-seo' );
			if ( is_array( $json ) && ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
				$first = reset( $json['errors'] );
				if ( is_array( $first ) && ! empty( $first['message'] ) ) {
					$message = (string) $first['message'];
				}
			}
			return new WP_Error( 'rnrd_cf_api_error', $message );
		}

		return $json;
	}

	/**
	 * Create the markdown content-negotiation bypass Cache Rule.
	 *
	 * IMPORTANT — scope: we bypass cache ONLY for the `Accept: text/markdown`
	 * header on a normal page URL. That is the one case APO breaks: same URL,
	 * same cache key, APO ignores `Vary: Accept` and serves the cached HTML to
	 * a markdown request. Distinct `.md` URLs (e.g. /page.md) have their OWN
	 * cache key, always return markdown, and have no collision — so they are
	 * intentionally NOT bypassed and remain fully cacheable (faster for AI
	 * crawlers, less origin load). Bypassing them would only slow agents for
	 * no correctness benefit.
	 *
	 * @return string|WP_Error Rule ID on success.
	 */
	private static function create_rule( $token, string $zone_id ) {
		$rule = array(
			'expression'        => '(http.request.headers["accept"][0] contains "text/markdown")',
			'action'            => 'set_cache_settings',
			'action_parameters' => array(
				'cache' => false,
			),
			'description'       => self::RULE_DESCRIPTION,
			'enabled'           => true,
		);

		// Read the existing cache-phase entrypoint ruleset first. We APPEND our
		// rule to it — never PUT the whole ruleset, which would silently wipe
		// every Cache Rule the user already configured in Cloudflare (a real
		// risk on production sites that already tune caching). See audit note
		// "never conflict with or harm the site's existing config".
		$entry = self::api_request(
			$token,
			'GET',
			'/zones/' . rawurlencode( $zone_id ) . '/rulesets/phases/http_request_cache_settings/entrypoint'
		);

		if ( ! is_wp_error( $entry ) && ! empty( $entry['result']['id'] ) ) {
			$ruleset_id = (string) $entry['result']['id'];

			// Idempotent re-connect: if our rule is already there, reuse its ID —
			// but if its expression has drifted from what this plugin version
			// wants (e.g. a release narrowed the bypass scope), PATCH it in place
			// so existing installs self-heal on reconnect / plugin update.
			if ( ! empty( $entry['result']['rules'] ) && is_array( $entry['result']['rules'] ) ) {
				foreach ( $entry['result']['rules'] as $existing ) {
					if ( isset( $existing['description'] ) && self::RULE_DESCRIPTION === $existing['description'] && ! empty( $existing['id'] ) ) {
						$current_expr = isset( $existing['expression'] ) ? (string) $existing['expression'] : '';
						if ( $current_expr !== $rule['expression'] ) {
							$updated = self::api_request(
								$token,
								'PATCH',
								'/zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules/' . rawurlencode( (string) $existing['id'] ),
								$rule
							);
							return self::extract_rule_id( $updated );
						}
						return (string) $existing['id'];
					}
				}
			}

			// Append a single rule — leaves every existing rule untouched.
			$response = self::api_request(
				$token,
				'POST',
				'/zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules',
				$rule
			);
			return self::extract_rule_id( $response );
		}

		// No cache-phase entrypoint exists yet — create it with just our rule.
		// Safe: there are no existing rules to overwrite.
		$response = self::api_request(
			$token,
			'PUT',
			'/zones/' . rawurlencode( $zone_id ) . '/rulesets/phases/http_request_cache_settings/entrypoint',
			array( 'rules' => array( $rule ) )
		);
		return self::extract_rule_id( $response );
	}

	/**
	 * Pull our rule's ID out of a Rulesets API response (the updated ruleset).
	 * Matches by our unique RULE_DESCRIPTION so it works whether the response
	 * lists one rule or many.
	 *
	 * @param array|WP_Error $response Cloudflare API response.
	 * @return string|WP_Error Rule ID on success.
	 */
	private static function extract_rule_id( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['result']['rules'] ) || ! is_array( $response['result']['rules'] ) ) {
			return new WP_Error( 'rnrd_cf_no_rule', __( 'Cloudflare returned no rule ID.', 'rankready-ai-llm-seo' ) );
		}

		foreach ( $response['result']['rules'] as $rule ) {
			if ( isset( $rule['description'] ) && self::RULE_DESCRIPTION === $rule['description'] && ! empty( $rule['id'] ) ) {
				return (string) $rule['id'];
			}
		}

		return new WP_Error( 'rnrd_cf_no_rule', __( 'Could not locate the RankReady rule in Cloudflare response.', 'rankready-ai-llm-seo' ) );
	}

	private static function delete_rule( $token, string $zone_id, string $rule_id ): void {
		// Single-rule deletion must use the RULESET-ID path. The phase-entrypoint
		// path only supports GET/PUT, so a DELETE there silently no-ops (leaving a
		// stale rule behind on disconnect). Resolve the cache-phase ruleset ID
		// first, then delete the rule from it.
		$entry = self::api_request(
			$token,
			'GET',
			'/zones/' . rawurlencode( $zone_id ) . '/rulesets/phases/http_request_cache_settings/entrypoint'
		);
		if ( is_wp_error( $entry ) || empty( $entry['result']['id'] ) ) {
			return;
		}
		$ruleset_id = (string) $entry['result']['id'];
		self::api_request(
			$token,
			'DELETE',
			'/zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules/' . rawurlencode( $rule_id )
		);
	}

	// ── Admin render helper (called from RNRD_Admin Settings tab) ─────────

	/**
	 * Render the Cloudflare card on the Settings tab.
	 * Detects state and renders the appropriate UI:
	 *   - Connected: status + Disconnect button
	 *   - Detected but not connected: token + zone ID form
	 *   - Not detected: passive label
	 */
	public static function render_card(): void {
		$state = self::detect();

		// v1.1.10 — Only render the card when Cloudflare is actually in front
		// of the site (cf-ray header detected) OR already connected. If neither
		// is true, no UI noise: the user isn't on Cloudflare, so the card
		// has nothing to offer.
		if ( ! $state['detected'] && ! $state['connected'] ) {
			return;
		}
		?>
		<div class="rnrd-card rnrd-cf-card">
			<h2 class="rnrd-card-title"><?php esc_html_e( 'Cloudflare cache compatibility', 'rankready-ai-llm-seo' ); ?></h2>
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Cloudflare APO caches the HTML response and serves it back to every visitor, including AI agents that requested Markdown. RankReady adds one Cache Rule that bypasses cache for those requests.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<?php if ( $state['connected'] ) : ?>
				<p class="rnrd-cf-status rnrd-cf-status--ok">
					<?php esc_html_e( 'Cache rule active. AI markdown requests bypass APO.', 'rankready-ai-llm-seo' ); ?>
				</p>
				<button type="button" class="button button-secondary" id="rnrd-cf-disconnect">
					<?php esc_html_e( 'Disconnect and remove rule', 'rankready-ai-llm-seo' ); ?>
				</button>
			<?php else : ?>
				<p class="rnrd-cf-status rnrd-cf-status--info">
					<?php
					/* translators: %s: Cloudflare Ray ID */
					echo esc_html( sprintf( __( 'Cloudflare detected (Ray %s).', 'rankready-ai-llm-seo' ), (string) ( $state['ray'] ?? '?' ) ) );
					?>
				</p>
				<?php self::render_connect_form(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_connect_form(): void {
		?>
		<div class="rnrd-cf-form">
			<p class="rnrd-card-desc">
				<?php esc_html_e( 'Connect Cloudflare so RankReady creates the Markdown cache-bypass rule for you.', 'rankready-ai-llm-seo' ); ?>
			</p>

			<table class="form-table rnrd-form-table" id="rnrd-cf-token-fields">
				<tr>
					<th scope="row"><label for="rnrd-cf-token"><?php esc_html_e( 'API token', 'rankready-ai-llm-seo' ); ?></label></th>
					<td>
						<input type="password" id="rnrd-cf-token" class="regular-text" autocomplete="off" />

						<p class="description" style="margin-top:8px;">
							<?php
							printf(
								/* translators: %s: link to the Cloudflare API token creation page */
								esc_html__( 'Create a Custom Token at %s', 'rankready-ai-llm-seo' ),
								// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Instructional link to the user's own Cloudflare dashboard, not an offloaded asset.
								'<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer">dash.cloudflare.com/profile/api-tokens</a>'
							);
							?>
						</p>

						<p class="description" style="margin:10px 0 4px;">
							<strong><?php esc_html_e( 'Give it these two permission rows:', 'rankready-ai-llm-seo' ); ?></strong>
						</p>
						<ul class="description" style="margin:0 0 0 2px;padding:0;list-style:none;">
							<li style="margin:0 0 3px;"><code>Zone</code> &rarr; <code>Zone</code> &rarr; <code>Read</code></li>
							<li style="margin:0;"><code>Zone</code> &rarr; <code>Cache Rules</code> &rarr; <code>Edit</code></li>
						</ul>

						<p class="description" style="margin-top:10px;">
							<?php esc_html_e( 'RankReady finds the zone automatically — you do not need the zone ID.', 'rankready-ai-llm-seo' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button button-primary" id="rnrd-cf-connect">
					<?php esc_html_e( 'Connect and create rule', 'rankready-ai-llm-seo' ); ?>
				</button>
				<span id="rnrd-cf-msg" class="rnrd-cf-msg"></span>
			</p>
		</div>
		<?php
	}
}
