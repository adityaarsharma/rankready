<?php
/**
 * Brand Identity — single source of truth for all brand data.
 *
 * Centralises the four brand fields (name, summary, about, canonical terms)
 * that every AI surface — llms.txt, robots.txt, Markdown, MCP, prompts —
 * needs to read. Previously these getters lived in RNRD_Llms_Txt; they are
 * here so nothing that only needs brand data has to load the full llms.txt
 * generator.
 *
 * Option keys (defined in rankready.php):
 *   RNRD_OPT_LLMS_SITE_NAME   — brand/site name
 *   RNRD_OPT_LLMS_SUMMARY     — one-line summary (≤ 160 chars)
 *   RNRD_OPT_LLMS_ABOUT       — longer description (≤ 500 chars)
 *   RNRD_OPT_BRAND_TERMS      — newline-separated canonical brand names
 *
 * @package RankReady
 * @since   1.2.2
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Brand_Identity {

	/**
	 * Unified Brand Identity getter.
	 *
	 * Returns an associative array shaped:
	 *   [
	 *     'name'    => string (site / brand name),
	 *     'summary' => string (one-line, ≤ 160 chars),
	 *     'about'   => string (longer description, ≤ 500 chars),
	 *     'terms'   => string[] (canonical brand names list),
	 *   ]
	 *
	 * Resolution order per field:
	 *   1. The unified options if present
	 *   2. WordPress core fallbacks (bloginfo)
	 *
	 * @since 1.2.2
	 */
	public static function get_brand_identity(): array {
		// Name: explicit > WP site title.
		$name = (string) get_option( RNRD_OPT_LLMS_SITE_NAME, '' );
		if ( '' === trim( $name ) ) {
			$name = (string) get_bloginfo( 'name' );
		}

		// Summary: option > WP tagline.
		$summary = (string) get_option( RNRD_OPT_LLMS_SUMMARY, '' );
		if ( '' === trim( $summary ) ) {
			$summary = (string) get_bloginfo( 'description' );
		}

		// About: dedicated option only.
		$about = (string) get_option( RNRD_OPT_LLMS_ABOUT, '' );

		// Terms: canonical brand terms list.
		$terms = self::get_brand_terms_list();

		return array(
			'name'    => trim( $name ),
			'summary' => trim( $summary ),
			'about'   => trim( $about ),
			'terms'   => $terms,
		);
	}

	// ── Convenience sub-getters ────────────────────────────────────────────────

	public static function get_brand_name(): string    { return (string) self::get_brand_identity()['name']; }
	public static function get_brand_summary(): string { return (string) self::get_brand_identity()['summary']; }
	public static function get_brand_about(): string   { return (string) self::get_brand_identity()['about']; }

	/**
	 * Returns brand terms as a flat array.
	 * Each entry is a single line from RNRD_OPT_BRAND_TERMS, trimmed and deduplicated.
	 *
	 * @return string[]
	 */
	public static function get_brand_terms_list(): array {
		$raw = (string) get_option( RNRD_OPT_BRAND_TERMS, '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			// Strip any embedded line separators that survived sanitize_textarea_field.
			// A \r / \u2028 / \u2029 inside a term would split the robots.txt
			// # Brand: line across records and confuse parsers.
			$line = preg_replace( '/[\r\n\x{2028}\x{2029}]+/u', ' ', $line );
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Returns brand terms as a comma-separated string suitable for prompt injection.
	 * Returns empty string when nothing is configured.
	 */
	public static function get_brand_terms_string(): string {
		$list = self::get_brand_terms_list();
		return empty( $list ) ? '' : implode( ', ', $list );
	}
}
