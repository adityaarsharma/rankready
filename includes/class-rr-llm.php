<?php
/**
 * RankReady — LLM provider dispatcher.
 *
 * Single entry point for every AI call in the plugin (summary + FAQ + future
 * features). Dispatches to one of four providers based on the
 * `rr_llm_provider` option: OpenAI, Anthropic (Claude), Google (Gemini),
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

class RR_LLM {

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
		$provider = (string) get_option( 'rr_llm_provider', self::PROVIDER_OPENAI );
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
			case self::PROVIDER_ANTHROPIC: return (string) get_option( 'rr_anthropic_api_key', '' );
			case self::PROVIDER_GEMINI:    return (string) get_option( 'rr_gemini_api_key', '' );
			case self::PROVIDER_DEEPSEEK:  return (string) get_option( 'rr_deepseek_api_key', '' );
			case self::PROVIDER_OPENAI:
			default:                       return (string) get_option( RR_OPT_KEY, '' );
		}
	}

	/**
	 * Returns the active model for a given provider.
	 */
	public static function get_model( string $provider ): string {
		switch ( $provider ) {
			case self::PROVIDER_ANTHROPIC: return (string) get_option( 'rr_anthropic_model', 'claude-haiku-4-5' );
			case self::PROVIDER_GEMINI:    return (string) get_option( 'rr_gemini_model', 'gemini-2.5-flash' );
			case self::PROVIDER_DEEPSEEK:  return (string) get_option( 'rr_deepseek_model', 'deepseek-chat' );
			case self::PROVIDER_OPENAI:
			default:                       return (string) get_option( RR_OPT_MODEL, 'gpt-4o-mini' );
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
			case self::PROVIDER_ANTHROPIC: return __( 'Claude (Anthropic)', 'rankready' );
			case self::PROVIDER_GEMINI:    return __( 'Gemini (Google)', 'rankready' );
			case self::PROVIDER_DEEPSEEK:  return __( 'DeepSeek', 'rankready' );
			case self::PROVIDER_OPENAI:
			default:                       return __( 'OpenAI', 'rankready' );
		}
	}

	/**
	 * Returns the list of model IDs available for a provider. Kept short —
	 * latest production models only. Each entry is `id => human label`.
	 *
	 * Pricing context (per 1M tokens, May 2026):
	 *   - GPT-4o-mini:        $0.15 in / $0.60 out
	 *   - GPT-4o:             $2.50 in / $10.00 out
	 *   - Claude Haiku 4.5:   $1.00 in / $5.00 out
	 *   - Claude Sonnet 4.5:  $3.00 in / $15.00 out
	 *   - Gemini 2.5 Flash:   $0.075 in / $0.30 out
	 *   - Gemini 2.5 Pro:     $1.25 in / $5.00 out
	 *   - DeepSeek V3 chat:   $0.27 in / $1.10 out
	 *   - DeepSeek Reasoner:  $0.55 in / $2.19 out
	 */
	public static function get_models_for( string $provider ): array {
		switch ( $provider ) {
			case self::PROVIDER_OPENAI:
				return array(
					'gpt-4o-mini'   => __( 'GPT-4o mini (fast, cheapest)', 'rankready' ),
					'gpt-4o'        => __( 'GPT-4o (best quality)', 'rankready' ),
					'gpt-4-turbo'   => __( 'GPT-4 Turbo (legacy)', 'rankready' ),
					'gpt-3.5-turbo' => __( 'GPT-3.5 Turbo (legacy, cheapest)', 'rankready' ),
				);
			case self::PROVIDER_ANTHROPIC:
				return array(
					'claude-haiku-4-5'  => __( 'Claude Haiku 4.5 (fast, cheap)', 'rankready' ),
					'claude-sonnet-4-5' => __( 'Claude Sonnet 4.5 (best quality)', 'rankready' ),
				);
			case self::PROVIDER_GEMINI:
				return array(
					'gemini-2.5-flash'      => __( 'Gemini 2.5 Flash (fast, cheapest)', 'rankready' ),
					'gemini-2.5-pro'        => __( 'Gemini 2.5 Pro (best quality)', 'rankready' ),
					'gemini-2.5-flash-lite' => __( 'Gemini 2.5 Flash Lite (lowest cost)', 'rankready' ),
				);
			case self::PROVIDER_DEEPSEEK:
				return array(
					'deepseek-chat'     => __( 'DeepSeek Chat (V3, fast)', 'rankready' ),
					'deepseek-reasoner' => __( 'DeepSeek Reasoner (R1, deep reasoning)', 'rankready' ),
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
					__( 'No %s API key configured. Add one in RankReady → Settings.', 'rankready' ),
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
			case self::PROVIDER_ANTHROPIC: return RR_LLM_Anthropic::generate( $system, $user, $opts );
			case self::PROVIDER_GEMINI:    return RR_LLM_Gemini::generate( $system, $user, $opts );
			case self::PROVIDER_DEEPSEEK:  return RR_LLM_DeepSeek::generate( $system, $user, $opts );
			case self::PROVIDER_OPENAI:
			default:                       return RR_LLM_OpenAI::generate( $system, $user, $opts );
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
