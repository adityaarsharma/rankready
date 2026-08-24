<?php
/**
 * Shared utilities used across RankReady feature classes.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Util {

	/**
	 * Check if a theme builder (Elementor Pro or Nexter) renders this page.
	 * The blog post itself is NOT built with Elementor — the theme builder template is.
	 */
	public static function is_theme_builder_page( int $post_id ): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		if ( get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return true;
		}

		if ( did_action( 'elementor/theme/before_do_single' ) ) {
			return true;
		}

		if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			$theme_module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
			if ( method_exists( $theme_module, 'get_conditions_manager' ) ) {
				$conditions = $theme_module->get_conditions_manager();
				if ( method_exists( $conditions, 'get_documents_for_location' ) ) {
					$docs = $conditions->get_documents_for_location( 'single' );
					if ( ! empty( $docs ) ) {
						return true;
					}
				}
			}
		}

		if ( did_action( 'nexter_single_builder_render' ) ) {
			return true;
		}

		$nxt_single = get_option( 'nexter_builder_single_template', '' );
		if ( ! empty( $nxt_single ) ) {
			return true;
		}

		return false;
	}

	public static function validate_heading_tag( $tag ): string {
		$allowed = array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' );
		return in_array( $tag, $allowed, true ) ? $tag : 'h4';
	}

	public static function sanitize_color( $color ): string {
		$color = trim( (string) $color );
		if ( preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|[a-zA-Z]+)$/', $color ) ) {
			return $color;
		}
		return '';
	}
}
