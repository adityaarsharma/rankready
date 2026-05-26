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
	 * - OpenAI:    no evergreen alias. `gpt-4o-mini` is the cheapest current model.
	 * - Anthropic: every ID is pinned (Anthropic docs explicitly say so —
	 *              `claude-haiku-4-5` is the dateless pin, not an evergreen pointer).
	 * - Gemini:    `gemini-2.5-flash` is the stable production pick. Google docs
	 *              warn against `*-latest` aliases in production (hot-swap risk).
	 * - DeepSeek:  `deepseek-v4-flash` replaces the deprecated `deepseek-chat`
	 *              alias (per DeepSeek's pricing page, `deepseek-chat` /
	 *              `deepseek-reasoner` are being retired).
	 *
	 * If a user has an older saved value (e.g. `deepseek-chat`), it stays in
	 * `wp_options` and we still send it to the provider — the dropdown just
	 * stops listing it. When the user saves any change, sanitize_provider_model
	 * preserves whatever string they pick.
	 */
	public static function get_model( string $provider ): string {
		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC: return (string) get_option( 'rnrd_anthropic_model', 'claude-haiku-4-5' );
			case self::PROVIDER_GEMINI:    return (string) get_option( 'rnrd_gemini_model',    'gemini-2.5-flash' );
			case self::PROVIDER_DEEPSEEK:  return (string) get_option( 'rnrd_deepseek_model',  'deepseek-v4-flash' );
			case self::PROVIDER_OPENAI:
			default:                       return (string) get_option( RNRD_OPT_MODEL,         'gpt-4o-mini' );
		}
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
	 * `id => human label`. Kept short — only currently-recommended production
	 * IDs. Updated each plugin release as providers ship new generations.
	 *
	 * Why no `*-latest` evergreen aliases:
	 *   - Anthropic: every model ID is a pinned snapshot — there's no evergreen
	 *     pointer (per Anthropic docs).
	 *   - Google: `gemini-flash-latest` etc. exist but Google explicitly
	 *     recommends pinned IDs for production (2-week hot-swap notice).
	 *   - DeepSeek: `deepseek-chat` / `deepseek-reasoner` were the only true
	 *     evergreen aliases and DeepSeek is deprecating them in favour of
	 *     pinned `deepseek-v4-*` IDs.
	 *
	 * So "evergreen" in 2026 means shipping each provider's most current
	 * recommended pinned ID with every plugin release. Anything else risks
	 * sudden behaviour change or silent retirement.
	 */
	public static function get_models_for( string $provider ): array {
		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				return array(
					'gpt-4o-mini'   => __( 'GPT-4o mini (fast, cheapest)', 'rankready-ai-llm-seo' ),
					'gpt-4o'        => __( 'GPT-4o (best quality)', 'rankready-ai-llm-seo' ),
				);
			case self::PROVIDER_ANTHROPIC:
				return array(
					'claude-haiku-4-5'  => __( 'Claude Haiku 4.5 (fast, cheap)', 'rankready-ai-llm-seo' ),
					'claude-sonnet-4-6' => __( 'Claude Sonnet 4.6 (balanced)', 'rankready-ai-llm-seo' ),
					'claude-opus-4-7'   => __( 'Claude Opus 4.7 (highest quality)', 'rankready-ai-llm-seo' ),
				);
			case self::PROVIDER_GEMINI:
				return array(
					'gemini-2.5-flash'      => __( 'Gemini 2.5 Flash (fast, cheap)', 'rankready-ai-llm-seo' ),
					'gemini-2.5-flash-lite' => __( 'Gemini 2.5 Flash Lite (lowest cost)', 'rankready-ai-llm-seo' ),
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
