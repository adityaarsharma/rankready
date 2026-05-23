<?php
/**
 * RankReady Pro — license + sandbox bootstrap (single-zip distribution).
 *
 * Defines rr_is_sandbox(), rr_is_pro(), and the EDD Software Licensing
 * helpers (rr_edd_api_request, rr_activate_license, rr_deactivate_license,
 * rr_beta_check_license).
 *
 * rc.15 cleanup: removed the RR_BETA_BUILD always-true gate and the
 * auto-activation block that hardcoded a shared beta license. Pro now
 * unlocks only via:
 *   1. Sandbox sites + rr_sandbox_simulate_pro = 'on' (dev only)
 *   2. EDD-issued license stored in rr_license_key option (production)
 *
 * @package RankReady
 * @since   1.2.0-rc.13 (single-zip rewrite)
 */
defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────
//
// rc.13 change — Free and Pro now ship as the same zip. Pro features unlock
// only when a valid license is active OR sandbox simulation is on (dev sites
// only). This file always loads now — it no longer marks the zip as "beta".

// EDD store item ID for RankReady Pro (used by RR_SL_Plugin_Updater).
if ( ! defined( 'RR_EDD_ITEM_ID' ) ) {
	define( 'RR_EDD_ITEM_ID', 463989 );
}

// Sandbox-mode option key — local/dev sites can flip Pro on without a license.
if ( ! defined( 'RR_OPT_SANDBOX_PRO' ) ) {
	define( 'RR_OPT_SANDBOX_PRO', 'rr_sandbox_simulate_pro' );
}

// ── rr_is_sandbox() — detect local / dev environments ───────────────────────

if ( ! function_exists( 'rr_is_sandbox' ) ) {
	/**
	 * Returns true when the site is clearly NOT production.
	 *
	 * We respect this check ONLY for the sandbox-Pro toggle — production sites
	 * can never accidentally enable Pro without a license.
	 *
	 * Detection chain (any one match → sandbox):
	 *   1. RR_SANDBOX_MODE constant defined and true
	 *   2. WP_ENVIRONMENT_TYPE === 'local' or 'development' (WP 5.5+ standard)
	 *   3. Host = localhost / 127.0.0.1 / ::1
	 *   4. Host TLD in .local / .test / .localhost / .docker / .wp-env
	 *   5. Host is RFC1918 private IP (10.x, 172.16-31.x, 192.168.x)
	 *
	 * @since 1.2.0-rc.13
	 */
	function rr_is_sandbox(): bool {
		// 1. Explicit constant override
		if ( defined( 'RR_SANDBOX_MODE' ) && RR_SANDBOX_MODE ) {
			return true;
		}

		// 2. WP environment type (WP 5.5+)
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$env = wp_get_environment_type();
			if ( 'local' === $env || 'development' === $env ) {
				return true;
			}
		}

		// 3-5. Host-based detection
		$host = parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		// Strip port if present
		$host = strtok( $host, ':' );

		// Loopback addresses
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		// Dev TLDs
		if ( preg_match( '/\.(local|test|localhost|docker|wp-env)$/i', $host ) ) {
			return true;
		}

		// RFC1918 private IP ranges
		if ( preg_match( '/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host ) ) {
			return true;
		}

		return false;
	}
}

// ── rr_is_pro() ───────────────────────────────────────────────────────────────

if ( ! function_exists( 'rr_is_pro' ) ) {
	/**
	 * Returns true when Pro features should be active on this site.
	 *
	 * Activation rules (any one match → Pro):
	 *   1. Sandbox site + sandbox-Pro toggle is on → simulate Pro for dev work
	 *   2. EDD-issued license is valid (production path)
	 *
	 * Sandbox mode CANNOT enable Pro on production sites — rr_is_sandbox()
	 * gates the toggle so the only way to unlock Pro on a real site is a
	 * legitimate license.
	 *
	 * @since   1.2.0-rc.13 (rewritten — single zip, license-driven, sandbox-aware)
	 * @return  bool
	 */
	function rr_is_pro(): bool {
		// Sandbox / dev override (default ON for dev convenience; can be toggled
		// off in Advanced → Developer Mode card)
		if ( rr_is_sandbox() && 'on' === get_option( RR_OPT_SANDBOX_PRO, 'on' ) ) {
			return true;
		}

		// Production path — real EDD license
		return 'valid' === get_option( 'rr_license_status', '' );
	}
}

// ── EDD SL license helpers ────────────────────────────────────────────────────

/**
 * Calls the EDD SL API on store.posimyth.com.
 *
 * @param string $edd_action 'activate_license' | 'deactivate_license' | 'check_license'
 * @param string $license    License key.
 * @return object|WP_Error   Decoded JSON response body or WP_Error on failure.
 */
function rr_edd_api_request( string $edd_action, string $license ) {
	$response = wp_remote_post(
		'https://store.posimyth.com',
		array(
			'timeout'   => 15,
			'sslverify' => true,
			'body'      => array(
				'edd_action' => $edd_action,
				'license'    => $license,
				'item_id'    => RR_EDD_ITEM_ID,
				'url'        => home_url(),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ) );
	if ( null === $data ) {
		return new WP_Error( 'rr_edd_bad_response', __( 'Invalid response from license server.', 'rankready' ) );
	}

	return $data;
}

/**
 * Activate a license key and store the result.
 *
 * @param string $license License key to activate.
 * @return true|WP_Error  true on success, WP_Error on failure.
 */
function rr_activate_license( string $license ) {
	$data = rr_edd_api_request( 'activate_license', $license );

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$status = isset( $data->license ) ? (string) $data->license : 'invalid';
	update_option( 'rr_license_key',    $license, false );
	update_option( 'rr_license_status', $status,  false );

	if ( 'valid' !== $status ) {
		$msg = isset( $data->error ) ? (string) $data->error : $status;
		return new WP_Error( 'rr_license_' . $status, rr_license_error_message( $msg ) );
	}

	return true;
}

/**
 * Deactivate the stored license key.
 *
 * @return true|WP_Error
 */
function rr_deactivate_license() {
	$license = (string) get_option( 'rr_license_key', '' );
	if ( '' === $license ) {
		update_option( 'rr_license_status', '', false );
		return true;
	}

	$data = rr_edd_api_request( 'deactivate_license', $license );

	// Always clear local status regardless of API result.
	update_option( 'rr_license_status', 'deactivated', false );

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	return true;
}

/**
 * Re-check stored license status against EDD store (run by cron / admin load).
 */
function rr_beta_check_license(): void {
	$license = (string) get_option( 'rr_license_key', '' );
	if ( '' === $license ) {
		return;
	}

	$data = rr_edd_api_request( 'check_license', $license );
	if ( is_wp_error( $data ) ) {
		return;
	}

	$status = isset( $data->license ) ? (string) $data->license : 'invalid';
	update_option( 'rr_license_status', $status, false );
}

/**
 * Human-readable error message for EDD error codes.
 *
 * @param string $code EDD error code or raw status string.
 * @return string
 */
function rr_license_error_message( string $code ): string {
	$messages = array(
		'expired'            => __( 'Your license key has expired. Renew at store.posimyth.com.', 'rankready' ),
		'revoked'            => __( 'Your license key has been revoked. Contact support@posimyth.com.', 'rankready' ),
		'missing'            => __( 'License key not found. Make sure you copied the full key.', 'rankready' ),
		'invalid'            => __( 'Invalid license key.', 'rankready' ),
		'site_inactive'      => __( 'License not active for this site. Activate it from your account at store.posimyth.com.', 'rankready' ),
		'item_name_mismatch' => __( 'This license key is for a different product.', 'rankready' ),
		'no_activations_left'=> __( 'You have reached the activation limit for this license. Deactivate an unused site first.', 'rankready' ),
		'deactivated'        => __( 'License deactivated.', 'rankready' ),
	);
	return $messages[ $code ] ?? sprintf(
		/* translators: %s: raw error code */
		__( 'License error: %s. Contact support@posimyth.com.', 'rankready' ),
		esc_html( $code )
	);
}

// ── Cron: daily license re-check ─────────────────────────────────────────────
// rc.15 — removed the rr_beta_auto_activate() block (was auto-activating a
// hardcoded shared license key on every install). License entry now requires
// either the EDD Settings UI (ships in v1.3) or wp-cli:
//   wp option update rr_license_key <KEY>
//   wp eval 'rr_activate_license( get_option("rr_license_key") );'

add_action( 'rr_daily_license_check', 'rr_beta_check_license' );

add_action( 'wp_loaded', function (): void {
	if ( ! wp_next_scheduled( 'rr_daily_license_check' ) ) {
		wp_schedule_event( time(), 'daily', 'rr_daily_license_check' );
	}
} );
