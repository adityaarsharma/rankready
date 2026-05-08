<?php
/**
 * RankReady Pro Beta — license bootstrap.
 *
 * Loaded by rankready.php when this file is present (beta zip only).
 * Defines RR_BETA_BUILD, RR_BETA_LICENSE, and rr_is_pro().
 * Registers EDD SL license activate / deactivate / check hooks.
 *
 * @package RankReady
 */
defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────

if ( ! defined( 'RR_BETA_BUILD' ) ) {
	define( 'RR_BETA_BUILD', true );
}

// Pre-issued beta license key — used for auto-updates & status checks.
// Hardcoded so beta testers never see a license field; activation happens
// silently on first wp_loaded so the EDD store registers each install and
// auto-updates flow without user interaction.
if ( ! defined( 'RR_BETA_LICENSE' ) ) {
	define( 'RR_BETA_LICENSE', 'fe7f1e5173f7b8c20f9e139067ddd628' );
}

// EDD store item ID for RankReady Pro.
if ( ! defined( 'RR_EDD_ITEM_ID' ) ) {
	define( 'RR_EDD_ITEM_ID', 463989 );
}

// ── rr_is_pro() ───────────────────────────────────────────────────────────────

if ( ! function_exists( 'rr_is_pro' ) ) {
	/**
	 * Returns true when a valid Pro (or beta) license is active on this site.
	 *
	 * For beta builds: the pre-issued beta key is always considered valid so
	 * testers never hit the free-tier limits. A real license check against the
	 * EDD store still runs in the background (via rr_beta_check_license) to keep
	 * the stored status current; it does not gate feature access during beta.
	 */
	function rr_is_pro(): bool {
		if ( defined( 'RR_BETA_BUILD' ) && RR_BETA_BUILD ) {
			return true; // Beta: all features unlocked.
		}
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
	$license = defined( 'RR_BETA_LICENSE' ) ? RR_BETA_LICENSE : (string) get_option( 'rr_license_key', '' );
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

// ── Silent auto-activation on first load ─────────────────────────────────────
// The beta build never shows a license UI. The pre-issued RR_BETA_LICENSE is
// auto-activated against the EDD store the first time wp_loaded fires after
// install (and re-tried daily until EDD reports `valid`). Once activated, the
// EDD SL Plugin Updater can fetch updates without any user interaction.

add_action( 'wp_loaded', 'rr_beta_auto_activate' );

function rr_beta_auto_activate(): void {
	// Only beta builds carry RR_BETA_LICENSE; production WP.org zip never enters this branch.
	if ( ! defined( 'RR_BETA_LICENSE' ) || '' === RR_BETA_LICENSE ) {
		return;
	}

	$status = (string) get_option( 'rr_license_status', '' );
	if ( 'valid' === $status ) {
		return; // Already activated — daily cron keeps it warm.
	}

	// Throttle retries to one attempt per hour so a flapping store doesn't
	// hammer EDD on every page load.
	$last_try = (int) get_option( 'rr_license_last_attempt', 0 );
	if ( ( time() - $last_try ) < HOUR_IN_SECONDS ) {
		return;
	}
	update_option( 'rr_license_last_attempt', time(), false );

	rr_activate_license( RR_BETA_LICENSE );
}

// ── Cron: daily license re-check ─────────────────────────────────────────────

add_action( 'rr_daily_license_check', 'rr_beta_check_license' );

add_action( 'wp_loaded', function (): void {
	if ( ! wp_next_scheduled( 'rr_daily_license_check' ) ) {
		wp_schedule_event( time(), 'daily', 'rr_daily_license_check' );
	}
} );
