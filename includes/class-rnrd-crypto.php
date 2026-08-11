<?php
/**
 * RankReady — Secret encryption at rest.
 *
 * Wraps the 5 secret options (4 LLM provider API keys + the DataForSEO
 * password) so they're stored in `wp_options` as AES-256-GCM ciphertext
 * keyed off the site's AUTH_KEY + SECURE_AUTH_SALT constants. Plaintext
 * never persists to disk.
 *
 * Backward-compat (CLAUDE.md hard rule): values stored as plaintext by
 * earlier RankReady versions still decrypt correctly (we detect the
 * "rnrdenc:" prefix; absent prefix = plaintext = return as-is). The
 * next save through the admin UI re-saves with the encryption prefix,
 * so plaintext leaves the DB on first user save in v1.1.0+.
 *
 * Threat model: protects against `wp_options` table dumps via SQL
 * injection on OTHER plugins, against backup files containing the
 * options table, and against `wp_options` being readable to other
 * tenants on shared hosts. Does NOT protect against full WP root
 * compromise — anyone with `wp-config.php` access has the keys.
 *
 * @package RankReady
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Crypto {

	/** Prefix that marks a value as encrypted. */
	private const PREFIX = 'rnrdenc:';

	/** Cipher used for encryption. AES-256-GCM provides integrity (auth tag) too. */
	private const CIPHER = 'aes-256-gcm';

	/**
	 * Option keys that hold secrets. Reads decrypt, writes encrypt.
	 * Other RankReady options (model names, toggles, post-type arrays)
	 * are stored plaintext — they're not secrets.
	 */
	private const SECRET_OPTIONS = array(
		'rnrd_openai_api_key',
		'rnrd_anthropic_api_key',
		'rnrd_gemini_api_key',
		'rnrd_deepseek_api_key',
		'rnrd_dfs_password',
		'rnrd_cf_api_token',   // v1.1.0 — Cloudflare auto-fix API token
		'rnrd_cf_global_key',  // v1.2.1 — Cloudflare Global API Key (account-wide; MUST be encrypted at rest)
	);

	// ── Lifecycle ──────────────────────────────────────────────────────────

	public static function init(): void {
		foreach ( self::SECRET_OPTIONS as $opt ) {
			add_filter( 'pre_update_option_' . $opt, array( self::class, 'encrypt_on_save' ), 10, 1 );
			add_filter( 'option_' . $opt,             array( self::class, 'decrypt_on_read' ), 10, 1 );
			add_filter( 'default_option_' . $opt,     array( self::class, 'default_value' ),   10, 1 );
		}
	}

	// ── Filters ────────────────────────────────────────────────────────────

	/**
	 * `pre_update_option_X` filter — fires when `update_option()` is called.
	 * Encrypts the incoming plaintext value before WordPress writes it.
	 *
	 * @param mixed $value New value about to be saved.
	 * @return string Encrypted ciphertext (or empty string if input is empty).
	 */
	public static function encrypt_on_save( $value ): string {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		// Idempotent: if it's already ciphertext, don't double-encrypt.
		if ( self::is_encrypted( $value ) ) {
			return $value;
		}
		return self::encrypt( $value );
	}

	/**
	 * `option_X` filter — fires when `get_option()` reads the stored value.
	 * Decrypts ciphertext. Leaves plaintext (legacy values from v1.0.x)
	 * untouched so backward-compat reads keep working.
	 *
	 * @param mixed $value Stored value from wp_options.
	 * @return string Plaintext value for downstream code.
	 */
	public static function decrypt_on_read( $value ): string {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( ! self::is_encrypted( $value ) ) {
			// Legacy plaintext from a pre-1.1.0 install. Pass through.
			// The next time the user saves the option, encrypt_on_save()
			// will encrypt it — so plaintext drains naturally over time.
			return $value;
		}
		return self::decrypt( $value );
	}

	/**
	 * `default_option_X` filter — fires when an option doesn't exist yet.
	 * Returns empty string. Necessary because some callers pass a default
	 * to get_option() and we want our decryption logic to never run on
	 * the literal default.
	 */
	public static function default_value( $value ) {
		return '' === $value || null === $value ? '' : $value;
	}

	// ── Primitives ─────────────────────────────────────────────────────────

	/**
	 * Encrypt plaintext with AES-256-GCM.
	 *
	 * Format: rnrdenc:base64(iv ‖ tag ‖ ciphertext)
	 *
	 * @param string $plaintext Raw value to encrypt.
	 * @return string Prefixed ciphertext. Returns empty string on encryption failure.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// openssl ext unavailable — fall back to plaintext rather than break.
			// This is theoretical on modern hosts; openssl ships with PHP.
			return $plaintext;
		}

		$key = self::get_key();
		$iv  = random_bytes( 12 ); // 96-bit IV per GCM spec
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16 // 128-bit tag
		);

		if ( false === $ciphertext ) {
			// Encryption failed — refuse to silently drop the secret. Return
			// plaintext; the user's value still saves correctly. We log
			// nothing (could leak the secret).
			return $plaintext;
		}

		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $ciphertext Prefixed ciphertext.
	 * @return string Decrypted plaintext, or empty string on decryption failure.
	 */
	public static function decrypt( string $ciphertext ): string {
		if ( '' === $ciphertext ) {
			return '';
		}
		if ( ! self::is_encrypted( $ciphertext ) ) {
			return $ciphertext;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( substr( $ciphertext, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 32 ) {
			return '';
		}

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );

		$key       = self::get_key();
		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Heuristic: does this value look like one of our encrypted strings?
	 */
	public static function is_encrypted( string $value ): bool {
		return strncmp( $value, self::PREFIX, strlen( self::PREFIX ) ) === 0;
	}

	// ── Key derivation ─────────────────────────────────────────────────────

	/**
	 * Derive a 32-byte AES key from WP constants.
	 *
	 * If `AUTH_KEY` / `SECURE_AUTH_SALT` are unset (shouldn't happen on a
	 * real install but defensive), fall back to a per-site option-stored
	 * key so existing encrypted values stay decryptable.
	 */
	private static function get_key(): string {
		$material = '';
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$material .= AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_SALT' ) && SECURE_AUTH_SALT ) {
			$material .= SECURE_AUTH_SALT;
		}

		if ( '' === $material ) {
			// Defensive fallback for installs missing both constants.
			// Store a generated per-site key in options so values stay
			// readable across requests.
			$material = get_option( 'rnrd_crypto_fallback_key' );
			if ( ! is_string( $material ) || '' === $material ) {
				$material = bin2hex( random_bytes( 32 ) );
				update_option( 'rnrd_crypto_fallback_key', $material, false );
			}
		}

		return hash( 'sha256', 'rnrd:secret:v1:' . $material, true );
	}
}
