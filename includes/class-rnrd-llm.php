<?php
/**
 * RankReady — LLM provider dispatcher.
 *
 * Single entry point for every AI call in the plugin (summary + FAQ + future
 * features). Dispatches to one of four providers based on the
 * `rnrd_llm_provider` option: OpenAI, Anthropic (Claude), Google (Gemini),
 * or DeepSeek.
 *
 * Every provider implementation follows the same contract:
 *
 *     ProviderClass::generate( string $system, string $user, array $opts ): array
 *     // Returns: [
 *     //   'ok'         => bool,
 *     //   'content'    => string,   // raw assistant content
 *     //   'tokens_in'  => int,
 *     //   'tokens_out' => int,
 *     //   'tokens_total' => int,
 *     //   'model'      => string,
 *     //   'provider'   => string,
 *     //   'error'      => string,   // empty when ok=true
 *     // ]
 *
 * Options (all optional):
 *   - max_tokens       (int)    Default: 500
 *   - temperature      (float)  Default: 0.2
 *   - json             (bool)   Request JSON-only output. Default: false
 *   - timeout          (int)    HTTP timeout in seconds. Default: 25
 *
 * @package RankReady
 */
defined( 'ABSPATH' ) || exit;

class RNRD_LLM {

	/** Provider IDs — keep in sync with the provider radio in admin. */
	const PROVIDER_OPENAI    = 'openai';
	const PROVIDER_ANTHROPIC = 'anthropic';
	const PROVIDER_GEMINI    = 'gemini';
	const PROVIDER_DEEPSEEK  = 'deepseek';

	/**
	 * Returns the currently active provider ID, validated against the
	 * known list. Falls back to OpenAI if a stale/unknown value is stored.
	 */
	public static function get_active_provider(): string {
		$provider = (string) get_option( 'rnrd_llm_provider', self::PROVIDER_OPENAI );
		$valid    = array(
			self::PROVIDER_OPENAI,
			self::PROVIDER_ANTHROPIC,
			self::PROVIDER_GEMINI,
			self::PROVIDER_DEEPSEEK,
		);
		return in_array( $provider, $valid, true ) ? $provider : self::PROVIDER_OPENAI;
	}

	/**
	 * Returns the API key for a given provider. Empty string if not set.
	 */
	public static function get_api_key( string $provider ): string {
		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC: return (string) get_option( 'rnrd_anthropic_api_key', '' );
			case self::PROVIDER_GEMINI:    return (string) get_option( 'rnrd_gemini_api_key', '' );
			case self::PROVIDER_DEEPSEEK:  return (string) get_option( 'rnrd_deepseek_api_key', '' );
			case self::PROVIDER_OPENAI:
			default:                       return (string) get_option( RNRD_OPT_KEY, '' );
		}
	}

	/**
	 * Returns the active model for a given provider.
	 *
	 * Defaults are kept in sync with each provider's *current* recommended
	 * production ID as of the plugin release. Notes per provider:
	 *
	 * - OpenAI:    no evergreen alias. `gpt-5.4-mini` is the cheapest current
	 *              text model (the GPT-4o generation is now legacy → migrated).
	 * - Anthropic: every ID is pinned (Anthropic docs explicitly say so —
	 *              `claude-haiku-4-5` is the dateless pin, not an evergreen pointer).
	 * - Gemini:    `gemini-2.5-flash` is the stable production pick. Google docs
	 *              warn against `*-latest` aliases in production (hot-swap risk).
	 * - DeepSeek:  `deepseek-v4-flash` replaces the deprecated `deepseek-chat`
	 *              alias (per DeepSeek's pricing page, `deepseek-chat` /
	 *              `deepseek-reasoner` are being retired).
	 *
	 * Retired IDs are routed to their current same-tier equivalent via
	 * self::migrate_model() — see self::model_migration_map() for the full list.
	 *
	 * If a user has an older saved value (e.g. `deepseek-chat`), it stays in
	 * `wp_options` and we still send it to the provider — the dropdown just
	 * stops listing it. When the user saves any change, sanitize_provider_model
	 * preserves whatever string they pick.
	 */
	public static function get_model( string $provider ): string {
		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC: $saved = (string) get_option( 'rnrd_anthropic_model', 'claude-haiku-4-5' ); break;
			case self::PROVIDER_GEMINI:    $saved = (string) get_option( 'rnrd_gemini_model',    'gemini-2.5-flash' ); break;
			case self::PROVIDER_DEEPSEEK:  $saved = (string) get_option( 'rnrd_deepseek_model',  'deepseek-v4-flash' ); break;
			case self::PROVIDER_OPENAI:
			default:                       $saved = (string) get_option( RNRD_OPT_MODEL,         'gpt-5.4-mini' ); break;
		}
		// Route a retired ID to its tier-matched current equivalent at call-time
		// so the provider never receives a dead model — even before the one-shot
		// DB migration (RNRD_Admin::track_installed_version) has had a chance to
		// run. Side-effect-free; the DB rewrite + admin notice live in the admin.
		return self::migrate_model( $provider, $saved );
	}

	/**
	 * Single source of truth for retired → current model migration.
	 *
	 * Every entry maps a retired/deprecated provider model ID to the CURRENT
	 * model in the SAME tier (Haiku→Haiku, Sonnet→Sonnet, Opus→Opus, mini→mini,
	 * flash→flash, pro→pro) — never cross-tier (a Sonnet user is never silently
	 * upgraded to Opus pricing). Used by both get_model() (call-time routing)
	 * and RNRD_Admin::track_installed_version() (one-shot DB rewrite + notice).
	 *
	 * To add a newly-retired model later: add one line here. Nothing else.
	 *
	 * @return array<string,array<string,string>> provider => [ old => new ]
	 */
	public static function model_migration_map(): array {
		return array(
			self::PROVIDER_OPENAI => array(
				'gpt-4o-mini'   => 'gpt-5.4-mini',
				'gpt-4.1-mini'  => 'gpt-5.4-mini',
				'gpt-3.5-turbo' => 'gpt-5.4-mini',
				'gpt-4o'        => 'gpt-5.4',
				'gpt-4.1'       => 'gpt-5.4',
				'gpt-4-turbo'   => 'gpt-5.4',
				'gpt-4'         => 'gpt-5.4',
			),
			self::PROVIDER_ANTHROPIC => array(
				// Haiku tier
				'claude-3-5-haiku-20241022'  => 'claude-haiku-4-5',
				'claude-3-5-haiku-latest'    => 'claude-haiku-4-5',
				'claude-3-haiku-20240307'    => 'claude-haiku-4-5',
				// Sonnet tier
				'claude-sonnet-4-20250514'   => 'claude-sonnet-4-6',
				'claude-sonnet-4-0'          => 'claude-sonnet-4-6',
				'claude-sonnet-4-5'          => 'claude-sonnet-4-6',
				'claude-sonnet-4-5-20250929' => 'claude-sonnet-4-6',
				'claude-3-7-sonnet-20250219' => 'claude-sonnet-4-6',
				'claude-3-7-sonnet-latest'   => 'claude-sonnet-4-6',
				'claude-3-5-sonnet-20241022' => 'claude-sonnet-4-6',
				'claude-3-5-sonnet-latest'   => 'claude-sonnet-4-6',
				// Opus tier (4-7 is still live, so NOT migrated; 4-6 and older are)
				'claude-opus-4-20250514'     => 'claude-opus-4-8',
				'claude-opus-4-0'            => 'claude-opus-4-8',
				'claude-opus-4-1'            => 'claude-opus-4-8',
				'claude-opus-4-1-20250805'   => 'claude-opus-4-8',
				'claude-opus-4-5'            => 'claude-opus-4-8',
				'claude-opus-4-6'            => 'claude-opus-4-8',
				'claude-3-opus-20240229'     => 'claude-opus-4-8',
				'claude-3-opus-latest'       => 'claude-opus-4-8',
			),
			self::PROVIDER_GEMINI => array(
				'gemini-1.5-flash'        => 'gemini-2.5-flash',
				'gemini-1.5-flash-latest' => 'gemini-2.5-flash',
				'gemini-2.0-flash'        => 'gemini-2.5-flash',
				'gemini-2.0-flash-001'    => 'gemini-2.5-flash',
				'gemini-2.5-flash-lite'   => 'gemini-3.1-flash-lite',
				'gemini-1.5-pro'          => 'gemini-2.5-pro',
				'gemini-1.5-pro-latest'   => 'gemini-2.5-pro',
			),
			self::PROVIDER_DEEPSEEK => array(
				'deepseek-chat'     => 'deepseek-v4-flash',
				'deepseek-reasoner' => 'deepseek-v4-pro',
			),
		);
	}

	/**
	 * Resolve a (possibly retired) model ID to its current equivalent for a
	 * provider. Returns the input unchanged when it is already current or not
	 * in the migration map. Pure function — no DB writes, no side effects.
	 */
	public static function migrate_model( string $provider, string $model ): string {
		$map = self::model_migration_map();
		return isset( $map[ $provider ][ $model ] ) ? $map[ $provider ][ $model ] : $model;
	}

	/**
	 * Returns true when the active provider has an API key configured.
	 * Used by the admin "API key set?" status badge.
	 */
	public static function active_provider_ready(): bool {
		return '' !== trim( self::get_api_key( self::get_active_provider() ) );
	}

	/**
	 * Returns a human-readable label for a provider ID. Used in admin UI
	 * and error messages. Translatable via the `rankready` text domain.
	 */
	public static function get_provider_label( string $provider ): string {
		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC: return __( 'Claude (Anthropic)', 'rankready-ai-llm-seo' );
			case self::PROVIDER_GEMINI:    return __( 'Gemini (Google)', 'rankready-ai-llm-seo' );
			case self::PROVIDER_DEEPSEEK:  return __( 'DeepSeek', 'rankready-ai-llm-seo' );
			case self::PROVIDER_OPENAI:
			default:                       return __( 'OpenAI', 'rankready-ai-llm-seo' );
		}
	}

	/**
	 * Returns the list of model IDs available for a provider. Each entry is
	 * `id => human label`. Deliberately SHORT — only the 4-5 KEY text models we
	 * curate per provider (get_fallback_models). Updated each plugin release as
	 * providers ship new generations.
	 *
	 * When a key is configured we still call the provider's live /models
	 * endpoint, but only to CONFIRM which curated IDs the account can actually
	 * use — we intersect the live list with our curated set rather than dumping
	 * every model the provider exposes (OpenAI alone returns 25+). This keeps
	 * the dropdown to a hand-picked few, drops any curated model the account
	 * can't access, and lets us add new models by editing one curated list.
	 *
	 * Why no `*-latest` evergreen aliases:
	 *   - Anthropic: every model ID is a pinned snapshot — there's no evergreen
	 *     pointer (per Anthropic docs).
	 *   - Google: `gemini-flash-latest` etc. exist but Google explicitly
	 *     recommends pinned IDs for production (2-week hot-swap notice).
	 *   - DeepSeek: `deepseek-chat` / `deepseek-reasoner` were the only true
	 *     evergreen aliases and DeepSeek is deprecating them in favour of
	 *     pinned `deepseek-v4-*` IDs.
	 */
	public static function get_models_for( string $provider ): array {
		$curated = self::get_fallback_models( $provider );

		$key = trim( self::get_api_key( $provider ) );
		if ( '' === $key ) {
			return self::add_saved_model( $provider, $curated );
		}

		$cache_key = 'rnrd_models_' . $provider;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return self::add_saved_model( $provider, $cached );
		}

		$remote = self::fetch_remote_models( $provider, $key );
		if ( ! empty( $remote ) ) {
			// Keep only curated KEY models the account actually exposes,
			// preserving our labels + order. If the live list confirms none of
			// them (e.g. a brand-new generation not yet in our curated set),
			// show the curated list as-is so the dropdown is never empty.
			$confirmed = array();
			foreach ( $curated as $id => $label ) {
				if ( isset( $remote[ $id ] ) ) {
					$confirmed[ $id ] = $label;
				}
			}
			$list = ! empty( $confirmed ) ? $confirmed : $curated;
			set_transient( $cache_key, $list, 12 * HOUR_IN_SECONDS );
			return self::add_saved_model( $provider, $list );
		}

		// Fetch failed — serve the curated list, and cache it briefly so a
		// down/blocked provider doesn't trigger a slow call on every page load.
		set_transient( $cache_key, $curated, 30 * MINUTE_IN_SECONDS );
		return self::add_saved_model( $provider, $curated );
	}

	/**
	 * Ensure the user's currently-saved model always appears in the dropdown,
	 * even if the provider has since dropped it from the live list — otherwise
	 * the saved value would silently fall off the <select>.
	 */
	private static function add_saved_model( string $provider, array $models ): array {
		$saved = self::get_model( $provider );
		if ( '' !== $saved && ! isset( $models[ $saved ] ) ) {
			$models[ $saved ] = $saved;
		}
		return $models;
	}

	/**
	 * Live model list from the provider's /models endpoint. Returns id => label.
	 * Empty array on any failure (caller falls back to the static list).
	 */
	private static function fetch_remote_models( string $provider, string $key ): array {
		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				$resp = wp_remote_get( 'https://api.openai.com/v1/models', array(
					'timeout' => 8,
					'headers' => array( 'Authorization' => 'Bearer ' . $key ),
				) );
				// Keep gpt-* chat models; drop non-chat families and noisy dated /
				// sized / preview snapshots so the dropdown shows clean aliases.
				return self::parse_data_id_models(
					$resp,
					'gpt-',
					array( 'instruct', 'realtime', 'audio', 'transcribe', 'tts', 'image', 'dall', 'whisper', 'search', 'embedding', 'moderation', 'codex' ),
					'/(\d{4}|\d+k\b|preview|chatgpt)/'
				);

			case self::PROVIDER_DEEPSEEK:
				$resp = wp_remote_get( 'https://api.deepseek.com/v1/models', array(
					'timeout' => 8,
					'headers' => array( 'Authorization' => 'Bearer ' . $key ),
				) );
				return self::parse_data_id_models( $resp, 'deepseek-', array() );

			case self::PROVIDER_ANTHROPIC:
				$resp = wp_remote_get( 'https://api.anthropic.com/v1/models', array(
					'timeout' => 8,
					'headers' => array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ),
				) );
				$json = self::decode_ok( $resp );
				if ( empty( $json['data'] ) || ! is_array( $json['data'] ) ) {
					return array();
				}
				$out = array();
				foreach ( $json['data'] as $m ) {
					$id = isset( $m['id'] ) ? (string) $m['id'] : '';
					if ( '' === $id || 0 !== strpos( $id, 'claude-' ) ) {
						continue;
					}
					$out[ $id ] = isset( $m['display_name'] ) && '' !== $m['display_name'] ? (string) $m['display_name'] : $id;
				}
				return $out;

			case self::PROVIDER_GEMINI:
				$resp = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $key ), array(
					'timeout' => 8,
				) );
				$json = self::decode_ok( $resp );
				if ( empty( $json['models'] ) || ! is_array( $json['models'] ) ) {
					return array();
				}
				$out = array();
				foreach ( $json['models'] as $m ) {
					$methods = isset( $m['supportedGenerationMethods'] ) ? (array) $m['supportedGenerationMethods'] : array();
					if ( ! in_array( 'generateContent', $methods, true ) ) {
						continue;
					}
					$id = isset( $m['name'] ) ? str_replace( 'models/', '', (string) $m['name'] ) : '';
					if ( '' === $id || 0 !== strpos( $id, 'gemini-' ) ) {
						continue;
					}
					$out[ $id ] = isset( $m['displayName'] ) && '' !== $m['displayName'] ? (string) $m['displayName'] : $id;
				}
				return $out;
		}
		return array();
	}

	/**
	 * Shared parser for OpenAI-shaped `{ data: [ { id } ] }` model lists.
	 * Keeps ids beginning with $prefix, drops any containing a $exclude term.
	 */
	private static function parse_data_id_models( $resp, string $prefix, array $exclude, string $drop_regex = '' ): array {
		$json = self::decode_ok( $resp );
		if ( empty( $json['data'] ) || ! is_array( $json['data'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $json['data'] as $m ) {
			$id = isset( $m['id'] ) ? (string) $m['id'] : '';
			if ( '' === $id || 0 !== strpos( $id, $prefix ) ) {
				continue;
			}
			foreach ( $exclude as $term ) {
				if ( false !== strpos( $id, $term ) ) {
					continue 2;
				}
			}
			if ( '' !== $drop_regex && preg_match( $drop_regex, $id ) ) {
				continue;
			}
			$out[ $id ] = $id;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Return the JSON body of a successful (HTTP 200) wp_remote response as an
	 * array, or null on any error / non-200 / non-array body.
	 */
	private static function decode_ok( $resp ): ?array {
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		return is_array( $json ) ? $json : null;
	}

	/**
	 * Verified static model lists — the FALLBACK only. Correct as of the
	 * release date; the live fetch above supersedes these whenever a key is
	 * present. Update when a provider ships/retires a model.
	 */
	private static function get_fallback_models( string $provider ): array {
		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				return array(
					'gpt-5.4-nano' => __( 'GPT-5.4 nano (fastest, lowest cost)', 'rankready-ai-llm-seo' ),
					'gpt-5.4-mini' => __( 'GPT-5.4 mini (fast, cheap)', 'rankready-ai-llm-seo' ),
					'gpt-5.4'      => __( 'GPT-5.4 (balanced)', 'rankready-ai-llm-seo' ),
					'gpt-5.5'      => __( 'GPT-5.5 (highest quality)', 'rankready-ai-llm-seo' ),
				);
			case self::PROVIDER_ANTHROPIC:
				return array(
					'claude-haiku-4-5'  => __( 'Claude Haiku 4.5 (fast, cheap)', 'rankready-ai-llm-seo' ),
					'claude-sonnet-4-6' => __( 'Claude Sonnet 4.6 (balanced)', 'rankready-ai-llm-seo' ),
					'claude-opus-4-7'   => __( 'Claude Opus 4.7 (high quality)', 'rankready-ai-llm-seo' ),
					'claude-opus-4-8'   => __( 'Claude Opus 4.8 (highest quality)', 'rankready-ai-llm-seo' ),
				);
			case self::PROVIDER_GEMINI:
				return array(
					'gemini-3.1-flash-lite' => __( 'Gemini 3.1 Flash Lite (lowest cost)', 'rankready-ai-llm-seo' ),
					'gemini-2.5-flash'      => __( 'Gemini 2.5 Flash (balanced, cheap)', 'rankready-ai-llm-seo' ),
					'gemini-3.5-flash'      => __( 'Gemini 3.5 Flash (fast, most intelligent)', 'rankready-ai-llm-seo' ),
					'gemini-2.5-pro'        => __( 'Gemini 2.5 Pro (highest quality)', 'rankready-ai-llm-seo' ),
				);
			case self::PROVIDER_DEEPSEEK:
				return array(
					'deepseek-v4-flash' => __( 'DeepSeek V4 Flash (fast, cheap)', 'rankready-ai-llm-seo' ),
					'deepseek-v4-pro'   => __( 'DeepSeek V4 Pro (highest quality)', 'rankready-ai-llm-seo' ),
				);
		}
		return array();
	}

	/**
	 * Main dispatch entry point. All callers (summary, FAQ, future features)
	 * use this. Routes to the active provider's static `generate()` method.
	 *
	 * Returns the standardized response array described in the file docblock.
	 * Never throws — errors come back as `['ok' => false, 'error' => '…']`.
	 *
	 * @param string $system System prompt (provider-agnostic).
	 * @param string $user   User prompt.
	 * @param array  $opts   Optional: max_tokens, temperature, json, timeout.
	 */
	public static function generate( string $system, string $user, array $opts = array() ): array {
		$provider = self::get_active_provider();
		$api_key  = self::get_api_key( $provider );

		if ( '' === trim( $api_key ) ) {
			return self::error_response(
				$provider,
				sprintf(
					/* translators: %s: provider label */
					__( 'No %s API key configured. Add one in RankReady → Settings.', 'rankready-ai-llm-seo' ),
					self::get_provider_label( $provider )
				)
			);
		}

		$opts = wp_parse_args( $opts, array(
			'max_tokens'  => 500,
			'temperature' => 0.2,
			'json'        => false,
			'timeout'     => 25,
		) );

		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC: return RNRD_LLM_Anthropic::generate( $system, $user, $opts );
			case self::PROVIDER_GEMINI:    return RNRD_LLM_Gemini::generate( $system, $user, $opts );
			case self::PROVIDER_DEEPSEEK:  return RNRD_LLM_DeepSeek::generate( $system, $user, $opts );
			case self::PROVIDER_OPENAI:
			default:                       return RNRD_LLM_OpenAI::generate( $system, $user, $opts );
		}
	}

	/**
	 * Builds the standardized error response shape. Centralized so every
	 * provider returns identical fields when something fails.
	 */
	public static function error_response( string $provider, string $error, string $model = '' ): array {
		return array(
			'ok'           => false,
			'content'      => '',
			'tokens_in'    => 0,
			'tokens_out'   => 0,
			'tokens_total' => 0,
			'model'        => $model,
			'provider'     => $provider,
			'error'        => $error,
		);
	}

	/**
	 * Builds the standardized success response shape.
	 */
	public static function success_response( string $provider, string $model, string $content, int $tokens_in = 0, int $tokens_out = 0 ): array {
		return array(
			'ok'           => true,
			'content'      => $content,
			'tokens_in'    => $tokens_in,
			'tokens_out'   => $tokens_out,
			'tokens_total' => $tokens_in + $tokens_out,
			'model'        => $model,
			'provider'     => $provider,
			'error'        => '',
		);
	}
}
