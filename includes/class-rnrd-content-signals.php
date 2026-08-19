<?php
/**
 * Content Signals — contentsignals.org directive builder for robots.txt.
 *
 * Manages the three content-usage permission signals (ai-train, search,
 * ai-input) that tell AI engines what they may do with site content.
 * The directives are emitted inside the RankReady block in robots.txt
 * via RNRD_Robots::generate_robots_block(), which calls
 * RNRD_Content_Signals::get_robots_fragment() to get this class's output.
 *
 * Option keys (defined in rankready.php):
 *   RNRD_OPT_CONTENT_SIGNALS_ENABLE   — master on/off toggle
 *   RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN — 'allow' | 'deny'
 *   RNRD_OPT_CONTENT_SIGNALS_SEARCH   — 'allow' | 'deny'
 *   RNRD_OPT_CONTENT_SIGNALS_AI_INPUT — 'allow' | 'deny'
 *
 * @package RankReady
 * @since   1.2.2
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Content_Signals {

	/**
	 * Whether Content Signals are enabled.
	 */
	public static function is_enabled(): bool {
		return 'on' === get_option( RNRD_OPT_CONTENT_SIGNALS_ENABLE, 'off' );
	}

	/**
	 * Read the three signal values.
	 *
	 * @return array{ai_train:string, search:string, ai_input:string}
	 *   Each value is 'yes' (allow) or 'no' (deny) — the output format
	 *   per the contentsignals.org spec.
	 */
	public static function get_signals(): array {
		return array(
			'ai_train' => 'allow' === get_option( RNRD_OPT_CONTENT_SIGNALS_AI_TRAIN, 'allow' ) ? 'yes' : 'no',
			'search'   => 'allow' === get_option( RNRD_OPT_CONTENT_SIGNALS_SEARCH, 'allow' ) ? 'yes' : 'no',
			'ai_input' => 'allow' === get_option( RNRD_OPT_CONTENT_SIGNALS_AI_INPUT, 'allow' ) ? 'yes' : 'no',
		);
	}

	/**
	 * Build the robots.txt fragment for Content Signals.
	 *
	 * Returns an empty string when the feature is off.
	 * The returned string is meant to be embedded inside the RankReady
	 * BEGIN/END block — it does NOT include those markers.
	 *
	 * Format per isitagentready.com:
	 *   # Content Signals (contentsignals.org)
	 *   Content-Signal: ai-train=yes, search=yes, ai-input=yes
	 */
	public static function get_robots_fragment(): string {
		if ( ! self::is_enabled() ) {
			return '';
		}

		$s = self::get_signals();
		return "# Content Signals (contentsignals.org)\n"
			. "Content-Signal: ai-train={$s['ai_train']}, search={$s['search']}, ai-input={$s['ai_input']}\n"
			. "\n";
	}
}
